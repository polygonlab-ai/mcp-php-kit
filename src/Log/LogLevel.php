<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Log;

/**
 * Log levels used by the kit; the values are the PSR-3 level names.
 */
final class LogLevel
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private function __construct() {}
}
