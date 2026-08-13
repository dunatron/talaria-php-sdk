<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use PDOStatement;
use Talaria\TalariaClient;

/**
 * PDOStatement decorator — spans {@see execute()} so prepared N+1 stays visible.
 */
final class TracingPdoStatement
{
    public function __construct(
        private readonly PDOStatement $statement,
        private readonly TalariaClient $client,
        private readonly string $sql,
        private readonly string $system = 'mysql',
    ) {
    }

    public function inner(): PDOStatement
    {
        return $this->statement;
    }

    public function execute(?array $params = null): bool
    {
        $result = DbSpan::trace(
            $this->client,
            $this->system,
            $this->sql,
            fn () => $this->statement->execute($params),
        );

        return $result === true;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->statement->{$name}(...$arguments);
    }
}
