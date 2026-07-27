<?php

declare(strict_types=1);

namespace Talaria;

use Talaria\Context\RuntimeContext;
use Talaria\Exception\TransportException;
use Talaria\Integration\ErrorIntegration;
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

    /** @var array<string, string> */
    private array $globalTags = [];

    /** @var array<string, mixed> */
    private array $globalExtra = [];

    private ?string $globalUserId = null;

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

    /**
     * @param array{
     *   tags?: array<string, mixed>,
     *   extra?: array<string, mixed>,
     *   userId?: string|null,
     *   title?: string|null,
     * } $context
     */
    public function captureException(\Throwable $exception, array $context = []): void
    {
        if ($this->closed || !$this->config->shouldSample()) {
            return;
        }

        $extra = array_merge(
            $this->globalExtra,
            is_array($context['extra'] ?? null) ? $context['extra'] : [],
            [
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'code' => $exception->getCode(),
            ],
        );

        $this->enqueueBuilt(
            message: $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
            level: SeverityLevel::Error,
            title: is_string($context['title'] ?? null) ? $context['title'] : $exception::class,
            stackTrace: $exception->getTraceAsString(),
            context: [
                'tags' => $context['tags'] ?? null,
                'extra' => $extra,
                'userId' => $context['userId'] ?? null,
            ],
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
        if ($this->closed || !$this->config->shouldSample()) {
            return;
        }

        $severity = SeverityLevel::tryFromMixed($level) ?? SeverityLevel::Info;
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
        if (function_exists('fastcgi_finish_request')) {
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
     */
    private function enqueueBuilt(
        string $message,
        SeverityLevel $level,
        ?string $title,
        ?string $stackTrace,
        array $context,
    ): void {
        $runtime = RuntimeContext::collect();
        $tags = array_merge(
            $this->globalTags,
            Config::normalizeTags(is_array($context['tags'] ?? null) ? $context['tags'] : []),
        );

        $extra = array_merge(
            $runtime['extra'],
            is_array($context['extra'] ?? null) ? $context['extra'] : [],
        );

        $userId = null;
        if (is_string($context['userId'] ?? null) && $context['userId'] !== '') {
            $userId = $context['userId'];
        } elseif ($this->globalUserId !== null) {
            $userId = $this->globalUserId;
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
        );

        $this->queue->enqueue($event);
    }
}
