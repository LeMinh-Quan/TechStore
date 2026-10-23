<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAdministrator extends Command
{
    protected $signature = 'admin:create {username} {email} {name} {--role=chu_quan} {--branch=}';

    protected $description = 'Create an internal account with a securely hashed password';

    public function handle(): int
    {
        $username = $this->argument('username');
        $email = Str::lower($this->argument('email'));
        $role = $this->option('role');
        $branchId = $this->option('branch');
        $roles = ['chu_doanh_nghiep', 'chu_quan', 'giam_doc_chi_nhanh', 'nhan_vien'];
        $branchRequired = in_array($role, ['giam_doc_chi_nhanh', 'nhan_vien'], true);

        if (! preg_match('/\A[a-zA-Z0-9_-]{3,20}\z/', $username)
            || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ! in_array($role, $roles, true)
            || DB::table('quan_tri')->where('tk_admin', $username)->exists()
            || DB::table('quan_tri')->where('email', $email)->exists()
            || ($branchRequired && (! is_string($branchId) || ! ctype_digit($branchId)
                || ! DB::table('cua_hang')->where('id_cua_hang', $branchId)->where('trang_thai', true)->exists()))
            || (! $branchRequired && $branchId !== null)) {
            $this->components->error('Provide a unique account ID, valid email and role; branch managers and staff require an existing branch.');

            return self::FAILURE;
        }

        $password = $this->secret('Admin password (at least 12 characters)');
        if (! is_string($password) || strlen($password) < 12) {
            $this->components->error('Admin password must contain at least 12 characters.');

            return self::FAILURE;
        }

        DB::table('quan_tri')->insert([
            'tk_admin' => $username,
            'mat_khau' => Hash::make($password),
            'ten_admin' => $this->argument('name'),
            'email' => $email,
            'role' => $role,
            'id_cua_hang' => $branchId,
        ]);

        $this->components->info("Internal account {$username} ({$role}) created.");

        return self::SUCCESS;
    }
}
