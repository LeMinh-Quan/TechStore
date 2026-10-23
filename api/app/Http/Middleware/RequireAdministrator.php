<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->attributes->get('api_account');
        if (($account['type'] ?? null) !== 'admin'
            || ($account['record']->role ?? null) !== 'chu_quan') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
