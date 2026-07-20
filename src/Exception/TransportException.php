<?php

declare(strict_types=1);

namespace Talaria\Exception;

/**
 * Raised when a Talaria ingest HTTP call fails.
 */
final class TransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
