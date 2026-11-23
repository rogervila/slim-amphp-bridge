<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Tests\Integration;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\TestCase;
use Rogervila\SlimAmphpBridge\Bridge\AmphpServer;
use Rogervila\SlimAmphpBridge\Bridge\AmphpServerFactory;
use Rogervila\SlimAmphpBridge\Contract\ApplicationFactoryInterface;
use Rogervila\SlimAmphpBridge\Example\ApplicationFactory;

final class TodoApiTest extends TestCase
{
    private const MEDIA_TYPE = 'application/json';

    private AmphpServer $server;
    private HttpClient $client;

    protected function setUp(): void
    {
        $factory = $this->createMock(ApplicationFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturnCallback((new ApplicationFactory())->create(...));
        $this->server = AmphpServerFactory::create($factory, port: 0);
        $this->client = HttpClientBuilder::buildDefault();
        $this->server->start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testTodoLifecycleUsesPlainJsonAcrossRequests(): void
    {
        self::assertSame([], self::document($this->send('GET', '/todos'), 200));

        $created = $this->send(
            'POST',
            '/todos',
            ['title' => '  Buy milk  '],
            ['X-Request-Id' => 'create-todo'],
        );
        $first = ['id' => '1', 'title' => 'Buy milk', 'completed' => false];
        self::assertSame('/todos/1', $created->getHeader('Location'));
        self::assertSame('create-todo', $created->getHeader('X-Request-Id'));
        self::assertSame($first, self::document($created, 201));

        $second = ['id' => '2', 'title' => 'Read', 'completed' => true];
        $created = $this->send('POST', '/todos', ['title' => 'Read', 'completed' => true]);
        self::assertNull($created->getHeader('X-Request-Id'));
        self::assertSame($second, self::document($created, 201));

        self::assertSame($first, self::document($this->send('GET', '/todos/1'), 200));
        self::assertSame([$first, $second], self::document($this->send('GET', '/todos'), 200));

        $first['completed'] = true;
        self::assertSame($first, self::document($this->send('PATCH', '/todos/1', ['completed' => true]), 200));
        self::assertSame($first, self::document($this->send('GET', '/todos/1'), 200));
        self::assertSame($first, self::document($this->send('PATCH', '/todos/1', '{}'), 200));

        $first['completed'] = false;
        self::assertSame($first, self::document($this->send('PATCH', '/todos/1', ['completed' => false]), 200));

        $deleted = $this->send('DELETE', '/todos/1');
        self::assertSame(204, $deleted->getStatus());
        self::assertSame('', $deleted->getBody()->buffer());
        self::assertNull($deleted->getHeader('Content-Type'));
        self::assertNull($deleted->getHeader('Content-Length'));
        self::assertError($this->send('GET', '/todos/1'), 404);
        self::assertError($this->send('DELETE', '/todos/1'), 404);
        self::assertSame([$second], self::document($this->send('GET', '/todos'), 200));
    }

    public function testReturnsPlainJsonErrorsAndKeepsFailedWritesOutOfTheStore(): void
    {
        self::assertError($this->send('POST', '/todos', '{'), 400);
        self::assertError($this->send('POST', '/todos', '[]'), 400);
        self::assertError($this->send('POST', '/todos', '{"completed":false}'), 422);
        self::assertError($this->send('POST', '/todos', ['title' => ' ']), 422);
        self::assertError($this->send('POST', '/todos', ['id' => 'custom', 'title' => 'Buy milk']), 422);

        $created = self::document($this->send('POST', '/todos', ['title' => 'Read']), 201);
        self::assertSame('1', $created['id']);
        self::assertError($this->send('PATCH', '/todos/1', ['id' => '2']), 422);
        self::assertError($this->send('PATCH', '/todos/1', ['completed' => 'yes']), 422);
        self::assertError($this->send('PATCH', '/todos/1', ['title' => null]), 422);
        self::assertSame($created, self::document($this->send('GET', '/todos/1'), 200));

        self::assertError($this->send('GET', '/missing'), 404);
        $wrongMethod = $this->send('PUT', '/todos/1');
        self::assertSame('GET, PATCH, DELETE', $wrongMethod->getHeader('Allow'));
        self::assertError($wrongMethod, 405);
    }

    public function testWriteRequestsUsePlainJson(): void
    {
        self::assertError($this->send('POST', '/todos', ['title' => 'Read'], ['Content-Type' => 'text/plain']), 415);

        $response = $this->send('POST', '/todos', ['title' => 'Read'], [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
        self::assertSame(['id' => '1', 'title' => 'Read', 'completed' => false], self::document($response, 201));
    }

    /**
     * @param non-empty-string $method
     * @param array<string, mixed>|string|null $body
     * @param array<non-empty-string, string> $headers
     */
    private function send(string $method, string $path, array|string|null $body = null, array $headers = []): Response
    {
        $address = $this->server->getAddress();
        self::assertNotNull($address);
        $request = new Request('http://' . $address . $path, $method);
        $headers += ['Accept' => self::MEDIA_TYPE];
        if ($body !== null) {
            $request->setBody(is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body);
            $headers += ['Content-Type' => self::MEDIA_TYPE];
        }
        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }

        return $this->client->request($request, new TimeoutCancellation(5));
    }

    /** @return array<array-key, mixed> */
    private static function document(Response $response, int $status): array
    {
        self::assertSame($status, $response->getStatus());
        self::assertSame(self::MEDIA_TYPE, $response->getHeader('Content-Type'));
        $document = json_decode($response->getBody()->buffer(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return $document;
    }

    private static function assertError(Response $response, int $status): void
    {
        $document = self::document($response, $status);
        self::assertSame(['error'], array_keys($document));
        self::assertIsString($document['error']);
    }
}
