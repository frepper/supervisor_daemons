<?php

namespace Bozoslivehere\SupervisorDaemonBundle\Helpers;

class ShellHelper
{

    /**
     * Exit code of the last command ran
     *
     * @var integer
     */
    private $status;

    /**
     * Output of the last command ran
     *
     * @var array
     */
    private $output;

    private function command($command)
    {
        $this->output = [];
        $this->status = null;
        exec($command, $this->output, $this->status);
        return $this;
    }

    /**
     * run
     *
     * Runs a linux command
     *
     * @param string $command The command to run
     * @return ShellHelper
     */
    public function run($command)
    {
        return $this->command($command . ' 2>&1');
    }

    /**
     * runInBackground
     *
     * Runs a linux command without waiting for results
     *
     * @param string $command The command to run
     * @return ShellHelper
     */
    public function runInBackground($command)
    {
        return $this->command($command . ' > /dev/null 2>/dev/null &');
    }

    /**
     *
     * @return array The output of the last ran command
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     *
     * @return integer The exit code of the last ran command
     */
    public function getStatus()
    {
        return $this->status;
    }

}