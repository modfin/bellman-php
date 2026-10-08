<?php

declare(strict_types=1);

namespace Bellman\Embed;

use Bellman\BellmanException;
use Bellman\Metadata;

final class Response
{
    /**
     * @param list<list<float>> $embeddings one vector per input text, in input order
     */
    public function __construct(
        public readonly array $embeddings,
        public readonly Metadata $metadata,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $embeddings = [];
        foreach ($data['embeddings'] ?? [] as $vector) {
            $embeddings[] = array_map('floatval', $vector);
        }
        return new self($embeddings, Metadata::fromArray($data['metadata'] ?? []));
    }

    /**
     * Returns the only embedding, for requests with a single text.
     *
     * @return list<float>
     */
    public function single(): array
    {
        if (count($this->embeddings) !== 1) {
            throw new BellmanException('response contains ' . count($this->embeddings) . ' embeddings, expected a single one');
        }
        return $this->embeddings[0];
    }
}
