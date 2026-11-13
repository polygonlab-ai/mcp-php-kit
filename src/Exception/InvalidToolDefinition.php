<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Exception;

/**
 * A tool cannot be registered: invalid name, unsupported parameter type or invalid input schema.
 * Thrown while the server is being built, never while a request is handled.
 */
final class InvalidToolDefinition extends \LogicException implements McpKitException {}
