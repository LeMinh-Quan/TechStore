<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        if (($request->attributes->get('api_account')['type'] ?? null) !== 'customer') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
