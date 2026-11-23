<?php

declare(strict_types=1);

namespace Rogervila\SlimAmphpBridge\Bridge;

use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class Psr7RequestMapper
{
    public function __construct(
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function toPsr7(Request $request): ServerRequestInterface
    {
        $uri = $request->getUri();
        $query = $uri->getQuery();
        $target = $uri->getPath() === '' ? '/' : $uri->getPath();
        if ($query !== '') {
            $target .= '?' . $query;
        }

        $serverParams = [
            'REQUEST_METHOD' => $request->getMethod(),
            'REQUEST_URI' => $target,
            'QUERY_STRING' => $query,
            'SERVER_PROTOCOL' => 'HTTP/' . $request->getProtocolVersion(),
        ];
        $client = $request->getClient();
        $remote = $client->getRemoteAddress();
        if ($remote instanceof InternetAddress) {
            $serverParams['REMOTE_ADDR'] = $remote->getAddress();
            $serverParams['REMOTE_PORT'] = $remote->getPort();
        }
        $local = $client->getLocalAddress();
        if ($local instanceof InternetAddress) {
            $serverParams['SERVER_ADDR'] = $local->getAddress();
            $serverParams['SERVER_PORT'] = $local->getPort();
        }
        if ($uri->getScheme() === 'https') {
            $serverParams['HTTPS'] = 'on';
        }

        parse_str($query, $queryParams);
        $cookies = [];
        foreach ($request->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        $psrRequest = $this->requestFactory->createServerRequest($request->getMethod(), $uri, $serverParams)
            ->withProtocolVersion($request->getProtocolVersion())
            ->withQueryParams($queryParams)
            ->withCookieParams($cookies)
            ->withBody($this->streamFactory->createStream($request->getBody()->buffer()));

        // Some PSR-17 implementations read process headers when given server parameters.
        foreach (array_keys($psrRequest->getHeaders()) as $name) {
            $psrRequest = $psrRequest->withoutHeader((string) $name);
        }
        foreach ($request->getHeaders() as $name => $values) {
            $psrRequest = $psrRequest->withHeader((string) $name, $values);
        }
        if (!$psrRequest->hasHeader('Host') && $uri->getHost() !== '') {
            $port = $uri->getPort();
            $host = $port === null ? $uri->getHost() : $uri->getHost() . ':' . $port;
            $psrRequest = $psrRequest->withHeader('Host', $host);
        }
        foreach ($request->getAttributes() as $name => $value) {
            $psrRequest = $psrRequest->withAttribute((string) $name, $value);
        }

        return $psrRequest;
    }
}
