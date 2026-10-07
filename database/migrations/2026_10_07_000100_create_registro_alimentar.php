<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Spec 09 (D13): registro do que foi comido, alimento próprio, g/ml e catálogo fora do plano. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->string('measure', 2)->default('g')->after('source');   // RN47
            $table->boolean('in_plans')->default(false)->after('measure'); // RN52
            $table->index(['is_active', 'in_plans']);
        });
        DB::table('foods')->update(['in_plans' => true]); // os alimentos de antes continuam no plano

        Schema::create('custom_foods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('name_normalized', 60);
            $table->string('measure', 2);
            $table->decimal('kcal_per_100', 5, 1);
            $table->decimal('protein_per_100', 4, 1);
            $table->decimal('carbs_per_100', 4, 1);
            $table->decimal('fat_per_100', 4, 1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'name_normalized']);
        });

        Schema::create('meal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('day_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->nullable()->constrained('foods')->restrictOnDelete();
            $table->foreignId('custom_food_id')->nullable()->constrained('custom_foods')->restrictOnDelete();
            $table->foreignId('suggestion_item_id')->nullable()->constrained('day_meal_items')->nullOnDelete();
            $table->string('name', 120);
            $table->string('measure', 2);
            $table->decimal('amount', 6, 1);
            $table->unsignedSmallInteger('calories');
            $table->decimal('protein', 5, 1);
            $table->decimal('carbs', 5, 1);
            $table->decimal('fat', 5, 1);
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->index(['day_meal_id', 'position']);
            $table->index(['user_id', 'created_at']);
            $table->unique(['day_meal_id', 'suggestion_item_id']);
        });
        DB::statement('ALTER TABLE meal_entries ADD CONSTRAINT meal_entries_um_alimento CHECK ((food_id IS NOT NULL) + (custom_food_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_entries');
        Schema::dropIfExists('custom_foods');
        Schema::table('foods', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'in_plans']);
            $table->dropColumn(['measure', 'in_plans']);
        });
    }
};
