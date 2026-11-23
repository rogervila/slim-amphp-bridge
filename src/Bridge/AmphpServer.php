<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Bridge;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\HttpServerStatus;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\SocketHttpServer;

final class AmphpServer
{
    /** @var DeferredFuture<null>|null */
    private ?DeferredFuture $stopped = null;

    public function __construct(
        private readonly SocketHttpServer $server,
        private readonly RequestHandler $requestHandler,
    ) {
    }

    public function start(): void
    {
        $this->server->start($this->requestHandler, new DefaultErrorHandler());
        $this->stopped = new DeferredFuture();
    }

    public function getAddress(): ?string
    {
        if ($this->server->getStatus() !== HttpServerStatus::Started) {
            return null;
        }

        return ($this->server->getServers()[0] ?? null)?->getAddress()->toString();
    }

    public function stop(): void
    {
        if ($this->server->getStatus() === HttpServerStatus::Stopping) {
            $this->stopped?->getFuture()->await();

            return;
        }

        try {
            $this->server->stop();
        } finally {
            $stopped = $this->stopped;
            $this->stopped = null;
            $stopped?->complete();
        }
    }

    /**
     * Serve requests until stop() is called or cancellation is requested.
     */
    public function run(?Cancellation $shutdown = null): void
    {
        if ($shutdown?->isRequested()) {
            return;
        }

        $this->start();
        $stopped = $this->stopped;

        try {
            $stopped?->getFuture()->await($shutdown);
        } catch (CancelledException) {
            // Cancellation requests graceful shutdown.
        } finally {
            if ($this->stopped === $stopped) {
                $this->stop();
            }
        }
    }
}
