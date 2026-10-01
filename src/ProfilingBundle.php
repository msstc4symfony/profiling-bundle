<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle;

use Override;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class ProfilingBundle extends AbstractBundle
{
    protected string $extensionAlias = 'msstc4symfony_profiling';

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
