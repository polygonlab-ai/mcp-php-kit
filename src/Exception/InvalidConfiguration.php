<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Exception;

/**
 * The server is configured incorrectly (duplicate tool, empty token list, invalid option).
 * Thrown while the server is being built, never while a request is handled.
 */
final class InvalidConfiguration extends \LogicException implements McpKitException {}
