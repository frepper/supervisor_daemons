<?php

namespace Bozoslivehere\SupervisorDaemonBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class InstallSupervisorDaemonCommand extends ContainerAwareCommand
{

    private $daemonName = '';
    private $aborted = false;
    private $daemons = [];
    private $start = false;
    private $uninstall = false;

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setName('bozos:daemons:install')
            ->setDescription('install or uninstall a daemon')
            ->addArgument(
                'name', InputArgument::OPTIONAL, 'name of the daemon to (re-) install'
            )
            ->addOption('uninstall')
            ->addOption('start')
            ->addOption('all');
    }

    /**
     * @inheritdoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->daemonName = $input->getArgument('name');
        $this->uninstall = $input->getOption('uninstall');
        $this->start = $input->getOption('start');
        if (!$input->isInteractive() && empty($this->daemonName) && !$input->getOption('all')) {
            $output->writeln('<error>No daemon specified</error>');
            $this->aborted = true;
        }
    }

    private function getDaemons()
    {
        $daemonChain = $this->getContainer()->get('bozoslivehere_supervisor_daemon.daemon_chain');
        return $daemonChain->getDaemons();
    }

    /**
     * @inheritdoc
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        if ($this->aborted) {
            return;
        }
        $daemonName = $this->daemonName;
        $uninstall = $this->uninstall;
        if (!$input->getOption('all') && empty($daemonName)) {
            $daemons = $this->getDaemons();
            if (empty($daemons)) {
                $output->writeln('No daemons found, plz specify them in your services config.');
                $this->aborted = true;
                return;
            }
            $names = [];
            $output->writeln('');
            foreach ($daemons as $name => $service) {
                $names[] = $name;
                $output->writeln($name . ' (' . get_class($service) . ')');
                $this->daemons[$name] = $service;
            }
            $output->writeln('');
            $helper = $this->getHelper('question');
            if ($uninstall) {
                $question = new Question('Please enter the id of the daemon to uninstall: ');
            } else {
                $question = new Question('Please enter the id of the daemon to (re-) install: ');
            }
            $question->setAutocompleterValues($names);
            $daemonName = $helper->ask($input, $output, $question);
        }
        $this->daemonName = $daemonName;
        if (!$this->uninstall) {
            $start = $this->start;
            if (!$start) {
                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion("Do you want to start {$this->daemonName}?", true);
                $start = $helper->ask($input, $output, $question);
            }
            $this->start = $start;
        }
    }

    /**
     * Very verbose daemon handling
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param $daemonName
     */
    protected function handleDaemon(InputInterface $input, OutputInterface $output, $daemonName)
    {
        $daemon = $this->daemons[$daemonName];
        if ($daemon->isInstalled()) {
            $output->writeln("<info>{$daemonName} is currently installed</info>");
            if ($daemon->isRunning()) {
                $output->writeln("<info>{$daemonName} is currently running</info>");
                if ($daemon->stop()) {
                    $output->writeln("<info>{$daemonName} was stopped</info>");
                } else {
                    $output->writeln("<error>{$daemonName} could not be stopped</error>");
                    return;
                }
            } else {
                $output->writeln("<info>{$daemonName} is currently not running</info>");
            }
            if ($daemon->uninstall($this->getContainer(), $daemonName)) {
                $output->writeln("<info>{$daemonName} was uninstalled</info>");
            } else {
                $output->writeln("<error>{$daemonName} could not be uninstalled</error>");
                return;
            }
        } else {
            $output->writeln("<info>{$daemonName} is currently not installed</info>");
        }
        if (!$input->getOption('uninstall')) {
            if ($daemon->install($this->getContainer(), $daemonName)) {
                $output->writeln("<info>{$daemonName} was installed</info>");
                if ($this->start) {
                    if ($daemon->start()) {
                        $output->writeln("<info>{$daemonName} was started</info>");
                    } else {
                        $output->writeln("<error>{$daemonName} was NOT started</error>");
                    }
                }
            } else {
                $output->writeln("<error>{$daemonName} was NOT installed</error>");
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->aborted) {
            $output->writeln('<error>Aborting mission!</error>');
            return;
        }
        if ($input->getOption('all')) {
            foreach ($this->daemons as $name => $daemon) {
                $this->handleDaemon($input, $output, $name);
            }
        } else {
            $this->handleDaemon($input, $output, $this->daemonName);
        }
    }

}
