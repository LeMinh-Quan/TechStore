<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('danh_gia_mau')) {
            return;
        }

        $productKey = DB::getDriverName() === 'mysql'
            ? DB::selectOne(
                'select CHARACTER_SET_NAME as charset, COLLATION_NAME as collation
                from information_schema.COLUMNS
                where TABLE_SCHEMA = DATABASE() and TABLE_NAME = ? and COLUMN_NAME = ?',
                ['san_pham', 'ma_sp'],
            )
            : null;

        Schema::create('danh_gia_mau', function (Blueprint $table) use ($productKey): void {
            if ($productKey) {
                $table->charset($productKey->charset);
                $table->collation($productKey->collation);
            }
            $table->bigIncrements('id_danh_gia_mau');
            $productId = $table->char('ma_sp', 10)->unique();
            if ($productKey) {
                $productId->charset($productKey->charset);
                $productId->collation($productKey->collation);
            }
            $table->string('name', 100);
            $table->unsignedTinyInteger('rating');
            $table->text('content');
            $table->timestamps();
            $table->foreign('ma_sp')->references('ma_sp')->on('san_pham')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('danh_gia_mau');
    }
};
