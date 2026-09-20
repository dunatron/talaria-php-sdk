<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Talaria\Exception\TransportException;
use Talaria\Transport\IngestError;

/**
 * Minimal Serverpod RPC client for spans/ingestBatch.
 *
 * Uses a dedicated Guzzle client — never the app HTTP stack — so ingest is
 * not recorded as a child span of the traced request.
 */
final class SpanTransport implements SpanTransportInterface
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
     * @param list<Span> $spans
     */
    public function sendBatch(array $spans): void
    {
        if ($spans === []) {
            return;
        }

        $payload = [
            'input' => [
                '__className__' => 'IngestSpanBatchInput',
                'spans' => array_map(static fn (Span $span) => $span->toWire(), $spans),
            ],
        ];

        $url = rtrim($this->baseUrl, '/') . '/spans/ingestBatch';

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
                ? "Talaria spans/ingestBatch failed: HTTP {$status}"
                : 'Talaria spans/ingestBatch failed: ' . $e->getMessage();
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
            "Talaria spans/ingestBatch failed: HTTP {$status}" . ($detail !== '' ? " — {$detail}" : ''),
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
