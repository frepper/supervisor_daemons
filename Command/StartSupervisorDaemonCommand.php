<?php

namespace Bozoslivehere\SupervisorDaemonBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StartSupervisorDaemonCommand extends ContainerAwareCommand
{

    private $daemons = [];
    private $aborted = false;

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setName('bozos:daemons:start')
            ->setDescription('start a daemon')
            ->addArgument(
                'id', InputArgument::REQUIRED, 'service id of the daemon to start'
            )
            ->addOption('stop');
    }

    private function getDaemons()
    {
        $daemonChain = $this->getContainer()->get('bozoslivehere_supervisor_daemon.daemon_chain');
        return $daemonChain->getDaemons();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
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
            $daemons[$daemonName]->run();
        }

    }

}