<?php

declare(strict_types=1);

namespace Bellman\Prompt;

final class ToolResponse
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $content,
    ) {
    }
}
