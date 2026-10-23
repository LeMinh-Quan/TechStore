<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncProductPricesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('san_pham', function (Blueprint $table): void {
            $table->char('ma_sp', 10)->primary();
            $table->string('ten_sp');
            $table->string('source_url');
            $table->decimal('gia_ban', 10, 0)->nullable();
        });
    }

    public function test_it_previews_verified_current_price_without_modifying_mysql(): void
    {
        $url = 'https://cellphones.com.vn/laptop-a.html';
        DB::table('san_pham')->insert([
            'ma_sp' => 'LAPTOP0001',
            'ten_sp' => 'Laptop A',
            'source_url' => $url,
            'gia_ban' => 0,
        ]);
        Http::fake(['cellphones.com.vn/*' => Http::response($this->productPage($url, '12500000'), 200)]);

        $this->artisan('catalog:sync-prices', ['--limit' => 10, '--delay' => 0])
            ->expectsOutputToContain('12500000 VND')
            ->expectsOutputToContain('prices found 1, prices saved 0')
            ->assertSuccessful();

        $this->assertDatabaseHas('san_pham', ['ma_sp' => 'LAPTOP0001', 'gia_ban' => 0]);
    }

    public function test_it_saves_only_matching_positive_vnd_product_offers_when_apply_is_requested(): void
    {
        $url = 'https://cellphones.com.vn/laptop-a.html';
        DB::table('san_pham')->insert([
            'ma_sp' => 'LAPTOP0001',
            'ten_sp' => 'Laptop A',
            'source_url' => $url,
            'gia_ban' => 0,
        ]);
        Http::fake(['cellphones.com.vn/*' => Http::response($this->productPage($url, '12500000'), 200)]);

        $this->artisan('catalog:sync-prices', ['--limit' => 10, '--delay' => 0, '--apply' => true])
            ->expectsOutputToContain('prices found 1, prices saved 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('san_pham', ['ma_sp' => 'LAPTOP0001', 'gia_ban' => 12500000]);
    }

    public function test_it_does_not_update_prices_when_the_source_offer_is_zero_or_does_not_match(): void
    {
        $url = 'https://cellphones.com.vn/laptop-a.html';
        DB::table('san_pham')->insert([
            'ma_sp' => 'LAPTOP0001',
            'ten_sp' => 'Laptop A',
            'source_url' => $url,
            'gia_ban' => 0,
        ]);
        Http::fake(['cellphones.com.vn/*' => Http::response($this->productPage($url, '0'), 200)]);

        $this->artisan('catalog:sync-prices', ['--limit' => 10, '--delay' => 0, '--apply' => true])
            ->expectsOutputToContain('No verified VND offer found')
            ->assertSuccessful();

        $this->assertDatabaseHas('san_pham', ['ma_sp' => 'LAPTOP0001', 'gia_ban' => 0]);
    }

    public function test_it_stops_the_batch_when_the_source_rate_limits_requests(): void
    {
        DB::table('san_pham')->insert([
            [
                'ma_sp' => 'LAPTOP0001',
                'ten_sp' => 'Laptop A',
                'source_url' => 'https://cellphones.com.vn/laptop-a.html',
                'gia_ban' => 0,
            ],
            [
                'ma_sp' => 'LAPTOP0002',
                'ten_sp' => 'Laptop B',
                'source_url' => 'https://cellphones.com.vn/laptop-b.html',
                'gia_ban' => 0,
            ],
        ]);
        Http::fake(['cellphones.com.vn/*' => Http::response('Too Many Requests', 429)]);

        $this->artisan('catalog:sync-prices', ['--all' => true, '--delay' => 0])
            ->expectsOutputToContain('stopping to avoid further requests')
            ->assertFailed();

        Http::assertSentCount(1);
    }

    private function productPage(string $url, string $price): string
    {
        $offer = json_encode([
            '@type' => 'Offer',
            'url' => $url,
            'priceCurrency' => 'VND',
            'price' => $price,
        ], JSON_THROW_ON_ERROR);

        return '<html><head><link rel="canonical" href="'.$url.'">'
            .'<script type="application/ld+json">{"@type":"Product","name":"Laptop A","offers":'.$offer.'}</script>'
            .'</head><body></body></html>';
    }
}
