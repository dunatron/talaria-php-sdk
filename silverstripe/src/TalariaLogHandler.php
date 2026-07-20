<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Talaria\Client;
use Talaria\SeverityLevel;

/**
 * Monolog handler that forwards records into the Talaria batch queue.
 */
final class TalariaLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly Client $client,
        int|string|Level $level = Level::Warning,
        bool $bubble = true,
    ) {
        if (is_string($level)) {
            $level = Level::fromName($level);
        }

        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $this->client->captureException($exception, [
                'extra' => $this->contextWithoutException($record->context),
                'tags' => $this->stringTags($record->context['tags'] ?? null),
                'userId' => $this->resolveUserId($record->context),
                'title' => $exception::class,
            ]);

            return;
        }

        $severity = SeverityLevel::tryFromMixed($record->level->toPsrLogLevel()) ?? SeverityLevel::Info;

        $this->client->captureMessage($record->message, $severity, [
            'extra' => array_merge(
                $this->contextWithoutException($record->context),
                ['channel' => $record->channel],
            ),
            'tags' => $this->stringTags($record->context['tags'] ?? null),
            'userId' => $this->resolveUserId($record->context),
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
