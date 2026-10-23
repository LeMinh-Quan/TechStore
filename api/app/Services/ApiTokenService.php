<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ApiTokenService
{
    public function issue(string $accountType, string $accountId): string
    {
        $token = bin2hex(random_bytes(32));

        DB::table('api_access_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'account_type' => $accountType,
            'account_id' => $accountId,
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
