<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_internal_logins_keep_their_account_and_role_rules(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Customer',
            'username' => 'testcustomer',
            'email' => 'customer@example.test',
            'password' => 'strong-password',
            'password_confirmation' => 'strong-password',
        ])->assertCreated();

        $customerLogin = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'testcustomer',
            'password' => 'strong-password',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Test Customer');
        $this->assertDatabaseHas('api_access_tokens', [
            'token_hash' => hash('sha256', $customerLogin->json('token')),
            'account_type' => 'customer',
        ]);

        DB::table('quan_tri')->insert([
            [
                'tk_admin' => 'owner01',
                'mat_khau' => Hash::make('owner-password'),
                'ten_admin' => 'Owner',
                'email' => 'owner@example.test',
                'role' => 'chu_quan',
                'id_cua_hang' => null,
            ],
            [
                'tk_admin' => 'staff01',
                'mat_khau' => Hash::make('staff-password'),
                'ten_admin' => 'Staff',
                'email' => 'staff@example.test',
                'role' => 'nhan_vien',
                'id_cua_hang' => null,
            ],
        ]);

        $this->postJson('/api/v1/auth/admin/login', [
            'identifier' => 'owner01',
            'password' => 'owner-password',
        ])->assertOk()->assertJsonPath('data.role', 'chu_quan');
        $this->postJson('/api/v1/auth/admin/login', [
            'identifier' => 'staff01',
            'password' => 'staff-password',
        ])->assertUnprocessable();
        $staffLogin = $this->postJson('/api/v1/auth/staff/login', [
            'identifier' => 'staff01',
            'password' => 'staff-password',
        ])->assertOk()->assertJsonPath('data.role', 'nhan_vien');
        $this->assertDatabaseHas('api_access_tokens', [
            'token_hash' => hash('sha256', $staffLogin->json('token')),
            'account_type' => 'admin',
            'account_id' => 'staff01',
        ]);
    }
}
