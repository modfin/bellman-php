<?php

declare(strict_types=1);

namespace Bellman\Gen;

use Bellman\BellmanException;
use Bellman\Client;
use Bellman\Prompt\Prompt;

/**
 * Immutable request builder, every setter returns a new Generator so a
 * configured instance can be shared and reused.
 *
 *     $llm = $bellman->generator()
 *         ->model(Models\OpenAI::GenModel_gpt4o_mini())
 *         ->system('Answer in one sentence.')
 *         ->temperature(0.2);
 *
 *     $res = $llm->prompt('Why is the sky blue?');
 */
final class Generator
{
    private ?Model $model = null;
    private string $system = '';
    /** @var array<string, mixed>|object|null */
    private array|object|null $outputSchema = null;
    private bool $strictOutput = false;
    private ?int $thinkingBudget = null;
    private ?bool $thinkingParts = null;
    private ?float $topP = null;
    private ?int $topK = null;
    private ?float $temperature = null;
    private ?int $maxTokens = null;
    private ?float $frequencyPenalty = null;
    private ?float $presencePenalty = null;
    /** @var list<string> */
    private array $stopSequences = [];

    public function __construct(private readonly Client $client)
    {
    }

    public function model(Model $model): self
    {
        $g = clone $this;
        $g->model = $model;
        return $g;
    }

    public function system(string $prompt): self
    {
        $g = clone $this;
        $g->system = $prompt;
        return $g;
    }

    /**
     * Requests structured output following the given JSON schema, read it back
     * with Response::json().
     *
     *     ->output([
     *         'type' => 'object',
     *         'properties' => [
     *             'name' => ['type' => 'string'],
     *             'age' => ['type' => 'integer'],
     *         ],
     *         'required' => ['name', 'age'],
     *     ])
     *
     * @param array<string, mixed>|object $schema
     */
    public function output(array|object $schema): self
    {
        $g = clone $this;
        $g->outputSchema = $schema;
        return $g;
    }

    public function strictOutput(bool $strict = true): self
    {
        $g = clone $this;
        $g->strictOutput = $strict;
        return $g;
    }

    public function stopAt(string ...$stop): self
    {
        $g = clone $this;
        $g->stopSequences = array_values($stop);
        return $g;
    }

    public function temperature(float $temperature): self
    {
        $g = clone $this;
        $g->temperature = $temperature;
        return $g;
    }

    public function frequencyPenalty(float $penalty): self
    {
        $g = clone $this;
        $g->frequencyPenalty = $penalty;
        return $g;
    }

    public function presencePenalty(float $penalty): self
    {
        $g = clone $this;
        $g->presencePenalty = $penalty;
        return $g;
    }

    public function topP(float $topP): self
    {
        $g = clone $this;
        $g->topP = $topP;
        return $g;
    }

    public function topK(int $topK): self
    {
        $g = clone $this;
        $g->topK = $topK;
        return $g;
    }

    public function maxTokens(int $maxTokens): self
    {
        $g = clone $this;
        $g->maxTokens = $maxTokens;
        return $g;
    }

    /** Token budget for reasoning on models that support it. */
    public function thinkingBudget(int $budget): self
    {
        $g = clone $this;
        $g->thinkingBudget = $budget;
        return $g;
    }

    /** Whether visible thinking should be returned, see Response::$thinking. */
    public function includeThinkingParts(bool $include = true): self
    {
        $g = clone $this;
        $g->thinkingParts = $include;
        return $g;
    }

    /**
     * Sends the conversation to the model. Strings are treated as user prompts.
     */
    public function prompt(Prompt|string ...$prompts): Response
    {
        return Response::fromArray($this->client->post('/gen', $this->toArray(...$prompts)));
    }

    /**
     * The request body sent to bellmand.
     *
     * @return array<string, mixed>
     */
    public function toArray(Prompt|string ...$prompts): array
    {
        if ($this->model === null) {
            throw new BellmanException('no model set, call model() before prompt()');
        }
        if ($prompts === []) {
            throw new BellmanException('at least one prompt is required');
        }

        $body = [
            'stream' => false,
            'model' => $this->model->toArray(),
        ];
        if ($this->system !== '') {
            $body['system_prompt'] = $this->system;
        }
        if ($this->outputSchema !== null) {
            $body['output_schema'] = $this->outputSchema;
        }
        if ($this->strictOutput) {
            $body['output_strict'] = true;
        }
        $optional = [
            'thinking_budget' => $this->thinkingBudget,
            'thinking_parts' => $this->thinkingParts,
            'top_p' => $this->topP,
            'top_k' => $this->topK,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'frequency_penalty' => $this->frequencyPenalty,
            'presence_penalty' => $this->presencePenalty,
        ];
        foreach ($optional as $key => $value) {
            if ($value !== null) {
                $body[$key] = $value;
            }
        }
        if ($this->stopSequences !== []) {
            $body['stop_sequences'] = $this->stopSequences;
        }
        $body['prompts'] = array_map(
            fn (Prompt|string $p) => (is_string($p) ? Prompt::asUser($p) : $p)->toArray(),
            array_values($prompts),
        );
        return $body;
    }
}
