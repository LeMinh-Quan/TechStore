<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('voucher_da_thu_thap')) {
            Schema::create('voucher_da_thu_thap', function (Blueprint $table): void {
                $table->id();
                $table->string('ma_kh', 20);
                $table->string('code', 40);
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['ma_kh', 'code']);
                $table->index(['code', 'ma_kh']);
            });
        }

        if (! Schema::hasTable('uu_dai_hoc_tap')) {
            Schema::create('uu_dai_hoc_tap', function (Blueprint $table): void {
                $table->string('ma_kh', 20)->primary();
                $table->string('email_truong', 255);
                $table->string('doi_tuong', 40);
                $table->timestamp('verified_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('xac_minh_hoc_tap')) {
            Schema::create('xac_minh_hoc_tap', function (Blueprint $table): void {
                $table->string('ma_kh', 20)->primary();
                $table->string('email_truong', 255);
                $table->string('doi_tuong', 40);
                $table->string('ma_otp_hash');
                $table->unsignedTinyInteger('so_lan_thu')->default(0);
                $table->timestamp('expires_at');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('don_hang') && ! Schema::hasColumn('don_hang', 'loai_uu_dai')) {
            Schema::table('don_hang', function (Blueprint $table): void {
                $table->string('loai_uu_dai', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('xac_minh_hoc_tap');
        Schema::dropIfExists('uu_dai_hoc_tap');
        Schema::dropIfExists('voucher_da_thu_thap');
        if (Schema::hasTable('don_hang') && Schema::hasColumn('don_hang', 'loai_uu_dai')) {
            Schema::table('don_hang', function (Blueprint $table): void {
                $table->dropColumn('loai_uu_dai');
            });
        }
    }
};
