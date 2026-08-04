<?php

declare(strict_types=1);

namespace Talaria;

use Talaria\Context\RuntimeContext;
use Talaria\Exception\TransportException;
use Talaria\Integration\ErrorIntegration;
use Talaria\Protocol\ExceptionPayloadBuilder;
use Talaria\Transport\EventQueue;
use Talaria\Transport\ServerpodHttpTransport;
use Talaria\Transport\TransportInterface;

/**
 * Talaria capture client — enqueues events for batch ingest.
 */
final class TalariaClient
{
    private readonly Config $config;
    private readonly EventQueue $queue;
    private readonly string $sessionId;
    private bool $closed = false;
    private ?ErrorIntegration $errorIntegration = null;

    /** Mutable floor; initialized from config. */
    private SeverityLevel $minLevel;

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
    ) {
        $this->config = $options instanceof Config ? $options : new Config($options);
        $this->sessionId = RuntimeContext::newSessionId();
        $this->globalTags = $this->config->tags;
        $this->globalUserId = $this->config->userId;
        $this->minLevel = $this->config->minLevel;
        $this->beforeSend = $this->config->beforeSend;

        $transport ??= new ServerpodHttpTransport(
            $this->config->baseUrl,
            $this->config->apiKey,
            $this->config->httpTimeoutSeconds,
        );

        $this->queue = $queue ?? new EventQueue(
            $transport,
            $this->config->maxBatchSize,
            $this->config->flushIntervalMs,
            $clock,
            static function (TransportException $e): void {
                error_log('[Talaria] ' . $e->getMessage());
            },
        );

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

    public function isLevelEnabled(string|SeverityLevel $level): bool
    {
        $severity = SeverityLevel::tryFromMixed($level) ?? SeverityLevel::Info;

        return $severity->atLeast($this->minLevel);
    }

    /**
     * @param array{tags?: array<string, string>, minLevel?: string|SeverityLevel} $options
     */
    public function logger(array $options = []): Logger
    {
        return new Logger($this, $options);
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
        if ($this->closed) {
            return;
        }
        if (!SeverityLevel::Error->atLeast($this->minLevel)) {
            return;
        }
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
    public function captureMessage(
        string $message,
        string|SeverityLevel $level = SeverityLevel::Info,
        array $context = [],
    ): void {
        if ($this->closed) {
            return;
        }

        $severity = SeverityLevel::tryFromMixed($level) ?? SeverityLevel::Info;
        if (!$severity->atLeast($this->minLevel)) {
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

        $event = new Event(
            message: $message,
            environment: Environment::fromMixed($this->config->environment),
            level: $level,
            eventType: $level->toEventType(),
            title: $title,
            stackTrace: $stackTrace,
            release: $this->config->release,
            userId: $userId,
            sessionId: $this->sessionId,
            requestId: $runtime['requestId'],
            url: $runtime['url'],
            tags: $tags !== [] ? $tags : null,
            extraJson: $extraJson,
            timestamp: RuntimeContext::isoTimestamp(),
            exception: $exception,
            platform: $platform,
        );

        $this->queue->enqueue($event);
    }
}
