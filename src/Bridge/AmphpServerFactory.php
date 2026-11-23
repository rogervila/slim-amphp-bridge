<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Bridge;

use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketException;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rogervila\SlimAmphpBridge\Contract\ApplicationFactoryInterface;

final class AmphpServerFactory
{
    public static function create(
        ApplicationFactoryInterface $applicationFactory,
        string $host = '127.0.0.1',
        int $port = 1337,
        ?LoggerInterface $logger = null,
        ?ServerRequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): AmphpServer {
        if ($port < 0 || $port > 65535) {
            throw new InvalidArgumentException('Port must be between 0 and 65535.');
        }

        try {
            $address = new InternetAddress($host, $port);
        } catch (SocketException $exception) {
            throw new InvalidArgumentException('Host must be an IPv4 or IPv6 address.', previous: $exception);
        }

        $logger ??= new NullLogger();
        $psr17 = new Psr17Factory();
        $server = SocketHttpServer::createForDirectAccess($logger, allowedMethods: null);
        $server->expose($address);

        return new AmphpServer(
            $server,
            new SlimAmphpBridge(
                $applicationFactory->create(),
                new Psr7RequestMapper($requestFactory ?? $psr17, $streamFactory ?? $psr17),
                new Psr7ResponseMapper(),
                $logger,
            ),
        );
    }
}
