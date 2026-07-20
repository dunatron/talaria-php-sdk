<?php

declare(strict_types=1);

namespace Talaria\Transport;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Talaria\Event;
use Talaria\Exception\TransportException;

/**
 * Minimal Serverpod RPC client for events/ingestBatch.
 */
final class ServerpodHttpTransport implements TransportInterface
{
    private readonly ClientInterface $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly float $timeoutSeconds = 3.0,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new GuzzleClient([
            'timeout' => $this->timeoutSeconds,
            'http_errors' => false,
        ]);
    }

    /**
     * @param list<Event> $events
     */
    public function sendBatch(array $events): void
    {
        if ($events === []) {
            return;
        }

        $payload = [
            'input' => [
                '__className__' => 'IngestEventBatchInput',
                'events' => array_map(static fn (Event $event) => $event->toWire(), $events),
            ],
        ];

        $url = rtrim($this->baseUrl, '/') . '/events/ingestBatch';

        try {
            $response = $this->http->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'X-API-Key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => $this->timeoutSeconds,
            ]);
        } catch (GuzzleException $e) {
            throw new TransportException(
                'Talaria events/ingestBatch failed: ' . $e->getMessage(),
                previous: $e,
            );
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = (string) $response->getBody();
        $detail = self::formatErrorDetail($body);

        throw new TransportException(
            "Talaria events/ingestBatch failed: HTTP {$status}" . ($detail !== '' ? " — {$detail}" : ''),
            $status,
        );
    }

    private static function formatErrorDetail(string $body): string
    {
        $snippet = substr($body, 0, 400);
        try {
            /** @var array<string, mixed> $parsed */
            $parsed = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $className = $parsed['className'] ?? $parsed['exception'] ?? null;
            $message = $parsed['message'] ?? null;
            $parts = array_filter([
                is_string($className) ? $className : null,
                is_string($message) ? $message : null,
            ]);
            if ($parts !== []) {
                return implode(': ', $parts);
            }
        } catch (\Throwable) {
            // keep raw snippet
        }

        return $snippet;
    }
}
