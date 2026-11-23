<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Tests\Integration;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\splitLines;

final class ExampleEntrypointTest extends TestCase
{
    public function testEntrypointStartsAndServesTheTodoCollection(): void
    {
        $process = Process::start([PHP_BINARY, __DIR__ . '/../../examples/public/index.php', '0']);

        try {
            $url = self::listeningUrl($process);
            $response = HttpClientBuilder::buildDefault()->request(new Request($url), new TimeoutCancellation(5));

            $redirect = $response->getPreviousResponse();
            self::assertNotNull($redirect);
            self::assertSame(302, $redirect->getStatus());
            self::assertSame('/todos', $redirect->getHeader('Location'));
            self::assertSame(200, $response->getStatus());
            self::assertSame('application/json', $response->getHeader('Content-Type'));
            self::assertSame('[]', $response->getBody()->buffer());
        } finally {
            $process->kill();
            $process->join(new TimeoutCancellation(10));
        }
    }

    private static function listeningUrl(Process $process): string
    {
        foreach (splitLines($process->getStdout(), new TimeoutCancellation(10)) as $line) {
            if (preg_match('~^Listening on (http://127\.0\.0\.1:[1-9][0-9]*)$~D', $line, $matches) === 1) {
                return $matches[1];
            }
        }

        self::fail('The example exited before reporting its listening address.');
    }
}
