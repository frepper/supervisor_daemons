Supervisor daemons
===

A Symfony 3 bundle to run background processes using Supervisor

Requirements
---
This bundle relies on Symfony 3 and Supervisor (http://supervisord.org/), a wrapper for ubuntu/debian's systemd startup services.
It needs write permissions in the /etc/supervisor/conf.d directory.

Setup
---
Add the repo to composer.json (a packagist package will come in the near future)
```
...
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:alfons56/supervisor_daemon.git"
        }
    ],
...
```
require as usual:
```
$ composer require bozoslivehere/supervisor-daemon-bundle
```
and let your kernel know about it:
(AppKernel.php)
```
class AppKernel extends Kernel {

    public function registerBundles() {
        $bundles = [
            ...
            new Bozoslivehere\SupervisorDaemonBundle\BozoslivehereSupervisorDaemonBundle(),
        ]
    }
}
```
If you wish to prefix table names you can do so in the config:
```
...
bozoslivehere_supervisor_daemon:
    table_prefix: 'foo_'
```

Don't forget to update your schema!


Simple usage
---
Create your daemon class specifying the timeout between iterations and the number of iterations 
after which your daemon should restart
```
<?php
namespace AppBundle\Daemons;

use Bozoslivehere\SupervisorDaemonBundle\Daemons\Supervisor\SupervisorDaemon;

class FooDaemon extends SupervisorDaemon
{
    protected $timeout = self::ONE_MINUTE;

    protected $maxIterations = 100;

    protected function iterate()
    {
        // fly the Kessel Run in as many parsecs as you need...
        $this->logger->info('we are doing some heavy lifting right here!!');
    }
}
?>
```

and add it as a service using the supervisor_damon tag:
(services.yml)
```
services:
    app.foo_daemon:
        class: AppBundle\Daemons\FooDaemon
        arguments: ['@service_container']
        tags:
            - { name: bozoslivehere.supervisor_daemon }
```
Now simply install and start your service from the symfony console:
```
$ bin/console bozos:daemons:install
```
and you should be prompted to install and start!

The daemon will report any logging in your <project directory>/var/logs/daemons/hostname/ directory.

To be continued..............