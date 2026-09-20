<?php

declare(strict_types=1);

namespace Talaria\Exception;

use Talaria\Transport\IngestError;

/**
 * Raised when a Talaria ingest HTTP call fails.
 */
final class TransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        ?\Throwable $previous = null,
        public readonly ?string $className = null,
        public readonly ?bool $retry = null,
        public readonly ?string $bodyMessage = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isPermanent(): bool
    {
        return (new IngestError(
            className: $this->className,
            message: $this->bodyMessage ?? $this->getMessage(),
            retry: $this->retry,
        ))->isPermanent();
    }

    public function isScopeOnly(): bool
    {
        return (new IngestError(
            className: $this->className,
            message: $this->bodyMessage ?? $this->getMessage(),
            retry: $this->retry,
        ))->isScopeOnly();
    }
}
