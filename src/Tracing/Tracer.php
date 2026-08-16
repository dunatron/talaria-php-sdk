<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Talaria\Config;
use Talaria\Context\RuntimeContext;

/**
 * Process-local tracer: one transaction (root span) plus child spans.
 *
 * Spans are recorded while tracing is enabled, then dropped or flushed as a
 * batch when the root ends. Dropped (unsampled, non-error) spans are not sent.
 */
final class Tracer
{
    public const MAX_CHILD_SPANS = 200;

    private ?Span $root = null;

    /** @var list<Span> */
    private array $stack = [];

    /** @var list<Span> */
    private array $finished = [];

    private bool $headSampled = false;

    private bool $sawError = false;

    /** Keep the transaction even when head sampling would drop it (errors, attached events). */
    private bool $forceSend = false;

    private int $childCount = 0;

    /** @var array<string, string> */
    private array $requestAttributes = [];

    public function __construct(
        private readonly Config $config,
        private readonly SpanQueue $queue,
        private readonly string $sessionId = '',
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->enableTracing;
    }

    /**
     * Head-sampling decision for the active transaction (error override is separate).
     */
    public function isSampled(): bool
    {
        return $this->forceSend || $this->sawError || $this->headSampled;
    }

    public function currentSpan(): ?Span
    {
        if ($this->stack === []) {
            return null;
        }

        return $this->stack[array_key_last($this->stack)];
    }

    public function rootSpan(): ?Span
    {
        return $this->root;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function setRequestAttributes(array $attributes): void
    {
        $this->requestAttributes = array_merge($this->requestAttributes, $attributes);
    }

    /**
     * @return array<string, string>
     */
    public function requestAttributes(): array
    {
        return $this->requestAttributes;
    }

    public function markError(?string $message = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $this->sawError = true;
        $this->forceSend = true;
        $current = $this->currentSpan();
        $current?->setStatus(SpanStatus::Error, $message);
        if ($this->root !== null && $this->root !== $current) {
            $this->root->setStatus(SpanStatus::Error, $message);
        }
    }

    /**
     * Keep this transaction so a stamped event's "View trace" link resolves.
     */
    public function retain(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $this->forceSend = true;
    }

    /**
     * Start a root SERVER span, or a child if a transaction is already open.
     *
     * @param array<string, string> $attributes
     */
    public function startTransaction(
        string $name,
        string|SpanKind $kind = SpanKind::Server,
        array $attributes = [],
    ): Span {
        if ($this->root !== null && $this->root->hasEnded()) {
            $this->reset();
        }

        return $this->startSpan($name, $kind, $attributes);
    }

    /**
     * @param array<string, string> $attributes
     */
    public function startSpan(
        string $name,
        string|SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
    ): Span {
        if (!$this->isEnabled()) {
            return Span::noop();
        }

        $kindValue = SpanKind::fromMixed($kind)->value;
        $isRoot = $this->root === null;
        $incoming = $isRoot ? TraceContext::fromServer() : null;

        if ($isRoot) {
            $traceId = $incoming?->traceId ?? TraceContext::generateTraceId();
            $parentSpanId = $incoming?->spanId;
            $this->headSampled = Sampling::head($this->config->tracesSampleRate, $incoming?->sampled);
        } else {
            if ($this->childCount >= self::MAX_CHILD_SPANS) {
                return Span::noop();
            }
            $this->childCount++;
            $parent = $this->currentSpan() ?? $this->root;
            $traceId = $parent?->traceId ?? TraceContext::generateTraceId();
            $parentSpanId = $parent?->spanId;
        }

        $span = new Span(
            traceId: $traceId,
            spanId: TraceContext::generateSpanId(),
            parentSpanId: $parentSpanId,
            name: $name,
            kind: $kindValue,
            startTime: RuntimeContext::isoTimestamp(),
            attributes: $attributes,
            resource: $this->resource(),
            recording: true,
            onEnd: $this->onSpanEnded(...),
            environment: $this->config->environment,
            release: $this->config->release,
            userId: $this->config->userId,
            sessionId: $this->sessionId !== '' ? $this->sessionId : null,
            requestId: $incoming?->traceId,
        );

        $this->stack[] = $span;
        if ($isRoot) {
            $this->root = $span;
        }

        return $span;
    }

    public function reset(): void
    {
        $this->root = null;
        $this->stack = [];
        $this->finished = [];
        $this->headSampled = false;
        $this->sawError = false;
        $this->forceSend = false;
        $this->childCount = 0;
        $this->requestAttributes = [];
    }

    private function onSpanEnded(Span $span): void
    {
        $this->pop($span);
        $this->finished[] = $span;

        if ($this->root !== $span) {
            return;
        }

        $endTime = RuntimeContext::isoTimestamp();
        foreach ($this->stack as $open) {
            if (!$open->hasEnded()) {
                $open->forceEnd($endTime);
                $this->finished[] = $open;
            }
        }
        $this->stack = [];
        $this->commitTransaction();
    }

    private function commitTransaction(): void
    {
        $isError = $this->sawError || ($this->root !== null && $this->root->getStatus() === SpanStatus::Error->value);
        if (Sampling::shouldSend($this->headSampled, $isError || $this->forceSend)) {
            $toSend = [];
            if ($this->root !== null) {
                $toSend[] = $this->root;
            }
            foreach ($this->finished as $span) {
                if ($span !== $this->root) {
                    $toSend[] = $span;
                }
            }
            $this->queue->sendTransaction($toSend);
        }

        $this->reset();
    }

    private function pop(Span $span): void
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i] === $span) {
                array_splice($this->stack, $i, 1);

                return;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function resource(): array
    {
        $resource = [
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.name' => 'talaria-php',
            'deployment.environment' => $this->config->environment,
        ];

        $service = $this->config->tags['service'] ?? null;
        if (is_string($service) && $service !== '') {
            $resource['service.name'] = $service;
        } else {
            $resource['service.name'] = 'php';
        }

        if ($this->config->release !== null && $this->config->release !== '') {
            $resource['service.version'] = $this->config->release;
        }

        return $resource;
    }
}
