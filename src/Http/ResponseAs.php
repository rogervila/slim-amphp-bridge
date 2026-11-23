<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Http;

use Amp\Http\HttpStatus;
use InvalidArgumentException;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

final class ResponseAs
{
    public static function json(ResponseInterface $response, mixed $data, ?int $status = null): ResponseInterface
    {
        return self::content($response, json_encode($data, JSON_THROW_ON_ERROR), 'application/json', $status);
    }

    public static function text(ResponseInterface $response, string $text, ?int $status = null): ResponseInterface
    {
        return self::content($response, $text, 'text/plain; charset=utf-8', $status);
    }

    public static function html(ResponseInterface $response, string $html, ?int $status = null): ResponseInterface
    {
        return self::content($response, $html, 'text/html; charset=utf-8', $status);
    }

    public static function redirect(
        ResponseInterface $response,
        string|UriInterface $location,
        int $status = HttpStatus::FOUND,
    ): ResponseInterface {
        if (!in_array($status, [
            HttpStatus::MOVED_PERMANENTLY,
            HttpStatus::FOUND,
            HttpStatus::SEE_OTHER,
            HttpStatus::TEMPORARY_REDIRECT,
            HttpStatus::PERMANENT_REDIRECT,
        ], true)) {
            throw new InvalidArgumentException('A redirect requires HTTP status 301, 302, 303, 307, or 308.');
        }

        $location = (string) $location;
        if ($location === '' || preg_match('/[\x00-\x20\x7f]/', $location) === 1) {
            throw new InvalidArgumentException('Redirect location must be non-empty and contain no spaces or controls.');
        }

        return self::replaceBody($response->withStatus($status), '')
            ->withoutHeader('Content-Type')
            ->withHeader('Location', $location);
    }

    public static function noContent(ResponseInterface $response): ResponseInterface
    {
        return self::replaceBody($response->withStatus(HttpStatus::NO_CONTENT), '')
            ->withoutHeader('Content-Type');
    }

    private static function content(
        ResponseInterface $response,
        string $body,
        string $contentType,
        ?int $status,
    ): ResponseInterface {
        $code = $status ?? $response->getStatusCode();
        if (
            HttpStatus::isInformational($code)
            || in_array($code, [HttpStatus::NO_CONTENT, HttpStatus::RESET_CONTENT, HttpStatus::NOT_MODIFIED], true)
        ) {
            throw new InvalidArgumentException("HTTP status {$code} does not allow a response body.");
        }

        if ($status !== null) {
            $response = $response->withStatus($status);
        }

        return self::replaceBody($response, $body)->withHeader('Content-Type', $contentType);
    }

    private static function replaceBody(ResponseInterface $response, string $body): ResponseInterface
    {
        return $response->withBody(Stream::create($body))
            ->withoutHeader('Content-Length')
            ->withoutHeader('Content-Encoding')
            ->withoutHeader('Content-Range')
            ->withoutHeader('Transfer-Encoding')
            ->withoutHeader('Trailer');
    }
}
