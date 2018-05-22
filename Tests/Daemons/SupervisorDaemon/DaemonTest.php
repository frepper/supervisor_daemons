<?php
/**
 * Created by PhpStorm.
 * User: alfons
 * Date: 5/18/2018
 * Time: 17:22
 */

namespace Tests\Daemons\SupervisorDaemon;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DaemonTest extends KernelTestCase
{
    public function testDaemons() {
        $this->assertTrue(true);
    }
}