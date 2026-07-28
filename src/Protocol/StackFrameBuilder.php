<?php

declare(strict_types=1);

namespace Talaria\Protocol;

/**
 * Builds Serverpod StackFrameDto wire arrays from PHP backtrace frames.
 */
final class StackFrameBuilder
{
    /**
     * @param array{
     *   file?: string,
     *   line?: int,
     *   function?: string,
     *   class?: string,
     *   type?: string,
     * } $frame
     * @return array<string, mixed>
     */
    public static function fromTraceFrame(array $frame): array
    {
        $file = isset($frame['file']) && is_string($frame['file']) && $frame['file'] !== ''
            ? $frame['file']
            : null;

        $wire = [
            '__className__' => 'StackFrameDto',
            'platform' => 'php',
        ];

        if ($file !== null) {
            $wire['filename'] = basename($file);
            $wire['absPath'] = $file;
            $wire['inApp'] = self::isInApp($file);
        }

        $functionName = self::functionName($frame);
        if ($functionName !== null) {
            $wire['functionName'] = $functionName;
        }

        if (isset($frame['line']) && is_int($frame['line'])) {
            $wire['lineno'] = $frame['line'];
        }

        return $wire;
    }

    /**
     * Throw-site frame when file/line are known (may lack a function name).
     *
     * @return array<string, mixed>|null
     */
    public static function fromThrowSite(string $file, int $line): ?array
    {
        if ($file === '') {
            return null;
        }

        return [
            '__className__' => 'StackFrameDto',
            'filename' => basename($file),
            'absPath' => $file,
            'lineno' => $line,
            'inApp' => self::isInApp($file),
            'platform' => 'php',
        ];
    }

    public static function isInApp(string $path): bool
    {
        return !str_contains($path, '/vendor/');
    }

    /**
     * @param array{
     *   function?: string,
     *   class?: string,
     *   type?: string,
     * } $frame
     */
    public static function functionName(array $frame): ?string
    {
        $function = isset($frame['function']) && is_string($frame['function']) && $frame['function'] !== ''
            ? $frame['function']
            : null;

        if ($function === null) {
            return null;
        }

        if (isset($frame['class']) && is_string($frame['class']) && $frame['class'] !== '') {
            $type = isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : '::';

            return $frame['class'] . $type . $function;
        }

        return $function;
    }

    /**
     * Build frames oldest → newest from a Throwable.
     *
     * PHP getTrace() is newest-first; we reverse. Append throw-site as newest
     * when that file:line is not already the last frame.
     *
     * @return list<array<string, mixed>>
     */
    public static function framesFromThrowable(\Throwable $throwable): array
    {
        return self::framesFromTrace(
            $throwable->getTrace(),
            $throwable->getFile(),
            $throwable->getLine(),
        );
    }

    /**
     * @param list<array<string, mixed>> $trace Newest-first PHP backtrace frames
     * @return list<array<string, mixed>>
     */
    public static function framesFromTrace(array $trace, string $throwFile, int $throwLine): array
    {
        $frames = [];

        foreach (array_reverse($trace) as $frame) {
            if (!is_array($frame)) {
                continue;
            }
            /** @var array{file?: string, line?: int, function?: string, class?: string, type?: string} $frame */
            $frames[] = self::fromTraceFrame($frame);
        }

        if ($throwFile !== '' && !self::lastFrameMatches($frames, $throwFile, $throwLine)) {
            $site = self::fromThrowSite($throwFile, $throwLine);
            if ($site !== null) {
                $frames[] = $site;
            }
        }

        return $frames;
    }

    /**
     * @param list<array<string, mixed>> $frames
     */
    private static function lastFrameMatches(array $frames, string $file, int $line): bool
    {
        if ($frames === []) {
            return false;
        }

        $last = $frames[array_key_last($frames)];
        $absPath = $last['absPath'] ?? null;
        $lineno = $last['lineno'] ?? null;

        return $absPath === $file && $lineno === $line;
    }
}
