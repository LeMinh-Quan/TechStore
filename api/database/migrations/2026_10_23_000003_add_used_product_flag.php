<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('san_pham') || Schema::hasColumn('san_pham', 'la_hang_cu')) {
            return;
        }

        Schema::table('san_pham', function (Blueprint $table): void {
            $table->boolean('la_hang_cu')->default(false)->index();
        });

        DB::table('san_pham')
            ->where('ten_sp', 'like', '% - Cũ%')
            ->update(['la_hang_cu' => true]);
    }

    public function down(): void
    {
        if (Schema::hasTable('san_pham') && Schema::hasColumn('san_pham', 'la_hang_cu')) {
            Schema::table('san_pham', function (Blueprint $table): void {
                $table->dropColumn('la_hang_cu');
            });
        }
    }
};
