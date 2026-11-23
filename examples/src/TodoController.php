<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Example;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rogervila\SlimAmphpBridge\Http\ResponseAs;

final class TodoController
{
    /** @var array<array-key, Todo> */
    private array $todos = [];

    private int $nextId = 1;

    public function redirectToIndex(ResponseInterface $response): ResponseInterface
    {
        return ResponseAs::redirect($response, '/todos');
    }

    public function index(ResponseInterface $response): ResponseInterface
    {
        $todos = array_map(static fn (Todo $todo) => (array) $todo, array_values($this->todos));

        return ResponseAs::json($response, $todos);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = self::readInput($request, $response);
        if ($input instanceof ResponseInterface) {
            return $input;
        }
        if ($input['title'] === null) {
            return self::error($response, 422, 'Provide a title for the todo.');
        }

        $id = (string) $this->nextId++;
        $todo = $this->todos[$id] = new Todo($id, $input['title'], $input['completed'] ?? false);

        return ResponseAs::json($response, (array) $todo, status: 201)->withHeader('Location', '/todos/' . $id);
    }

    public function show(string $id, ResponseInterface $response): ResponseInterface
    {
        $todo = $this->todos[$id] ?? null;
        if ($todo === null) {
            return self::error($response, 404, 'Todo not found.');
        }

        return ResponseAs::json($response, (array) $todo);
    }

    public function update(
        string $id,
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $todo = $this->todos[$id] ?? null;
        if ($todo === null) {
            return self::error($response, 404, 'Todo not found.');
        }

        $input = self::readInput($request, $response);
        if ($input instanceof ResponseInterface) {
            return $input;
        }

        $todo = $this->todos[$id] = new Todo(
            $id,
            $input['title'] ?? $todo->title,
            $input['completed'] ?? $todo->completed,
        );

        return ResponseAs::json($response, (array) $todo);
    }

    public function delete(string $id, ResponseInterface $response): ResponseInterface
    {
        if (!isset($this->todos[$id])) {
            return self::error($response, 404, 'Todo not found.');
        }
        unset($this->todos[$id]);

        return ResponseAs::noContent($response);
    }

    public static function error(ResponseInterface $response, int $status, string $message): ResponseInterface
    {
        return ResponseAs::json($response, ['error' => $message], status: $status);
    }

    /** @return array{title: string|null, completed: bool|null}|ResponseInterface */
    private static function readInput(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): array|ResponseInterface {
        [$contentType] = explode(';', $request->getHeaderLine('Content-Type'), 2);
        if (strtolower(trim($contentType)) !== 'application/json') {
            return self::error($response, 415, 'Use Content-Type: application/json.');
        }

        $body = $request->getBody()->getContents();
        try {
            $input = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::error($response, 400, 'The request body must contain valid JSON.');
        }
        if (!is_array($input) || !str_starts_with(ltrim($body), '{')) {
            return self::error($response, 400, 'The request body must be a JSON object.');
        }
        if (array_diff(array_keys($input), ['title', 'completed']) !== []) {
            return self::error($response, 422, 'Only title and completed are supported.');
        }

        $title = $input['title'] ?? null;
        if (array_key_exists('title', $input) && (!is_string($title) || trim($title) === '')) {
            return self::error($response, 422, 'Title must be a non-blank string.');
        }
        $completed = $input['completed'] ?? null;
        if (array_key_exists('completed', $input) && !is_bool($completed)) {
            return self::error($response, 422, 'Completed must be a boolean.');
        }

        return [
            'title' => is_string($title) ? trim($title) : null,
            'completed' => is_bool($completed) ? $completed : null,
        ];
    }
}
