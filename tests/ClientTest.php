<?php

declare(strict_types=1);

namespace Bellman\Tests;

use Bellman\BellmanException;
use Bellman\Client;
use Bellman\Embed\Type;
use Bellman\Gen\Model as GenModel;
use Bellman\Models\Anthropic;
use Bellman\Models\Catalog;
use Bellman\Models\OpenAI;
use Bellman\Models\VertexAI;
use Bellman\Models\VoyageAI;
use Bellman\Prompt\Mime;
use Bellman\Prompt\Prompt;
use Bellman\Prompt\Role;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ClientTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    /** @param list<Response|\Throwable> $responses */
    private function client(array $responses): Client
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new Client('https://bellman.test/api/', 'my-app', 'secret', new GuzzleClient(['handler' => $stack]));
    }

    private static function json(mixed $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** @return array<string, mixed> */
    private function sentBody(int $i = 0): array
    {
        return json_decode((string) $this->history[$i]['request']->getBody(), true);
    }

    public function testGenTextRequestAndResponse(): void
    {
        $client = $this->client([self::json([
            'texts' => ['OpenAI'],
            'metadata' => ['model' => 'OpenAI/gpt-4o-mini', 'input_tokens' => 12, 'output_tokens' => 2, 'total_tokens' => 14],
        ])]);

        $res = $client->generator()
            ->model(OpenAI::GenModel_gpt4o_mini())
            ->system('Be brief.')
            ->temperature(0.0)
            ->maxTokens(100)
            ->stopAt('END')
            ->prompt('What company made you?');

        self::assertSame('OpenAI', $res->text());
        self::assertSame(14, $res->metadata->totalTokens);
        self::assertSame('OpenAI/gpt-4o-mini', $res->metadata->model);

        $req = $this->history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://bellman.test/api/gen', (string) $req->getUri());
        self::assertSame('Bearer my-app_secret', $req->getHeaderLine('Authorization'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));

        $body = $this->sentBody();
        self::assertFalse($body['stream']);
        self::assertSame('OpenAI', $body['model']['provider']);
        self::assertSame('gpt-4o-mini', $body['model']['name']);
        self::assertSame('Be brief.', $body['system_prompt']);
        self::assertEquals(0.0, $body['temperature']);
        self::assertSame(100, $body['max_tokens']);
        self::assertSame(['END'], $body['stop_sequences']);
        self::assertSame([['role' => 'user', 'text' => 'What company made you?']], $body['prompts']);
        self::assertArrayNotHasKey('top_p', $body);
        self::assertArrayNotHasKey('output_schema', $body);
    }

    public function testGeneratorIsImmutable(): void
    {
        $client = $this->client([self::json(['texts' => ['a']]), self::json(['texts' => ['b']])]);

        $base = $client->generator()->model(OpenAI::GenModel_gpt4o_mini());
        $base->temperature(1.0)->system('ignored');
        $base->prompt('hi');

        $body = $this->sentBody();
        self::assertArrayNotHasKey('temperature', $body);
        self::assertArrayNotHasKey('system_prompt', $body);
    }

    public function testStructuredOutput(): void
    {
        $client = $this->client([self::json(['texts' => ['{"name":"Ada","age":36}']])]);

        $schema = [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string'], 'age' => ['type' => 'integer']],
            'required' => ['name', 'age'],
        ];
        $res = $client->generator()
            ->model(Anthropic::genModels()[0])
            ->output($schema)
            ->strictOutput()
            ->prompt('Who wrote the first program?');

        self::assertSame(['name' => 'Ada', 'age' => 36], $res->json());
        self::assertSame($schema, $this->sentBody()['output_schema']);
        self::assertTrue($this->sentBody()['output_strict']);
    }

    public function testTurnRoundTripsReplayBytes(): void
    {
        $signature = random_bytes(32);
        $args = '{"city":"Stockholm"}';
        $client = $this->client([
            self::json([
                'texts' => ['42'],
                'thinking' => ['let me think'],
                'turn' => [
                    ['role' => 'thinking', 'thinking' => ['text' => 'let me think'], 'replay' => base64_encode($signature)],
                    ['role' => 'tool-call', 'tool_call' => ['id' => 'c1', 'name' => 'weather', 'arguments' => base64_encode($args)]],
                    ['role' => 'assistant', 'text' => '42'],
                ],
            ]),
            self::json(['texts' => ['ok']]),
        ]);

        $llm = $client->generator()->model(Anthropic::genModels()[0]);
        $first = $llm->prompt('What is the answer?');

        self::assertSame(['let me think'], $first->thinking);
        self::assertSame(Role::Thinking, $first->turn[0]->role);
        self::assertSame($signature, $first->turn[0]->replay);
        self::assertSame($args, $first->turn[1]->toolCall->arguments);

        $llm->prompt('What is the answer?', ...[...$first->turn, Prompt::asUser('Why?')]);

        $prompts = $this->sentBody(1)['prompts'];
        self::assertCount(5, $prompts);
        self::assertSame(base64_encode($signature), $prompts[1]['replay']);
        self::assertSame(['text' => 'let me think'], $prompts[1]['thinking']);
        self::assertSame(base64_encode($args), $prompts[2]['tool_call']['arguments']);
    }

    public function testBinaryPayloadIsBase64Encoded(): void
    {
        $client = $this->client([self::json(['texts' => ['a cat']])]);
        $png = "\x89PNG\r\n\x1a\n" . random_bytes(16);

        $client->generator()
            ->model(VertexAI::genModels()[0])
            ->prompt(Prompt::asUserWithData(Mime::IMAGE_PNG, $png), 'What is this?');

        $prompts = $this->sentBody()['prompts'];
        self::assertSame('image/png', $prompts[0]['payload']['mime_type']);
        self::assertSame($png, base64_decode($prompts[0]['payload']['data']));
        self::assertSame(['role' => 'user', 'text' => 'What is this?'], $prompts[1]);
    }

    public function testEmbed(): void
    {
        $client = $this->client([self::json([
            'embeddings' => [[0.1, 0, -0.3]],
            'metadata' => ['total_tokens' => 3],
        ])]);

        $res = $client->embed(VoyageAI::EmbedModel_voyage_3_5()->withType(Type::QUERY), 'hello');

        self::assertSame([0.1, 0.0, -0.3], $res->single());
        self::assertSame('https://bellman.test/api/embed', (string) $this->history[0]['request']->getUri());
        $body = $this->sentBody();
        self::assertSame(['hello'], $body['texts']);
        self::assertSame('query', $body['model']['type']);
        self::assertSame('voyage-3.5', $body['model']['name']);
    }

    public function testEmbedDocument(): void
    {
        $client = $this->client([self::json(['embeddings' => [[1.0], [2.0]]])]);

        $res = $client->embedDocument(VoyageAI::EmbedModel_voyage_context_4(), ['chunk one', 'chunk two']);

        self::assertSame([[1.0], [2.0]], $res->embeddings);
        self::assertSame('https://bellman.test/api/embed/document', (string) $this->history[0]['request']->getUri());
        self::assertSame(['chunk one', 'chunk two'], $this->sentBody()['document_chunks']);
    }

    public function testServerErrorBecomesException(): void
    {
        $client = $this->client([self::json(['error' => 'rate limit exceeded'], 429)]);

        try {
            $client->generator()->model(OpenAI::GenModel_gpt4o_mini())->prompt('hi');
            self::fail('expected exception');
        } catch (BellmanException $e) {
            self::assertSame(429, $e->statusCode);
            self::assertTrue($e->isRateLimited());
            self::assertStringContainsString('rate limit exceeded', $e->getMessage());
        }
    }

    public function testTransportErrorBecomesException(): void
    {
        $client = $this->client([new ConnectException('connection refused', new \GuzzleHttp\Psr7\Request('POST', '/'))]);

        $this->expectException(BellmanException::class);
        $this->expectExceptionMessage('could not reach bellman');
        $client->embed(VoyageAI::EmbedModel_voyage_3_5(), 'hi');
    }

    public function testFromKey(): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([self::json(['embeddings' => [[1]]])]));
        $stack->push(Middleware::history($this->history));

        $client = Client::fromKey('https://bellman.test', 'my-app_tok_with_underscore', new GuzzleClient(['handler' => $stack]));
        $client->embed(VoyageAI::EmbedModel_voyage_3_5(), 'hi');

        self::assertSame('Bearer my-app_tok_with_underscore', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testMissingModel(): void
    {
        $this->expectException(BellmanException::class);
        $this->client([])->generator()->prompt('hi');
    }

    public function testCatalog(): void
    {
        self::assertGreaterThan(50, count(Catalog::genModels()));
        self::assertEquals(OpenAI::GenModel_gpt4o_mini(), Catalog::gen('OpenAI/gpt-4o-mini'));
        self::assertNull(Catalog::gen('OpenAI/does-not-exist'));
        self::assertSame('VoyageAI/voyage-3.5', (string) Catalog::embed('VoyageAI/voyage-3.5'));
        self::assertEquals(new GenModel('xAI', 'grok-4'), GenModel::fromFqn('xAI/grok-4'));
    }
}
