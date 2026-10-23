<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! is_string($token) || ! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $record = DB::table('api_access_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if (! $record) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $account = match ($record->account_type) {
            'customer' => DB::table('nguoi_dung')->where('ma_kh', $record->account_id)->first(),
            'admin' => DB::table('quan_tri')->where('tk_admin', $record->account_id)->first(),
            default => null,
        };

        if (! $account) {
            DB::table('api_access_tokens')->where('id', $record->id)->delete();

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        DB::table('api_access_tokens')->where('id', $record->id)->update(['last_used_at' => now()]);
        $request->attributes->set('api_account', [
            'type' => $record->account_type,
            'id' => $record->account_id,
            'record' => $account,
        ]);

        return $next($request);
    }
}
