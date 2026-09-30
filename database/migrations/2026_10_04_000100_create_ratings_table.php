<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RN40: uma avaliação por pessoa por item; avaliar de novo substitui.
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rateable_type', 20);
            $table->unsignedBigInteger('rateable_id');
            $table->string('value', 4);
            $table->string('comment', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'rateable_type', 'rateable_id']);
            $table->index(['rateable_type', 'rateable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
