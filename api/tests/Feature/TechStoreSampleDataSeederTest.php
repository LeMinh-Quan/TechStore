<?php

namespace Tests\Feature;

use Database\Seeders\TechStoreSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TechStoreSampleDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_linked_demo_data_without_users_or_chatbot_rows(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(7, DB::table('quan_tri')->count());
        $demoPasswordHash = DB::table('quan_tri')->where('tk_admin', 'demo-owner')->value('mat_khau');
        $this->assertNotEmpty($demoPasswordHash);
        $this->assertMatchesRegularExpression('/^\$2y\$\d{2}\$/', $demoPasswordHash);
        $this->assertSame('chu_quan', DB::table('quan_tri')->where('tk_admin', 'demo-owner')->value('role'));
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('chatbot_conversations')->count());
        $this->assertSame(0, DB::table('chatbot_huan_luyen')->count());

        $this->assertSame(3, DB::table('san_pham_hinh_anh')->count());
        $this->assertSame(2, DB::table('don_hang')->count());
        $this->assertDatabaseHas('chi_tiet_don_hang', [
            'ma_dh' => 'DHDEMO0001',
            'ma_sp' => 'DEMO000001',
        ]);
        $this->assertDatabaseHas('thanh_toan', [
            'ma_tt' => 'TTDEMO0001',
            'ma_dh' => 'DHDEMO0001',
        ]);
        $this->assertDatabaseHas('bao_hanh', [
            'ma_dh' => 'DHDEMO0001',
            'ma_kh' => 'KHDEMO000001',
        ]);
    }

    public function test_demo_accounts_are_not_seeded_in_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Demo accounts with preset passwords must not be seeded in production.');

        app(TechStoreSampleDataSeeder::class)->run();
    }
}
