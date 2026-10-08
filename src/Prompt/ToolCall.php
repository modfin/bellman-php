<?php

declare(strict_types=1);

namespace Bellman\Prompt;

final class ToolCall
{
    /**
     * @param string $arguments JSON encoded arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $arguments,
    ) {
    }
}
