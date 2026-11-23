<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Example;

use DI\Bridge\Slim\Bridge;
use DI\Container;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rogervila\SlimAmphpBridge\Contract\ApplicationFactoryInterface;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Throwable;

final class ApplicationFactory implements ApplicationFactoryInterface
{
    /** @return App<*> */
    public function create(): App
    {
        $factory = new Psr17Factory();
        $container = new Container();
        $container->set(ResponseFactoryInterface::class, $factory);
        $app = Bridge::create($container);
        require_once __DIR__ . '/../routes.php';
        registerTodoRoutes($app);

        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false)->setDefaultErrorHandler(fn (
            ServerRequestInterface $request,
            Throwable $exception,
        ): ResponseInterface => self::errorResponse($factory->createResponse(), $exception));

        $app->add(function (
            ServerRequestInterface $request,
            RequestHandlerInterface $handler,
        ): ResponseInterface {
            $requestId = $request->getHeaderLine('X-Request-Id');
            $response = $handler->handle($request);

            return $requestId === '' ? $response : $response->withHeader('X-Request-Id', $requestId);
        });

        return $app;
    }

    private static function errorResponse(ResponseInterface $response, Throwable $exception): ResponseInterface
    {
        if ($exception instanceof HttpMethodNotAllowedException) {
            $response = $response->withHeader('Allow', implode(', ', $exception->getAllowedMethods()));
        }
        if ($exception instanceof HttpException && $exception->getCode() >= 400 && $exception->getCode() < 500) {
            return TodoController::error($response, $exception->getCode(), $exception->getMessage());
        }

        return TodoController::error($response, 500, 'An unexpected server error occurred.');
    }
}
