<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Attribute;

/**
 * Declares a public method as an MCP tool.
 *
 *     #[Tool(name: 'add', description: 'Add two numbers.')]
 *     public function add(float $a, float $b): array { ... }
 *
 * The input schema is generated from the parameter types (see Param to refine it), or taken
 * from $inputSchema when given.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Tool
{
    /**
     * @param array<mixed>|null $inputSchema explicit JSON Schema of the arguments (type "object")
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?string $title = null,
        public ?array $inputSchema = null,
    ) {}
}
