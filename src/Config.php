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
    public readonly ?string $commitSha;
    public readonly float $sampleRate;
    public readonly int $maxBatchSize;
    public readonly int $flushIntervalMs;
    public readonly bool $defaultIntegrations;
    public readonly ?string $userId;
    /** @var array<string, string> */
    public readonly array $tags;
    public readonly float $httpTimeoutSeconds;
    /**
     * Default / root minimum severity for unset scopes and direct client captures.
     * When {@see $enforceDefaultLevel} is false, scoped loggers may override below this.
     */
    public readonly SeverityLevel $minLevel;
    /**
     * When true, scoped loggers cannot log below {@see $minLevel} (legacy hard floor).
     * Default false — Logback/MEL-style overrides allowed.
     */
    public readonly bool $enforceDefaultLevel;
    /**
     * Named logger presets: name → { minLevel?, tags? }.
     *
     * @var array<string, array{minLevel?: string|SeverityLevel, tags?: array<string, string>}>
     */
    public readonly array $loggers;
    /** @var (callable(array<string, mixed>, array<string, mixed>): (?array<string, mixed>))|null */
    public readonly mixed $beforeSend;
    /**
     * Tracing is off until this is true or {@see $tracesSampleRate} > 0.
     */
    public readonly bool $enableTracing;
    /**
     * Head-based sample rate for successful transactions. Error transactions
     * are always kept. Default 0.1 when tracing is enabled and the option is omitted.
     */
    public readonly float $tracesSampleRate;

    /**
     * @param array{
     *   dsn?: string,
     *   baseUrl?: string,
     *   apiKey: string,
     *   environment: string|Environment,
     *   release?: string|null,
     *   commitSha?: string|null,
     *   sampleRate?: float|int,
     *   maxBatchSize?: int,
     *   flushIntervalMs?: int,
     *   defaultIntegrations?: bool,
     *   userId?: string|null,
     *   tags?: array<string, string>,
     *   httpTimeoutSeconds?: float|int,
     *   minLevel?: string|SeverityLevel,
     *   enforceDefaultLevel?: bool,
     *   loggers?: array<string, array{minLevel?: string|SeverityLevel, tags?: array<string, string>}>,
     *   beforeSend?: callable|null,
     *   enableTracing?: bool,
     *   tracesSampleRate?: float|int,
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
        $this->commitSha = isset($options['commitSha']) && is_string($options['commitSha']) && $options['commitSha'] !== ''
            ? $options['commitSha']
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

        $this->enforceDefaultLevel = (bool) ($options['enforceDefaultLevel'] ?? false);
        $this->loggers = self::normalizeLoggers($options['loggers'] ?? []);

        $beforeSend = $options['beforeSend'] ?? null;
        if ($beforeSend !== null && !is_callable($beforeSend)) {
            throw new \InvalidArgumentException('Talaria beforeSend must be callable or null.');
        }
        $this->beforeSend = $beforeSend;

        $enableTracing = (bool) ($options['enableTracing'] ?? false);
        if (array_key_exists('tracesSampleRate', $options)) {
            $tracesSampleRate = max(0.0, min(1.0, (float) $options['tracesSampleRate']));
        } else {
            $tracesSampleRate = $enableTracing ? 0.1 : 0.0;
        }
        $this->enableTracing = $enableTracing || $tracesSampleRate > 0.0;
        $this->tracesSampleRate = $tracesSampleRate;
    }

    /**
     * @param mixed $loggers
     * @return array<string, array{minLevel?: string|SeverityLevel, tags?: array<string, string>}>
     */
    public static function normalizeLoggers(mixed $loggers): array
    {
        if (!is_array($loggers)) {
            return [];
        }

        $normalized = [];
        foreach ($loggers as $name => $preset) {
            if (!is_string($name) || $name === '' || !is_array($preset)) {
                continue;
            }
            $entry = [];
            if (array_key_exists('minLevel', $preset)) {
                $entry['minLevel'] = $preset['minLevel'] instanceof SeverityLevel
                    ? $preset['minLevel']
                    : (string) $preset['minLevel'];
            }
            if (isset($preset['tags']) && is_array($preset['tags'])) {
                $entry['tags'] = self::normalizeTags($preset['tags']);
            }
            $normalized[$name] = $entry;
        }

        return $normalized;
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

    /**
     * Drop PHP-runtime keys that must never appear on browser init tags.
     *
     * @param array<string, string> $tags
     * @return array<string, string>
     */
    public static function withoutPhpRuntimeTags(array $tags): array
    {
        unset($tags['cli'], $tags['php.version']);
        if (($tags['platform'] ?? '') === 'php') {
            unset($tags['platform']);
        }

        return $tags;
    }
}
