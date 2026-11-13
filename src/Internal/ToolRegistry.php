<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Internal;

use PolygonLab\McpKit\Exception\InvalidConfiguration;

/**
 * Immutable, ordered set of tools (registration order, which keeps `tools/list` deterministic).
 *
 * @internal
 */
final readonly class ToolRegistry
{
    /**
     * @param array<string, RegisteredTool> $tools
     */
    public function __construct(private array $tools = []) {}

    public function with(RegisteredTool $tool): self
    {
        $name = $tool->definition->name;
        if (isset($this->tools[$name])) {
            throw new InvalidConfiguration('A tool named "' . $name . '" is already registered.');
        }

        return new self([...$this->tools, $name => $tool]);
    }

    public function get(string $name): ?RegisteredTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<RegisteredTool>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    public function count(): int
    {
        return count($this->tools);
    }
}
