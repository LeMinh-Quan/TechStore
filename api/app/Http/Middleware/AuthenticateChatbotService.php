<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateChatbotService
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('services.chatbot.token');
        $providedToken = $request->bearerToken();

        if (! is_string($expectedToken) || $expectedToken === '') {
            return response()->json(['message' => 'Chatbot service is not configured.'], 503);
        }

        if (! is_string($providedToken) || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
