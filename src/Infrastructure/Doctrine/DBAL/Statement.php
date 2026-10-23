<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Override;

/**
 * @internal created by Connection only
 */
final class Statement extends AbstractStatementMiddleware
{
    public function __construct(
        StatementInterface $statement,
        private readonly QuerySpans $spans,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    /**
     * DBAL 3 passes bound values here; DBAL 4 dropped the parameter. Forwarding the received arguments as-is keeps
     * both majors working.
     *
     * @param array<array-key, mixed>|null $params
     */
    #[Override]
    public function execute(mixed $params = null): Result
    {
        $arguments = func_get_args();

        return $this->spans->profile($this->sql, fn (): Result => parent::execute(...$arguments));
    }
}
