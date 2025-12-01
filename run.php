<?php

require __DIR__.'/vendor/autoload.php';

use Pierstoval\Threads\Run;
use Symfony\Component\Console\Application;

$application = new Application('echo', '1.0.0');
$command = new Run();
$application->addCommand($command);
$application->setDefaultCommand($command->getName(), true);
$application->run();
