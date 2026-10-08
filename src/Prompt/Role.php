<?php

declare(strict_types=1);

namespace Bellman\Prompt;

enum Role: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case ToolCall = 'tool-call';
    case ToolResponse = 'tool-resp';
    case Thinking = 'thinking';
}
