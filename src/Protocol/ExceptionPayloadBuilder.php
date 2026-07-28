<?php

declare(strict_types=1);

namespace Talaria\Protocol;

/**
 * Builds Serverpod ExceptionDataDto wire trees from Throwable chains.
 */
final class ExceptionPayloadBuilder
{
    /**
     * @param array{
     *   type?: string,
     *   handled?: bool,
     *   synthetic?: bool,
     * }|null $mechanism
     * @return array<string, mixed>
     */
    public static function fromThrowable(\Throwable $throwable, ?array $mechanism = null): array
    {
        $mechanismWire = self::mechanismWire($mechanism);

        // Collect newest → oldest, then reverse to oldest → newest.
        $chain = [];
        $current = $throwable;
        while ($current !== null) {
            $chain[] = $current;
            $current = $current->getPrevious();
        }

        $values = [];
        foreach (array_reverse($chain) as $item) {
            $values[] = self::valueWire($item, $mechanismWire);
        }

        return [
            '__className__' => 'ExceptionDataDto',
            'values' => $values,
        ];
    }

    /**
     * Short class name for titles (basename after last backslash).
     */
    public static function shortName(\Throwable $throwable): string
    {
        $class = $throwable::class;
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * @param array{
     *   type?: string,
     *   handled?: bool,
     *   synthetic?: bool,
     * }|null $mechanism
     * @return array<string, mixed>
     */
    private static function mechanismWire(?array $mechanism): array
    {
        return [
            '__className__' => 'ExceptionMechanismDto',
            'type' => is_string($mechanism['type'] ?? null) && $mechanism['type'] !== ''
                ? $mechanism['type']
                : 'generic',
            'handled' => array_key_exists('handled', $mechanism ?? [])
                ? (bool) $mechanism['handled']
                : true,
            'synthetic' => array_key_exists('synthetic', $mechanism ?? [])
                ? (bool) $mechanism['synthetic']
                : false,
        ];
    }

    /**
     * @param array<string, mixed> $mechanismWire
     * @return array<string, mixed>
     */
    private static function valueWire(\Throwable $throwable, array $mechanismWire): array
    {
        $frames = StackFrameBuilder::framesFromThrowable($throwable);

        $wire = [
            '__className__' => 'ExceptionValueDto',
            'type' => $throwable::class,
            'value' => $throwable->getMessage(),
            'code' => (string) $throwable->getCode(),
            'mechanism' => $mechanismWire,
            'stacktrace' => [
                '__className__' => 'StackTraceDto',
                'frames' => $frames,
            ],
        ];

        return $wire;
    }
}
