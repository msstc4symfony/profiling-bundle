<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle;

use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpan;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\AutoSpanRequirements;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\ProfileDoctrineDbalPass;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\ProfileGuzzlePass;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\ProfileHttpClientPass;
use Msstc4Symfony\ProfilingBundle\DependencyInjection\CompilerPass\RegisterGuzzleMiddlewarePass;
use Override;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class ProfilingBundle extends AbstractBundle
{
    protected string $extensionAlias = 'msstc4symfony_profiling';

    /**
     * @param AutoSpanRequirements $autoSpanRequirements @internal tests replace the packages an auto span needs
     */
    public function __construct(
        private readonly AutoSpanRequirements $autoSpanRequirements = new AutoSpanRequirements(),
    ) {
    }

    #[Override]
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new ProfileDoctrineDbalPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, ProfileDoctrineDbalPass::PRIORITY);
        $container->addCompilerPass(new ProfileHttpClientPass());
        $container->addCompilerPass(new RegisterGuzzleMiddlewarePass());
        // After ResolveNamedArgumentsPass: a named "$config" argument of a client is at index 0 by then.
        $container->addCompilerPass(new ProfileGuzzlePass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode()->children();
        $this->stringList($root, 'routes', 'Main-request route names that get a "request <route>" span.');
        $this->stringList($root, 'commands', 'Console command names that get a "cli command <name>" span.');
        $this->stringList($root, 'messages', 'Message classes (or their parents/interfaces) that get a "message <class>" span while a worker handles them.', classNames: true);

        $spans = $root->arrayNode('spans')
            ->addDefaultsIfNotSet()
            ->info('Span message prefixes; set at most one list. Unset = record everything.')
            ->validate()
                ->ifTrue(static fn (array $lists): bool => $lists['whitelist'] !== null && $lists['blacklist'] !== null)
                ->thenInvalid('Set either "whitelist" or "blacklist", not both.')
            ->end()
            ->children()
        ;
        foreach (['whitelist', 'blacklist'] as $list) {
            $spans->variableNode($list)
                ->defaultNull()
                ->validate()
                    ->ifTrue(static fn (mixed $value): bool => $value !== null && !self::isStringList($value))
                    ->thenInvalid('Expected null or a list of prefixes, got %s.')
                ->end()
            ;
        }

        $autoSpans = $root->arrayNode('auto_spans')
            ->addDefaultsIfNotSet()
            ->info('Automatic spans for libraries; each needs its library installed.')
            ->children()
        ;
        foreach (AutoSpan::cases() as $autoSpan) {
            $missing = $this->autoSpanRequirements->missingPackages($autoSpan);
            $autoSpans->booleanNode($autoSpan->value)
                ->defaultFalse()
                ->info($autoSpan->info())
                ->validate()
                    ->ifTrue(static fn (mixed $enabled): bool => $enabled === true && $missing !== [])
                    ->thenInvalid(sprintf('"%s" needs %s installed.', $autoSpan->option(), implode(', ', $missing)))
                ->end()
            ;
        }
    }

    /**
     * @param array<array-key, mixed> $config processed by configure(), so every list is validated
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/Resources/config/services.php');

        $container->parameters()
            ->set('msstc4symfony_profiling.routes.whitelist', $config['routes'] ?? [])
            ->set('msstc4symfony_profiling.commands.whitelist', $config['commands'] ?? [])
            ->set('msstc4symfony_profiling.messages.whitelist', $config['messages'] ?? [])
            ->set('msstc4symfony_profiling.spans.whitelist', is_array($config['spans'] ?? null) ? $config['spans']['whitelist'] ?? null : null)
            ->set('msstc4symfony_profiling.spans.blacklist', is_array($config['spans'] ?? null) ? $config['spans']['blacklist'] ?? null : null)
        ;

        $autoSpans = is_array($config['auto_spans'] ?? null) ? $config['auto_spans'] : [];
        foreach (AutoSpan::cases() as $autoSpan) {
            $enabled = $autoSpans[$autoSpan->value] ?? false;
            // An %env()% placeholder reaches here as a string: the flag decides the wiring, so it must be known now.
            if (!is_bool($enabled)) {
                throw new InvalidConfigurationException(sprintf('"%s" must be true or false; an environment variable cannot decide the container wiring.', $autoSpan->option()));
            }

            $container->parameters()->set($autoSpan->option(), $enabled);
        }
    }

    private function stringList(NodeBuilder $parent, string $name, string $info, bool $classNames = false): void
    {
        $prototype = $parent->arrayNode($name)
            ->info($info)
            ->scalarPrototype()
                ->cannotBeEmpty()
        ;
        if ($classNames) {
            // "\App\Foo" must match App\Foo::class.
            $prototype->beforeNormalization()
                ->ifString()
                ->then(static fn (string $value): string => ltrim($value, '\\'))
            ;
        }

        $prototype
                ->validate()
                    ->ifTrue(static fn (mixed $value): bool => !is_string($value))
                    ->thenInvalid('Expected a string, got %s.')
                ->end()
            ->end()
        ;
    }

    private static function isStringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => is_string($item));
    }
}
