<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Injector\Factory;
use Talaria\Config as SdkConfig;
use Talaria\TalariaClient;
use Talaria\Transport\NullTransport;

/**
 * Builds a shared TalariaClient from YAML / environment config.
 *
 * When DSN/API key are missing, returns a disabled client so Install / flush
 * does not crash before env vars are set.
 */
final class InjectorFactory implements Factory
{
    private static ?TalariaClient $client = null;

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
            self::$client = new TalariaClient(
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
            [
                'platform' => 'php',
                'runtime' => 'silverstripe',
            ],
        );

        $runtimeVersion = FrameworkVersion::resolve();
        if ($runtimeVersion !== null) {
            $options['tags']['runtime_version'] = $runtimeVersion;
        }

        $options['tags'] = SdkConfig::normalizeTags($options['tags']);

        self::$client = new TalariaClient($options);
        self::$client->addProcessor(static function (array $bag): array {
            return ['tags' => RequestTags::collect()];
        });

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
