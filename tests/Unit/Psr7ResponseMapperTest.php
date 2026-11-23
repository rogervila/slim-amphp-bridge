<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Rogervila\SlimAmphpBridge\Bridge\Psr7ResponseMapper;

use function Amp\ByteStream\buffer;

final class Psr7ResponseMapperTest extends TestCase
{
    public function testMapsStatusHeadersAndFullSeekableBody(): void
    {
        $response = (new Psr17Factory())->createResponse(201)
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('X-Value', ['one', 'two'])
            ->withAddedHeader('Set-Cookie', 'first=one; Path=/')
            ->withAddedHeader('Set-Cookie', 'second=two; Path=/');
        $response->getBody()->write("one\0two");

        $result = (new Psr7ResponseMapper())->toAmphp($response);

        self::assertSame(201, $result->getStatus());
        self::assertSame('application/octet-stream', $result->getHeader('Content-Type'));
        self::assertSame(['one', 'two'], $result->getHeaderArray('X-Value'));
        self::assertSame(['first=one; Path=/', 'second=two; Path=/'], $result->getHeaderArray('Set-Cookie'));
        self::assertSame("one\0two", buffer($result->getBody()));
    }

    public function testReadsNonSeekableBodyWithoutRewinding(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(false);
        $stream->expects(self::never())->method('rewind');
        $stream->method('getContents')->willReturn('remaining bytes');

        $response = (new Psr17Factory())->createResponse()->withBody($stream);
        $result = (new Psr7ResponseMapper())->toAmphp($response);

        self::assertSame('remaining bytes', buffer($result->getBody()));
    }

    #[DataProvider('bodylessResponses')]
    public function testContentLengthForBodylessResponses(int $status, ?string $length, ?string $expected): void
    {
        $response = (new Psr17Factory())->createResponse($status);
        if ($length !== null) {
            $response = $response->withHeader('Content-Length', $length);
        }

        $result = (new Psr7ResponseMapper())->toAmphp($response);

        self::assertSame($status, $result->getStatus());
        self::assertSame($expected, $result->getHeader('Content-Length'));
        self::assertSame('', buffer($result->getBody()));
    }

    /** @return iterable<string, array{int, ?string, ?string}> */
    public static function bodylessResponses(): iterable
    {
        yield 'no content' => [204, '0', null];
        yield 'not modified without metadata' => [304, null, null];
        yield 'not modified with a representation length' => [304, '42', '42'];
    }
}
