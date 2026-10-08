<?php

declare(strict_types=1);

namespace Bellman\Embed;

/**
 * An embedding model. Known models are available in Bellman\Models\*, e.g.
 * Bellman\Models\VoyageAI::EmbedModel_voyage_3_5().
 */
final class Model implements \Stringable
{
    /**
     * @param string $type see Type, or a provider specific type such as Bellman\Models\VertexAI::TypeClustering
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $name,
        public readonly string $type = Type::NONE,
        public readonly string $description = '',
        public readonly int $inputMaxTokens = 0,
        public readonly int $outputDimensions = 0,
        public readonly array $config = [],
    ) {
    }

    /** Parses a fully qualified name such as "VoyageAI/voyage-3.5". */
    public static function fromFqn(string $fqn): self
    {
        $parts = explode('/', $fqn, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("invalid fqn '$fqn', did not find a '/' separating provider and model");
        }
        return new self($parts[0], $parts[1]);
    }

    /** Returns a copy embedding as the given type, e.g. Type::QUERY or Type::DOCUMENT. */
    public function withType(string $type): self
    {
        return new self(
            $this->provider,
            $this->name,
            $type,
            $this->description,
            $this->inputMaxTokens,
            $this->outputDimensions,
            $this->config,
        );
    }

    public function fqn(): string
    {
        return $this->provider . '/' . $this->name;
    }

    public function __toString(): string
    {
        return $this->fqn();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'provider' => $this->provider,
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
            'input_max_tokens' => $this->inputMaxTokens,
            'output_dimensions' => $this->outputDimensions,
            'config' => $this->config,
        ], fn ($v, $k) => $k === 'provider' || $k === 'name' || ($v !== [] && $v !== '' && $v !== 0), ARRAY_FILTER_USE_BOTH);
    }
}
