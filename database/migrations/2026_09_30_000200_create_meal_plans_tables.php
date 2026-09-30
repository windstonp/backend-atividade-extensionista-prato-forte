<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12);
            $table->boolean('is_active')->default(false);
            // RN20: um só plano ativo por usuário, garantido pelo banco (UNIQUE abaixo). Não é coluna gerada:
            // o MySQL não aceita CASCADE na FK da coluna-base de uma coluna gerada. O model mantém o valor.
            $table->unsignedBigInteger('active_user_id')->nullable();
            $table->unsignedSmallInteger('target_kcal');
            $table->unsignedSmallInteger('target_protein_g');
            $table->unsignedSmallInteger('target_carbs_g');
            $table->unsignedSmallInteger('target_fat_g');
            $table->json('inputs');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('failure_reason')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->unique('active_user_id');
        });

        Schema::create('plan_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 12);
            $table->string('name', 40);
            $table->time('time');
            $table->unsignedTinyInteger('position');
            $table->unique(['meal_plan_id', 'slot']);
        });

        Schema::create('plan_meal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('foods')->restrictOnDelete();
            $table->decimal('grams', 6, 1);
            $table->unsignedTinyInteger('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_meal_items');
        Schema::dropIfExists('plan_meals');
        Schema::dropIfExists('meal_plans');
    }
};
