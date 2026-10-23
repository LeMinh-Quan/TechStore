<?php

namespace App\Services;

use App\Mail\WelcomeCustomer;
use App\Models\AdministratorAccount;
use App\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function registerCustomer(array $data, ApiTokenService $tokens): array
    {
        $email = Str::lower($data['email']);
        [$accountId, $token] = DB::transaction(function () use ($data, $email, $tokens): array {
            $accountId = 'KH'.Str::upper(Str::random(12));
            CustomerAccount::query()->create([
                'ma_kh' => $accountId,
                'ten_khach_hang' => $data['name'],
                'ten_dang_nhap' => $data['username'],
                'email' => $email,
                'so_dien_thoai' => $data['phone'] ?? null,
                'mat_khau' => Hash::make($data['password']),
            ]);

            return [$accountId, $tokens->issue('customer', $accountId)];
        });

        Mail::to($email)->queue(new WelcomeCustomer($data['name']));

        return ['id' => $accountId, 'email' => $email, 'token' => $token];
    }

    public function loginCustomer(array $credentials, ApiTokenService $tokens): array
    {
        $account = CustomerAccount::query()
            ->where('email', $credentials['identifier'])
            ->orWhere('ten_dang_nhap', $credentials['identifier'])
            ->first();

        if (! $account || ! Hash::check($credentials['password'], $account->mat_khau)) {
            throw ValidationException::withMessages(['identifier' => ['Thông tin đăng nhập không chính xác.']]);
        }

        return [
            'account' => $account,
            'token' => $tokens->issue('customer', $account->ma_kh),
        ];
    }

    public function loginInternalAccount(array $credentials, array $allowedRoles, ApiTokenService $tokens): array
    {
        $account = AdministratorAccount::query()
            ->where('email', $credentials['identifier'])
            ->orWhere('tk_admin', $credentials['identifier'])
            ->first();

        if (! $account
            || ! Hash::check($credentials['password'], $account->mat_khau)
            || ! in_array($account->role, $allowedRoles, true)) {
            throw ValidationException::withMessages(['identifier' => ['Thông tin đăng nhập không chính xác.']]);
        }

        return [
            'account' => $account,
            'token' => $tokens->issue('admin', $account->tk_admin),
        ];
    }

    public function revokeToken(?string $token): void
    {
        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            DB::table('api_access_tokens')->where('token_hash', hash('sha256', $token))->delete();
        }
    }
}
