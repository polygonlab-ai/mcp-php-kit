<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Examples\Attributes;

use PolygonLab\McpKit\Attribute\Param;
use PolygonLab\McpKit\Attribute\Tool;
use PolygonLab\McpKit\Exception\ToolError;

/**
 * Tools declared with attributes: the input schema is generated from the parameters.
 */
final class CalculatorTools
{
    /**
     * @return array{quotient: float}
     */
    #[Tool(name: 'divide', description: 'Divide a number by another one.')]
    public function divide(
        #[Param(description: 'Number to divide')]
        float $dividend,
        #[Param(description: 'Number to divide by, not zero')]
        float $divisor,
    ): array {
        if ($divisor === 0.0) {
            // Business failure: returned to the model with isError: true.
            throw new ToolError('Cannot divide by zero: choose another divisor.');
        }

        return ['quotient' => $dividend / $divisor];
    }

    #[Tool(name: 'round_number', description: 'Round a number to a given number of decimals.')]
    public function roundNumber(
        float $value,
        #[Param(description: 'Number of decimals', minimum: 0, maximum: 10)]
        int $decimals = 2,
    ): string {
        return number_format(round($value, $decimals), $decimals, '.', '');
    }
}
