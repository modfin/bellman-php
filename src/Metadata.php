<?php

declare(strict_types=1);

namespace Bellman;

/**
 * Token usage reported for a request.
 *
 * The cache counts are subsets of $inputTokens. Zero can also mean the
 * provider did not report cache usage.
 */
final class Metadata
{
    /**
     * @param array<string, mixed> $other
     */
    public function __construct(
        public readonly string $model = '',
        public readonly int $inputTokens = 0,
        public readonly int $cacheReadInputTokens = 0,
        public readonly int $cacheCreationInputTokens = 0,
        public readonly int $thinkingTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $totalTokens = 0,
        public readonly array $other = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            model: (string) ($data['model'] ?? ''),
            inputTokens: (int) ($data['input_tokens'] ?? 0),
            cacheReadInputTokens: (int) ($data['cache_read_input_tokens'] ?? 0),
            cacheCreationInputTokens: (int) ($data['cache_creation_input_tokens'] ?? 0),
            thinkingTokens: (int) ($data['thinking_tokens'] ?? 0),
            outputTokens: (int) ($data['output_tokens'] ?? 0),
            totalTokens: (int) ($data['total_tokens'] ?? 0),
            other: (array) ($data['other'] ?? []),
        );
    }
}
