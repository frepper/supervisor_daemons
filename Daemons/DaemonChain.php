<?php

namespace Bozoslivehere\SupervisorDaemonBundle\Daemons;

use Bozoslivehere\SupervisorDaemonBundle\Daemons\Supervisor\SupervisorDaemon;

class DaemonChain
{
    private $daemons;

    public function __construct()
    {
        $this->daemons = [];
    }

    public function addDaemon($id, SupervisorDaemon $daemon)
    {
        $this->daemons[$id] = $daemon;
    }

    public function getDaemons() {
        return $this->daemons;
    }
}