<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Config\Configurable;
use Talaria\Environment;

/**
 * YAML / env-backed configuration for the Silverstripe adapter.
 *
 * Prefer environment variables:
 * - TALARIA_DSN
 * - TALARIA_API_KEY
 * - TALARIA_ENVIRONMENT
 * - TALARIA_RELEASE (optional)
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

        $options = [
            'dsn' => $dsn,
            'apiKey' => $apiKey,
            'environment' => $environment,
            'maxBatchSize' => (int) ($cfg->get('maxBatchSize') ?? 50),
            'flushIntervalMs' => (int) ($cfg->get('flushIntervalMs') ?? 2000),
            'sampleRate' => (float) ($cfg->get('sampleRate') ?? 1.0),
            'defaultIntegrations' => true,
        ];

        if ($release !== '') {
            $options['release'] = $release;
        }

        return $options;
    }

    /**
     * Browser SDK init payload for Requirements injection, or null when disabled / misconfigured.
     *
     * @return array<string, mixed>|null
     */
    public static function toBrowserOptions(string $runtimeTag): ?array
    {
        $options = self::toClientOptions();
        $dsn = is_string($options['dsn'] ?? null) ? $options['dsn'] : '';
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

        $browser = [
            'dsn' => $dsn,
            'apiKey' => $apiKey,
            'environment' => $environment,
            'replaysSessionSampleRate' => (float) (static::config()->get('browserReplaysSessionSampleRate') ?? 0),
            'replaysOnErrorSampleRate' => (float) (static::config()->get('browserReplaysOnErrorSampleRate') ?? 0),
            // Attached when @newtalaria/browser supports init tags; ignored on older versions.
            'tags' => [
                'runtime' => $runtimeTag,
            ],
            'inlineStylesheet' => self::resolveInlineStylesheet($runtimeTag),
            'captureFailedRequests' => self::resolveCaptureFailedRequests(),
            'failedRequestStatusCodes' => self::resolveFailedRequestStatusCodes($runtimeTag),
        ];

        if (!empty($options['release']) && is_string($options['release'])) {
            $browser['release'] = $options['release'];
        }

        return $browser;
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
        $version = static::config()->get('browserSdkVersion') ?? '0.1.12';
        if (!is_string($version) || $version === '') {
            return '0.1.12';
        }

        // Allow semver and npm tags like 0.1.12 or latest (prefer exact semver).
        if (preg_match('/^[a-zA-Z0-9._~+%-]+$/', $version) !== 1) {
            return '0.1.12';
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
