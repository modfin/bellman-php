<?php

declare(strict_types=1);

namespace Bellman\Prompt;

use Bellman\BellmanException;

/**
 * One message in a conversation. Build them with the static constructors:
 *
 *     Prompt::asUser('What is in this picture?')
 *     Prompt::asUserWithData(Mime::IMAGE_PNG, file_get_contents('cat.png'))
 *     Prompt::asAssistant('A cat.')
 *
 * To continue a conversation, append Gen\Response::$turn to your prompts rather
 * than building assistant prompts yourself, it carries the provider signatures
 * needed to replay thinking.
 */
final class Prompt
{
    /**
     * @param string $replay opaque raw bytes from the provider that must be sent back verbatim, never construct it yourself
     */
    public function __construct(
        public readonly Role $role,
        public readonly string $text = '',
        public readonly ?Payload $payload = null,
        public readonly ?ToolCall $toolCall = null,
        public readonly ?ToolResponse $toolResponse = null,
        public readonly ?Thinking $thinking = null,
        public readonly string $replay = '',
    ) {
    }

    public static function asUser(string $text): self
    {
        return new self(Role::User, $text);
    }

    public static function asAssistant(string $text): self
    {
        return new self(Role::Assistant, $text);
    }

    /** Attaches binary data, e.g. an image or pdf, as raw bytes (not base64). */
    public static function asUserWithData(string $mime, string $data): self
    {
        return new self(Role::User, payload: new Payload($mime, base64_encode($data)));
    }

    /** Attaches a file by uri, e.g. a gs:// uri for VertexAI. */
    public static function asUserWithUri(string $mime, string $uri): self
    {
        return new self(Role::User, payload: new Payload($mime, uri: $uri));
    }

    /** @param string $arguments JSON encoded arguments */
    public static function asToolCall(string $toolCallId, string $name, string $arguments): self
    {
        return new self(Role::ToolCall, toolCall: new ToolCall($toolCallId, $name, $arguments));
    }

    public static function asToolResponse(string $toolCallId, string $name, string $response): self
    {
        return new self(Role::ToolResponse, toolResponse: new ToolResponse($toolCallId, $name, $response));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['role' => $this->role->value];
        if ($this->text !== '') {
            $data['text'] = $this->text;
        }
        if ($this->payload !== null) {
            $data['payload'] = [
                'mime_type' => $this->payload->mime,
                'data' => $this->payload->data,
                'uri' => $this->payload->uri,
            ];
        }
        if ($this->toolCall !== null) {
            $data['tool_call'] = array_filter([
                'id' => $this->toolCall->id,
                'name' => $this->toolCall->name,
            ], fn ($v) => $v !== '') + ['arguments' => base64_encode($this->toolCall->arguments)];
        }
        if ($this->toolResponse !== null) {
            $data['tool_response'] = array_filter([
                'id' => $this->toolResponse->id,
                'name' => $this->toolResponse->name,
            ], fn ($v) => $v !== '') + ['content' => $this->toolResponse->content];
        }
        if ($this->thinking !== null) {
            $data['thinking'] = array_filter([
                'text' => $this->thinking->text,
                'id' => $this->thinking->id,
                'redacted' => $this->thinking->redacted,
            ], fn ($v) => $v !== '' && $v !== false);
            if ($data['thinking'] === []) {
                // An empty PHP array would encode as [], Go expects an object.
                $data['thinking'] = new \stdClass();
            }
        }
        if ($this->replay !== '') {
            $data['replay'] = base64_encode($this->replay);
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $role = Role::tryFrom((string) ($data['role'] ?? ''));
        if ($role === null) {
            throw new BellmanException('unknown prompt role: ' . json_encode($data['role'] ?? null));
        }

        $payload = null;
        if (isset($data['payload'])) {
            $payload = new Payload(
                (string) ($data['payload']['mime_type'] ?? ''),
                (string) ($data['payload']['data'] ?? ''),
                (string) ($data['payload']['uri'] ?? ''),
            );
        }

        $toolCall = null;
        if (isset($data['tool_call'])) {
            $toolCall = new ToolCall(
                (string) ($data['tool_call']['id'] ?? ''),
                (string) ($data['tool_call']['name'] ?? ''),
                self::decodeBytes($data['tool_call']['arguments'] ?? null),
            );
        }

        $toolResponse = null;
        if (isset($data['tool_response'])) {
            $toolResponse = new ToolResponse(
                (string) ($data['tool_response']['id'] ?? ''),
                (string) ($data['tool_response']['name'] ?? ''),
                (string) ($data['tool_response']['content'] ?? ''),
            );
        }

        $thinking = null;
        if (isset($data['thinking'])) {
            $thinking = new Thinking(
                (string) ($data['thinking']['text'] ?? ''),
                (string) ($data['thinking']['id'] ?? ''),
                (bool) ($data['thinking']['redacted'] ?? false),
            );
        }

        return new self(
            $role,
            (string) ($data['text'] ?? ''),
            $payload,
            $toolCall,
            $toolResponse,
            $thinking,
            self::decodeBytes($data['replay'] ?? null),
        );
    }

    /** Go encodes []byte fields as base64 strings. */
    private static function decodeBytes(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $decoded = base64_decode((string) $value, true);
        if ($decoded === false) {
            throw new BellmanException('invalid base64 in bellman response');
        }
        return $decoded;
    }
}
