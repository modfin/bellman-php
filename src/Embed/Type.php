<?php

declare(strict_types=1);

namespace Bellman\Embed;

/**
 * Generic embedding types, mapped by bellmand to each provider's equivalent.
 * Providers may define more specific ones, see the Type* constants in Bellman\Models\*.
 */
final class Type
{
    public const QUERY = 'query';
    public const DOCUMENT = 'document';
    public const NONE = '';
}
