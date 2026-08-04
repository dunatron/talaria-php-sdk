<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

/**
 * Per-request Silverstripe tags for filterable dimensions.
 *
 * Built at capture / browser-init time (not frozen on the Injector singleton).
 */
final class RequestTags
{
    /**
     * @return array<string, string>
     */
    public static function collect(): array
    {
        if (!class_exists(\SilverStripe\Control\Director::class)) {
            return [];
        }

        $tags = [];

        try {
            $tags['ajax'] = \SilverStripe\Control\Director::is_ajax() ? 'true' : 'false';
        } catch (\Throwable) {
            // ignore
        }

        try {
            $env = \SilverStripe\Control\Director::get_environment_type();
            if (is_string($env) && $env !== '') {
                $tags['ss_env'] = $env;
            }
        } catch (\Throwable) {
            // ignore
        }

        try {
            $host = \SilverStripe\Control\Director::host();
            if (is_string($host) && $host !== '') {
                $tags['host'] = strlen($host) <= 128 ? $host : substr($host, 0, 128);
            }
        } catch (\Throwable) {
            // ignore
        }

        return $tags;
    }
}
