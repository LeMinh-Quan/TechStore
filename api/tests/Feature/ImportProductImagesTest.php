<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportProductImagesTest extends TestCase
{
    private string $sourceDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('san_pham', function (Blueprint $table) {
            $table->char('ma_sp', 10)->primary();
            $table->string('ten_sp');
            $table->string('source_url');
        });
        Schema::create('san_pham_hinh_anh', function (Blueprint $table) {
            $table->bigIncrements('id_hinh_anh');
            $table->char('ma_sp', 10);
            $table->string('duong_dan_anh', 2048);
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('thu_tu')->default(0);
            $table->timestamps();
        });

        Storage::fake('public');
        $this->sourceDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'techstore-images-'.Str::uuid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sourceDirectory);
        parent::tearDown();
    }

    public function test_import_matches_source_slug_and_is_idempotent(): void
    {
        File::ensureDirectoryExists($this->sourceDirectory.DIRECTORY_SEPARATOR.'laptop-a');
        File::ensureDirectoryExists($this->sourceDirectory.DIRECTORY_SEPARATOR.'similar-laptop');
        File::put($this->sourceDirectory.DIRECTORY_SEPARATOR.'laptop-a'.DIRECTORY_SEPARATOR.'image_02.png', 'matched image bytes');
        File::put($this->sourceDirectory.DIRECTORY_SEPARATOR.'similar-laptop'.DIRECTORY_SEPARATOR.'image_02.png', 'unrelated image bytes');
        DB::table('san_pham')->insert([
            'ma_sp' => 'LAPTOP01',
            'ten_sp' => 'Laptop A',
            'source_url' => 'https://cellphones.com.vn/laptop-a.html?ref=source',
        ]);

        $this->artisan('catalog:import-images', ['directory' => $this->sourceDirectory])
            ->expectsOutputToContain('Linked 1 images to 1 MySQL products.')
            ->expectsOutputToContain('Unmatched image folders: 1.')
            ->assertSuccessful();

        $storedPath = 'catalog/LAPTOP01/image_02.png';
        Storage::disk('public')->assertExists($storedPath);
        $this->assertDatabaseHas('san_pham_hinh_anh', [
            'ma_sp' => 'LAPTOP01',
            'duong_dan_anh' => $storedPath,
            'alt_text' => 'Laptop A',
        ]);

        $this->artisan('catalog:import-images', ['directory' => $this->sourceDirectory])
            ->assertSuccessful();

        $this->assertSame(1, DB::table('san_pham_hinh_anh')->where('ma_sp', 'LAPTOP01')->count());
        $this->assertSame('matched image bytes', Storage::disk('public')->get($storedPath));
    }
}
