<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Exception;

/**
 * Business failure raised by a tool ("division by zero", "unknown customer").
 *
 * The message is returned to the client in a tool result with `isError: true`, so that the
 * language model can read it and correct its call. Write it for the model: short and actionable,
 * without secrets or internal details. Any other exception becomes a generic JSON-RPC
 * "Internal error" and its message is never sent.
 */
final class ToolError extends \RuntimeException implements McpKitException {}
