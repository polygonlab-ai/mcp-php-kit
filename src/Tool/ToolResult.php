<?php

declare(strict_types=1);

namespace PolygonLab\McpKit\Tool;

/**
 * Result of a tool call: text content, optional structured content, and the error flag.
 *
 * A tool may also return a plain value: a string becomes text content, any other value
 * (array, number, boolean, JsonSerializable) becomes structured content, also serialised as
 * JSON text for clients that only read the text.
 */
final readonly class ToolResult
{
    /**
     * @param list<string> $texts
     */
    private function __construct(
        public array $texts,
        public bool $hasStructuredContent,
        public mixed $structuredContent,
        public bool $isError,
    ) {}

    public static function text(string ...$texts): self
    {
        return new self(array_values($texts), false, null, false);
    }

    /**
     * Structured content (any JSON value), with its JSON serialisation as text content.
     *
     * @throws \JsonException when the value cannot be encoded as JSON
     */
    public static function structured(mixed $data): self
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self([$json], true, $data, false);
    }

    /**
     * Business failure reported to the language model (`isError: true`).
     */
    public static function error(string $message): self
    {
        return new self([$message], false, null, true);
    }

    /**
     * JSON form of the result.
     *
     * @param bool $objectStructuredContentOnly legacy revisions only accept an object as structured content
     */
    public function toJson(bool $objectStructuredContentOnly): \stdClass
    {
        $json = new \stdClass();
        $json->content = array_map(static fn(string $text): array => ['type' => 'text', 'text' => $text], $this->texts);
        if ($this->hasStructuredContent) {
            $isObject = $this->structuredContent instanceof \stdClass
                || $this->structuredContent instanceof \JsonSerializable
                || (is_array($this->structuredContent) && ($this->structuredContent === [] || !array_is_list($this->structuredContent)));
            if (!$objectStructuredContentOnly || $isObject) {
                $json->structuredContent = $this->structuredContent === [] ? new \stdClass() : $this->structuredContent;
            }
        }
        $json->isError = $this->isError;

        return $json;
    }
}
