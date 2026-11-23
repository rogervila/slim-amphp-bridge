<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Example;

use Slim\App;

/** @param App<*> $app */
function registerTodoRoutes(App $app): void
{
    $app->get('/', [TodoController::class, 'redirectToIndex']);
    $app->get('/todos', [TodoController::class, 'index']);
    $app->post('/todos', [TodoController::class, 'create']);
    $app->get('/todos/{id}', [TodoController::class, 'show']);
    $app->patch('/todos/{id}', [TodoController::class, 'update']);
    $app->delete('/todos/{id}', [TodoController::class, 'delete']);
}
