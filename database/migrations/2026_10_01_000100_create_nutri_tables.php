<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutri_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80)->nullable();
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('summarized_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('nutri_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('nutri_conversations')->cascadeOnDelete();
            $table->string('role', 10);
            $table->text('content');
            $table->text('follow_up')->nullable();
            $table->json('follow_up_suggestions')->nullable();
            $table->json('card')->nullable();
            $table->json('actions')->nullable();
            $table->timestamp('actions_resolved_at')->nullable();
            $table->unsignedTinyInteger('resolved_action_index')->nullable();
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutri_messages');
        Schema::dropIfExists('nutri_conversations');
    }
};
