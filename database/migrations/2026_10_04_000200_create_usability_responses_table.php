<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usability_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('round', 10);
            $table->json('sus_answers');
            $table->decimal('sus_score', 5, 1);
            $table->unsignedTinyInteger('usefulness');
            $table->string('liked', 1000)->nullable();
            $table->string('disliked', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'round']); // RN41: uma resposta por rodada
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usability_responses');
    }
};
