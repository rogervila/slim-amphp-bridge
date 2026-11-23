<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Bridge;

use Amp\Http\HttpStatus;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class SlimAmphpBridge implements RequestHandler
{
    public function __construct(
        private readonly RequestHandlerInterface $slimApp,
        private readonly Psr7RequestMapper $requestMapper,
        private readonly Psr7ResponseMapper $responseMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handleRequest(Request $request): Response
    {
        try {
            $psrRequest = $this->requestMapper->toPsr7($request);

            return $this->responseMapper->toAmphp($this->slimApp->handle($psrRequest));
        } catch (ClientException $exception) {
            // The HTTP driver owns disconnects and request-body size violations.
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->error('Slim request failed', ['exception' => $exception]);

            return new Response(
                HttpStatus::INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'application/json'],
                '{"error":"internal_server_error"}',
            );
        }
    }
}
