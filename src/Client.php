<?php

declare(strict_types=1);

namespace Bellman;

use Bellman\Embed\Model as EmbedModel;
use Bellman\Embed\Response as EmbedResponse;
use Bellman\Gen\Generator;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Client for a bellmand server.
 *
 *     $bellman = new Bellman\Client('https://bellman.example.com', 'my-app', $token);
 *     $res = $bellman->generator()
 *         ->model(Bellman\Models\OpenAI::GenModel_gpt4o_mini())
 *         ->prompt('What company made you?');
 *     echo $res->text();
 */
final class Client
{
    private readonly string $url;
    private readonly ClientInterface $http;

    /**
     * @param string $url base url of bellmand, including any api prefix
     * @param string $keyName name of the caller, shows up in bellmand logs and metrics
     * @param string $token api key configured in bellmand
     * @param ClientInterface|null $http any PSR-18 client, defaults to Guzzle
     * @param float $timeout request timeout in seconds, only used for the default Guzzle client
     */
    public function __construct(
        string $url,
        private readonly string $keyName,
        #[\SensitiveParameter] private readonly string $token,
        ?ClientInterface $http = null,
        float $timeout = 300.0,
    ) {
        $this->url = rtrim($url, '/');
        $this->http = $http ?? new GuzzleClient(['timeout' => $timeout]);
    }

    /**
     * Creates a client from a combined "{name}_{token}" key, the format bellmand
     * expects in the Authorization header.
     */
    public static function fromKey(string $url, #[\SensitiveParameter] string $key, ?ClientInterface $http = null): self
    {
        $parts = explode('_', $key, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException('expected key in the format {name}_{token}');
        }
        return new self($url, $parts[0], $parts[1], $http);
    }

    public function generator(): Generator
    {
        return new Generator($this);
    }

    /**
     * Embeds one or more texts. Use Embed\Response::single() when embedding a single text.
     *
     * @param string|list<string> $texts
     */
    public function embed(EmbedModel $model, string|array $texts): EmbedResponse
    {
        return EmbedResponse::fromArray($this->post('/embed', [
            'model' => $model->toArray(),
            'texts' => is_string($texts) ? [$texts] : array_values($texts),
        ]));
    }

    /**
     * Embeds the chunks of a document with a contextualized model (e.g.
     * VoyageAI::EmbedModel_voyage_context_4()), each chunk is embedded with
     * awareness of the rest of the document.
     *
     * @param list<string> $chunks
     */
    public function embedDocument(EmbedModel $model, array $chunks): EmbedResponse
    {
        return EmbedResponse::fromArray($this->post('/embed/document', [
            'model' => $model->toArray(),
            'document_chunks' => array_values($chunks),
        ]));
    }

    /**
     * Sends a JSON request to bellmand and returns the decoded JSON response.
     *
     * @internal
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        try {
            $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new BellmanException('could not encode bellman request: ' . $e->getMessage(), null, $e);
        }

        $request = new Request('POST', $this->url . $path, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->keyName . '_' . $this->token,
        ], $payload);

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new BellmanException('could not reach bellman at ' . $this->url . $path . ': ' . $e->getMessage(), null, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($raw, true);
            $message = is_array($decoded) && isset($decoded['error']) ? (string) $decoded['error'] : $raw;
            throw new BellmanException("bellman returned status $status: $message", $status);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BellmanException('could not decode bellman response: ' . $e->getMessage(), $status, $e);
        }
        if (!is_array($decoded)) {
            throw new BellmanException('unexpected bellman response: ' . $raw, $status);
        }
        return $decoded;
    }
}
