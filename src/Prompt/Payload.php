<?php

declare(strict_types=1);

namespace Bellman\Prompt;

final class Payload
{
    /**
     * @param string $data base64 encoded content, empty when $uri is set
     */
    public function __construct(
        public readonly string $mime,
        public readonly string $data = '',
        public readonly string $uri = '',
    ) {
    }
}
