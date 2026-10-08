<?php

declare(strict_types=1);

namespace Bellman;

/**
 * Thrown for transport failures, non-2xx responses from bellmand and
 * responses that cannot be decoded.
 */
class BellmanException extends \RuntimeException
{
    /**
     * @param int|null $statusCode HTTP status from bellmand, null if the request never got a response
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public function isRateLimited(): bool
    {
        return $this->statusCode === 429;
    }

    public function isUnauthorized(): bool
    {
        return $this->statusCode === 401 || $this->statusCode === 403;
    }
}
