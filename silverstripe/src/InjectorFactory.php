<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Injector\Factory;
use Talaria\Client;
use Talaria\Transport\NullTransport;

/**
 * Builds a shared Talaria\Client from YAML / environment config.
 *
 * When DSN/API key are missing, returns a disabled client so Install / flush
 * does not crash before env vars are set.
 */
final class InjectorFactory implements Factory
{
    private static ?Client $client = null;

    /**
     * @param string $service
     * @param array<int|string, mixed> $params
     */
    public function create($service, array $params = [])
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $options = Config::toClientOptions();
        $dsn = is_string($options['dsn'] ?? null) ? $options['dsn'] : '';
        $apiKey = is_string($options['apiKey'] ?? null) ? $options['apiKey'] : '';

        if ($dsn === '' || $apiKey === '' || !str_starts_with($apiKey, 'tal_live_')) {
            error_log('[Talaria] Silverstripe client disabled — set TALARIA_DSN and TALARIA_API_KEY.');
            self::$client = new Client(
                [
                    'dsn' => 'https://disabled.invalid',
                    'apiKey' => 'tal_live_disabled_placeholder_key_xxxxxxxxxxxx',
                    'environment' => 'production',
                    'sampleRate' => 0.0,
                    'defaultIntegrations' => false,
                ],
                new NullTransport(),
            );

            return self::$client;
        }

        $options['tags'] = array_merge(
            is_array($options['tags'] ?? null) ? $options['tags'] : [],
            ['runtime' => 'silverstripe'],
        );

        if (defined('SILVERSTRIPE_VERSION') && is_string(SILVERSTRIPE_VERSION)) {
            $options['tags']['silverstripe_version'] = SILVERSTRIPE_VERSION;
        }

        self::$client = new Client($options);

        return self::$client;
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$client?->close();
        self::$client = null;
    }
}
