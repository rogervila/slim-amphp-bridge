<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Tests\Unit;

use Amp\ByteStream\ReadableStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use League\Uri\Http;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Rogervila\SlimAmphpBridge\Bridge\Psr7RequestMapper;
use Rogervila\SlimAmphpBridge\Bridge\Psr7ResponseMapper;
use Rogervila\SlimAmphpBridge\Bridge\SlimAmphpBridge;
use RuntimeException;

use function Amp\ByteStream\buffer;

final class SlimAmphpBridgeTest extends TestCase
{
    public function testDelegatesRequestToSlimAndMapsResponse(): void
    {
        $factory = new Psr17Factory();
        $psrResponse = $factory->createResponse(200)->withHeader('Content-Type', 'text/plain');
        $psrResponse->getBody()->write('Hello World');

        $slimApp = $this->createMock(RequestHandlerInterface::class);
        $slimApp->expects(self::once())
            ->method('handle')
            ->with(self::isInstanceOf(ServerRequestInterface::class))
            ->willReturn($psrResponse);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $bridge = new SlimAmphpBridge(
            $slimApp,
            new Psr7RequestMapper($factory, $factory),
            new Psr7ResponseMapper(),
            $logger,
        );

        $amphpRequest = new Request(
            $this->client(),
            'GET',
            Http::new('http://127.0.0.1/test'),
        );

        $response = $bridge->handleRequest($amphpRequest);

        self::assertSame(200, $response->getStatus());
        self::assertSame('text/plain', $response->getHeader('Content-Type'));
        self::assertSame('Hello World', buffer($response->getBody()));
    }

    public function testRethrowsClientExceptionWithoutLogging(): void
    {
        $factory = new Psr17Factory();
        $client = $this->client();
        $clientException = new ClientException($client, 'Payload too large', HttpStatus::PAYLOAD_TOO_LARGE);

        $bodyStream = $this->createMock(ReadableStream::class);
        $bodyStream->method('read')->willThrowException($clientException);

        $slimApp = $this->createMock(RequestHandlerInterface::class);
        $slimApp->expects(self::never())->method('handle');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $bridge = new SlimAmphpBridge(
            $slimApp,
            new Psr7RequestMapper($factory, $factory),
            new Psr7ResponseMapper(),
            $logger,
        );

        $amphpRequest = new Request(
            $client,
            'POST',
            Http::new('http://127.0.0.1/upload'),
            body: $bodyStream,
        );

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Payload too large');

        $bridge->handleRequest($amphpRequest);
    }

    public function testLogsGenericThrowableAndReturns500Response(): void
    {
        $exception = new RuntimeException('Database failure');

        $factory = new Psr17Factory();
        $slimApp = $this->createMock(RequestHandlerInterface::class);
        $slimApp->expects(self::once())
            ->method('handle')
            ->willThrowException($exception);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Slim request failed', ['exception' => $exception]);

        $bridge = new SlimAmphpBridge(
            $slimApp,
            new Psr7RequestMapper($factory, $factory),
            new Psr7ResponseMapper(),
            $logger,
        );

        $amphpRequest = new Request(
            $this->client(),
            'GET',
            Http::new('http://127.0.0.1/error'),
        );

        $response = $bridge->handleRequest($amphpRequest);

        self::assertSame(500, $response->getStatus());
        self::assertSame('application/json', $response->getHeader('Content-Type'));
        self::assertSame('{"error":"internal_server_error"}', buffer($response->getBody()));
    }

    private function client(): Client
    {
        $client = $this->createStub(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress('127.0.0.1', 12345));
        $client->method('getLocalAddress')->willReturn(new InternetAddress('127.0.0.1', 80));

        return $client;
    }
}
