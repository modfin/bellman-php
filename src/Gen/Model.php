<?php

declare(strict_types=1);

namespace Bellman\Gen;

/**
 * A generative model. Known models are available in Bellman\Models\*, e.g.
 * Bellman\Models\Anthropic::GenModel_..., but any model that bellmand can route
 * to works:
 *
 *     new Model('VertexAI', 'gemini-2.5-flash', config: ['region' => 'us-central1'])
 */
final class Model implements \Stringable
{
    /**
     * @param array<string, mixed> $config provider specific settings, e.g. ['region' => 'global'] for VertexAI
     * @param list<string> $inputContentTypes
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $name,
        public readonly array $config = [],
        public readonly string $description = '',
        public readonly array $inputContentTypes = [],
        public readonly int $inputMaxToken = 0,
        public readonly int $outputMaxToken = 0,
        public readonly bool $supportTools = false,
        public readonly bool $supportStructuredOutput = false,
        public readonly bool $usesAdaptiveThinking = false,
    ) {
    }

    /** Parses a fully qualified name such as "OpenAI/gpt-4o". */
    public static function fromFqn(string $fqn): self
    {
        $parts = explode('/', $fqn, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("invalid fqn '$fqn', did not find a '/' separating provider and model");
        }
        return new self($parts[0], $parts[1]);
    }

    /** @param array<string, mixed> $config */
    public function withConfig(array $config): self
    {
        return new self(
            $this->provider,
            $this->name,
            $config,
            $this->description,
            $this->inputContentTypes,
            $this->inputMaxToken,
            $this->outputMaxToken,
            $this->supportTools,
            $this->supportStructuredOutput,
            $this->usesAdaptiveThinking,
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
            'config' => $this->config,
            'description' => $this->description,
            'input_content_types' => $this->inputContentTypes,
            'input_max_token' => $this->inputMaxToken,
            'output_max_token' => $this->outputMaxToken,
            'support_tools' => $this->supportTools,
            'support_structured_output' => $this->supportStructuredOutput,
            'uses_adaptive_thinking' => $this->usesAdaptiveThinking,
        ], fn ($v, $k) => $k === 'provider' || $k === 'name' || ($v !== [] && $v !== '' && $v !== 0 && $v !== false), ARRAY_FILTER_USE_BOTH);
    }
}
