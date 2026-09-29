<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restriction_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restriction_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'restriction_id']);
        });

        Schema::create('pantry_item_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pantry_item_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'pantry_item_id']);
        });

        Schema::create('disliked_food_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            $table->primary(['user_id', 'food_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disliked_food_user');
        Schema::dropIfExists('pantry_item_user');
        Schema::dropIfExists('restriction_user');
    }
};
