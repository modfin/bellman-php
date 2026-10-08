<?php

declare(strict_types=1);

namespace Bellman\Gen;

use Bellman\BellmanException;
use Bellman\Metadata;
use Bellman\Prompt\Prompt;

final class Response
{
    /**
     * @param list<string> $texts generated texts, usually exactly one
     * @param list<string> $thinking visible thinking text, if requested and supported by the model
     * @param list<Prompt> $turn the assistant side of this exchange in replay-ready form,
     *                           append it to your prompts to continue the conversation
     */
    public function __construct(
        public readonly array $texts,
        public readonly array $thinking,
        public readonly array $turn,
        public readonly Metadata $metadata,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map('strval', $data['texts'] ?? []),
            array_map('strval', $data['thinking'] ?? []),
            array_map(fn (array $p) => Prompt::fromArray($p), $data['turn'] ?? []),
            Metadata::fromArray($data['metadata'] ?? []),
        );
    }

    public function isText(): bool
    {
        return $this->texts !== [];
    }

    /** Returns the generated text. */
    public function text(): string
    {
        if (!$this->isText()) {
            throw new BellmanException('no text in response');
        }
        return $this->texts[0];
    }

    /**
     * Decodes structured output, see Generator::output().
     *
     * @param bool $associative return arrays instead of stdClass objects
     */
    public function json(bool $associative = true): mixed
    {
        try {
            return json_decode($this->text(), $associative, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BellmanException('response is not valid json: ' . $e->getMessage(), null, $e);
        }
    }
}
