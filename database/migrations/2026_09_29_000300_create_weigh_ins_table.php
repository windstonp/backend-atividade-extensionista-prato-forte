<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weigh_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('weight_kg', 5, 1);
            $table->timestamps();
            $table->unique(['user_id', 'date']); // RN34: uma pesagem por dia
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weigh_ins');
    }
};
