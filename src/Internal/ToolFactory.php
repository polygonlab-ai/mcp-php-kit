<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Internal;

use PolygonLab\McpKit\Attribute\Param;
use PolygonLab\McpKit\Attribute\Tool;
use PolygonLab\McpKit\Exception\InvalidToolDefinition;
use PolygonLab\McpKit\Exception\ToolError;
use PolygonLab\McpKit\Tool\ToolDefinition;

/**
 * Builds tools from callables and from methods marked with #[Tool], by reflection.
 *
 * Parameter types give the input schema: int (integer), float (number), string, bool (boolean),
 * array, and backed enums (their values). A parameter with a default value, or nullable, is
 * optional. Arguments are passed to the PHP function by name.
 *
 * @internal
 */
final class ToolFactory
{
    /** @var array<string, string> PHP type => JSON Schema type */
    private const SCALARS = ['int' => 'integer', 'float' => 'number', 'string' => 'string', 'bool' => 'boolean', 'array' => 'array'];

    private function __construct() {}

    /**
     * @param array<mixed>|null $inputSchema explicit schema; generated from the parameters when null
     */
    public static function fromCallable(string $name, string $description, callable $handler, ?array $inputSchema = null, ?string $title = null): RegisteredTool
    {
        $closure = \Closure::fromCallable($handler);

        return self::build($name, $description, $title, $inputSchema, new \ReflectionFunction($closure), $closure);
    }

    /**
     * Every public method of $instance marked with #[Tool].
     *
     * @return list<RegisteredTool>
     */
    public static function fromObject(object $instance): array
    {
        $tools = [];
        foreach ((new \ReflectionObject($instance))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Tool::class) as $attribute) {
                $tool = $attribute->newInstance();
                $closure = $method->getClosure($method->isStatic() ? null : $instance);
                $tools[] = self::build($tool->name, $tool->description, $tool->title, $tool->inputSchema, $method, $closure);
            }
        }
        if ($tools === []) {
            throw new InvalidToolDefinition('No public method of ' . $instance::class . ' is marked with #[Tool].');
        }

        return $tools;
    }

    /**
     * @param array<mixed>|null $inputSchema
     */
    private static function build(string $name, string $description, ?string $title, ?array $inputSchema, \ReflectionFunctionAbstract $function, \Closure $closure): RegisteredTool
    {
        $plan = [];
        $properties = [];
        $required = [];
        foreach ($function->getParameters() as $parameter) {
            $where = 'parameter $' . $parameter->getName() . ' of the tool "' . $name . '"';
            if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
                throw new InvalidToolDefinition('The ' . $where . ' cannot be variadic or passed by reference.');
            }
            $spec = self::spec($parameter);
            $plan[] = $spec;
            if ($inputSchema === null) {
                $properties[$spec['name']] = self::schema($parameter, $spec, $where);
                if (!$spec['optional']) {
                    $required[] = $spec['name'];
                }
            }
        }

        if ($inputSchema === null) {
            $inputSchema = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $inputSchema['required'] = $required;
            }
            $inputSchema['additionalProperties'] = false;
        }

        $definition = new ToolDefinition($name, $description, $inputSchema, $title);
        $headers = HeaderParameters::collect($definition->inputSchema, $name);

        return new RegisteredTool($definition, self::invoker($closure, $plan), $headers);
    }

    /**
     * @return array{name: string, kind: string, enum: class-string<\BackedEnum>|null, optional: bool, hasDefault: bool}
     */
    private static function spec(\ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        $kind = 'raw';
        $enum = null;
        if ($type instanceof \ReflectionNamedType) {
            $typeName = $type->getName();
            if (isset(self::SCALARS[$typeName])) {
                $kind = $typeName;
            } elseif (enum_exists($typeName) && is_subclass_of($typeName, \BackedEnum::class)) {
                $kind = 'enum';
                $enum = $typeName;
            }
        }
        $hasDefault = $parameter->isDefaultValueAvailable();

        return [
            'name' => $parameter->getName(),
            'kind' => $kind,
            'enum' => $enum,
            'optional' => $hasDefault || ($type !== null && $type->allowsNull()),
            'hasDefault' => $hasDefault,
        ];
    }

    /**
     * @param array{name: string, kind: string, enum: class-string<\BackedEnum>|null, optional: bool, hasDefault: bool} $spec
     *
     * @return array<mixed>
     */
    private static function schema(\ReflectionParameter $parameter, array $spec, string $where): array
    {
        $attribute = null;
        foreach ($parameter->getAttributes(Param::class) as $found) {
            $attribute = $found->newInstance();
        }
        if ($attribute !== null && $attribute->schema !== null) {
            return $attribute->schema;
        }

        $nullable = $parameter->getType() !== null && $parameter->getType()->allowsNull();
        if ($spec['enum'] !== null) {
            $values = array_map(static fn(\BackedEnum $case): int|string => $case->value, $spec['enum']::cases());
            $backing = (new \ReflectionEnum($spec['enum']))->getBackingType();
            $jsonType = $backing instanceof \ReflectionNamedType && $backing->getName() === 'int' ? 'integer' : 'string';
            $schema = ['type' => $nullable ? [$jsonType, 'null'] : $jsonType, 'enum' => $nullable ? [...$values, null] : $values];
        } elseif ($spec['kind'] !== 'raw') {
            $jsonType = self::SCALARS[$spec['kind']];
            $schema = ['type' => $nullable ? [$jsonType, 'null'] : $jsonType];
        } else {
            throw new InvalidToolDefinition('Unsupported type for the ' . $where . ': use int, float, string, bool, array or a backed enum, or describe it with #[Param(schema: [...])].');
        }

        if ($attribute !== null) {
            $schema = array_merge($schema, $attribute->keywords());
        }
        if ($spec['hasDefault']) {
            $default = $parameter->getDefaultValue();
            $default = $default instanceof \BackedEnum ? $default->value : $default;
            if ($default === null || is_scalar($default) || is_array($default)) {
                $schema['default'] = $default;
            }
        }

        return $schema;
    }

    /**
     * @param list<array{name: string, kind: string, enum: class-string<\BackedEnum>|null, optional: bool, hasDefault: bool}> $plan
     *
     * @return \Closure(\stdClass): mixed
     */
    private static function invoker(\Closure $closure, array $plan): \Closure
    {
        return static function (\stdClass $arguments) use ($closure, $plan): mixed {
            $named = [];
            foreach ($plan as $spec) {
                $name = $spec['name'];
                if (!property_exists($arguments, $name)) {
                    if ($spec['hasDefault']) {
                        continue;
                    }
                    if (!$spec['optional']) {
                        throw new ToolError('Missing argument "' . $name . '".');
                    }
                    $named[$name] = null;
                    continue;
                }
                $named[$name] = self::convert($arguments->{$name}, $spec);
            }

            return $closure(...$named);
        };
    }

    /**
     * Converts a decoded JSON value to the PHP type of the parameter.
     *
     * @param array{name: string, kind: string, enum: class-string<\BackedEnum>|null, optional: bool, hasDefault: bool} $spec
     */
    private static function convert(mixed $value, array $spec): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($spec['enum'] !== null) {
            $case = is_int($value) || is_string($value) ? $spec['enum']::tryFrom($value) : null;
            if ($case === null) {
                throw new ToolError('Invalid value for "' . $spec['name'] . '".');
            }

            return $case;
        }
        if ($spec['kind'] === 'int' && is_float($value) && floor($value) === $value && abs($value) <= PHP_INT_MAX) {
            return (int) $value;
        }

        return self::toPhp($value);
    }

    /**
     * JSON objects become associative arrays, recursively.
     */
    public static function toPhp(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map(self::toPhp(...), $value);
        }

        return $value;
    }
}
