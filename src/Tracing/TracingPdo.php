<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use PDO;
use PDOStatement;
use Talaria\TalariaClient;

/**
 * PDO decorator that records CLIENT db spans for query / exec / prepare+execute.
 *
 * Does not extend PDO so it can wrap an existing connection (including SQLite in tests).
 */
final class TracingPdo
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TalariaClient $client,
        private readonly string $system = 'mysql',
    ) {
    }

    public function inner(): PDO
    {
        return $this->pdo;
    }

    public function exec(string $statement): int|false
    {
        $result = $this->trace($statement, fn () => $this->pdo->exec($statement));

        return is_int($result) || $result === false ? $result : false;
    }

    public function query(string $query, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $result = $this->trace($query, function () use ($query, $fetchModeArgs) {
            return $fetchModeArgs === []
                ? $this->pdo->query($query)
                : $this->pdo->query($query, ...$fetchModeArgs);
        });

        return $result instanceof PDOStatement || $result === false ? $result : false;
    }

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): TracingPdoStatement|false
    {
        $stmt = $this->pdo->prepare($query, $options);
        if ($stmt === false) {
            return false;
        }

        return new TracingPdoStatement($stmt, $this->client, $query, $this->system);
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->pdo->{$name}(...$arguments);
    }

    private function trace(string $sql, callable $run): mixed
    {
        return DbSpan::trace(
            $this->client,
            $this->system,
            $sql,
            $run,
        );
    }
}
