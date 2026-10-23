<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_conversations', function (Blueprint $table): void {
            $table->string('assigned_admin_id', 20)->nullable()->index();
        });

        Schema::create('nhat_ky_ton_kho', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('ma_sp', 20)->index();
            $table->unsignedBigInteger('id_cua_hang')->index();
            $table->string('tk_admin', 20);
            $table->unsignedInteger('ton_cu');
            $table->unsignedInteger('ton_moi');
            $table->string('dia_chi_ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['id_cua_hang', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhat_ky_ton_kho');
        Schema::table('chatbot_conversations', function (Blueprint $table): void {
            $table->dropColumn('assigned_admin_id');
        });
    }
};
