<?php

declare(strict_types=1);

namespace Talaria\Tracing;

enum SpanStatus: string
{
    case Unset = 'unset';
    case Ok = 'ok';
    case Error = 'error';

    public static function fromMixed(string|self $status): self
    {
        if ($status instanceof self) {
            return $status;
        }

        return self::tryFrom(strtolower(trim($status))) ?? self::Unset;
    }
}
