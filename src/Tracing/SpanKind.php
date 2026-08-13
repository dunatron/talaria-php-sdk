<?php

declare(strict_types=1);

namespace Talaria\Tracing;

enum SpanKind: string
{
    case Internal = 'internal';
    case Server = 'server';
    case Client = 'client';
    case Producer = 'producer';
    case Consumer = 'consumer';

    public static function fromMixed(string|self $kind): self
    {
        if ($kind instanceof self) {
            return $kind;
        }

        return self::tryFrom(strtolower(trim($kind))) ?? self::Internal;
    }
}
