<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Bridge;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Response;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

final class Psr7ResponseMapper
{
    public function toAmphp(ResponseInterface $response): Response
    {
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $status = $response->getStatusCode();
        $mapped = new Response($status, body: $stream->getContents());
        foreach ($response->getHeaders() as $name => $values) {
            $name = (string) $name;
            if ($name === '') {
                throw new InvalidArgumentException('Response header name must not be empty.');
            }
            $mapped->setHeader($name, $values);
        }

        if (
            $status < HttpStatus::OK
            || $status === HttpStatus::NO_CONTENT
            || ($status === HttpStatus::NOT_MODIFIED && !$response->hasHeader('Content-Length'))
        ) {
            $mapped->removeHeader('Content-Length');
        }

        return $mapped;
    }
}
