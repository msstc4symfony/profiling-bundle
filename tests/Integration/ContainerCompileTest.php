<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Integration;

use Monolog\Handler\TestHandler;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactory;
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Msstc4Symfony\ProfilingBundle\Test\Integration\Kernel\TestKernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

#[CoversNothing]
final class ContainerCompileTest extends TestCase
{
    private TestKernel $kernel;

    protected function setUp(): void
    {
        new Filesystem()->remove(TestKernel::cacheRoot());
        $this->kernel = new TestKernel('test', false);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        new Filesystem()->remove(TestKernel::cacheRoot());
    }

    public function testWhitelistedRouteIsLoggedOnTheProfilingChannel(): void
    {
        $this->handle('/ping');

        $records = $this->handler()->getRecords();
        self::assertCount(1, $records);
        self::assertSame('request ping', $records[0]->message);
        self::assertSame('profiling', $records[0]->channel);
    }

    public function testOtherRoutesAreNotProfiled(): void
    {
        $this->handle('/other');

        self::assertSame([], $this->handler()->getRecords());
    }

    public function testKernelResetEndsOpenSpans(): void
    {
        $factory = $this->kernel->getContainer()->get(ProfilingFactoryInterface::class);
        self::assertInstanceOf(ProfilingFactory::class, $factory);
        $factory->createSpan('worker job');

        $resetter = $this->kernel->getContainer()->get('test.services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertSame('worker job', $this->handler()->getRecords()[0]->message ?? null);
    }

    private function handle(string $path): void
    {
        $request = Request::create($path);
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
    }

    private function handler(): TestHandler
    {
        $handler = $this->kernel->getContainer()->get('test.profiling_handler');
        self::assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }
}
