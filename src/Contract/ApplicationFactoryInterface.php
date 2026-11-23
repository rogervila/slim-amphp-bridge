<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Contract;

use Slim\App;

interface ApplicationFactoryInterface
{
    /**
     * Build the application, including its routes and middleware.
     *
     * @return App<*>
     */
    public function create(): App;
}
