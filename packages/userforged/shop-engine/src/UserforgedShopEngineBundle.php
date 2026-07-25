<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class UserforgedShopEngineBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('order_class')
            ->isRequired()
            ->cannotBeEmpty()
            ->info('FQCN of the host order class, implementing Userforged\ShopEngine\OrderInterface.')
            ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/workflow.yaml');
        $container->import('../config/messenger.yaml');

        $builder->prependExtensionConfig('framework', [
            'workflows' => [
                'shop_order' => [
                    'supports' => [$this->resolveOrderClass($builder)],
                ],
            ],
        ]);
    }

    /** @param array<array-key, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.yaml');
    }

    private function resolveOrderClass(ContainerBuilder $builder): string
    {
        $orderClass = null;

        foreach ($builder->getExtensionConfig('userforged_shop_engine') as $config) {
            if (isset($config['order_class']) && '' !== $config['order_class']) {
                $orderClass = $config['order_class'];
            }
        }

        if (null === $orderClass) {
            throw new InvalidConfigurationException('The "userforged_shop_engine.order_class" configuration option is required — set it to the FQCN of your host order class, implementing Userforged\ShopEngine\OrderInterface.');
        }

        return $orderClass;
    }
}
