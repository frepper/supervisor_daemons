<?php
namespace Bozoslivehere\SupervisorDaemonBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Class Daemon
 *
 * @ORM\Table(name="supervisor_daemon")
 * @ORM\Entity
 */
class Daemon
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var integer
     *
     * @ORM\Column(name="pid", type="integer", nullable=true)
     */
    private $pid;

    /**
     * @var string
     *
     * @ORM\Column(name="name", type="string", length=255)
     */
    private $name;

    /**
     * @var string
     *
     * @ORM\Column(name="host", type="string", length=255)
     */
    private $host;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="last_checkin", type="datetime", nullable=true)
     */
    private $lastCheckin;

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return int
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * @param int $pid
     * @return Daemon
     */
    public function setPid(int $pid): Daemon
    {
        $this->pid = $pid;
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return Daemon
     */
    public function setName(string $name): Daemon
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @param string $host
     * @return Daemon
     */
    public function setHost(string $host): Daemon
    {
        $this->host = $host;
        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getLastCheckin(): \DateTime
    {
        return $this->lastCheckin;
    }

    /**
     * @param \DateTime $lastCheckin
     * @return Daemon
     */
    public function setLastCheckin(\DateTime $lastCheckin): Daemon
    {
        $this->lastCheckin = $lastCheckin;
        return $this;
    }


}