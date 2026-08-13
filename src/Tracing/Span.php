<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Talaria\Context\RuntimeContext;

/**
 * In-memory span ready to serialize as IngestSpanInput.
 */
final class Span
{
    private bool $ended = false;
    private bool $recording;
    private string $status;
    private ?string $statusMessage = null;
    private ?string $endTime = null;

    /** @var array<string, string> */
    private array $attributes;

    /** @var array<string, string> */
    private array $resource;

    /** @var (callable(Span): void)|null */
    private $onEnd;

    /**
     * @param array<string, string> $attributes
     * @param array<string, string> $resource
     * @param (callable(Span): void)|null $onEnd
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly string $name,
        public readonly string $kind,
        public readonly string $startTime,
        array $attributes = [],
        array $resource = [],
        bool $recording = true,
        ?callable $onEnd = null,
        public readonly ?string $environment = null,
        public readonly ?string $release = null,
        public readonly ?string $userId = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $requestId = null,
    ) {
        $this->attributes = $attributes;
        $this->resource = $resource;
        $this->recording = $recording;
        $this->onEnd = $onEnd;
        $this->status = SpanStatus::Unset->value;
    }

    public static function noop(): self
    {
        return new self(
            traceId: str_repeat('0', 32),
            spanId: str_repeat('0', 16),
            parentSpanId: null,
            name: 'noop',
            kind: SpanKind::Internal->value,
            startTime: RuntimeContext::isoTimestamp(),
            recording: false,
        );
    }

    public function isRecording(): bool
    {
        return $this->recording && !$this->ended;
    }

    public function hasEnded(): bool
    {
        return $this->ended;
    }

    public function setAttribute(string $key, string $value): self
    {
        if ($this->recording && $key !== '') {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    public function setStatus(string|SpanStatus $status, ?string $message = null): self
    {
        if ($this->recording) {
            $this->status = SpanStatus::fromMixed($status)->value;
            $this->statusMessage = $message !== null && $message !== '' ? $message : null;
        }

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function end(?string $endTime = null): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->endTime = $endTime ?? RuntimeContext::isoTimestamp();

        if ($this->onEnd !== null && $this->recording) {
            $callback = $this->onEnd;
            $this->onEnd = null;
            $callback($this);
        }
    }

    /**
     * Mark ended without notifying the tracer (used when the root closes open children).
     */
    public function forceEnd(?string $endTime = null): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->endTime = $endTime ?? RuntimeContext::isoTimestamp();
        $this->onEnd = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $wire = [
            '__className__' => 'IngestSpanInput',
            'traceId' => $this->traceId,
            'spanId' => $this->spanId,
            'name' => $this->name,
            'kind' => $this->kind,
            'startTime' => $this->startTime,
            'endTime' => $this->endTime ?? RuntimeContext::isoTimestamp(),
            'status' => $this->status,
        ];

        if ($this->parentSpanId !== null && $this->parentSpanId !== '') {
            $wire['parentSpanId'] = $this->parentSpanId;
        }
        if ($this->statusMessage !== null && $this->statusMessage !== '') {
            $wire['statusMessage'] = $this->statusMessage;
        }
        if ($this->attributes !== []) {
            $wire['attributes'] = $this->attributes;
        }
        if ($this->resource !== []) {
            $wire['resource'] = $this->resource;
        }
        if ($this->environment !== null && $this->environment !== '') {
            $wire['environment'] = $this->environment;
        }
        if ($this->release !== null && $this->release !== '') {
            $wire['release'] = $this->release;
        }
        if ($this->userId !== null && $this->userId !== '') {
            $wire['userId'] = $this->userId;
        }
        if ($this->sessionId !== null && $this->sessionId !== '') {
            $wire['sessionId'] = $this->sessionId;
        }
        if ($this->requestId !== null && $this->requestId !== '') {
            $wire['requestId'] = $this->requestId;
        }

        return $wire;
    }
}
