<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Tests\Unit;

use InvalidArgumentException;
use JsonException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rogervila\SlimAmphpBridge\Http\ResponseAs;

final class ResponseAsTest extends TestCase
{
    public function testJsonReplacesTheBodyWhilePreservingOtherHeadersAndTheInputStream(): void
    {
        $response = (new Psr17Factory())->createResponse()
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Length', '8')
            ->withHeader('Content-Encoding', 'gzip')
            ->withHeader('Content-Range', 'bytes 0-7/16')
            ->withHeader('Transfer-Encoding', 'chunked')
            ->withHeader('Trailer', 'Digest')
            ->withHeader('Set-Cookie', ['first=one', 'second=two']);
        $response->getBody()->write('old body');
        $response->getBody()->seek(2);

        $result = ResponseAs::json($response, ['id' => 42], status: 201);

        self::assertSame(201, $result->getStatusCode());
        self::assertSame('application/json', $result->getHeaderLine('Content-Type'));
        self::assertSame('{"id":42}', $result->getBody()->getContents());
        self::assertSame(['first=one', 'second=two'], $result->getHeader('Set-Cookie'));
        foreach (['Content-Length', 'Content-Encoding', 'Content-Range', 'Transfer-Encoding', 'Trailer'] as $header) {
            self::assertTrue($response->hasHeader($header));
            self::assertFalse($result->hasHeader($header));
        }
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame(2, $response->getBody()->tell());
        self::assertSame('old body', (string) $response->getBody());
    }

    public function testJsonAcceptsScalarAndNullValues(): void
    {
        $response = (new Psr17Factory())->createResponse();

        self::assertSame('false', ResponseAs::json($response, false)->getBody()->getContents());
        self::assertSame('null', ResponseAs::json($response, null)->getBody()->getContents());
    }

    public function testJsonThrowsOnEncodingFailure(): void
    {
        $response = (new Psr17Factory())->createResponse();

        $this->expectException(JsonException::class);
        $this->expectExceptionCode(JSON_ERROR_UTF8);

        ResponseAs::json($response, "\xff");
    }

    public function testTextAndHtmlUseUtf8AndSupportCustomStatuses(): void
    {
        $response = (new Psr17Factory())->createResponse(202);

        $text = ResponseAs::text($response, 'Café');
        self::assertSame(202, $text->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $text->getHeaderLine('Content-Type'));
        self::assertSame('Café', $text->getBody()->getContents());

        $html = ResponseAs::html($response, '<h1>Café</h1>', status: 400);
        self::assertSame(400, $html->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $html->getHeaderLine('Content-Type'));
        self::assertSame('<h1>Café</h1>', $html->getBody()->getContents());
    }

    #[DataProvider('statusesWithoutContent')]
    public function testRejectsAStatusThatProhibitsContent(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("HTTP status {$status} does not allow a response body.");

        ResponseAs::text((new Psr17Factory())->createResponse($status), 'body');
    }

    /** @return iterable<array{int}> */
    public static function statusesWithoutContent(): iterable
    {
        yield [103];
        yield [204];
        yield [205];
        yield [304];
    }

    public function testRedirectUsesTheRequestedLocationAndClearsThePreviousBody(): void
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(201)
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('Content-Length', '3')
            ->withHeader('Location', '/old');
        $response->getBody()->write('old');

        $default = ResponseAs::redirect($response, '/dashboard/42');
        self::assertSame(302, $default->getStatusCode());
        self::assertSame('/dashboard/42', $default->getHeaderLine('Location'));
        self::assertSame('', $default->getBody()->getContents());
        self::assertFalse($default->hasHeader('Content-Type'));
        self::assertFalse($default->hasHeader('Content-Length'));
        self::assertSame('old', (string) $response->getBody());

        $redirect = ResponseAs::redirect($response, $factory->createUri('https://example.test/items'), status: 303);
        self::assertSame(303, $redirect->getStatusCode());
        self::assertSame('https://example.test/items', $redirect->getHeaderLine('Location'));
    }

    #[DataProvider('invalidRedirects')]
    public function testRejectsInvalidRedirects(string $location, int $status): void
    {
        $this->expectException(InvalidArgumentException::class);

        ResponseAs::redirect((new Psr17Factory())->createResponse(), $location, $status);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidRedirects(): iterable
    {
        yield 'invalid status' => ['/valid', 304];
        yield 'empty location' => ['', 302];
        yield 'unescaped space' => ['/a b', 302];
        yield 'control characters' => ["/a\r\nX-Example: injected", 302];
    }

    public function testNoContentClearsTheBodyAndItsHeaders(): void
    {
        $response = (new Psr17Factory())->createResponse()
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('Content-Length', '3')
            ->withHeader('X-Request-Id', '123');
        $response->getBody()->write('old');

        $result = ResponseAs::noContent($response);

        self::assertSame(204, $result->getStatusCode());
        self::assertSame('', $result->getBody()->getContents());
        self::assertFalse($result->hasHeader('Content-Type'));
        self::assertFalse($result->hasHeader('Content-Length'));
        self::assertSame('123', $result->getHeaderLine('X-Request-Id'));
        self::assertSame('old', (string) $response->getBody());
    }
}
