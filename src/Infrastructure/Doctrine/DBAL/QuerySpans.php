<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Infrastructure\Doctrine\DBAL;

use Closure;
use Doctrine\DBAL\Driver\Result;
use Msstc4Symfony\ProfilingBundle\Application\DetachedSpanFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Msstc4Symfony\SqlLabeller\Domain\Helper\SqlLabelling;
use Throwable;

/**
 * Wraps one query of a connection in a "sql <type> <table>" span.
 *
 * @internal shared by the connection and statement middlewares
 */
final readonly class QuerySpans
{
    public function __construct(
        private ProfilingFactoryInterface $profiling,
        private string $connectionName,
        private SqlLabelling $labeller,
    ) {
    }

    /**
     * @template TResult of Result|int|string
     *
     * @param Closure(): TResult $query
     *
     * @return TResult
     */
    public function profile(string $sql, Closure $query): Result|int|string
    {
        try {
            $labels = $this->labeller->label($sql);
            $span = $this->profiling->createSpan(
                'sql ' . $labels->type->value . ' ' . ($labels->table ?? 'unknown'),
                ['connection' => $this->connectionName, ...DetachedSpanFactoryInterface::AUTOMATIC],
            );
        } catch (Throwable) {
            return $query();
        }

        $endContext = [];

        try {
            return $query();
        } catch (Throwable $exception) {
            $endContext = ['exception' => get_debug_type($exception)];

            throw $exception;
        } finally {
            try {
                $this->profiling->endSpan($span, $endContext);
            } catch (Throwable) {
                // The query's result or exception reaches the caller, not a factory decorator's failure.
            }
        }
    }
}
