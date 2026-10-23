<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ApiTokenService;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request, AuthService $auth, ApiTokenService $tokens): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:50', Rule::unique('nguoi_dung', 'ten_dang_nhap')],
            'email' => ['required', 'email', 'max:255', Rule::unique('nguoi_dung', 'email')],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9+().\-\s]{8,20}$/', Rule::unique('nguoi_dung', 'so_dien_thoai')],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $registered = $auth->registerCustomer($data, $tokens);

        return response()->json([
            'data' => [
                'id' => $registered['id'],
                'name' => $data['name'],
                'email' => $registered['email'],
                'phone' => $data['phone'] ?? null,
            ],
            'token' => $registered['token'],
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(Request $request, AuthService $auth, ApiTokenService $tokens): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:72'],
        ]);

        $authenticated = $auth->loginCustomer($data, $tokens);
        $account = $authenticated['account'];

        return response()->json([
            'data' => ['id' => $account->ma_kh, 'name' => $account->ten_khach_hang ?? $account->ten_dang_nhap, 'email' => $account->email],
            'token' => $authenticated['token'],
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request, AuthService $auth): JsonResponse
    {
        $auth->revokeToken($request->bearerToken());

        return response()->json(['message' => 'Đã đăng xuất.']);
    }

    public function me(Request $request): JsonResponse
    {
        $account = $request->attributes->get('api_account')['record'];

        return response()->json([
            'data' => [
                'id' => $account->ma_kh,
                'name' => $account->ten_khach_hang ?? $account->ten_dang_nhap,
                'email' => $account->email,
                'phone' => $account->so_dien_thoai,
            ],
        ]);
    }

    public function adminLogin(Request $request, AuthService $auth, ApiTokenService $tokens): JsonResponse
    {
        return $this->internalLogin($request, $auth, $tokens, ['chu_quan']);
    }

    public function staffLogin(Request $request, AuthService $auth, ApiTokenService $tokens): JsonResponse
    {
        return $this->internalLogin($request, $auth, $tokens, [
            'chu_doanh_nghiep',
            'chu_quan',
            'giam_doc_chi_nhanh',
            'nhan_vien',
        ]);
    }

    private function internalLogin(Request $request, AuthService $auth, ApiTokenService $tokens, array $allowedRoles): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:72'],
        ]);

        $authenticated = $auth->loginInternalAccount($data, $allowedRoles, $tokens);
        $admin = $authenticated['account'];

        return response()->json([
            'data' => [
                'id' => $admin->tk_admin,
                'name' => $admin->ten_admin,
                'email' => $admin->email,
                'role' => $admin->role,
                'branch_id' => $admin->id_cua_hang,
            ],
            'token' => $authenticated['token'],
            'token_type' => 'Bearer',
        ]);
    }
}
