<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RN38: nunca a mesma notificação duas vezes — o índice único é a trava.
        Schema::create('sent_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('reference', 40);
            $table->string('rule', 30)->nullable();
            $table->timestamp('sent_at');
            $table->unique(['user_id', 'type', 'reference']);
            $table->index(['user_id', 'type', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_notifications');
    }
};
