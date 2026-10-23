<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('san_pham')) {
            return;
        }

        if (Schema::hasColumn('san_pham', 'so_luong')) {
            DB::table('san_pham')
                ->whereNull('so_luong')
                ->update(['so_luong' => 0]);
        }

        Schema::table('san_pham', function (Blueprint $table): void {
            if (Schema::hasColumn('san_pham', 'loai')) {
                $table->string('loai', 100)->nullable()->change();
            }
            if (Schema::hasColumn('san_pham', 'mo_ta')) {
                $table->text('mo_ta')->nullable()->change();
            }
            if (Schema::hasColumn('san_pham', 'so_luong')) {
                $table->integer('so_luong')->default(0)->nullable(false)->change();
            }
        });
    }

    public function down(): void
    {
        // Keep widened columns and normalized stock; shrinking them could truncate existing data.
    }
};
