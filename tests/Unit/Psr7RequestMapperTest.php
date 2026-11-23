<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Tests\Unit;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Rogervila\SlimAmphpBridge\Bridge\Psr7RequestMapper;

final class Psr7RequestMapperTest extends TestCase
{
    public function testMapsMetadataAndReadsAllBodyChunks(): void
    {
        $factory = new Psr17Factory();
        $uri = $factory->createUri('https://example.test:8443/a%20b?tags[]=red&tags[]=blue&term=a+b');
        $request = new Request(
            $this->client(),
            'POST',
            $uri,
            [
                'Host' => 'example.test:8443',
                'X-Value' => ['one', 'two'],
                'Cookie' => 'session=sample; preference=blue',
            ],
            new ReadableIterableStream(["one\0", "two\n", 'three']),
            '2',
        );
        $request->setAttribute('example.context', ['userId' => 42]);

        $result = (new Psr7RequestMapper($factory, $factory))->toPsr7($request);

        self::assertSame('POST', $result->getMethod());
        self::assertSame((string) $uri, (string) $result->getUri());
        self::assertSame('/a%20b?tags%5B%5D=red&tags%5B%5D=blue&term=a+b', $result->getRequestTarget());
        self::assertSame('2', $result->getProtocolVersion());
        self::assertSame('example.test:8443', $result->getHeaderLine('Host'));
        self::assertSame(['one', 'two'], $result->getHeader('X-Value'));
        self::assertSame(['tags' => ['red', 'blue'], 'term' => 'a b'], $result->getQueryParams());
        self::assertSame(['session' => 'sample', 'preference' => 'blue'], $result->getCookieParams());
        self::assertSame(['userId' => 42], $result->getAttribute('example.context'));
        self::assertSame("one\0two\nthree", $result->getBody()->getContents());
        self::assertSame([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/a%20b?tags%5B%5D=red&tags%5B%5D=blue&term=a+b',
            'QUERY_STRING' => 'tags%5B%5D=red&tags%5B%5D=blue&term=a+b',
            'SERVER_PROTOCOL' => 'HTTP/2',
            'REMOTE_ADDR' => '192.0.2.7',
            'REMOTE_PORT' => 43210,
            'SERVER_ADDR' => '192.0.2.8',
            'SERVER_PORT' => 8443,
            'HTTPS' => 'on',
        ], $result->getServerParams());
    }

    public function testEmptyRequestUsesOnlyTransportHeadersAndDerivedHost(): void
    {
        $factory = new Psr17Factory();
        $uri = $factory->createUri('http://[::1]:8080');
        $ambientRequest = $factory->createServerRequest('GET', $uri)
            ->withHeader('Cookie', 'session=ambient')
            ->withHeader('Host', 'ambient.test')
            ->withHeader('X-Ambient', 'ignored')
            ->withCookieParams(['session' => 'ambient']);
        $requestFactory = $this->createStub(ServerRequestFactoryInterface::class);
        $requestFactory->method('createServerRequest')->willReturn($ambientRequest);

        $result = (new Psr7RequestMapper($requestFactory, $factory))
            ->toPsr7(new Request($this->client(), 'GET', $uri));

        self::assertSame('/', $result->getRequestTarget());
        self::assertSame(['Host' => ['[::1]:8080']], $result->getHeaders());
        self::assertSame([], $result->getQueryParams());
        self::assertSame([], $result->getCookieParams());
        self::assertSame('', $result->getBody()->getContents());
    }

    private function client(): Client
    {
        $client = $this->createStub(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress('192.0.2.7', 43210));
        $client->method('getLocalAddress')->willReturn(new InternetAddress('192.0.2.8', 8443));

        return $client;
    }
}
