<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Talaria\Context\RuntimeContext;

/**
 * Ring buffer of recent breadcrumbs attached to error events.
 *
 * @phpstan-type Breadcrumb array{
 *   __className__: string,
 *   timestamp: string,
 *   type: string,
 *   category?: string,
 *   message?: string,
 *   level?: string,
 *   data?: array<string, string>
 * }
 */
final class BreadcrumbBuffer
{
    public const DEFAULT_CAPACITY = 50;

    /** @var list<Breadcrumb> */
    private array $items = [];

    public function __construct(private readonly int $capacity = self::DEFAULT_CAPACITY)
    {
    }

    /**
     * @param array{
     *   timestamp?: string,
     *   type?: string,
     *   category?: string|null,
     *   message?: string|null,
     *   level?: string|null,
     *   data?: array<string, mixed>
     * } $breadcrumb
     */
    public function add(array $breadcrumb): void
    {
        $item = [
            '__className__' => 'BreadcrumbDto',
            'timestamp' => is_string($breadcrumb['timestamp'] ?? null) && $breadcrumb['timestamp'] !== ''
                ? $breadcrumb['timestamp']
                : RuntimeContext::isoTimestamp(),
            'type' => is_string($breadcrumb['type'] ?? null) && $breadcrumb['type'] !== ''
                ? $breadcrumb['type']
                : 'default',
        ];

        if (is_string($breadcrumb['category'] ?? null) && $breadcrumb['category'] !== '') {
            $item['category'] = $breadcrumb['category'];
        }
        if (is_string($breadcrumb['message'] ?? null) && $breadcrumb['message'] !== '') {
            $item['message'] = $breadcrumb['message'];
        }
        if (is_string($breadcrumb['level'] ?? null) && $breadcrumb['level'] !== '') {
            $item['level'] = $breadcrumb['level'];
        }

        $data = self::normalizeData(is_array($breadcrumb['data'] ?? null) ? $breadcrumb['data'] : []);
        if ($data !== []) {
            $item['data'] = $data;
        }

        $this->items[] = $item;
        $overflow = count($this->items) - $this->capacity;
        if ($overflow > 0) {
            $this->items = array_slice($this->items, $overflow);
        }
    }

    /**
     * @return list<Breadcrumb>
     */
    public function snapshot(): array
    {
        return array_values($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function clear(): void
    {
        $this->items = [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function normalizeData(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';
            } elseif (is_scalar($value) || $value instanceof \Stringable) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }
}
