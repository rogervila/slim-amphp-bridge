<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Tests\Integration;

use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Server\Driver\HttpDriver;
use Amp\TimeoutCancellation;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Rogervila\SlimAmphpBridge\Bridge\AmphpServer;
use Rogervila\SlimAmphpBridge\Bridge\AmphpServerFactory;
use Rogervila\SlimAmphpBridge\Contract\ApplicationFactoryInterface;
use Rogervila\SlimAmphpBridge\Example\ApplicationFactory;
use Rogervila\SlimAmphpBridge\Http\ResponseAs;
use RuntimeException;
use Slim\App;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

final class SlimAmphpBridgeTest extends TestCase
{
    public function testAwaitsOverlappingOperationsWithinAnHttpRequest(): void
    {
        $app = new App(new Psr17Factory());
        $app->get('/concurrent', static function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface {
            /** @var DeferredFuture<null> $secondStarted */
            $secondStarted = new DeferredFuture();
            $first = async(static function () use ($secondStarted): string {
                $secondStarted->getFuture()->await(new TimeoutCancellation(3));

                return 'first';
            });
            $second = async(static function () use ($secondStarted): string {
                $secondStarted->complete();

                return 'second';
            });

            $results = await(['first' => $first, 'second' => $second]);

            return ResponseAs::json($response, [$results['first'], $results['second']]);
        });
        $factory = $this->createStub(ApplicationFactoryInterface::class);
        $factory->method('create')->willReturn($app);
        $server = AmphpServerFactory::create($factory, port: 0);
        $server->start();

        try {
            $response = HttpClientBuilder::buildDefault()->request(
                new Request(self::baseUrl($server) . '/concurrent'),
                new TimeoutCancellation(5),
            );

            self::assertSame(200, $response->getStatus());
            self::assertSame('["first","second"]', $response->getBody()->buffer());
        } finally {
            $server->stop();
        }
    }

    public function testLogsUnhandledFailureAndKeepsServing(): void
    {
        $failure = new RuntimeException('sensitive failure details');
        $app = new App(new Psr17Factory());
        $app->get('/error', static fn (): never => throw $failure);
        $app->get('/', static fn () => (new Psr17Factory())->createResponse());
        $factory = $this->createStub(ApplicationFactoryInterface::class);
        $factory->method('create')->willReturn($app);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Slim request failed', ['exception' => $failure]);
        $server = AmphpServerFactory::create($factory, port: 0, logger: $logger);
        $client = HttpClientBuilder::buildDefault();
        $server->start();

        try {
            $url = self::baseUrl($server);
            $response = $client->request(new Request($url . '/error'), new TimeoutCancellation(5));
            self::assertSame(500, $response->getStatus());
            self::assertSame('application/json', $response->getHeader('Content-Type'));
            self::assertSame('{"error":"internal_server_error"}', $response->getBody()->buffer());

            $healthy = $client->request(new Request($url . '/'), new TimeoutCancellation(5));
            self::assertSame(200, $healthy->getStatus());
            self::assertSame('', $healthy->getBody()->buffer());
        } finally {
            $server->stop();
        }
    }

    public function testResponseHelpersSendARedirectAndAnEmpty204OverTcp(): void
    {
        $app = new App(new Psr17Factory());
        $app->get('/redirect', static fn (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface => ResponseAs::redirect($response, '/completed', status: 303));
        $app->get('/completed', static fn (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface => ResponseAs::noContent($response));
        $factory = $this->createStub(ApplicationFactoryInterface::class);
        $factory->method('create')->willReturn($app);
        $server = AmphpServerFactory::create($factory, port: 0);
        $server->start();

        try {
            $response = HttpClientBuilder::buildDefault()->request(
                new Request(self::baseUrl($server) . '/redirect'),
                new TimeoutCancellation(5),
            );

            $redirect = $response->getPreviousResponse();
            self::assertNotNull($redirect);
            self::assertSame(303, $redirect->getStatus());
            self::assertSame('/completed', $redirect->getHeader('Location'));
            self::assertSame(204, $response->getStatus());
            self::assertSame('', $response->getBody()->buffer());
            self::assertNull($response->getHeader('Content-Length'));
            self::assertNull($response->getHeader('Content-Type'));
        } finally {
            $server->stop();
        }
    }

    public function testAmphpRejectsOversizedBodiesWithAClientError(): void
    {
        $server = AmphpServerFactory::create(new ApplicationFactory(), port: 0);
        $server->start();

        try {
            $request = new Request(self::baseUrl($server) . '/todos', 'POST');
            $request->setProtocolVersions(['1.1']);
            $request->setBody(str_repeat('x', HttpDriver::DEFAULT_BODY_SIZE_LIMIT + 1));

            $response = HttpClientBuilder::buildDefault()->request($request, new TimeoutCancellation(5));

            self::assertSame(413, $response->getStatus());
            $response->getBody()->buffer();
        } finally {
            $server->stop();
        }
    }

    public function testRunWaitsForRequestsAndStopsOnCancellation(): void
    {
        $shutdown = new DeferredCancellation();
        $server = AmphpServerFactory::create(new ApplicationFactory(), port: 0);
        $request = async(static function () use ($server, $shutdown): void {
            try {
                $response = HttpClientBuilder::buildDefault()->request(
                    new Request(self::awaitBaseUrl($server) . '/todos'),
                    new TimeoutCancellation(5),
                );
                self::assertSame(200, $response->getStatus());
                self::assertSame('[]', $response->getBody()->buffer());
            } finally {
                $shutdown->cancel();
            }
        });

        $server->run($shutdown->getCancellation());
        $request->await();

        self::assertNull($server->getAddress());
    }

    public function testRunReturnsAfterExplicitStop(): void
    {
        $server = AmphpServerFactory::create(new ApplicationFactory(), port: 0);
        $timeout = new TimeoutCancellation(5);
        $stop = async(static function () use ($server): void {
            self::awaitBaseUrl($server);
            $server->stop();
        });

        $server->run($timeout);
        $stop->await();

        self::assertFalse($timeout->isRequested());
        self::assertNull($server->getAddress());
    }

    #[DataProvider('invalidAddresses')]
    public function testRejectsInvalidAddressBeforeBooting(string $host, int $port): void
    {
        $factory = $this->createMock(ApplicationFactoryInterface::class);
        $factory->expects(self::never())->method('create');

        $this->expectException(InvalidArgumentException::class);
        AmphpServerFactory::create($factory, $host, $port);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidAddresses(): iterable
    {
        yield 'hostname' => ['invalid.test', 1337];
        yield 'negative port' => ['127.0.0.1', -1];
        yield 'out of range port' => ['127.0.0.1', 65536];
    }

    private static function baseUrl(AmphpServer $server): string
    {
        $address = $server->getAddress();
        self::assertNotNull($address);

        return 'http://' . $address;
    }

    private static function awaitBaseUrl(AmphpServer $server): string
    {
        $timeout = new TimeoutCancellation(5);
        while (($address = $server->getAddress()) === null) {
            delay(0.001, cancellation: $timeout);
        }

        return 'http://' . $address;
    }
}
