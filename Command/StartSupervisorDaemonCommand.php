<?php

namespace Bozoslivehere\SupervisorDaemonBundle\Command;

use Bozoslivehere\SupervisorDaemonBundle\Daemons\Supervisor\SupervisorDaemon;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StartSupervisorDaemonCommand extends ContainerAwareCommand {
    
    private $daemons = [];
    private $aborted = false;

    protected function configure() {
        $this
                ->setName('bozos:daemons:start')
                ->setDescription('start a daemon')
                ->addArgument(
                        'id', InputArgument::REQUIRED, 'service id of the daemon to start'
                )
                ->addOption('stop')
        ;
    }
    
    protected function getDaemons() {
        $daemons = $this->getContainer()->getParameter('bozoslivehere_supervisor_daemon_daemons');
        $result = [];
        foreach ($daemons as $name) {
            $names[] = $name;
            $class = get_class($this->getContainer()->get($name));
            $result[$name] = $class;
        }
        return $result;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        if ($this->aborted) {
            return;
        }
        $daemonName = $input->getArgument('id');
        $daemons = $this->getDaemons();
        if (!isset($daemons[$daemonName])) {
            $output->write("Daemon not found: $daemonName");
            return;
        }
        $stopping = $input->getOption('stop');
        if ($stopping) {
            exec('supervisorctl stop ' . $daemonName);
        } else {
            $daemon = new $daemons[$daemonName]($this->getContainer(), Logger::INFO);
            $daemon->run();
        }
        
    }

}