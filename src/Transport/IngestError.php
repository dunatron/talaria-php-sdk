<?php

declare(strict_types=1);

namespace Talaria\Transport;

/**
 * Classification of a Talaria ingest HTTP error body.
 */
final class IngestError
{
    public function __construct(
        public readonly ?string $className = null,
        public readonly ?string $message = null,
        public readonly ?bool $retry = null,
    ) {
    }

    public static function parse(string $body): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return new self();
        }
        if (!is_array($decoded)) {
            return new self();
        }
        $nested = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return new self(
            className: self::stringOf($decoded['__className__'] ?? $decoded['className'] ?? $decoded['exception'] ?? ($nested['__className__'] ?? null)),
            message: self::stringOf($decoded['message'] ?? ($nested['message'] ?? null)),
            retry: self::boolOf($decoded['retry'] ?? ($nested['retry'] ?? null)),
        );
    }

    public function isPermanent(): bool
    {
        if ($this->retry === true) {
            return false;
        }
        if ($this->retry === false) {
            return true;
        }
        $name = $this->className ?? '';
        if (str_contains($name, 'ApiUnauthorizedException')) {
            return true;
        }
        if (str_contains($name, 'ApiDisallowedDomainException')) {
            return true;
        }
        if (str_contains($name, 'ApiNotFoundException')) {
            return true;
        }
        if (str_contains($name, 'ApiConflictException') && str_contains(strtolower((string) $this->message), 'not active')) {
            return true;
        }

        return false;
    }

    public function isScopeOnly(): bool
    {
        return str_contains(strtolower((string) $this->message), 'lacks required scope');
    }

    private static function stringOf(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function boolOf(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1' => true,
                'false', '0' => false,
                default => null,
            };
        }

        return null;
    }
}
