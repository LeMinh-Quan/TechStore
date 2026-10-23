<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chatbot_huan_luyen')) {
            return;
        }

        Schema::create('chatbot_huan_luyen', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('y_dinh', 50);
            $table->text('cau_hoi');
            $table->text('phan_hoi')->nullable();
            $table->boolean('trang_thai')->default(true);
            $table->timestamp('thoi_gian_tao')->useCurrent();
            $table->index(['y_dinh', 'trang_thai']);
        });
    }

    public function down(): void
    {
        // This table may predate Laravel migrations in legacy installations.
    }
};
