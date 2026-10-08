<?php

declare(strict_types=1);

namespace Bellman\Prompt;

/** Content of a Prompt with Role::Thinking. */
final class Thinking
{
    public function __construct(
        public readonly string $text = '',
        public readonly string $id = '',
        public readonly bool $redacted = false,
    ) {
    }
}
