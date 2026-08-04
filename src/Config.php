<?php

declare(strict_types=1);

namespace Talaria;

/**
 * Immutable SDK configuration.
 */
final class Config
{
    public readonly string $baseUrl;
    public readonly string $apiKey;
    /** Normalized wire value: production | staging | development */
    public readonly string $environment;
    public readonly ?string $release;
    public readonly float $sampleRate;
    public readonly int $maxBatchSize;
    public readonly int $flushIntervalMs;
    public readonly bool $defaultIntegrations;
    public readonly ?string $userId;
    /** @var array<string, string> */
    public readonly array $tags;
    public readonly float $httpTimeoutSeconds;
    public readonly SeverityLevel $minLevel;
    /** @var (callable(array<string, mixed>, array<string, mixed>): (?array<string, mixed>))|null */
    public readonly mixed $beforeSend;

    /**
     * @param array{
     *   dsn?: string,
     *   baseUrl?: string,
     *   apiKey: string,
     *   environment: string|Environment,
     *   release?: string|null,
     *   sampleRate?: float|int,
     *   maxBatchSize?: int,
     *   flushIntervalMs?: int,
     *   defaultIntegrations?: bool,
     *   userId?: string|null,
     *   tags?: array<string, string>,
     *   httpTimeoutSeconds?: float|int,
     *   minLevel?: string|SeverityLevel,
     *   beforeSend?: callable|null,
     * } $options
     */
    public function __construct(array $options)
    {
        $baseUrl = $options['dsn'] ?? $options['baseUrl'] ?? null;
        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            throw new \InvalidArgumentException('Talaria init requires dsn or baseUrl.');
        }

        $apiKey = $options['apiKey'] ?? '';
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new \InvalidArgumentException('Talaria init requires apiKey.');
        }
        if (!str_starts_with($apiKey, 'tal_live_')) {
            throw new \InvalidArgumentException('Talaria apiKey must start with tal_live_.');
        }

        if (!isset($options['environment'])) {
            throw new \InvalidArgumentException('Talaria init requires environment.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = trim($apiKey);
        // Expose the wire string so callers can use $cfg->environment without enum casts.
        $this->environment = Environment::fromMixed($options['environment'])->value;
        $this->release = isset($options['release']) && is_string($options['release']) && $options['release'] !== ''
            ? $options['release']
            : null;
        $this->sampleRate = max(0.0, min(1.0, (float) ($options['sampleRate'] ?? 1.0)));
        $this->maxBatchSize = max(1, (int) ($options['maxBatchSize'] ?? 50));
        $this->flushIntervalMs = max(0, (int) ($options['flushIntervalMs'] ?? 2000));
        $this->defaultIntegrations = (bool) ($options['defaultIntegrations'] ?? true);
        $this->userId = isset($options['userId']) && is_string($options['userId']) && $options['userId'] !== ''
            ? $options['userId']
            : null;
        $this->tags = self::normalizeTags($options['tags'] ?? []);
        $this->httpTimeoutSeconds = max(0.5, (float) ($options['httpTimeoutSeconds'] ?? 3.0));

        $minLevelRaw = $options['minLevel'] ?? SeverityLevel::Debug;
        $this->minLevel = SeverityLevel::tryFromMixed(
            $minLevelRaw instanceof SeverityLevel ? $minLevelRaw : (string) $minLevelRaw,
        ) ?? SeverityLevel::Debug;

        $beforeSend = $options['beforeSend'] ?? null;
        if ($beforeSend !== null && !is_callable($beforeSend)) {
            throw new \InvalidArgumentException('Talaria beforeSend must be callable or null.');
        }
        $this->beforeSend = $beforeSend;
    }

    public function shouldSample(): bool
    {
        if ($this->sampleRate >= 1.0) {
            return true;
        }
        if ($this->sampleRate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) <= $this->sampleRate;
    }

    /**
     * @param array<string, mixed> $tags
     * @return array<string, string>
     */
    public static function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_scalar($value) || $value instanceof \Stringable) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }
}
