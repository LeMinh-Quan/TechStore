<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('thong_bao_nguoi_dung')) {
            return;
        }

        Schema::create('thong_bao_nguoi_dung', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('ma_kh', 20)->index();
            $table->string('loai', 40);
            $table->string('tieu_de', 200);
            $table->text('noi_dung');
            $table->string('ma_dh', 30)->nullable()->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ma_kh', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thong_bao_nguoi_dung');
    }
};
