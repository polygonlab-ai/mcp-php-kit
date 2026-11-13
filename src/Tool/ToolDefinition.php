<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tool;

use PolygonLab\McpKit\Exception\InvalidToolDefinition;
use PolygonLab\McpKit\Schema\JsonSchema;

/**
 * What the client sees of a tool in `tools/list`: name, description and input schema.
 */
final readonly class ToolDefinition
{
    /**
     * @param array<mixed> $inputSchema JSON Schema of the arguments, of type "object"
     *
     * @throws InvalidToolDefinition
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public ?string $title = null,
    ) {
        // 1 to 128 characters among A-Z a-z 0-9 _ - . : the name is also sent in the Mcp-Name header.
        if (preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/', $name) !== 1) {
            throw new InvalidToolDefinition('Invalid tool name "' . $name . '": use 1 to 128 characters among A-Z, a-z, 0-9, "_", "-" and ".".');
        }
        if (trim($description) === '') {
            throw new InvalidToolDefinition('The tool "' . $name . '" needs a description.');
        }
        JsonSchema::check($inputSchema);
        if (($inputSchema['type'] ?? null) !== 'object') {
            throw new InvalidToolDefinition('The input schema of the tool "' . $name . '" must have "type": "object".');
        }
    }

    /**
     * JSON form sent in `tools/list`.
     */
    public function toJson(): \stdClass
    {
        $json = new \stdClass();
        $json->name = $this->name;
        if ($this->title !== null) {
            $json->title = $this->title;
        }
        $json->description = $this->description;
        $json->inputSchema = JsonSchema::toJson($this->inputSchema);

        return $json;
    }
}
