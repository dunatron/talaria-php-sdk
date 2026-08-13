<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use mysqli;
use mysqli_result;
use Talaria\TalariaClient;

/**
 * Opt-in MySQLi helpers. Does not subclass {@see mysqli} (constructor connects).
 */
final class TracingMysqli
{
    public static function query(
        mysqli $db,
        TalariaClient $client,
        string $sql,
        int $resultMode = 0,
    ): mysqli_result|bool {
        return DbSpan::trace(
            $client,
            'mysql',
            $sql,
            fn () => $db->query($sql, $resultMode),
        );
    }

    /**
     * @param list<mixed> $params
     */
    public static function execute(
        mysqli $db,
        TalariaClient $client,
        string $sql,
        string $types = '',
        array $params = [],
    ): mysqli_result|bool {
        return DbSpan::trace($client, 'mysql', $sql, function () use ($db, $sql, $types, $params) {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                return false;
            }
            if ($types !== '' && $params !== []) {
                $stmt->bind_param($types, ...$params);
            }
            $ok = $stmt->execute();
            if ($ok === false) {
                return false;
            }

            return $stmt->get_result();
        });
    }
}
