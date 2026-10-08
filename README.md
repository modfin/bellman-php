# bellman-php

PHP client for [bellmand](https://github.com/modfin/bellman), the Bellman LLM proxy.
One URL and one API key give you OpenAI, Anthropic, Gemini (VertexAI), VoyageAI,
Ollama, vLLM and xAI behind the same interface.

Requires PHP 8.1+.

## Install

```bash
composer require modfin/bellman
```

If the package is not on Packagist (or you want to pin to the GitHub repo directly),
add the repository to your `composer.json` first:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/modfin/bellman-php" }
    ],
    "require": {
        "modfin/bellman": "^0.1"
    }
}
```

## Usage

```php
use Bellman\Client;
use Bellman\Models\Anthropic;
use Bellman\Models\OpenAI;

$bellman = new Client(getenv('BELLMAN_URL'), 'my-app', getenv('BELLMAN_TOKEN'));
// or, with a combined key: Client::fromKey(getenv('BELLMAN_URL'), getenv('BELLMAN_KEY'))

$res = $bellman->generator()
    ->model(OpenAI::GenModel_gpt4o_mini())
    ->prompt('What company made you?');

echo $res->text(); // OpenAI
```

The key name (`my-app`) identifies the caller in bellmand's logs and metrics, the
token is the API key configured in bellmand.

### Configuring a generator

Generators are immutable, every setter returns a new instance, so configure once and reuse.

```php
$llm = $bellman->generator()
    ->model(Anthropic::GenModel_5_5_sonnet_latest())
    ->system('You are a helpful assistant. Answer briefly.')
    ->temperature(0.2)
    ->maxTokens(1000);

$a = $llm->prompt('Why is the sky blue?');
$b = $llm->temperature(1.0)->prompt('Write a haiku about Stockholm.'); // $llm is unchanged
```

Also available: `topP()`, `topK()`, `frequencyPenalty()`, `presencePenalty()`,
`stopAt()`, `thinkingBudget()` and `includeThinkingParts()`.

### Structured output

```php
$res = $llm
    ->output([
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'founded' => ['type' => 'integer'],
        ],
        'required' => ['name', 'founded'],
    ])
    ->prompt('Which company makes the Monitor IR platform?');

$data = $res->json(); // ['name' => '...', 'founded' => ...]
```

### Images, PDFs and other files

```php
use Bellman\Prompt\Mime;
use Bellman\Prompt\Prompt;

$res = $llm->prompt(
    Prompt::asUserWithData(Mime::APPLICATION_PDF, file_get_contents('report.pdf')),
    'Summarize this report in three bullet points.',
);
```

Pass raw bytes, the client takes care of base64 encoding. Plain strings passed to
`prompt()` are user messages.

### Conversations

Append `$res->turn` to the prompts to continue a conversation. It holds the
assistant's reply in replay-ready form, including thinking signatures that some
providers require you to send back.

```php
$history = [Prompt::asUser('Pick a random Nordic capital.')];
$res = $llm->prompt(...$history);

$history = [...$history, ...$res->turn, Prompt::asUser('What is its population?')];
$res = $llm->prompt(...$history);
```

### Embeddings

```php
use Bellman\Embed\Type;
use Bellman\Models\VoyageAI;

$model = VoyageAI::EmbedModel_voyage_3_5();

$vector = $bellman->embed($model->withType(Type::QUERY), 'search query')->single();
$vectors = $bellman->embed($model->withType(Type::DOCUMENT), ['first text', 'second text'])->embeddings;

// Contextualized chunk embeddings
$chunks = $bellman->embedDocument(VoyageAI::EmbedModel_voyage_context_4(), $documentChunks)->embeddings;
```

### Models

All models known to the Go library are available as static constructors on
`Bellman\Models\Anthropic`, `OpenAI`, `VertexAI`, `VoyageAI`, `Ollama`, `VLLM`,
`XAI` and `OMLX`, with the same names as in Go:

```php
OpenAI::GenModel_gpt4o_mini();
VertexAI::EmbedModel_text_005();
OpenAI::genModels();              // all OpenAI generative models
Catalog::gen('OpenAI/gpt-4o');    // look up by "provider/name", null if unknown
```

Any model bellmand can route to works, including ones not in the catalog:

```php
use Bellman\Gen\Model;

new Model('VertexAI', 'gemini-2.5-flash', config: ['region' => 'us-central1']);
Model::fromFqn('OpenAI/some-new-model');
```

### Usage and errors

```php
$res->metadata->inputTokens;
$res->metadata->outputTokens;
$res->metadata->totalTokens;
```

Every failure throws `Bellman\BellmanException`. `$e->statusCode` holds the HTTP
status from bellmand (`null` if it was never reached), and `$e->isRateLimited()`
and `$e->isUnauthorized()` cover the common cases.

### HTTP client

Guzzle is used by default with a 300 second timeout. Any PSR-18 client can be passed instead:

```php
$bellman = new Client($url, 'my-app', $token, http: $myPsr18Client);
```

### Laravel

```php
// config/services.php
'bellman' => [
    'url' => env('BELLMAN_URL'),
    'name' => env('BELLMAN_KEY_NAME', 'my-app'),
    'token' => env('BELLMAN_TOKEN'),
],

// AppServiceProvider::register()
$this->app->singleton(\Bellman\Client::class, fn () => new \Bellman\Client(
    config('services.bellman.url'),
    config('services.bellman.name'),
    config('services.bellman.token'),
));
```

Then inject `Bellman\Client` wherever you need it.

## Development

```bash
composer install
composer test
```

`src/Models` is generated from the Go library's model definitions. To update it after
models change in bellman, run this from the repo root, with a bellman checkout next to it:

```bash
go run modelgen/main.go -bellman ../bellman -out src/Models
```

Not yet supported: streaming, and tools / function calling.
