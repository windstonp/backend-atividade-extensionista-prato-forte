<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('unit_system', 10)->default('metric');
            $table->boolean('notify_meal_reminders')->default(true);
            $table->boolean('notify_weekly_summary')->default(true);
            $table->boolean('notify_tips')->default(false);
            $table->timestamp('usability_invite_dismissed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
