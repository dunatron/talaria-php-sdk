<?php

declare(strict_types=1);

namespace Talaria;

use Talaria\Context\RuntimeContext;
use Talaria\Exception\TransportException;
use Talaria\Integration\ErrorIntegration;
use Talaria\Protocol\ExceptionPayloadBuilder;
use Talaria\Tracing\BreadcrumbBuffer;
use Talaria\Tracing\NullSpanTransport;
use Talaria\Tracing\Span;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanQueue;
use Talaria\Tracing\SpanTransport;
use Talaria\Tracing\SpanTransportInterface;
use Talaria\Tracing\TraceContext;
use Talaria\Tracing\Tracer;
use Talaria\Transport\EventQueue;
use Talaria\Transport\NullTransport;
use Talaria\Transport\ServerpodHttpTransport;
use Talaria\Transport\TransportInterface;

/**
 * Talaria capture client — enqueues events for batch ingest.
 */
final class TalariaClient
{
    private readonly Config $config;
    private readonly EventQueue $queue;
    private readonly SpanQueue $spanQueue;
    private readonly Tracer $tracer;
    private readonly BreadcrumbBuffer $breadcrumbs;
    private readonly string $sessionId;
    private bool $closed = false;
    private ?ErrorIntegration $errorIntegration = null;

    /** Mutable default/root floor; initialized from config. */
    private SeverityLevel $minLevel;

    /** When true, scoped loggers cannot go below {@see $minLevel}. */
    private bool $enforceDefaultLevel;

    /**
     * Named logger presets from init.
     *
     * @var array<string, array{minLevel?: string|SeverityLevel, tags?: array<string, string>}>
     */
    private array $loggers;

    /** @var (callable(array<string, mixed>, array<string, mixed>): (?array<string, mixed>))|null */
    private $beforeSend;

    /** @var array<string, string> */
    private array $globalTags = [];

    /** @var array<string, mixed> */
    private array $globalExtra = [];

    private ?string $globalUserId = null;

    /**
     * Capture processors for per-request enrichment (tags/extra).
     *
     * @var list<callable(array{tags: array<string, string>, extra: array<string, mixed>}): array{tags?: array<string, string>, extra?: array<string, mixed>}>
     */
    private array $processors = [];

    /**
     * @param array<string, mixed>|Config $options
     */
    public function __construct(
        array|Config $options,
        ?TransportInterface $transport = null,
        ?EventQueue $queue = null,
        ?callable $clock = null,
        ?SpanTransportInterface $spanTransport = null,
        ?SpanQueue $spanQueue = null,
    ) {
        $this->config = $options instanceof Config ? $options : new Config($options);
        $this->sessionId = RuntimeContext::newSessionId();
        $this->globalTags = $this->config->tags;
        $this->globalUserId = $this->config->userId;
        $this->minLevel = $this->config->minLevel;
        $this->enforceDefaultLevel = $this->config->enforceDefaultLevel;
        $this->loggers = $this->config->loggers;
        $this->beforeSend = $this->config->beforeSend;
        $this->breadcrumbs = new BreadcrumbBuffer();

        $transport ??= new ServerpodHttpTransport(
            $this->config->baseUrl,
            $this->config->apiKey,
            $this->config->httpTimeoutSeconds,
        );

        if ($spanTransport === null) {
            $spanTransport = $transport instanceof NullTransport
                ? new NullSpanTransport()
                : new SpanTransport(
                    $this->config->baseUrl,
                    $this->config->apiKey,
                    $this->config->httpTimeoutSeconds,
                );
        }

        $onError = static function (TransportException $e): void {
            error_log('[Talaria] ' . $e->getMessage());
        };

        $this->queue = $queue ?? new EventQueue(
            $transport,
            $this->config->maxBatchSize,
            $this->config->flushIntervalMs,
            $clock,
            $onError,
        );

        $this->spanQueue = $spanQueue ?? new SpanQueue(
            $spanTransport,
            $this->config->maxBatchSize,
            $this->config->flushIntervalMs,
            $clock,
            $onError,
        );

        $this->tracer = new Tracer($this->config, $this->spanQueue, $this->sessionId);

        if ($this->config->defaultIntegrations) {
            $this->errorIntegration = new ErrorIntegration($this);
            $this->errorIntegration->register();
        }
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getMinLevel(): SeverityLevel
    {
        return $this->minLevel;
    }

    public function setMinLevel(string|SeverityLevel $level): void
    {
        $this->minLevel = SeverityLevel::tryFromMixed($level) ?? $this->minLevel;
    }

    public function isEnforceDefaultLevel(): bool
    {
        return $this->enforceDefaultLevel;
    }

    public function setEnforceDefaultLevel(bool $enforce): void
    {
        $this->enforceDefaultLevel = $enforce;
    }

    public function isLevelEnabled(string|SeverityLevel $level): bool
    {
        $severity = SeverityLevel::tryFromMixed($level) ?? SeverityLevel::Info;

        return $severity->atLeast($this->minLevel);
    }

    /**
     * Create a scoped logger. Pass a preset name string, or options
     * (`tags`, `minLevel`, optional `name` to merge a named preset).
     *
     * @param string|array{name?: string, tags?: array<string, string>, minLevel?: string|SeverityLevel} $options
     */
    public function logger(string|array $options = []): Logger
    {
        return new Logger($this, $this->resolveLoggerOptions($options));
    }

    /**
     * @param string|array{name?: string, tags?: array<string, string>, minLevel?: string|SeverityLevel} $options
     * @return array{tags?: array<string, string>, minLevel?: string|SeverityLevel|null}
     */
    public function resolveLoggerOptions(string|array $options = []): array
    {
        if (is_string($options)) {
            $preset = $this->loggers[$options] ?? [];

            return [
                'tags' => $preset['tags'] ?? [],
                'minLevel' => $preset['minLevel'] ?? null,
            ];
        }

        $name = isset($options['name']) && is_string($options['name']) ? $options['name'] : null;
        $preset = $name !== null ? ($this->loggers[$name] ?? []) : [];

        $tags = array_merge(
            Config::normalizeTags(is_array($preset['tags'] ?? null) ? $preset['tags'] : []),
            Config::normalizeTags(is_array($options['tags'] ?? null) ? $options['tags'] : []),
        );

        $minLevel = array_key_exists('minLevel', $options)
            ? $options['minLevel']
            : ($preset['minLevel'] ?? null);

        $resolved = ['tags' => $tags];
        if ($minLevel !== null) {
            $resolved['minLevel'] = $minLevel;
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $tags
     */
    public function withTags(array $tags): Logger
    {
        return $this->logger(['tags' => $tags]);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->captureMessage($message, SeverityLevel::Debug, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->captureMessage($message, SeverityLevel::Info, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->captureMessage($message, SeverityLevel::Warning, $context);
    }

    /** Alias of {@see warning()} — wire level stays `warning`. */
    public function warn(string $message, array $context = []): void
    {
        $this->captureMessage($message, SeverityLevel::Warning, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->captureMessage($message, SeverityLevel::Error, $context);
    }

    public function fatal(string $message, array $context = []): void
    {
        $this->captureMessage($message, SeverityLevel::Fatal, $context);
    }

    public function log(string|SeverityLevel $level, string $message, array $context = []): void
    {
        $this->captureMessage($message, $level, $context);
    }

    /**
     * @param array{
     *   tags?: array<string, mixed>,
     *   extra?: array<string, mixed>,
     *   userId?: string|null,
     *   title?: string|null,
     *   mechanism?: array{type?: string, handled?: bool, synthetic?: bool},
     * } $context
     */
    public function captureException(\Throwable $exception, array $context = []): void
    {
        $this->captureExceptionInternal($exception, $context, respectMinLevel: true);
    }

    /**
     * Logger-originated exception capture. Applies client {@see $minLevel} only when
     * {@see $enforceDefaultLevel} is true (scoped logger already gated on effective level).
     *
     * @param array{
     *   tags?: array<string, mixed>,
     *   extra?: array<string, mixed>,
     *   userId?: string|null,
     *   title?: string|null,
     *   mechanism?: array{type?: string, handled?: bool, synthetic?: bool},
     * } $context
     *
     * @internal
     */
    public function captureExceptionFromLogger(\Throwable $exception, array $context = []): void
    {
        $this->captureExceptionInternal(
            $exception,
            $context,
            respectMinLevel: $this->enforceDefaultLevel,
        );
    }

    /**
     * @param array{
     *   tags?: array<string, mixed>,
     *   extra?: array<string, mixed>,
     *   userId?: string|null,
     *   title?: string|null,
     * } $context
     */
    public function captureMessage(
        string $message,
        string|SeverityLevel $level = SeverityLevel::Info,
        array $context = [],
    ): void {
        $this->captureMessageInternal($message, $level, $context, respectMinLevel: true);
    }

    /**
     * Logger-originated message capture. Applies client {@see $minLevel} only when
     * {@see $enforceDefaultLevel} is true (scoped logger already gated on effective level).
     *
     * @param array{
     *   tags?: array<string, mixed>,
     *   extra?: array<string, mixed>,
     *   userId?: string|null,
     *   title?: string|null,
     * } $context
     *
     * @internal
     */
    public function captureMessageFromLogger(
        string $message,
        string|SeverityLevel $level = SeverityLevel::Info,
        array $context = [],
    ): void {
        $this->captureMessageInternal(
            $message,
            $level,
            $context,
            respectMinLevel: $this->enforceDefaultLevel,
        );
    }

    /**
     * @param array{
     *   tags?: array<string, mixed>,
     *   extra?: array<string, mixed>,
     *   userId?: string|null,
     *   title?: string|null,
     *   mechanism?: array{type?: string, handled?: bool, synthetic?: bool},
     * } $context
     */
    private function captureExceptionInternal(
        \Throwable $exception,
        array $context,
        bool $respectMinLevel,
    ): void {
        if ($this->closed) {
            return;
        }
        if ($respectMinLevel && !SeverityLevel::Error->atLeast($this->minLevel)) {
            return;
        }

        $this->tracer->markError($exception->getMessage());

        if (!$this->config->shouldSample()) {
            return;
        }

        $mechanism = is_array($context['mechanism'] ?? null) ? $context['mechanism'] : null;
        $exceptionPayload = ExceptionPayloadBuilder::fromThrowable($exception, $mechanism);

        $extra = array_merge(
            $this->globalExtra,
            is_array($context['extra'] ?? null) ? $context['extra'] : [],
        );

        $defaultTitle = ExceptionPayloadBuilder::shortName($exception);

        $this->enqueueBuilt(
            message: $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
            level: SeverityLevel::Error,
            title: is_string($context['title'] ?? null) ? $context['title'] : $defaultTitle,
            stackTrace: $exception->getTraceAsString(),
            context: [
                'tags' => $context['tags'] ?? null,
                'extra' => $extra,
                'userId' => $context['userId'] ?? null,
            ],
            exception: $exceptionPayload,
            platform: 'php',
            originalContext: $context,
        );
    }

    /**
     * @param array{
     *   tags?: array<string, mixed>,
     *   extra?: array<string, mixed>,
     *   userId?: string|null,
     *   title?: string|null,
     * } $context
     */
    private function captureMessageInternal(
        string $message,
        string|SeverityLevel $level,
        array $context,
        bool $respectMinLevel,
    ): void {
        if ($this->closed) {
            return;
        }

        $severity = SeverityLevel::tryFromMixed($level) ?? SeverityLevel::Info;
        if ($respectMinLevel && !$severity->atLeast($this->minLevel)) {
            return;
        }
        if (!$this->config->shouldSample()) {
            return;
        }

        $extra = array_merge(
            $this->globalExtra,
            is_array($context['extra'] ?? null) ? $context['extra'] : [],
        );

        $this->enqueueBuilt(
            message: $message,
            level: $severity,
            title: is_string($context['title'] ?? null) ? $context['title'] : null,
            stackTrace: null,
            context: [
                'tags' => $context['tags'] ?? null,
                'extra' => $extra,
                'userId' => $context['userId'] ?? null,
            ],
            originalContext: $context,
        );
    }

    /**
     * @param array{
     *   timestamp?: string,
     *   type?: string,
     *   category?: string|null,
     *   message?: string|null,
     *   level?: string|null,
     *   data?: array<string, mixed>
     * } $breadcrumb
     */
    public function addBreadcrumb(array $breadcrumb): void
    {
        $this->breadcrumbs->add($breadcrumb);
    }

    public function clearBreadcrumbs(): void
    {
        $this->breadcrumbs->clear();
    }

    /**
     * @param array<string, string> $attributes
     */
    public function startTransaction(
        string $name,
        string|SpanKind $kind = SpanKind::Server,
        array $attributes = [],
    ): Span {
        return $this->tracer->startTransaction($name, $kind, $attributes);
    }

    /**
     * @param array<string, string> $attributes
     */
    public function startSpan(
        string $name,
        string|SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
    ): Span {
        return $this->tracer->startSpan($name, $kind, $attributes);
    }

    public function getTracer(): Tracer
    {
        return $this->tracer;
    }

    /**
     * W3C `traceparent` for the active span, or null when tracing is off / idle.
     */
    public function getTraceparent(): ?string
    {
        $span = $this->tracer->currentSpan() ?? $this->tracer->rootSpan();
        if ($span === null) {
            return null;
        }
        if ($span->traceId === str_repeat('0', 32) || $span->spanId === str_repeat('0', 16)) {
            return null;
        }

        return TraceContext::format($span->traceId, $span->spanId, $this->tracer->isSampled());
    }

    /**
     * @param array<string, string> $tags
     */
    public function setTags(array $tags): void
    {
        $this->globalTags = array_merge($this->globalTags, Config::normalizeTags($tags));
    }

    /**
     * Register a capture-time processor for per-request tags/extra.
     *
     * Prefer this over {@see setTags()} for request-scoped dimensions when the
     * client is a long-lived singleton (e.g. Silverstripe Injector).
     *
     * @param callable(array{tags: array<string, string>, extra: array<string, mixed>}): array{tags?: array<string, string>, extra?: array<string, mixed>} $processor
     */
    public function addProcessor(callable $processor): void
    {
        $this->processors[] = $processor;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function setExtra(array $extra): void
    {
        $this->globalExtra = array_merge($this->globalExtra, $extra);
    }

    public function setUser(?string $userId): void
    {
        $this->globalUserId = $userId !== null && $userId !== '' ? $userId : null;
    }

    public function flush(): void
    {
        // Only finish the FCGI request when the HTTP response is already underway
        // (e.g. shutdown after output). Calling this mid-action before headers/body
        // are sent would close an empty response and drop the real reply.
        if (function_exists('fastcgi_finish_request') && headers_sent()) {
            @fastcgi_finish_request();
        }
        $this->queue->flush();
        $this->spanQueue->flush();
    }

    public function close(): void
    {
        $this->flush();
        $this->closed = true;
        $this->errorIntegration?->unregister();
    }

    public function queueSize(): int
    {
        return $this->queue->count();
    }

    public function spanQueueSize(): int
    {
        return $this->spanQueue->count();
    }

    /**
     * @param array{
     *   tags?: array<string, mixed>|null,
     *   extra?: array<string, mixed>|null,
     *   userId?: string|null,
     * } $context
     * @param array<string, mixed>|null $exception
     * @param array<string, mixed>|null $originalContext
     */
    private function enqueueBuilt(
        string $message,
        SeverityLevel $level,
        ?string $title,
        ?string $stackTrace,
        array $context,
        ?array $exception = null,
        ?string $platform = null,
        ?array $originalContext = null,
    ): void {
        $runtime = RuntimeContext::collect();

        // Later wins: automatic → global → processors → per-call (scope tags already in context).
        $tags = array_merge(
            Config::normalizeTags($runtime['tags']),
            $this->globalTags,
        );
        $extra = array_merge(
            $runtime['extra'],
            is_array($context['extra'] ?? null) ? $context['extra'] : [],
        );

        $bag = ['tags' => $tags, 'extra' => $extra];
        foreach ($this->processors as $processor) {
            $result = $processor($bag);
            if (!is_array($result)) {
                continue;
            }
            if (isset($result['tags']) && is_array($result['tags'])) {
                $bag['tags'] = array_merge($bag['tags'], Config::normalizeTags($result['tags']));
            }
            if (isset($result['extra']) && is_array($result['extra'])) {
                $bag['extra'] = array_merge($bag['extra'], $result['extra']);
            }
        }

        $tags = array_merge(
            $bag['tags'],
            Config::normalizeTags(is_array($context['tags'] ?? null) ? $context['tags'] : []),
        );
        $extra = $bag['extra'];

        $userId = null;
        if (is_string($context['userId'] ?? null) && $context['userId'] !== '') {
            $userId = $context['userId'];
        } elseif ($this->globalUserId !== null) {
            $userId = $this->globalUserId;
        }

        if ($this->beforeSend !== null) {
            $eventBag = [
                'message' => $message,
                'level' => $level->value,
                'eventType' => $level->toEventType(),
                'title' => $title,
                'tags' => $tags,
                'extra' => $extra,
                'userId' => $userId,
                'exception' => $exception,
            ];
            $hint = [
                'originalContext' => $originalContext,
                'isException' => $exception !== null,
            ];
            try {
                $result = ($this->beforeSend)($eventBag, $hint);
            } catch (\Throwable $e) {
                error_log('[Talaria] beforeSend failed: ' . $e->getMessage());

                return;
            }
            if ($result === null) {
                return;
            }
            if (!is_array($result)) {
                return;
            }
            $message = is_string($result['message'] ?? null) ? $result['message'] : $message;
            if (isset($result['level'])) {
                $level = SeverityLevel::tryFromMixed(
                    $result['level'] instanceof SeverityLevel
                        ? $result['level']
                        : (string) $result['level'],
                ) ?? $level;
            }
            $title = array_key_exists('title', $result)
                ? (is_string($result['title']) ? $result['title'] : null)
                : $title;
            $tags = isset($result['tags']) && is_array($result['tags'])
                ? Config::normalizeTags($result['tags'])
                : $tags;
            $extra = isset($result['extra']) && is_array($result['extra'])
                ? $result['extra']
                : $extra;
            $userId = array_key_exists('userId', $result)
                ? (is_string($result['userId']) && $result['userId'] !== '' ? $result['userId'] : null)
                : $userId;
            $exception = array_key_exists('exception', $result)
                ? (is_array($result['exception']) ? $result['exception'] : null)
                : $exception;
        }

        $extraJson = null;
        if ($extra !== []) {
            try {
                $extraJson = json_encode($extra, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException) {
                $extraJson = null;
            }
        }

        $active = $this->tracer->currentSpan() ?? $this->tracer->rootSpan();
        $traceId = $active !== null && ($active->isRecording() || $active->hasEnded()) && $active->traceId !== str_repeat('0', 32)
            ? $active->traceId
            : null;
        $spanId = $active !== null && ($active->isRecording() || $active->hasEnded()) && $active->spanId !== str_repeat('0', 16)
            ? $active->spanId
            : null;

        $isError = $level->atLeast(SeverityLevel::Error);
        $breadcrumbs = $isError ? $this->breadcrumbs->snapshot() : null;
        if ($breadcrumbs === []) {
            $breadcrumbs = null;
        }

        $event = new Event(
            message: $message,
            environment: Environment::fromMixed($this->config->environment),
            level: $level,
            eventType: $level->toEventType(),
            title: $title,
            stackTrace: $stackTrace,
            release: $this->config->release,
            commitSha: $this->config->commitSha,
            userId: $userId,
            sessionId: $this->sessionId,
            requestId: $runtime['requestId'],
            url: $runtime['url'],
            tags: $tags !== [] ? $tags : null,
            extraJson: $extraJson,
            timestamp: RuntimeContext::isoTimestamp(),
            exception: $exception,
            platform: $platform,
            traceId: $traceId,
            spanId: $spanId,
            breadcrumbs: $breadcrumbs,
        );

        $this->queue->enqueue($event);

        $this->breadcrumbs->add([
            'type' => $exception !== null ? 'error' : 'default',
            'category' => $exception !== null ? 'exception' : 'log',
            'message' => $message,
            'level' => $level->value,
        ]);
    }
}
