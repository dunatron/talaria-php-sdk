<?php

declare(strict_types=1);

namespace Talaria\Integration;

use Talaria\TalariaClient;

/**
 * Captures uncaught exceptions / fatals and flushes the queue on shutdown.
 */
final class ErrorIntegration
{
    /** @var callable|null */
    private $previousExceptionHandler = null;

    /** @var callable|null */
    private $previousErrorHandler = null;

    private bool $registered = false;

    public function __construct(private readonly TalariaClient $client)
    {
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->previousExceptionHandler = set_exception_handler($this->handleException(...));
        $this->previousErrorHandler = set_error_handler($this->handleError(...));
        register_shutdown_function($this->handleShutdown(...));
        $this->registered = true;
    }

    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }

        restore_exception_handler();
        restore_error_handler();
        $this->registered = false;
    }

    private function handleException(\Throwable $exception): void
    {
        try {
            $this->client->captureException($exception);
            $this->client->flush();
        } catch (\Throwable) {
            // never break host exception flow
        }

        if ($this->previousExceptionHandler !== null) {
            ($this->previousExceptionHandler)($exception);

            return;
        }

        throw $exception;
    }

    private function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        // Only escalate serious errors; leave notices/warnings to the app logger.
        $fatalish = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (in_array($severity, $fatalish, true)) {
            try {
                $this->client->captureException(new \ErrorException($message, 0, $severity, $file, $line));
            } catch (\Throwable) {
                // ignore
            }
        }

        if ($this->previousErrorHandler !== null) {
            return (bool) ($this->previousErrorHandler)($severity, $message, $file, $line);
        }

        return false;
    }

    private function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null) {
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
            if (in_array($error['type'], $fatalTypes, true)) {
                try {
                    $this->client->captureException(new \ErrorException(
                        $error['message'],
                        0,
                        $error['type'],
                        $error['file'],
                        $error['line'],
                    ));
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        try {
            $this->client->flush();
        } catch (\Throwable) {
            // ignore
        }
    }
}
