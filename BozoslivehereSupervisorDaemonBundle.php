<?php

namespace Bozoslivehere\SupervisorDaemonBundle;

use Bozoslivehere\SupervisorDaemonBundle\DependencyInjection\Compiler\DaemonPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class BozoslivehereSupervisorDaemonBundle extends Bundle
{
    /**
     * @inheritdoc
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
        $container->addCompilerPass(new DaemonPass());
    }
}
