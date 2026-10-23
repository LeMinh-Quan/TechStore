<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status', 20)->default('bot')->index();
            $table->timestamps();
        });

        Schema::create('chatbot_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('conversation_id');
            $table->string('sender', 20);
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('conversation_id')->references('id')->on('chatbot_conversations')->cascadeOnDelete();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_messages');
        Schema::dropIfExists('chatbot_conversations');
    }
};
