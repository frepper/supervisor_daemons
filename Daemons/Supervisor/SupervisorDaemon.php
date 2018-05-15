<?php

namespace Bozoslivehere\SupervisorDaemonBundle\Daemons\Supervisor;

use Doctrine\ORM\EntityManager;
use Bozoslivehere\SupervisorDaemonBundle\Entity\Daemon;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Bozoslivehere\SupervisorDaemonBundle\Utils\Utils;
use Bozoslivehere\SupervisorDaemonBundle\Helpers\ShellHelper;

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
    private static $daemons;
    protected static $extension = '.conf';

    static protected $_sigHandlers = [
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

    public function __construct(ContainerInterface $container, $logLevel = Logger::ERROR)
    {
        $this->setContainer($container);
        $this->pid = getmypid();
        $this->logger = new Logger(static::getName() . '_logger');
        $this->logger->pushHandler(new RotatingFileHandler($this->getLogFilename(), 10, $logLevel, true, 0777));
        $this->logger->info('Setting up: ' . get_called_class(), ['pid' => $this->pid]);
        foreach (self::$_sigHandlers as $signal => $handler) {
            if (is_string($signal) || !$signal) {
                if (defined($signal) && ($const = constant($signal))) {
                    self::$_sigHandlers[$const] = $handler;
                }
                unset(self::$_sigHandlers[$signal]);
            }
        }
        foreach (self::$_sigHandlers as $signal => $handler) {
            if (!pcntl_signal($signal, $handler)) {
                $this->logger->info('Could not bind signal: ' . $signal);
            }
        }
    }

    public final function defaultSigHandler($signo)
    {
        // receiving any signal will interrupt usleep.....
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

    public function run($options = [])
    {
        // http://stackoverflow.com/questions/14060507/doctrine2-connection-timeout-in-daemon
        $this->options = array_merge($this->options, $options);
        $this->setup();
        while (!$this->terminated) {
            if ($this->paused) {
                $this->logger->addInfo(static::getName() . ' is pauzed, skipping iterate');
            } else {
                $this->checkin();
                $this->currentIteration++;
                try {
                    $this->iterate();
                } catch (\Exception $error) {
                    if (!empty($this->logger)) {
                        $this->logger->addError($error->getMessage(), [$error]);
                    }
                }
            }
            usleep($this->timeout);
            if ($this->currentIteration == $this->maxIterations) {
                $this->terminate('Max iterations reached: ' . $this->maxIterations);
            }
            pcntl_signal_dispatch();
        }
        $this->teardown();
    }

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

    public function checkin()
    {
        /** @var EntityManager $manager */
        $manager = $this->getManager();
        /** @var Daemon $daemon */
        $daemon = $manager->getRepository('IminBundle:Daemon')->findOneBy([
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
     * @return EntityManager
     */
    protected function getManager()
    {
        /** @var EntityManager $manager */
        $manager = $this->getContainer()->get('doctrine.orm.entity_manager');
        if ($manager->getConnection()->ping() === false) {
            $manager->getConnection()->close();
            $manager->getConnection()->connect();
        }
        return $manager;
    }

    /**
     * @return ContainerInterface
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * @param ContainerInterface $container
     * @return SupervisorDaemon
     */
    public function setContainer(ContainerInterface $container): SupervisorDaemon
    {
        $this->container = $container;
        return $this;
    }

    protected function reloadConfig()
    {
    }

    protected function setup()
    {
    }

    abstract protected function iterate();

    /**
     * CAREFULL!!! teardown() might not get called on __destroy() :( (kill -9)
     * Please don't do anything important here.
     *
     */
    protected function teardown()
    {
        if (!$this->torndown) {
            $this->logger->info('Torn down', ['pid' => $this->pid]);
            $this->torndown = true;
        }
    }

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

    protected function getLogDir()
    {
        $logFileDir =
            $this->getContainer()->get('kernel')->getLogDir() .
            DIRECTORY_SEPARATOR . 'daemons' . DIRECTORY_SEPARATOR .
            Utils::cleanUpString(gethostname()) . DIRECTORY_SEPARATOR;
        if (!is_dir($logFileDir)) {
            mkdir($logFileDir, 0777, true);
        }
        return $logFileDir;
    }

    protected function getLogFilename()
    {
        $logFilename = $this->getLogDir() . $this->getName() . '.log';
        return $logFilename;
    }

    public function __destruct()
    {
        $this->teardown();
    }

    // management functions

    public static final function getName()
    {
        if (get_called_class() == get_class()) {
            // dont treat abstract IminDaemon as a valid daemon
            return null;
        }
        $classname = get_called_class();
        $slashPos = strrpos($classname, '\\');
        return Utils::decamelize(substr($classname, $slashPos + 1));
    }

    public static function getConfName()
    {
        return '/etc/supervisor/conf.d/' . static::getName() . static::$extension;
    }

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

    public static function getStatus()
    {
        $shell = new ShellHelper();
        $shell->run('supervisorctl status ' . static::getName());
        $status = static::parseStatus($shell);
        return $status;
    }

    protected static function buildConf($baseDir, $supervisorLogDir)
    {
        $conf = file_get_contents(__DIR__ . '/confs/' . 'template' . static::$extension);
        $conf = str_replace('{binDir}', $baseDir . '/bin', $conf);
        $conf = str_replace('{daemonName}', static::getName(), $conf);
        $logFile = $supervisorLogDir . static::getName() . '.log';
        $conf = str_replace('{logFile}', $logFile, $conf);
        return $conf;
    }

    public static function install(ContainerInterface $container, $uninstallFirst = false)
    {
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
        $daemon = $manager->getRepository('IminBundle:Daemon')->findOneBy([
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

    public static function uninstall(ContainerInterface $container)
    {
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
        $daemon = $manager->getRepository('IminBundle:Daemon')->findOneBy([
            'name' => static::getName(),
            'host' => gethostname()
        ]);
        if (!empty($daemon)) {
            $manager->remove($daemon);
            $manager->flush();
        }
        return true;
    }

    public static function getPid(ContainerInterface $container)
    {
        $pid = 0;
        /** @var EntityManager $manager */
        $manager = $container->get('doctrine.orm.entity_manager');
        /** @var Daemon $daemon */
        $daemon = $manager->getRepository('IminBundle:Daemon')->findOneBy([
            'name' => static::getName(),
            'host' => gethostname()
        ]);
        if (!empty($daemon)) {
            $pid = $daemon->getPid();
        }
        return $pid;
    }

    public static function isRunning()
    {
        return static::getStatus() == static::STATUS_RUNNING;
    }

    public static function isStopped()
    {
        return static::getStatus() == static::STATUS_STOPPED;
    }

    public static function isInstalled()
    {
        return file_exists(static::getConfName());
    }

    public static function isFailed()
    {
        return static::getStatus() == static::STATUS_FATAL;
    }

    public static function reload()
    {
        $shell = new ShellHelper();
        $shell->run('supervisorctl update');
    }

    public static function start()
    {
        $shell = new ShellHelper();
        $shell->run('supervisorctl start ' . static::getName());
        return static::isRunning();
    }

    public static function stop()
    {
        $shell = new ShellHelper();
        $shell->run('supervisorctl stop ' . static::getName());
        return static::isStopped();
    }

    private static function error($error)
    {
        // TODO: get logger from container
    }

}
