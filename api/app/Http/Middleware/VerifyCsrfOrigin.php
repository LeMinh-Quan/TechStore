<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCsrfOrigin
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');
        $referer = $request->headers->get('Referer');

        if ($origin === null && $referer === null) {
            return $next($request);
        }

        $requestOrigin = $this->normalizeOrigin($origin ?? $referer, $origin !== null);
        $allowedOrigins = array_merge(
            (array) config('cors.allowed_origins', []),
            [(string) config('app.url')],
        );

        foreach ($allowedOrigins as $allowedOrigin) {
            if ($requestOrigin !== null && $requestOrigin === $this->normalizeOrigin($allowedOrigin)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Request origin is not allowed.'], 403);
    }

    private function normalizeOrigin(?string $value, bool $isOriginHeader = false): ?string
    {
        if (! is_string($value) || trim($value) === '' || strtolower(trim($value)) === 'null') {
            return null;
        }

        $parts = parse_url(trim($value));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if ($isOriginHeader && (isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/'))) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }
}
