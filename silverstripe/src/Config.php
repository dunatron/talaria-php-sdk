<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Config\Configurable;

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
            $environment = self::env('TALARIA_ENVIRONMENT') ?: 'development';
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
