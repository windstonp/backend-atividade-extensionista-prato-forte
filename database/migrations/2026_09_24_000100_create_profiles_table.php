<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('preferred_name', 40)->nullable();
            $table->string('goal', 20)->nullable();
            $table->string('sex', 10)->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->decimal('start_weight_kg', 5, 1)->nullable();
            $table->decimal('goal_weight_kg', 5, 1)->nullable();
            $table->string('goal_weight_source', 10)->nullable();
            $table->string('activity_level', 10)->nullable();
            $table->string('work_posture', 12)->nullable();
            $table->time('wake_time')->nullable();
            $table->time('training_time')->nullable();
            $table->time('sleep_time')->nullable();
            $table->json('training_days');
            $table->string('lunch_place', 12)->nullable();
            $table->json('other_restrictions');
            $table->json('completed_steps');
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
