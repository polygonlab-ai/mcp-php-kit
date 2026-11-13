<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Attribute;

/**
 * Refines the JSON Schema generated for a tool parameter.
 *
 *     #[Param(description: 'Number of decimals', minimum: 0, maximum: 10)] int $precision = 2
 *
 * $schema replaces the generated schema entirely (needed for `mixed`, union or object types);
 * the other options are merged into the generated schema.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Param
{
    /**
     * @param list<string|int>|null $enum   allowed values
     * @param array<mixed>|null     $items  schema of the items of an array parameter
     * @param array<mixed>|null     $schema complete schema of the parameter
     * @param string|null           $header name mirrored in the `Mcp-Param-{header}` HTTP header (x-mcp-header)
     */
    public function __construct(
        public ?string $description = null,
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public ?array $enum = null,
        public ?array $items = null,
        public ?array $schema = null,
        public ?string $header = null,
    ) {}

    /**
     * Options to merge into the generated schema.
     *
     * @return array<string, mixed>
     */
    public function keywords(): array
    {
        return array_filter([
            'description' => $this->description,
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'pattern' => $this->pattern,
            'enum' => $this->enum,
            'items' => $this->items,
            'x-mcp-header' => $this->header,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
