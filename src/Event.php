<?php

declare(strict_types=1);

namespace Talaria;

/**
 * In-memory event ready to serialize as IngestEventInput.
 */
final class Event
{
    /**
     * @param array<string, string>|null $tags
     * @param array<string, mixed>|null $exception Wire-ready ExceptionDataDto tree
     */
    public function __construct(
        public readonly string $message,
        public readonly Environment $environment,
        public readonly SeverityLevel $level,
        public readonly ?string $eventType = null,
        public readonly ?string $title = null,
        public readonly ?string $stackTrace = null,
        public readonly ?string $release = null,
        public readonly ?string $commitSha = null,
        public readonly ?string $userId = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $requestId = null,
        public readonly ?string $url = null,
        public readonly ?array $tags = null,
        public readonly ?string $extraJson = null,
        public readonly ?string $timestamp = null,
        public readonly ?array $exception = null,
        public readonly ?string $platform = null,
    ) {
        if (trim($message) === '') {
            throw new \InvalidArgumentException('Event message must not be empty.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $wire = [
            '__className__' => 'IngestEventInput',
            'message' => $this->message,
            'environment' => $this->environment->value,
            'level' => $this->level->value,
            'eventType' => $this->eventType ?? $this->level->toEventType(),
        ];

        if ($this->title !== null && $this->title !== '') {
            $wire['title'] = $this->title;
        }
        if ($this->stackTrace !== null && $this->stackTrace !== '') {
            $wire['stackTrace'] = $this->stackTrace;
        }
        if ($this->exception !== null && $this->exception !== []) {
            $wire['exception'] = $this->exception;
        }
        if ($this->platform !== null && $this->platform !== '') {
            $wire['platform'] = $this->platform;
        }
        if ($this->release !== null && $this->release !== '') {
            $wire['release'] = $this->release;
        }
        if ($this->commitSha !== null && $this->commitSha !== '') {
            $wire['commitSha'] = $this->commitSha;
        }
        if ($this->userId !== null && $this->userId !== '') {
            $wire['userId'] = $this->userId;
        }
        if ($this->sessionId !== null && $this->sessionId !== '') {
            $wire['sessionId'] = $this->sessionId;
        }
        if ($this->requestId !== null && $this->requestId !== '') {
            $wire['requestId'] = $this->requestId;
        }
        if ($this->url !== null && $this->url !== '') {
            $wire['url'] = $this->url;
        }
        if ($this->tags !== null && $this->tags !== []) {
            $wire['tags'] = $this->tags;
        }
        if ($this->extraJson !== null && $this->extraJson !== '') {
            $wire['extraJson'] = $this->extraJson;
        }
        if ($this->timestamp !== null && $this->timestamp !== '') {
            $wire['timestamp'] = $this->timestamp;
        }

        return $wire;
    }
}
