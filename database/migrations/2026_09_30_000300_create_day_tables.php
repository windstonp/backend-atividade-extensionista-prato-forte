<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 12);
            $table->string('name', 40);
            $table->time('time');
            $table->string('note', 80)->nullable();
            $table->unsignedTinyInteger('position');
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'date', 'slot']); // RN22: o dia é materializado uma vez
            $table->index(['user_id', 'date']);
        });

        Schema::create('day_meal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('day_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('foods')->restrictOnDelete();
            $table->decimal('grams', 6, 1);
            $table->foreignId('replaced_food_id')->nullable()->constrained('foods')->restrictOnDelete();
            $table->string('source', 8);
            $table->unsignedTinyInteger('position');
        });

        Schema::create('day_meal_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('day_meal_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);
            $table->string('description', 160);
            $table->json('items_before');
            $table->timestamp('undone_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'date', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_meal_changes');
        Schema::dropIfExists('day_meal_items');
        Schema::dropIfExists('day_meals');
    }
};
