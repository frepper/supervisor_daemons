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

//    protected function initializeLogger($logLevel)
//    {
//        $logger = new Logger($this->getName() . '_logger');
//        $logger->pushHandler(new RotatingFileHandler($this->getLogFilename(), 10, $logLevel, true, 0777));
//        $logger->info('Setting up: ' . get_called_class(), ['pid' => $this->pid]);
//        return $logger;
//    }
//
//    protected function getLogDir()
//    {
//        $logFileDir =
//            $this->container->get('kernel')->getLogDir() .
//            DIRECTORY_SEPARATOR . 'daemons' . DIRECTORY_SEPARATOR .
//            $this->cleanHostName(gethostname()) . DIRECTORY_SEPARATOR;
//        if (!is_dir($logFileDir)) {
//            mkdir($logFileDir, 0777, true);
//        }
//        return $logFileDir;
//    }
//
//    protected function getLogFilename()
//    {
//        $logFilename = $this->getLogDir() . $this->getName() . '.log';
//        return $logFilename;
//    }
}