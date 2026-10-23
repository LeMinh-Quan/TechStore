<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('uu_dai_hoc_tap')) {
            return;
        }

        Schema::table('uu_dai_hoc_tap', function (Blueprint $table): void {
            if (! Schema::hasColumn('uu_dai_hoc_tap', 'trang_thai')) {
                $table->string('trang_thai', 20)->default('pending');
            }
            if (! Schema::hasColumn('uu_dai_hoc_tap', 'minh_chung_path')) {
                $table->string('minh_chung_path')->nullable();
            }
            if (! Schema::hasColumn('uu_dai_hoc_tap', 'ghi_chu_duyet')) {
                $table->text('ghi_chu_duyet')->nullable();
            }
            if (! Schema::hasColumn('uu_dai_hoc_tap', 'nguoi_duyet')) {
                $table->string('nguoi_duyet', 20)->nullable();
            }
            if (! Schema::hasColumn('uu_dai_hoc_tap', 'duyet_at')) {
                $table->timestamp('duyet_at')->nullable();
            }
        });

        DB::table('uu_dai_hoc_tap')
            ->where('trang_thai', 'pending')
            ->whereNull('minh_chung_path')
            ->update([
                'trang_thai' => 'rejected',
                'ghi_chu_duyet' => 'Vui lòng gửi lại hồ sơ kèm minh chứng để được xem xét.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('uu_dai_hoc_tap')) {
            return;
        }

        $columns = ['trang_thai', 'minh_chung_path', 'ghi_chu_duyet', 'nguoi_duyet', 'duyet_at'];
        $existing = array_values(array_filter($columns, static fn (string $column): bool => Schema::hasColumn('uu_dai_hoc_tap', $column)));
        if ($existing !== []) {
            Schema::table('uu_dai_hoc_tap', function (Blueprint $table) use ($existing): void {
                $table->dropColumn($existing);
            });
        }
    }
};
