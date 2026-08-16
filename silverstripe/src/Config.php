<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Config\Configurable;
use Talaria\Config as SdkConfig;
use Talaria\Environment;

/**
 * YAML / env-backed configuration for the Silverstripe adapter.
 *
 * Prefer environment variables:
 * - TALARIA_DSN
 * - TALARIA_BROWSER_DSN (optional; browser inject only — use when PHP DSN is not browser-reachable, e.g. http://host.docker.internal behind an HTTPS site)
 * - TALARIA_API_KEY
 * - TALARIA_ENVIRONMENT
 * - TALARIA_RELEASE (optional)
 * - TALARIA_COMMIT_SHA (optional)
 * - TALARIA_ENABLE_TRACING (optional; default off)
 * - TALARIA_TRACES_SAMPLE_RATE (optional; default 0.1 when tracing is on)
 */
class Config
{
    use Configurable;

    /**
     * @return array<string, mixed>
     */
    public static function toClientOptions(): array
    {
        $cfg = static::config();

        $dsn = self::resolveString($cfg->get('dsn') ?? '');
        $apiKey = self::resolveString($cfg->get('apiKey') ?? '');
        $environment = self::resolveString($cfg->get('environment') ?? '');
        $release = self::resolveString($cfg->get('release') ?? '');
        $commitSha = self::resolveString($cfg->get('commitSha') ?? '');
        $service = self::resolveString($cfg->get('service') ?? '');

        if ($dsn === '') {
            $dsn = self::env('TALARIA_DSN');
        }
        if ($apiKey === '') {
            $apiKey = self::env('TALARIA_API_KEY');
        }
        if ($environment === '') {
            $environment = self::env('TALARIA_ENVIRONMENT') ?: 'production';
        }
        if ($release === '') {
            $release = self::env('TALARIA_RELEASE');
        }
        if ($commitSha === '') {
            $commitSha = self::env('TALARIA_COMMIT_SHA');
        }

        $options = [
            'dsn' => $dsn,
            'apiKey' => $apiKey,
            'environment' => $environment,
            'maxBatchSize' => (int) ($cfg->get('maxBatchSize') ?? 50),
            'flushIntervalMs' => (int) ($cfg->get('flushIntervalMs') ?? 2000),
            'sampleRate' => (float) ($cfg->get('sampleRate') ?? 1.0),
            'minLevel' => self::minLevel(),
            'enforceDefaultLevel' => self::enforceDefaultLevel(),
            'defaultIntegrations' => true,
            'enableTracing' => self::enableTracing(),
        ];

        if (self::tracesSampleRateProvided()) {
            $options['tracesSampleRate'] = self::tracesSampleRate();
        }

        if ($release !== '') {
            $options['release'] = $release;
        }
        if ($commitSha !== '') {
            $options['commitSha'] = $commitSha;
        }

        $tags = [];
        if ($service !== '') {
            $tags['service'] = $service;
        }
        $yamlTags = $cfg->get('tags');
        if (is_array($yamlTags)) {
            $tags = array_merge($tags, $yamlTags);
        }
        if ($tags !== []) {
            $options['tags'] = SdkConfig::normalizeTags($tags);
        }

        $loggers = self::loggers();
        if ($loggers !== []) {
            $options['loggers'] = $loggers;
        }

        return $options;
    }

    /**
     * Browser SDK init payload for Requirements injection, or null when disabled / misconfigured.
     *
     * @param array<string, string> $extraTags CMS/frontend-only tags (e.g. ss.section)
     * @return array<string, mixed>|null
     */
    public static function toBrowserOptions(string $runtimeTag, array $extraTags = []): ?array
    {
        $options = self::toClientOptions();
        $dsn = self::resolveBrowserDsn($options);
        $apiKey = is_string($options['apiKey'] ?? null) ? $options['apiKey'] : '';

        if ($dsn === '' || $apiKey === '' || !str_starts_with($apiKey, 'tal_live_')) {
            return null;
        }

        // Match TalariaClient: map aliases (test/uat → staging); unknown → production.
        $environment = Environment::fromMixed(
            is_string($options['environment'] ?? null) && $options['environment'] !== ''
                ? $options['environment']
                : 'production'
        )->value;

        $tags = [
            'platform' => 'web',
            'runtime' => $runtimeTag,
        ];

        $runtimeVersion = FrameworkVersion::resolve();
        if ($runtimeVersion !== null) {
            $tags['runtime_version'] = $runtimeVersion;
        }

        $tags = array_merge($tags, RequestTags::collect());

        $service = self::resolveString(static::config()->get('service') ?? '');
        if ($service !== '') {
            $tags['service'] = $service;
        }

        $browserTags = static::config()->get('browserTags');
        if (is_array($browserTags)) {
            $tags = array_merge($tags, $browserTags);
        }

        if ($extraTags !== []) {
            $tags = array_merge($tags, $extraTags);
        }

        $browser = [
            'dsn' => $dsn,
            'apiKey' => $apiKey,
            'environment' => $environment,
            'minLevel' => self::minLevel(),
            'enforceDefaultLevel' => self::enforceDefaultLevel(),
            'replaysSessionSampleRate' => (float) (static::config()->get('browserReplaysSessionSampleRate') ?? 0),
            'replaysOnErrorSampleRate' => (float) (static::config()->get('browserReplaysOnErrorSampleRate') ?? 0),
            'tags' => SdkConfig::withoutPhpRuntimeTags(SdkConfig::normalizeTags($tags)),
            'inlineStylesheet' => self::resolveInlineStylesheet($runtimeTag),
            'captureFailedRequests' => self::resolveCaptureFailedRequests(),
            'failedRequestStatusCodes' => self::resolveFailedRequestStatusCodes($runtimeTag),
        ];

        if (self::enableTracing()) {
            $browser['enableTracing'] = true;
            $browser['tracesSampleRate'] = self::tracesSampleRate();
        }

        $loggers = self::loggers();
        if ($loggers !== []) {
            $browser['loggers'] = $loggers;
        }

        if (!empty($options['release']) && is_string($options['release'])) {
            $browser['release'] = $options['release'];
        }
        if (!empty($options['commitSha']) && is_string($options['commitSha'])) {
            $browser['commitSha'] = $options['commitSha'];
        }

        $userId = self::resolveMemberUserId();
        if ($userId !== null) {
            $browser['userId'] = $userId;
        }

        return $browser;
    }

    /**
     * Browser DSN: YAML/env `browserDsn` / TALARIA_BROWSER_DSN, else PHP client DSN.
     *
     * @param array<string, mixed> $clientOptions
     */
    private static function resolveBrowserDsn(array $clientOptions): string
    {
        $browserDsn = self::resolveString(static::config()->get('browserDsn') ?? '');
        if ($browserDsn === '') {
            $browserDsn = self::env('TALARIA_BROWSER_DSN');
        }
        if ($browserDsn !== '') {
            return rtrim($browserDsn, '/');
        }

        $dsn = is_string($clientOptions['dsn'] ?? null) ? $clientOptions['dsn'] : '';

        return $dsn !== '' ? rtrim($dsn, '/') : '';
    }

    /**
     * CMS admin defaults to inlining same-origin CSS; frontend stays false unless YAML overrides.
     */
    private static function resolveInlineStylesheet(string $runtimeTag): bool
    {
        $override = static::config()->get('browserInlineStylesheet');
        if ($override !== null) {
            return (bool) $override;
        }

        return $runtimeTag === 'silverstripe-cms';
    }

    private static function resolveCaptureFailedRequests(): bool
    {
        $value = static::config()->get('browserCaptureFailedRequests');

        return $value === null ? true : (bool) $value;
    }

    /**
     * CMS promotes 4xx+5xx (GridField/PJAX 404s); frontend keeps SDK default 5xx unless YAML overrides.
     *
     * @return list<array{0: int, 1: int}|int>
     */
    private static function resolveFailedRequestStatusCodes(string $runtimeTag): array
    {
        $override = static::config()->get('browserFailedRequestStatusCodes');
        if (is_array($override) && $override !== []) {
            return array_values($override);
        }

        if ($runtimeTag === 'silverstripe-cms') {
            return [[400, 599]];
        }

        return [[500, 599]];
    }

    /**
     * Published npm version of @newtalaria/browser to load from jsDelivr.
     *
     * @see https://www.npmjs.com/package/@newtalaria/browser
     */
    public static function browserSdkVersion(): string
    {
        $version = static::config()->get('browserSdkVersion') ?? '0.1.24';
        if (!is_string($version) || $version === '') {
            return '0.1.24';
        }

        // Allow semver and npm tags like 0.1.24 or latest (prefer exact semver).
        if (preg_match('/^[a-zA-Z0-9._~+%-]+$/', $version) !== 1) {
            return '0.1.24';
        }

        return $version;
    }

    public static function enableBrowserCms(): bool
    {
        $value = static::config()->get('enableBrowserCms');

        return $value === null ? true : (bool) $value;
    }

    public static function enableBrowserFrontend(): bool
    {
        $value = static::config()->get('enableBrowserFrontend');

        return $value === null ? true : (bool) $value;
    }

    public static function minLevel(): string
    {
        $level = static::config()->get('minLevel') ?? 'warning';

        return is_string($level) && $level !== '' ? $level : 'warning';
    }

    /**
     * When true, scoped Talaria loggers cannot go below {@see minLevel()}.
     * Default false — named/scoped overrides may be more verbose than the default.
     */
    public static function enforceDefaultLevel(): bool
    {
        $value = static::config()->get('enforceDefaultLevel');

        return $value === null ? false : (bool) $value;
    }

    /**
     * Named logger presets for Approach B (`Talaria::logger('name')`).
     *
     * @return array<string, array{minLevel?: string, tags?: array<string, string>}>
     */
    public static function loggers(): array
    {
        $raw = static::config()->get('loggers');

        return SdkConfig::normalizeLoggers(is_array($raw) ? $raw : []);
    }

    public static function enableTracing(): bool
    {
        $env = self::env('TALARIA_ENABLE_TRACING');
        if ($env !== '') {
            return self::resolveBool($env, false);
        }

        return self::resolveBool(static::config()->get('enableTracing'), false);
    }

    public static function tracesSampleRate(): float
    {
        $env = self::env('TALARIA_TRACES_SAMPLE_RATE');
        if ($env !== '') {
            return max(0.0, min(1.0, (float) $env));
        }

        $yaml = static::config()->get('tracesSampleRate');
        if ($yaml !== null && $yaml !== '') {
            return max(0.0, min(1.0, (float) $yaml));
        }

        return self::enableTracing() ? 0.1 : 0.0;
    }

    public static function tracesSampleRateProvided(): bool
    {
        if (self::env('TALARIA_TRACES_SAMPLE_RATE') !== '') {
            return true;
        }

        $yaml = static::config()->get('tracesSampleRate');

        return $yaml !== null && $yaml !== '';
    }

    private static function resolveBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }
        if (is_string($value)) {
            $resolved = self::resolveString($value);
            if ($resolved === '') {
                $resolved = $value;
            }

            return in_array(strtolower($resolved), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    private static function resolveMemberUserId(): ?string
    {
        if (!class_exists(\SilverStripe\Security\Security::class)) {
            return null;
        }

        try {
            $member = \SilverStripe\Security\Security::getCurrentUser();
            if ($member !== null && isset($member->ID) && (string) $member->ID !== '') {
                return (string) $member->ID;
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }

    private static function env(string $key): string
    {
        // Prefer Silverstripe's Environment so .env values loaded via
        // Environment::setEnv() are visible (plain getenv() often is not).
        if (class_exists(\SilverStripe\Core\Environment::class)) {
            $value = \SilverStripe\Core\Environment::getEnv($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_scalar($value) && $value !== false) {
                return (string) $value;
            }
        }

        $value = getenv($key);

        return is_string($value) ? $value : '';
    }

    private static function resolveString(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        // Silverstripe env placeholder style: `TALARIA_DSN`
        if (preg_match('/^`([A-Z0-9_]+)`$/', $value, $matches) === 1) {
            return self::env($matches[1]);
        }

        return $value;
    }
}
