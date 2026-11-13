<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Internal;

use PolygonLab\McpKit\Tool\ToolDefinition;

/**
 * A tool ready to be called: its definition, its invoker and its mirrored header parameters.
 *
 * @internal
 */
final readonly class RegisteredTool
{
    /**
     * @param \Closure(\stdClass): mixed                                         $invoker
     * @param list<array{header: string, path: list<string>, type: string}> $headerParameters
     */
    public function __construct(
        public ToolDefinition $definition,
        public \Closure $invoker,
        public array $headerParameters,
    ) {}
}
