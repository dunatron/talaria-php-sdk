<?php

declare(strict_types=1);

namespace Talaria\Tracing;

/**
 * Head-based trace sampling with an error override.
 *
 * Successful transactions follow {@see $tracesSampleRate} (and an incoming
 * W3C sampled flag when present). Error transactions are always kept.
 */
final class Sampling
{
    public static function head(float $tracesSampleRate, ?bool $parentSampled): bool
    {
        if ($parentSampled === true) {
            return true;
        }
        if ($parentSampled === false) {
            return false;
        }
        if ($tracesSampleRate >= 1.0) {
            return true;
        }
        if ($tracesSampleRate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) <= $tracesSampleRate;
    }

    public static function shouldSend(bool $headSampled, bool $isError): bool
    {
        return $isError || $headSampled;
    }
}
