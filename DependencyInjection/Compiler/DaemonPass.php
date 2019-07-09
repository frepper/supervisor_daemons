<?php

namespace Bozoslivehere\SupervisorDaemonBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class DaemonPass implements CompilerPassInterface
{
    /**
     * @inheritdoc
     */
    public function process(ContainerBuilder $container)
    {
        if (!$container->has('bozoslivehere_supervisor_daemon.daemon_chain')) {
            return;
        }
        $definition = $container->findDefinition('bozoslivehere_supervisor_daemon.daemon_chain');
        $taggedServices = $container->findTaggedServiceIds('bozoslivehere.supervisor_daemon');
        foreach ($taggedServices as $id => $tags) {
            $daemonReference = new Reference($id);
            $definition->addMethodCall('addDaemon', [$id, $daemonReference]);
            $daemonDefinition = $container->findDefinition($id);
            $daemonDefinition->addMethodCall('setName', [$id]);
        }
    }

}