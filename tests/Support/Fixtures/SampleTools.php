<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tests\Support\Fixtures;

use PolygonLab\McpKit\Attribute\Param;
use PolygonLab\McpKit\Attribute\Tool;
use PolygonLab\McpKit\Exception\ToolError;
use PolygonLab\McpKit\Tool\ToolResult;

/**
 * Tools covering every supported parameter type.
 */
final class SampleTools
{
    /**
     * @param list<string> $tags
     *
     * @return array<string, mixed>
     */
    #[Tool(name: 'describe', description: 'Echo every argument.', title: 'Describe')]
    public function describe(
        #[Param(description: 'A name', minLength: 2, maxLength: 20, pattern: '^[a-z]+$')]
        string $name,
        int $count,
        float $ratio,
        bool $active,
        Unit $unit,
        #[Param(items: ['type' => 'string'])]
        array $tags = [],
        ?string $note = null,
        Priority $priority = Priority::Low,
    ): array {
        return [
            'name' => $name,
            'count' => $count,
            'ratio' => $ratio,
            'active' => $active,
            'unit' => $unit->value,
            'tags' => $tags,
            'note' => $note,
            'priority' => $priority->value,
        ];
    }

    #[Tool(name: 'fail', description: 'Always fails with a business error.')]
    public function fail(): string
    {
        throw new ToolError('Nothing to do here.');
    }

    #[Tool(name: 'crash', description: 'Always fails with an unexpected exception.')]
    public function crash(): string
    {
        throw new \RuntimeException('database password is hunter-two');
    }

    #[Tool(name: 'raw_result', description: 'Returns a ToolResult.')]
    public static function rawResult(): ToolResult
    {
        return ToolResult::text('first', 'second');
    }

    #[Tool(name: 'nothing', description: 'Returns null.')]
    public function nothing(): void {}

    /**
     * @return list<int>
     */
    #[Tool(name: 'numbers', description: 'Returns a list.')]
    public function numbers(): array
    {
        return [1, 2, 3];
    }

    #[Tool(name: 'region_query', description: 'Query with a mirrored header.')]
    public function regionQuery(
        #[Param(header: 'Region')]
        string $region,
        #[Param(header: 'Limit')]
        ?int $limit = null,
        #[Param(header: 'Dry-Run')]
        bool $dryRun = false,
    ): string {
        return $region . ':' . ($limit ?? 'none') . ':' . ($dryRun ? 'dry' : 'real');
    }

    public function notATool(): string
    {
        return 'hidden';
    }
}
