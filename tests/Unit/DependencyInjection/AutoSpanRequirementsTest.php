<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Driver\Middleware;
use GuzzleHttp\ClientInterface;
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Application\Span\Span;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpan;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpanRequirements;
use Msstc4Symfony\SqlLabeller\Domain\Helper\SqlLabeller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\HttpClient;

#[CoversClass(AutoSpanRequirements::class)]
#[CoversClass(AutoSpan::class)]
final class AutoSpanRequirementsTest extends TestCase
{
    public function testListsThePackagesWhoseClassOrInterfaceIsMissing(): void
    {
        $requirements = new AutoSpanRequirements([
            'dbal' => ['acme/interface' => ProfilingFactoryInterface::class, 'acme/missing' => 'Acme\\Missing', 'acme/class' => Span::class, 'acme/gone' => 'Acme\\Gone'],
        ]);

        self::assertSame(['acme/missing', 'acme/gone'], $requirements->missingPackages(AutoSpan::DBAL));
        self::assertSame([], $requirements->missingPackages(AutoSpan::GUZZLE));
    }

    public function testWithoutReplacementTheInstalledAutoSpanPackagesAreFound(): void
    {
        if (!interface_exists(ClientInterface::class) || !class_exists(HttpClient::class) || !class_exists(DoctrineBundle::class) || !class_exists(SqlLabeller::class)) {
            self::markTestSkipped('Needs every optional library (composer-ci.json).');
        }

        $requirements = new AutoSpanRequirements();

        self::assertSame([], $requirements->missingPackages(AutoSpan::DBAL));
        self::assertSame([], $requirements->missingPackages(AutoSpan::HTTP_CLIENT));
        self::assertSame([], $requirements->missingPackages(AutoSpan::GUZZLE));
    }

    public function testEachAutoSpanNamesItsOptionAndPackages(): void
    {
        self::assertSame(['dbal', 'http_client', 'guzzle'], array_map(static fn (AutoSpan $autoSpan): string => $autoSpan->value, AutoSpan::cases()));
        self::assertSame('msstc4symfony_profiling.auto_spans.http_client', AutoSpan::HTTP_CLIENT->option());
        self::assertSame(
            ['doctrine/dbal' => Middleware::class, 'doctrine/doctrine-bundle' => DoctrineBundle::class, 'msstc4symfony/sql-labeller' => SqlLabeller::class],
            AutoSpan::DBAL->packages(),
        );
        self::assertSame(['symfony/http-client' => HttpClient::class], AutoSpan::HTTP_CLIENT->packages());
        self::assertSame(['guzzlehttp/guzzle' => ClientInterface::class], AutoSpan::GUZZLE->packages());
        self::assertStringContainsString('"sql <type> <table>"', AutoSpan::DBAL->info());
        self::assertStringContainsString('http_client.transport', AutoSpan::HTTP_CLIENT->info());
        self::assertStringContainsString('Guzzle', AutoSpan::GUZZLE->info());
    }

    public function testAnAutoSpanIsEnabledOnlyByATrueParameter(): void
    {
        $container = new ContainerBuilder();
        self::assertFalse(AutoSpan::DBAL->isEnabledIn($container));

        $container->setParameter(AutoSpan::DBAL->option(), 'true');
        self::assertFalse(AutoSpan::DBAL->isEnabledIn($container));

        $container->setParameter(AutoSpan::DBAL->option(), true);
        self::assertTrue(AutoSpan::DBAL->isEnabledIn($container));
        self::assertFalse(AutoSpan::GUZZLE->isEnabledIn($container));
    }
}
