<?php

namespace Bozoslivehere\SupervisorDaemonBundle\Daemons\Supervisor;

use Bozoslivehere\SupervisorDaemonBundle\Entity\Daemon;
use Bozoslivehere\SupervisorDaemonBundle\Helpers\ShellHelper;
use Bozoslivehere\SupervisorDaemonBundle\Utils\Utils;
use Doctrine\ORM\EntityManager;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class SupervisorDaemon
{

    const ONE_MINUTE = 60000000;
    const FIVE_MINUTES = SupervisorDaemon::ONE_MINUTE * 5;
    const TEN_MINUTES = SupervisorDaemon::ONE_MINUTE * 10;
    const TWELVE_MINUTES = SupervisorDaemon::ONE_MINUTE * 12;
    const ONE_HOUR = SupervisorDaemon::ONE_MINUTE * 60 * 60;
    const ONE_DAY = SupervisorDaemon::ONE_HOUR * 24;

    const STATUS_UNKOWN = 'UNKNOWN';
    const STATUS_RUNNING = 'RUNNING';
    const STATUS_STOPPED = 'STOPPED';
    const STATUS_FATAL = 'FATAL';

    const STATUSES = [
        self::STATUS_UNKOWN,
        self::STATUS_RUNNING,
        self::STATUS_STOPPED,
        self::STATUS_FATAL
    ];

    protected $terminated = false;
    protected $torndown = false;
    protected $timeout = SupervisorDaemon::FIVE_MINUTES;
    protected $options = [];
    protected $maxIterations = 100;
    private $currentIteration = 0;
    protected $paused = false;
    protected $pid;
    private static $daemons = [];
    protected static $name;
    protected static $extension = '.conf';
    protected static $errors = [];

    static protected $sigHandlers = [
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
     * @param int $logLevel
     */
    public function __construct(ContainerInterface $container, $logLevel = Logger::ERROR)
    {
        $this->setContainer($container);
        $this->pid = getmypid();
        $this->logger = $this->initializeLogger();
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
                $this->logger->addInfo(static::getName() . ' is pauzed, skipping iterate');
            } else {
                $this->checkin();
                try {
                    $this->iterate();
                } catch (\Exception $error) {
                    if (!empty($this->logger)) {
                        $this->logger->addError($error->getMessage(), [$error]);
                    }
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
     * Mainly for test purposes
     *
     * @param array $options
     */
    public function runOnce($options = [])
    {
        $this->options = array_merge($this->options, $options);
        $this->setup();
        try {
            $this->iterate();
        } catch (\Exception $error) {
            if (!empty($this->logger)) {
                $this->logger->addError($error->getMessage(), [$error]);
            }
        }
        $this->teardown();
    }

    /**
     * Records activity in the db
     *
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function checkin()
    {
        /** @var EntityManager $manager */
        $manager = $this->getManager();
        /** @var Daemon $daemon */
        $daemon = $manager->getRepository('BozoslivehereSupervisorDaemonBundle:Daemon')->findOneBy([
            'name' => static::getName(),
            'host' => gethostname()
        ]);
        if (empty($daemon)) {
            $this->terminate('Daemon not found in database.', 'error');
        } else {
            $now = new \DateTime('now', new \DateTimeZone("UTC"));
            $daemon->setPid(getmypid())->setLastCheckin($now);
            $manager->persist($daemon);
            $manager->flush();
        }
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
     * Set up logger with rotating file, will rotate daily and saves up to $maxFiles files
     *
     * @return Logger
     */
    protected function initializeLogger($maxFiles = 10)
    {
        $logger = new Logger(static::getName() . '_logger');
        $logger->pushHandler(new RotatingFileHandler($this->getLogFilename(), $maxFiles, Logger::INFO, true, 0777));
        $logger->info('Setting up: ' . get_called_class(), ['pid' => $this->pid]);
        return $logger;
    }

    /**
     * Gets an entity manager, will also reconnect if connection was lost somehow
     *
     * @return EntityManager
     */
    protected function getManager()
    {
        /** @var EntityManager $manager */
        $manager = $this->container->get('doctrine.orm.entity_manager');
        if ($manager->getConnection()->ping() === false) {
            $manager->getConnection()->close();
            $manager->getConnection()->connect();
        }
        return $manager;
    }

    /**
     * Sets the service container
     * @param ContainerInterface $container
     * @return SupervisorDaemon
     */
    public function setContainer(ContainerInterface $container): SupervisorDaemon
    {
        $this->container = $container;
        return $this;
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
     * Must be implemented by extenders.
     * Will be called every $timeout microseconds.
     *
     * @return mixed
     */
    abstract protected function iterate();

    /**
     * @return string service id for this daemon
     */
    public static function getName() {
        return static::$name;
    }

    public static function setName($name) {
        static::$name = $name;
    }

    public static function setDaemonId($id) {
        self::$daemons[] = $id;
    }

    public static function getDaemonIds() {
        return self::$daemons;
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
            $this->logger->info('Torn down', ['pid' => $this->pid]);
            $this->torndown = true;
        }
    }

    /**
     * Terminates main loop and logs a message if present
     *
     * @param $message
     * @param string $state either 'info', 'debug' or 'error'
     */
    public function terminate($message, $state = 'info')
    {
        if (!empty($message)) {
            switch ($state) {
                case 'info':
                    $this->logger->info($message, ['pid' => $this->pid]);
                    break;
                case 'debug':
                    $this->logger->debug($message, ['pid' => $this->pid]);
                    break;
                case 'error':
                    $this->logger->error($message, ['pid' => $this->pid]);
                    break;
            }
        }
        $this->terminated = true;
    }

    /**
     * Gets the symfony log directory appended with hostname for use on shared network drives used by load balanced servers
     *
     * @return string
     */
    protected function getLogDir()
    {
        $logFileDir =
            $this->container->get('kernel')->getLogDir() .
            DIRECTORY_SEPARATOR . 'daemons' . DIRECTORY_SEPARATOR .
            Utils::cleanUpString(gethostname()) . DIRECTORY_SEPARATOR;
        if (!is_dir($logFileDir)) {
            mkdir($logFileDir, 0777, true);
        }
        return $logFileDir;
    }

    /**
     * Gets the full log filename
     * @return string
     */
    protected function getLogFilename()
    {
        $logFilename = $this->getLogDir() . $this->getName() . '.log';
        return $logFilename;
    }

    //======================================= (static) management functions =================================

    /**
     * Gets the full name of the supervisor configuration file
     *
     * @return string
     */
    public static function getConfName()
    {
        return '/etc/supervisor/conf.d/' . static::getName() . static::$extension;
    }

    /**
     * Parses output of a supervisor shell command and returns the status as reported by supervisor
     *
     * @param ShellHelper $shell
     * @return string
     */
    private static function parseStatus(ShellHelper $shell)
    {
        $status = static::STATUS_UNKOWN;
        $output = $shell->getOutput();
        if (!empty($output[0])) {
            $parts = preg_split('/\s+/', $output[0]);
            if (!empty($parts[1])) {
                if ($parts[1]) {
                    if (in_array($parts[1], static::STATUSES)) {
                        $status = $parts[1];
                    }
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
    public static function getStatus()
    {
        $shell = new ShellHelper();
        $shell->run('supervisorctl status ' . static::getName());
        $status = static::parseStatus($shell);
        return $status;
    }

    /**
     * Build a supervisor config file from a template
     *
     * @param $baseDir
     * @param $supervisorLogDir
     * @return bool|mixed|string
     */
    protected static function buildConf($baseDir, $supervisorLogDir)
    {
        $conf = file_get_contents(__DIR__ . '/confs/template' . static::$extension);
        $conf = str_replace('{binDir}', $baseDir . '/bin', $conf);
        $conf = str_replace('{daemonName}', static::getName(), $conf);
        $logFile = $supervisorLogDir . static::getName() . '.log';
        $conf = str_replace('{logFile}', $logFile, $conf);
        return $conf;
    }

    /**
     * Builds our configuration file and copies it to /etc/supervisor/conf.d and
     * adds us to the daemons table
     *
     * @param ContainerInterface $container
     * @param bool $uninstallFirst
     * @return bool
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public static function install(ContainerInterface $container, $name, $uninstallFirst = false)
    {
        static::setName($name);
        $baseDir = $container->get('kernel')->getRootDir() . '/..';
        $supervisorLogDir = $container->get('kernel')->getLogDir() .
            DIRECTORY_SEPARATOR . 'daemons' . DIRECTORY_SEPARATOR .
            Utils::cleanUpString(gethostname()) . DIRECTORY_SEPARATOR .
            'supervisor' . DIRECTORY_SEPARATOR;
        if (!is_dir($supervisorLogDir)) {
            mkdir($supervisorLogDir, 0777, true);
        }
        $conf = static::buildConf($baseDir, $supervisorLogDir);
        $destination = static::getConfName();
        if ($uninstallFirst && static::isInstalled()) {
            static::uninstall($container);
        }
        if (file_put_contents($destination, $conf) === false) {
            static::error('Conf could not be copied to ' . $destination);
            return false;
        }
        static::reload();
        /** @var EntityManager $manager */
        $manager = $container->get('doctrine.orm.entity_manager');
        /** @var Daemon $daemon */
        $daemon = $manager->getRepository('BozoslivehereSupervisorDaemonBundle:Daemon')->findOneBy([
            'name' => static::getName(),
            'host' => gethostname()
        ]);
        if (empty($daemon)) {
            $now = new \DateTime('now', new \DateTimeZone("UTC"));
            $daemon = new Daemon();
            $daemon
                ->setName(static::getName())
                ->setHost(gethostname())
                ->setLastCheckin($now);
            $manager->persist($daemon);
            $manager->flush();
        }
        return static::isInstalled();
    }

    /**
     * Removes us from the daemons table and deletes the configuration file
     *
     * @param ContainerInterface $container
     * @return bool
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public static function uninstall(ContainerInterface $container, $name)
    {
        static::setName($name);
        static::stop();
        $conf = static::getConfName();
        if (!unlink($conf)) {
            static::error($conf . ' could not be deleted');
            return false;
        }
        static::reload();
        /** @var EntityManager $manager */
        $manager = $container->get('doctrine.orm.entity_manager');
        /** @var Daemon $daemon */
        $daemon = $manager->getRepository('BozoslivehereSupervisorDaemonBundle:Daemon')->findOneBy([
            'name' => static::getName(),
            'host' => gethostname()
        ]);
        if (!empty($daemon)) {
            $manager->remove($daemon);
            $manager->flush();
        }
        return true;
    }

    /**
     * Retrieves our process id from the daemons table
     *
     * @param ContainerInterface $container
     * @return int
     */
    public static function getPid(ContainerInterface $container)
    {
        $pid = 0;
        /** @var EntityManager $manager */
        $manager = $container->get('doctrine.orm.entity_manager');
        /** @var Daemon $daemon */
        $daemon = $manager->getRepository('BozoslivehereSupervisorDaemonBundle:Daemon')->findOneBy([
            'name' => static::getName(),
            'host' => gethostname()
        ]);
        if (!empty($daemon)) {
            $pid = $daemon->getPid();
        }
        return $pid;
    }

    /**
     * Tests if we are up and running
     * @return bool
     */
    public static function isRunning()
    {
        return static::getStatus() == static::STATUS_RUNNING;
    }

    /**
     * Tests if we are stopped
     * @return bool
     */
    public static function isStopped()
    {
        return static::getStatus() == static::STATUS_STOPPED;
    }

    /**
     * Tests if our configuration file exists
     * @return bool
     */
    public static function isInstalled()
    {
        return file_exists(static::getConfName());
    }

    /**
     * Tests if supervisor stopped us because of failure
     *
     * @return bool
     */
    public static function isFailed()
    {
        return static::getStatus() == static::STATUS_FATAL;
    }

    /**
     * Tells supervisor to reload our configs
     */
    public static function reload()
    {
        $shell = new ShellHelper();
        $shell->run('supervisorctl update');
    }

    /**
     * Tells supervisor we are ready to rock
     *
     * @return bool
     */
    public static function start()
    {
        $shell = new ShellHelper();
        $shell->run('supervisorctl start ' . static::getName());
        return static::isRunning();
    }

    /**
     * Tells supervisor to stop us as soon as possible
     *
     * @return bool
     */
    public static function stop()
    {
        $shell = new ShellHelper();
        $shell->run('supervisorctl stop ' . static::getName());
        return static::isStopped();
    }

    /**
     * Collects errors when in managment stage
     * @param string $error
     */
    protected static function error($error)
    {
        static::$errors[] = $error;
    }

    /**
     * Clears all errors
     */
    public static function clearErrors()
    {
        static::$errors = [];
    }

    /**
     * Retrieves all errors and whipes the slate
     * @return array
     */
    public static function getErrors()
    {
        $errors = static::$errors;
        static::$errors = [];
        return $errors;
    }

}
