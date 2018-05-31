<?php

namespace Bozoslivehere\SupervisorDaemonBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('bozoslivehere_supervisor_daemon');

        $rootNode
            ->children()
                ->scalarNode('table_prefix')
                    ->defaultValue('')
                ->end()
                ->scalarNode('log_path')
                    ->defaultValue('%kernel.logs_dir%/daemons/')
                ->end()
                ->scalarNode('supervisor_log_path')
                    ->defaultValue('%kernel.logs_dir%/daemons/supervisor/')
                ->end()
            ->end();
        return $treeBuilder;
    }
}
