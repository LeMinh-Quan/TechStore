<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('don_hang', function (Blueprint $table): void {
            if (! Schema::hasColumn('don_hang', 'don_vi_van_chuyen')) {
                $table->string('don_vi_van_chuyen', 100)->nullable();
            }
            if (! Schema::hasColumn('don_hang', 'ma_van_don')) {
                $table->string('ma_van_don', 100)->nullable()->index();
            }
            if (! Schema::hasColumn('don_hang', 'ngay_giao_du_kien')) {
                $table->date('ngay_giao_du_kien')->nullable();
            }
        });

        if (! Schema::hasTable('yeu_cau_doi_tra')) {
            Schema::create('yeu_cau_doi_tra', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('ma_dh', 30)->unique();
                $table->string('ma_kh', 20)->index();
                $table->string('loai_yeu_cau', 20);
                $table->string('ly_do', 100);
                $table->text('mo_ta')->nullable();
                $table->string('trang_thai', 30)->default('Cho xu ly');
                $table->text('ghi_chu_nhan_vien')->nullable();
                $table->string('nguoi_xu_ly', 60)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->index(['ma_kh', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('yeu_cau_doi_tra');
        Schema::table('don_hang', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['don_vi_van_chuyen', 'ma_van_don', 'ngay_giao_du_kien'],
                fn (string $column): bool => Schema::hasColumn('don_hang', $column),
            ));
            if ($columns !== []) {
                if (in_array('ma_van_don', $columns, true)) {
                    $table->dropIndex(['ma_van_don']);
                }
                $table->dropColumn($columns);
            }
        });
    }
};
