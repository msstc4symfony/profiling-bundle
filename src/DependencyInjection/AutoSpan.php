<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Driver\Middleware;
use GuzzleHttp\ClientInterface;
use Msstc4Symfony\SqlLabeller\Domain\Helper\SqlLabeller;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The automatic span integrations: configuration key, container parameter and the packages each needs.
 *
 * @internal
 */
enum AutoSpan: string
{
    case DBAL = 'dbal';
    case HTTP_CLIENT = 'http_client';
    case GUZZLE = 'guzzle';

    /**
     * The configuration option, also the name of the container parameter holding its flag.
     *
     * @return non-empty-string
     */
    public function option(): string
    {
        return 'msstc4symfony_profiling.auto_spans.' . $this->value;
    }

    public function isEnabledIn(ContainerBuilder $container): bool
    {
        return $container->hasParameter($this->option()) && $container->getParameter($this->option()) === true;
    }

    public function info(): string
    {
        return match ($this) {
            self::DBAL => 'Doctrine DBAL queries get a "sql <type> <table>" span (needs DoctrineBundle and msstc4symfony/sql-labeller).',
            self::HTTP_CLIENT => 'Symfony HttpClient requests get a detached "http <METHOD> <host>" span (decorates http_client.transport).',
            self::GUZZLE => 'Requests of Guzzle client services get a detached "http <METHOD> <host>" span.',
        };
    }

    /**
     * @return non-empty-array<non-empty-string, class-string> class or interface by package
     */
    public function packages(): array
    {
        return match ($this) {
            self::DBAL => [
                'doctrine/dbal' => Middleware::class,
                'doctrine/doctrine-bundle' => DoctrineBundle::class,
                'msstc4symfony/sql-labeller' => SqlLabeller::class,
            ],
            self::HTTP_CLIENT => ['symfony/http-client' => HttpClient::class],
            self::GUZZLE => ['guzzlehttp/guzzle' => ClientInterface::class],
        };
    }
}
