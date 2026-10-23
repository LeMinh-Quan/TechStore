<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStaffRole
{
    private const ROLES = ['chu_doanh_nghiep', 'chu_quan', 'giam_doc_chi_nhanh', 'nhan_vien'];

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $account = $request->attributes->get('api_account');
        $role = $account['record']->role ?? null;
        $allowedRoles = $roles === [] ? self::ROLES : $roles;

        if (($account['type'] ?? null) !== 'admin'
            || ! in_array($role, self::ROLES, true)
            || ! in_array($role, $allowedRoles, true)) {
            return response()->json(['message' => 'Bạn không có quyền truy cập chức năng nội bộ này.'], 403);
        }

        return $next($request);
    }
}
