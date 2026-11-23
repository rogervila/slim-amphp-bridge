<?php

declare(strict_types=1);

use Revolt\EventLoop;
use Rogervila\SlimAmphpBridge\Bridge\AmphpServerFactory;
use Rogervila\SlimAmphpBridge\Example\ApplicationFactory;

require __DIR__ . '/../../vendor/autoload.php';

$port = filter_var($argv[1] ?? '1337', FILTER_VALIDATE_INT);
if ($port === false) {
    throw new InvalidArgumentException('The listening port must be an integer.');
}

$server = AmphpServerFactory::create(new ApplicationFactory(), port: $port);
$server->start();

try {
    fwrite(STDOUT, 'Listening on http://' . $server->getAddress() . PHP_EOL);
    EventLoop::run();
} finally {
    $server->stop();
}
