<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Override;

/**
 * Remembers the arguments of every execute() call (DBAL 3 passes bound values, DBAL 4 none).
 */
final class RecordingStatement extends AbstractStatementMiddleware
{
    /** @var list<list<mixed>> */
    public array $arguments = [];

    public function __construct(
        Statement $statement,
        public readonly Result $result,
    ) {
        parent::__construct($statement);
    }

    /**
     * @param array<array-key, mixed>|null $params
     */
    #[Override]
    public function execute(mixed $params = null): Result
    {
        $this->arguments[] = func_get_args();

        return $this->result;
    }
}
