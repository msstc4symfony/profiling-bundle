<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Test\Unit;

use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpan;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpanRequirements;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\ProfileDoctrineDbalPass;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\ProfileGuzzlePass;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\ProfileHttpClientPass;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\RegisterGuzzleMiddlewarePass;
use Msstc4Symfony\ProfilingBundle\ProfilingBundle;
use Msstc4Symfony\ProfilingBundle\Test\Unit\Fixture\MarkerPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

#[CoversClass(ProfilingBundle::class)]
#[UsesClass(AutoSpanRequirements::class)]
#[UsesClass(AutoSpan::class)]
final class ProfilingBundleTest extends TestCase
{
    public function testAutoSpansAreOffByDefault(): void
    {
        self::assertSame(['dbal' => false, 'http_client' => false, 'guzzle' => false], $this->section($this->process([]), 'auto_spans'));
    }

    public function testInstalledLibrariesCanBeProfiled(): void
    {
        $requirements = new AutoSpanRequirements(['dbal' => [], 'http_client' => [], 'guzzle' => []]);

        self::assertSame(
            ['dbal' => true, 'http_client' => true, 'guzzle' => true],
            $this->section($this->process(['auto_spans' => ['dbal' => true, 'http_client' => true, 'guzzle' => true]], $requirements), 'auto_spans'),
        );
    }

    /**
     * @param 'dbal'|'http_client'|'guzzle' $autoSpan
     */
    #[TestWith(['dbal'])]
    #[TestWith(['http_client'])]
    #[TestWith(['guzzle'])]
    public function testEnablingAnAutoSpanWithoutItsLibraryIsAConfigurationError(string $autoSpan): void
    {
        $requirements = new AutoSpanRequirements([$autoSpan => ['acme/one' => 'Acme\\One', 'acme/two' => 'Acme\\Two']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('"msstc4symfony_profiling.auto_spans.%s" needs acme/one, acme/two installed.', $autoSpan));

        $this->process(['auto_spans' => [$autoSpan => true]], $requirements);
    }

    public function testADisabledAutoSpanDoesNotNeedItsLibrary(): void
    {
        $requirements = new AutoSpanRequirements(['dbal' => ['acme/one' => 'Acme\\One']]);

        self::assertSame(['dbal' => false, 'http_client' => false, 'guzzle' => false], $this->section($this->process(['auto_spans' => ['dbal' => false]], $requirements), 'auto_spans'));
    }

    public function testAutoSpanFlagsMustBeBooleans(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['auto_spans' => ['dbal' => 'yes']]);
    }

    public function testTheListsDefaultToEmptyAndUnset(): void
    {
        $config = $this->process([]);

        self::assertSame([], $this->section($config, 'routes'));
        self::assertSame([], $this->section($config, 'commands'));
        self::assertSame([], $this->section($config, 'messages'));
        self::assertSame(['whitelist' => null, 'blacklist' => null], $this->section($config, 'spans'));
    }

    public function testOnlyMessageClassesLoseTheirLeadingBackslash(): void
    {
        $config = $this->process(['routes' => ['\\kept'], 'commands' => ['\\cmd'], 'messages' => ['\\App\\Message\\Import']]);

        self::assertSame(['\\kept'], $this->section($config, 'routes'));
        self::assertSame(['\\cmd'], $this->section($config, 'commands'));
        self::assertSame(['App\\Message\\Import'], $this->section($config, 'messages'));
    }

    /**
     * @param array{whitelist?: list<string>, blacklist?: list<string>} $spans
     */
    #[TestWith([['whitelist' => ['request ']]])]
    #[TestWith([['blacklist' => ['sql ']]])]
    #[TestWith([['whitelist' => []]])]
    public function testOneSpanListMayBeSet(array $spans): void
    {
        self::assertEquals([...['whitelist' => null, 'blacklist' => null], ...$spans], $this->section($this->process(['spans' => $spans]), 'spans'));
    }

    public function testBothSpanListsCannotBeSet(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Set either "whitelist" or "blacklist", not both.');

        $this->process(['spans' => ['whitelist' => ['a'], 'blacklist' => ['b']]]);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[TestWith([['routes' => [1]], 'Expected a string'])]
    #[TestWith([['commands' => [1]], 'Expected a string'])]
    #[TestWith([['messages' => [1]], 'Expected a string'])]
    #[TestWith([['routes' => ['']], 'cannot contain an empty value'])]
    #[TestWith([['spans' => ['whitelist' => 'request ']], 'Expected null or a list of prefixes'])]
    #[TestWith([['spans' => ['blacklist' => 'sql ']], 'Expected null or a list of prefixes'])]
    #[TestWith([['spans' => ['blacklist' => ['a' => 'sql ']]], 'Expected null or a list of prefixes'])]
    #[TestWith([['spans' => ['blacklist' => [1]]], 'Expected null or a list of prefixes'])]
    public function testInvalidListsAreRejected(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process($config);
    }

    public function testTheBundleRegistersItsPasses(): void
    {
        $container = new ContainerBuilder();

        (new ProfilingBundle())->build($container);

        $passes = $container->getCompilerPassConfig();
        $beforeOptimization = array_map(get_class(...), $passes->getBeforeOptimizationPasses());
        $beforeRemoving = array_map(get_class(...), $passes->getBeforeRemovingPasses());
        self::assertContains(ProfileDoctrineDbalPass::class, $beforeOptimization);
        self::assertContains(ProfileHttpClientPass::class, $beforeOptimization);
        self::assertContains(RegisterGuzzleMiddlewarePass::class, $beforeOptimization);
        self::assertContains(ProfileGuzzlePass::class, $beforeRemoving);
    }

    /**
     * DoctrineBundle's MiddlewaresPass has the default priority 0; registered first, it would run first at equal
     * priority.
     */
    public function testTheDbalPassRunsBeforeAPassOfDefaultPriorityRegisteredEarlier(): void
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new MarkerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);

        (new ProfilingBundle())->build($container);

        $ordered = array_map(get_class(...), $container->getCompilerPassConfig()->getBeforeOptimizationPasses());
        self::assertLessThan(array_search(MarkerPass::class, $ordered, true), array_search(ProfileDoctrineDbalPass::class, $ordered, true));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function process(array $config, AutoSpanRequirements $requirements = new AutoSpanRequirements([])): array
    {
        $extension = (new ProfilingBundle($requirements))->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);

        return (new Processor())->processConfiguration($configuration, [$config]);
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function section(array $config, string $key): array
    {
        $section = $config[$key] ?? null;
        self::assertIsArray($section);

        return $section;
    }
}
