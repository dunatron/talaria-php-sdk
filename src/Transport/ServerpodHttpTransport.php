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
            $status = null;
            $body = '';
            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                $status = $response?->getStatusCode();
                $body = (string) ($response?->getBody() ?? '');
            }
            $parsed = IngestError::parse($body);
            $detail = self::formatErrorDetail($body, $parsed);
            $prefix = $status !== null
                ? "Talaria events/ingestBatch failed: HTTP {$status}"
                : 'Talaria events/ingestBatch failed: ' . $e->getMessage();
            throw new TransportException(
                $prefix . ($detail !== '' ? " — {$detail}" : ''),
                $status,
                previous: $e,
                className: $parsed->className,
                retry: $parsed->retry,
                bodyMessage: $parsed->message,
            );
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = (string) $response->getBody();
        $parsed = IngestError::parse($body);
        $detail = self::formatErrorDetail($body, $parsed);

        throw new TransportException(
            "Talaria events/ingestBatch failed: HTTP {$status}" . ($detail !== '' ? " — {$detail}" : ''),
            $status,
            className: $parsed->className,
            retry: $parsed->retry,
            bodyMessage: $parsed->message,
        );
    }

    private static function formatErrorDetail(string $body, IngestError $parsed): string
    {
        $parts = array_filter([
            $parsed->className,
            $parsed->message,
        ]);
        if ($parts !== []) {
            return implode(': ', $parts);
        }

        return substr($body, 0, 400);
    }
}
