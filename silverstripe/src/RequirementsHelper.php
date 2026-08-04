<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\View\Requirements;

/**
 * Injects the published npm package @newtalaria/browser via jsDelivr ESM.
 *
 * @see https://www.npmjs.com/package/@newtalaria/browser
 */
final class RequirementsHelper
{
    private static bool $injected = false;

    /**
     * @param array<string, string> $extraTags
     */
    public static function inject(string $runtimeTag, array $extraTags = []): void
    {
        if (self::$injected) {
            return;
        }

        $config = Config::toBrowserOptions($runtimeTag, $extraTags);
        if ($config === null) {
            return;
        }

        $json = json_encode(
            $config,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($json === false) {
            return;
        }

        $version = Config::browserSdkVersion();
        // Pin to the published npm package (ESM). jsDelivr +esm resolves the package entry.
        $importUrl = 'https://cdn.jsdelivr.net/npm/@newtalaria/browser@'
            . rawurlencode($version)
            . '/+esm';

        Requirements::customScript(
            'window.__TALARIA_CONFIG__ = ' . $json . ';',
            'talaria-config'
        );

        // Classic Requirements::javascript cannot load ESM; use a module script.
        // Expose window.Talaria after init so page scripts can call capture* / flush
        // on the shipped singleton (same global surface as the IIFE build).
        Requirements::insertHeadTags(
            '<script type="module">'
            . 'import { Talaria } from ' . json_encode($importUrl, JSON_UNESCAPED_SLASHES) . ';'
            . 'var cfg = window.__TALARIA_CONFIG__;'
            . 'if (cfg && Talaria && typeof Talaria.init === "function") {'
            . '  try { Talaria.init(cfg); window.Talaria = Talaria; }'
            . '  catch (err) { if (typeof console !== "undefined" && console.warn) console.warn("[Talaria] browser init failed", err); }'
            . '}'
            . '</script>',
            'talaria-browser-sdk'
        );

        self::$injected = true;
    }

    /**
     * @internal Reset between tests / multiple requests in long-running workers.
     */
    public static function reset(): void
    {
        self::$injected = false;
    }
}
