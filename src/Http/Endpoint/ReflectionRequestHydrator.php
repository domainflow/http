<?php

declare(strict_types=1);

namespace DomainFlow\Http\Endpoint;

use BackedEnum;
use DomainFlow\Http\Endpoint\Exception\RequestHydrationConfigurationException;
use DomainFlow\Http\ErrorHandling\Exception\RequestValidationException;
use DomainFlow\Http\ErrorHandling\Exception\UnsupportedMediaTypeException;
use DomainFlow\Http\RouteMatch;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use stdClass;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BackedEnumType;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Throwable;

final class ReflectionRequestHydrator implements RequestHydrator
{
    /**
     * @var array<class-string, array{
     *     class: class-string,
     *     parameters: list<array{name: string, type: Type, hasDefault: bool, default: mixed}>
     * }>
     */
    private array $plans = [];

    /**
     * @param list<class-string> $requestClasses
     */
    public function __construct(array $requestClasses)
    {
        $typeResolver = TypeResolver::create();

        foreach ($requestClasses as $requestClass) {
            $this->compile($requestClass, $typeResolver);
        }
    }

    public function hydrate(ServerRequestInterface $request, RouteMatch $match): ?object
    {
        if ($match->requestClass === null) {
            return null;
        }

        $plan = $this->plans[$match->requestClass] ?? null;
        if ($plan === null) {
            throw RequestHydrationConfigurationException::forRequestClass($match->requestClass);
        }

        $body = (string) $request->getBody();
        $this->validateMediaType($request, $body);
        $values = $this->inputValues($request, $match, $body);
        $violations = [];
        [$valid, $dto] = $this->hydratePlan($plan, $values, '', $violations);

        if (!$valid || !$dto instanceof $match->requestClass) {
            throw RequestValidationException::fromViolations($violations);
        }

        return $dto;
    }

    /** @param class-string $requestClass */
    private function compile(string $requestClass, TypeResolver $typeResolver): void
    {
        if (isset($this->plans[$requestClass])) {
            return;
        }

        try {
            $reflection = new ReflectionClass($requestClass);
            $constructor = $reflection->getConstructor();
        } catch (Throwable $exception) {
            throw RequestHydrationConfigurationException::forRequestClass(
                $requestClass,
                'metadata cannot be reflected.',
                $exception,
            );
        }
        if (!$reflection->isInstantiable()) {
            throw RequestHydrationConfigurationException::forRequestClass(
                $requestClass,
                'the class is not instantiable.',
            );
        }

        $parameters = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            try {
                $type = $typeResolver->resolve($parameter);
                $default = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
            } catch (Throwable $exception) {
                throw RequestHydrationConfigurationException::forRequestClass(
                    $requestClass,
                    sprintf('parameter "%s" metadata cannot be compiled.', $parameter->getName()),
                    $exception,
                );
            }
            $parameters[] = [
                'name' => $parameter->getName(),
                'type' => $type,
                'hasDefault' => $parameter->isDefaultValueAvailable(),
                'default' => $default,
            ];
        }

        $this->plans[$requestClass] = [
            'class' => $requestClass,
            'parameters' => $parameters,
        ];

        foreach ($parameters as $parameter) {
            $this->compileNestedTypes($requestClass, $parameter['name'], $parameter['type'], $typeResolver);
        }
    }

    /** @param class-string $requestClass */
    private function compileNestedTypes(
        string $requestClass,
        string $parameter,
        Type $type,
        TypeResolver $typeResolver,
    ): void {
        if ($type instanceof NullableType) {
            $this->compileNestedTypes($requestClass, $parameter, $type->getWrappedType(), $typeResolver);

            return;
        }

        if ($type instanceof CollectionType) {
            if (!$type->isList()) {
                throw RequestHydrationConfigurationException::forParameter(
                    $requestClass,
                    $parameter,
                    'must be declared as a list.',
                );
            }

            $this->compileNestedTypes($requestClass, $parameter, $type->getCollectionValueType(), $typeResolver);

            return;
        }

        if ($type instanceof BackedEnumType || $type instanceof BuiltinType) {
            return;
        }

        if ($type instanceof ObjectType) {
            $class = $type->getClassName();
            if (!class_exists($class)) {
                throw RequestHydrationConfigurationException::forParameter(
                    $requestClass,
                    $parameter,
                    sprintf('references unknown class %s.', $class),
                );
            }

            $this->compile($class, $typeResolver);

            return;
        }

        throw RequestHydrationConfigurationException::forParameter(
            $requestClass,
            $parameter,
            sprintf('uses unsupported type %s.', (string) $type),
        );
    }

    /**
     * @return array<array-key, array{value: mixed, coerce: bool}>
     */
    private function inputValues(ServerRequestInterface $request, RouteMatch $match, string $body): array
    {
        $values = $this->sourceValues($this->bodyData($body), false);
        $values = array_replace($values, $this->sourceValues($request->getQueryParams(), true));

        return array_replace($values, $this->sourceValues($match->routeParameters, true));
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, array{value: mixed, coerce: bool}>
     */
    private function sourceValues(array $values, bool $coerce): array
    {
        $sources = [];
        foreach ($values as $name => $value) {
            $sources[(string) $name] = ['value' => $value, 'coerce' => $coerce];
        }

        return $sources;
    }

    /**
     * @param array{class: class-string, parameters: list<array{name: string, type: Type, hasDefault: bool, default: mixed}>} $plan
     * @param array<array-key, array{value: mixed, coerce: bool}> $values
     * @param array<string, non-empty-list<string>> $violations
     *
     * @return array{bool, object|null}
     */
    private function hydratePlan(array $plan, array $values, string $path, array &$violations): array
    {
        $knownNames = [];
        foreach ($plan['parameters'] as $parameter) {
            $knownNames[$parameter['name']] = true;
        }

        foreach ($values as $name => $_value) {
            if (!isset($knownNames[$name])) {
                $unknownPath = $path === '' && is_int($name)
                    ? '$body.' . $name
                    : $this->path($path, (string) $name);
                $this->addViolation($violations, $unknownPath, 'Unknown field.');
            }
        }

        $arguments = [];
        $valid = true;

        foreach ($plan['parameters'] as $parameter) {
            $parameterPath = $this->path($path, $parameter['name']);

            if (!array_key_exists($parameter['name'], $values)) {
                if ($parameter['hasDefault']) {
                    $arguments[] = $parameter['default'];

                    continue;
                }

                if ($parameter['type']->isNullable()) {
                    $arguments[] = null;

                    continue;
                }

                $this->addViolation($violations, $parameterPath, 'Required field.');
                $valid = false;
                $arguments[] = null;

                continue;
            }

            $source = $values[$parameter['name']];
            [$converted, $value] = $this->convert(
                $parameter['type'],
                $source['value'],
                $source['coerce'],
                $parameterPath,
                $violations,
            );
            $valid = $converted && $valid;
            $arguments[] = $value;
        }

        if (!$valid || $violations !== []) {
            return [false, null];
        }

        $class = $plan['class'];

        return [true, new $class(...$arguments)];
    }

    /**
     * @param array<string, non-empty-list<string>> $violations
     *
     * @return array{bool, mixed}
     */
    private function convert(
        Type $type,
        mixed $value,
        bool $coerce,
        string $path,
        array &$violations,
    ): array {
        if ($type instanceof NullableType) {
            if ($value === null) {
                return [true, null];
            }

            return $this->convert($type->getWrappedType(), $value, $coerce, $path, $violations);
        }

        if ($type instanceof BackedEnumType) {
            return $this->convertEnum($type, $value, $coerce, $path, $violations);
        }

        if ($type instanceof BuiltinType) {
            return $this->convertBuiltin($type, $value, $coerce, $path, $violations);
        }

        if ($type instanceof CollectionType) {
            if (!is_array($value) || !$type->isList() || !array_is_list($value)) {
                $this->addViolation($violations, $path, 'Expected a list.');

                return [false, null];
            }

            $items = [];
            $valid = true;
            foreach ($value as $index => $item) {
                [$converted, $convertedItem] = $this->convert(
                    $type->getCollectionValueType(),
                    $item,
                    $coerce,
                    $this->path($path, (string) $index),
                    $violations,
                );
                $valid = $converted && $valid;
                $items[] = $convertedItem;
            }

            return [$valid, $items];
        }

        if ($type instanceof ObjectType) {
            if (!$value instanceof stdClass) {
                $this->addViolation($violations, $path, 'Expected an object.');

                return [false, null];
            }

            $class = $type->getClassName();
            $plan = $this->plans[$class] ?? null;
            // The bootstrap compiler always stores this nested plan; this is a corruption guard.
            // @codeCoverageIgnoreStart
            if ($plan === null) {
                throw RequestHydrationConfigurationException::forRequestClass($class);
            }
            // @codeCoverageIgnoreEnd

            return $this->hydratePlan(
                $plan,
                $this->sourceValues(get_object_vars($value), $coerce),
                $path,
                $violations,
            );
        }

        // @codeCoverageIgnoreStart — every TypeInfo type reaches a handled branch.
        $this->addViolation($violations, $path, 'Unsupported value type.');

        return [false, null];
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param BuiltinType<covariant TypeIdentifier> $type
     * @param array<string, non-empty-list<string>> $violations
     *
     * @return array{bool, mixed}
     */
    private function convertBuiltin(
        BuiltinType $type,
        mixed $value,
        bool $coerce,
        string $path,
        array &$violations,
    ): array {
        if ($type->accepts($value)) {
            return [true, $value];
        }

        $converted = null;
        $valid = false;

        if ($coerce && is_string($value)) {
            [$valid, $converted] = match ($type->getTypeIdentifier()) {
                TypeIdentifier::INT => $this->integer($value),
                TypeIdentifier::FLOAT => $this->float($value),
                TypeIdentifier::BOOL => $this->boolean($value),
                TypeIdentifier::STRING => [true, $value],
                default => [false, null],
            };
        }

        if (!$valid) {
            $this->addViolation($violations, $path, sprintf('Expected %s.', (string) $type));
        }

        return [$valid, $converted];
    }

    /**
     * @param BackedEnumType<class-string<BackedEnum>, BuiltinType<TypeIdentifier::INT>|BuiltinType<TypeIdentifier::STRING>> $type
     * @param array<string, non-empty-list<string>> $violations
     *
     * @return array{bool, mixed}
     */
    private function convertEnum(
        BackedEnumType $type,
        mixed $value,
        bool $coerce,
        string $path,
        array &$violations,
    ): array {
        [$valid, $backingValue] = $this->convertBuiltin(
            $type->getBackingType(),
            $value,
            $coerce,
            $path,
            $violations,
        );

        if (!$valid || (!is_int($backingValue) && !is_string($backingValue))) {
            return [false, null];
        }

        $enumClass = $type->getClassName();
        // @codeCoverageIgnoreStart — BackedEnumType guarantees a backed enum class.
        if (!is_subclass_of($enumClass, BackedEnum::class)) {
            throw RequestHydrationConfigurationException::forRequestClass(
                $enumClass,
                'the declared enum is not backed.',
            );
        }
        // @codeCoverageIgnoreEnd

        $case = $enumClass::tryFrom($backingValue);
        if ($case === null) {
            $this->addViolation($violations, $path, 'Unknown enum value.');

            return [false, null];
        }

        return [true, $case];
    }

    /** @return array{bool, int|null} */
    private function integer(string $value): array
    {
        if (!preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value)) {
            return [false, null];
        }

        $converted = filter_var($value, FILTER_VALIDATE_INT);

        return $converted === false ? [false, null] : [true, $converted];
    }

    /** @return array{bool, float|null} */
    private function float(string $value): array
    {
        $converted = filter_var($value, FILTER_VALIDATE_FLOAT);

        return $converted === false ? [false, null] : [true, $converted];
    }

    /** @return array{bool, bool|null} */
    private function boolean(string $value): array
    {
        return match ($value) {
            'true' => [true, true],
            'false' => [true, false],
            default => [false, null],
        };
    }

    /**
     * @param array<string, non-empty-list<string>> $violations
     */
    private function addViolation(array &$violations, string $path, string $message): void
    {
        if (!isset($violations[$path])) {
            $violations[$path] = [$message];

            return;
        }

        // @codeCoverageIgnoreStart — each input key is merged before validation.
        $violations[$path][] = $message;
        // @codeCoverageIgnoreEnd
    }

    private function path(string $prefix, string $name): string
    {
        return $prefix === '' ? $name : $prefix . '.' . $name;
    }

    /** @return array<array-key, mixed> */
    private function bodyData(string $body): array
    {
        if ($body === '') {
            return [];
        }

        try {
            $data = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw RequestValidationException::fromViolations([
                '$body' => ['Malformed JSON body.'],
            ]);
        }

        if (!$data instanceof stdClass) {
            throw RequestValidationException::fromViolations([
                '$body' => ['Expected a JSON object.'],
            ]);
        }

        return get_object_vars($data);
    }

    private function validateMediaType(ServerRequestInterface $request, string $body): void
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if ($contentType === '' && $body === '') {
            return;
        }

        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($mediaType === 'application/json' || $this->isStructuredJsonMediaType($mediaType)) {
            return;
        }

        throw UnsupportedMediaTypeException::forMediaType($mediaType);
    }

    private function isStructuredJsonMediaType(string $mediaType): bool
    {
        [$type, $subtype] = array_pad(explode('/', $mediaType, 2), 2, '');
        $suffix = substr($subtype, 0, -5);

        return $this->isMediaTypeToken($type)
            && $this->isMediaTypeToken($subtype)
            && $suffix !== ''
            && str_ends_with($subtype, '+json');
    }

    private function isMediaTypeToken(string $value): bool
    {
        $allowed = "!#$%&'*+-.^_`|~0123456789abcdefghijklmnopqrstuvwxyz";

        return $value !== '' && strspn($value, $allowed) === strlen($value);
    }
}
