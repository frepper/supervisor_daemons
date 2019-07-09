<?php

namespace Bozoslivehere\SupervisorDaemonBundle\Daemons\Supervisor;

use Bozoslivehere\SupervisorDaemonBundle\Entity\Daemon;
use Bozoslivehere\SupervisorDaemonBundle\Utils\Utils;
use Doctrine\ORM\EntityManager;
use Gedmo\Loggable\Loggable;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Process;

abstract class SupervisorDaemon
{

    const ONE_MINUTE = 60000000;
    const FIVE_MINUTES = self::ONE_MINUTE * 5;
    const TEN_MINUTES = self::ONE_MINUTE * 10;
    const TWELVE_MINUTES = self::ONE_MINUTE * 12;
    const ONE_HOUR = self::ONE_MINUTE * 60 * 60;
    const ONE_DAY = self::ONE_HOUR * 24;

    const STATUS_UNKOWN = 'UNKNOWN';
    const STATUS_RUNNING = 'RUNNING';
    const STATUS_STOPPED = 'STOPPED';
    const STATUS_FATAL = 'FATAL';
    const STATUS_STARTING = 'STARTING';

    const STATUSES = [
        self::STATUS_UNKOWN,
        self::STATUS_RUNNING,
        self::STATUS_STOPPED,
        self::STATUS_FATAL,
        self::STATUS_STARTING
    ];

    const EXIT_STATE_INFO = 'info';
    const EXIT_STATE_DEBUG = 'debug';
    const EXIT_STATE_ERROR = 'error';

    protected $terminated = false;
    protected $torndown = false;
    protected $timeout = self::FIVE_MINUTES;
    protected $options = [];
    protected $maxIterations = 100;
    protected $paused = false;
    protected $pid;
    protected $name;
    protected static $extension = '.conf';
    protected $errors = [];

    private $currentIteration = 0;
    private $daemons = [];

    protected $shouldCheckin = true;

    protected $autostart = true;

    protected static $sigHandlers = [
        SIGHUP => array(__CLASS__, 'defaultSigHandler'),
        SIGINT => array(__CLASS__, 'defaultSigHandler'),
        SIGUSR1 => array(__CLASS__, 'defaultSigHandler'),
        SIGUSR2 => array(__CLASS__, 'defaultSigHandler'),
        SIGTERM => array(__CLASS__, 'defaultSigHandler')
    ];

    /**
     *
     * @var \Monolog\Logger
     */
    protected $logger = null;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * SupervisorDaemon constructor.
     * @param ContainerInterface $container
     * @param Logger $logger
     */
    public function __construct(ContainerInterface $container, Logger $logger)
    {
        $this->container = $container;
        $this->pid = getmypid();
        $this->logger = $logger;
        $this->attachHandlers();
    }

    /**
     * SupervisorDaemon destructor.
     * There's no garantee this will be called, when the daemon is stopped by 'kill -9' for example
     */
    public function __destruct()
    {
        $this->teardown();
    }

    /**
     * Any incoming pcntl signals will be handled here
     * receiving any signal will interrupt usleep
     *
     * @param $signo
     */
    public final function defaultSigHandler($signo)
    {
        switch ($signo) {
            case SIGTERM:
                $this->terminate('Received signal: SIGTERM');
                break;
            case SIGHUP:
                $this->terminate('Received signal: SIGHUP');
                break;
            case SIGINT:
                $this->terminate('Received signal: SIGINT');
                break;
            case SIGUSR1: // reload configs
                $this->logger->info('Received signal: SIGUSR1');
                $this->reloadConfig();
                break;
            case SIGUSR2: // restart
                $this->logger->info('Received signal: SIGUSR2');
                $this->terminate('Received SIGUSR2, terminating');
                break;
        }
    }

    /**
     * Our main eventloop, loops until terminated or maxIterations is reached
     *
     * @param array $options
     */
    public function run($options = [])
    {
        $this->options = array_merge($this->options, $options);
        $this->setup();
        while (!$this->terminated) {
            if ($this->paused) {
            } else {
                try {
                    $this->beforeIterate();
                    $this->iterate();
                    $this->afterIterate();
                } catch (\Exception $error) {
                    $this->logger->error($error->getMessage(), [$error]);
                }
            }
            usleep($this->timeout);
            $this->currentIteration++;
            if ($this->currentIteration == $this->maxIterations) {
                $this->terminate('Max iterations reached: ' . $this->maxIterations);
            }
            pcntl_signal_dispatch();
        }
        $this->teardown();
    }

    /**
     * Run only once and quit immediatly
     *
     * @param array $options
     */
    public function runOnce($options = [])
    {
        $this->options = array_merge($this->options, $options);
        $this->setup();
        try {
            $this->beforeIterate();
            $this->iterate();
            $this->afterIterate();
        } catch (\Exception $error) {
            $this->logger->error($error->getMessage(), [$error]);
        }
        $this->teardown();
    }

    /**
     * Attaches all signal handlers defined by $sigHandlers and tests for availability
     */
    protected function attachHandlers()
    {
        foreach (self::$sigHandlers as $signal => $handler) {
            if (is_string($signal) || !$signal) {
                if (defined($signal) && ($const = constant($signal))) {
                    self::$sigHandlers[$const] = $handler;
                }
                unset(self::$sigHandlers[$signal]);
            }
        }
        foreach (self::$sigHandlers as $signal => $handler) {
            if (!pcntl_signal($signal, $handler)) {
                $this->logger->info('Could not bind signal: ' . $signal);
            }
        }
    }

    /**
     * TODO: reload config and restart main loop when SIGUSR1 or 2 is received
     */
    protected function reloadConfig()
    {
    }

    /**
     * Will be called before main loop starts
     */
    protected function setup()
    {
    }

    /**
     * Called before each iteration
     */
    protected function beforeIterate()
    {
    }

    /**
     * Must be implemented by extenders.
     * Will be called every $timeout microseconds.
     *
     * @return mixed
     */
    abstract protected function iterate();

    /**
     * Called after each iteration
     */
    protected function afterIterate()
    {
    }

    /**
     * @return string service id for this daemon
     */
    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Runs when maxIterations is reached or when stop signal is receaved.
     *
     * CAREFULL!!! teardown() might not get called on __destroy() :( (kill -9).
     * Please don't do anything important here..
     */
    protected function teardown()
    {
        if (!$this->torndown) {
            $this->torndown = true;
        }
    }

    /**
     * Terminates main loop and logs a message if present
     *
     * @param $message
     * @param string $state either 'info', 'debug' or 'error'
     */
    public function terminate($message = '', $state = self::EXIT_STATE_INFO)
    {
        if (!empty($message)) {
            switch ($state) {
                case self::EXIT_STATE_INFO:
                    $this->logger->info($message, ['pid' => $this->pid]);
                    break;
                case self::EXIT_STATE_DEBUG:
                    $this->logger->debug($message, ['pid' => $this->pid]);
                    break;
                case self::EXIT_STATE_ERROR:
                    $this->logger->error($message, ['pid' => $this->pid]);
                    break;
                default:
                    $this->logger->error("Illegal termination state: $state with message: $message", ['pid' => $this->pid]);
                    break;
            }
        }
        $this->terminated = true;
    }

    //======================================= management functions =================================

    /**
     * Gets the full name of the supervisor configuration file
     *
     * @return string
     */
    public function getConfName()
    {
        return '/etc/supervisor/conf.d/' . $this->getName() . static::$extension;
    }

    /**
     * Parses output of a supervisor shell command and returns the status as reported by supervisor
     *
     * @param $output
     * @return string
     */
    private function parseStatus($output)
    {
        $output = explode("\n", $output);
        $status = static::STATUS_UNKOWN;
        if (!empty($output[0])) {
            $parts = preg_split('/\s+/', $output[0]);
            if (!empty($parts[1])) {
                if (in_array($parts[1], static::STATUSES)) {
                    $status = $parts[1];
                }
            }
        }
        return $status;
    }

    /**
     * Returns the status as reported by supervisor
     *
     * @return string
     */
    public function getStatus()
    {
        $shell = new Process('supervisorctl status ' . $this->getName());
        $shell->run();
        return $this->parseStatus($shell->getOutput());
    }

    /**
     * Build a supervisor config file from a template
     *
     * @param $baseDir
     * @param $supervisorLogDir
     * @return bool|mixed|string
     */
    protected function buildConf()
    {
        $baseDir = $this->container->get('kernel')->getRootDir() . '/..';
        $supervisorLogDir = $this->container->getParameter('bozoslivehere_supervisor_daemon_supervisor_log_path');
        $logFile = $supervisorLogDir . $this->getName() . '.log';
        $env = $this->container->get('kernel')->getEnvironment();

        $conf = file_get_contents(__DIR__ . '/confs/template' . static::$extension);
        $conf = str_replace('{binDir}', $baseDir . '/bin', $conf);
        $conf = str_replace('{daemonName}', $this->getName(), $conf);
        $conf = str_replace('{logFile}', $logFile, $conf);
        $conf = str_replace('{autostart}', ($this->autostart) ? 'true' : 'false', $conf);
        $conf = str_replace('{env}', $env, $conf);
        return $conf;
    }

    /**
     * Builds our configuration file and copies it to /etc/supervisor/conf.d and
     * adds us to the daemons table
     *
     * @param bool $uninstallFirst
     * @return bool
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function install($uninstallFirst = false)
    {
        $conf = $this->buildConf();
        $destination = $this->getConfName();
        if ($uninstallFirst && $this->isInstalled()) {
            $this->uninstall();
        }
        if (file_put_contents($destination, $conf) === false) {
            $this->logError('Conf could not be copied to ' . $destination);
            return false;
        }
        $path = $this->container->getParameter('bozoslivehere_supervisor_daemon_supervisor_log_path');
        if (!is_dir($path)) {
            if (!mkdir($path, 0777, true)) {
                throw new \Exception('Could not create log dir: ' . $path);
            }
        }
        $this->reload();
        return $this->isInstalled();
    }

    /**
     * Removes us from the daemons table and deletes the configuration file
     *
     * @return bool
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function uninstall()
    {
        $this->stop();
        $conf = $this->getConfName();
        if (!unlink($conf)) {
            $this->logger->error($conf . ' could not be deleted');
            return false;
        }
        $this->reload();
        return true;
    }

    /**
     * Retrieves our process id
     *
     * @return int
     */
    public function getPid()
    {
        return getmypid();
    }

    /**
     * Tests if we are getting ready to run
     * @return bool
     */
    public function isStarting()
    {
        return $this->getStatus() == static::STATUS_STARTING;
    }

    /**
     * Tests if we are up and running
     * @return bool
     */
    public function isRunning()
    {
        return $this->getStatus() == static::STATUS_RUNNING;
    }

    /**
     * Tests if we are up and running or getting ready to rock
     * @return bool
     */
    public function isRunningOrStarting()
    {
        $status = $this->getStatus();
        return $status == static::STATUS_RUNNING || $status == static::STATUS_STARTING;
    }

    /**
     * Tests if we are stopped
     * @return bool
     */
    public function isStopped()
    {
        return $this->getStatus() == static::STATUS_STOPPED;
    }

    /**
     * Tests if our configuration file exists
     * @return bool
     */
    public function isInstalled()
    {
        return file_exists($this->getConfName());
    }

    /**
     * Tests if supervisor stopped us because of failure
     *
     * @return bool
     */
    public function isFailed()
    {
        return $this->getStatus() == static::STATUS_FATAL;
    }

    /**
     * Tells supervisor to reload our configs
     */
    public function reload()
    {
        $shell = new Process('supervisorctl update');
        $shell->run();
    }

    /**
     * Tells supervisor we are ready to rock
     *
     * @return bool
     */
    public function start()
    {
        $shell = new Process('supervisorctl start ' . $this->getName());
        $shell->run();
        return $this->isRunningOrStarting();
    }

    /**
     * Tells supervisor to stop us as soon as possible
     *
     * @return bool
     */
    public function stop()
    {
        $shell = new Process('supervisorctl stop ' . $this->getName());
        $shell->run();
        return $this->isStopped();
    }

    /**
     * Collects errors when in managment stage
     * @param string $error
     */
    protected function error($error)
    {
        $this->errors[] = $error;
    }

    /**
     * Clears all errors
     */
    public function clearErrors()
    {
        $this->errors = [];
    }

    /**
     * Retrieves all errors and whipes the slate
     * @return array
     */
    public function getErrors()
    {
        $errors = $this->errors;
        $this->errors = [];
        return $errors;
    }

    public function isAutostart()
    {
        return $this->autostart;
    }

    public function setAutostart($autostart)
    {
        $this->autostart = $autostart;
    }

    public function cleanHostName($str, $replace = array(), $delimiter = '-')
    {
        if (!empty($replace)) {
            $str = str_replace((array)$replace, '', $str);
        }
        $clean = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
        $clean = preg_replace("/[^a-zA-Z0-9\/_|+ -]/", '', $clean);
        $clean = strtolower(trim($clean, '-'));
        $clean = preg_replace("/[\/_|+ -]+/", $delimiter, $clean);
        return trim($clean);
    }

}
