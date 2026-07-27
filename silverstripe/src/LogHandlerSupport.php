<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use Talaria\SeverityLevel;
use Talaria\TalariaClient;

/**
 * Shared capture / context helpers for Monolog 1 and Monolog 3 Talaria handlers.
 */
trait LogHandlerSupport
{
    /**
     * @param array<string, mixed> $context
     */
    private function forwardToClient(
        TalariaClient $client,
        string $message,
        string $psrLevel,
        string $channel,
        array $context,
    ): void {
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $client->captureException($exception, [
                'extra' => $this->contextWithoutException($context),
                'tags' => $this->stringTags($context['tags'] ?? null),
                'userId' => $this->resolveUserId($context),
                'title' => $exception::class,
            ]);

            return;
        }

        $severity = SeverityLevel::tryFromMixed($psrLevel) ?? SeverityLevel::Info;

        $client->captureMessage($message, $severity, [
            'extra' => array_merge(
                $this->contextWithoutException($context),
                ['channel' => $channel],
            ),
            'tags' => $this->stringTags($context['tags'] ?? null),
            'userId' => $this->resolveUserId($context),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function contextWithoutException(array $context): array
    {
        unset($context['exception'], $context['tags'], $context['userId']);

        return $this->enrichSilverstripeContext($context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function enrichSilverstripeContext(array $context): array
    {
        if (class_exists(\SilverStripe\Security\Security::class)) {
            try {
                $member = \SilverStripe\Security\Security::getCurrentUser();
                if ($member !== null && isset($member->ID)) {
                    $context['member_id'] = (string) $member->ID;
                    if (isset($member->Email) && is_string($member->Email)) {
                        $context['member_email'] = $member->Email;
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if (class_exists(\SilverStripe\Control\Director::class)) {
            try {
                $context['ss_base_url'] = \SilverStripe\Control\Director::baseURL();
            } catch (\Throwable) {
                // ignore
            }
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveUserId(array $context): ?string
    {
        if (isset($context['userId']) && is_scalar($context['userId'])) {
            return (string) $context['userId'];
        }

        if (class_exists(\SilverStripe\Security\Security::class)) {
            try {
                $member = \SilverStripe\Security\Security::getCurrentUser();
                if ($member !== null && isset($member->ID)) {
                    return (string) $member->ID;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function stringTags(mixed $tags): ?array
    {
        if (!is_array($tags)) {
            return null;
        }

        $normalized = [];
        foreach ($tags as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value instanceof \Stringable)) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized !== [] ? $normalized : null;
    }
}
