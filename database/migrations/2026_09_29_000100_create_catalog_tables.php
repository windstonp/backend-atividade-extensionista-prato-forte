<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foods', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->json('aliases');
            $table->string('group', 20);
            $table->decimal('kcal_per_100g', 6, 1);
            $table->decimal('protein_per_100g', 5, 1);
            $table->decimal('carbs_per_100g', 5, 1);
            $table->decimal('fat_per_100g', 5, 1);
            $table->decimal('typical_portion_g', 6, 1);
            $table->string('unit_label', 40)->nullable();
            $table->string('unit_label_plural', 40)->nullable();
            $table->decimal('unit_grams', 6, 1)->nullable();
            $table->string('substitution_note', 120)->nullable();
            $table->boolean('common_dislike')->default(false);
            $table->boolean('is_staple')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('source', 40);
            $table->timestamps();
            $table->index(['group', 'is_active']);
        });
        DB::statement('ALTER TABLE foods ADD CONSTRAINT foods_macros_nao_negativos CHECK (kcal_per_100g >= 0 AND protein_per_100g >= 0 AND carbs_per_100g >= 0 AND fat_per_100g >= 0)');

        Schema::create('restrictions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique();
            $table->string('label', 60);
            $table->boolean('is_allergy')->default(false);
            $table->unsignedTinyInteger('position');
            $table->timestamps();
        });

        Schema::create('pantry_items', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('label', 60);
            $table->string('category', 20);
            $table->unsignedTinyInteger('position');
            $table->timestamps();
        });

        Schema::create('food_restriction', function (Blueprint $table) {
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            $table->foreignId('restriction_id')->constrained()->cascadeOnDelete();
            $table->primary(['food_id', 'restriction_id']);
        });

        Schema::create('food_pantry_item', function (Blueprint $table) {
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            $table->foreignId('pantry_item_id')->constrained()->cascadeOnDelete();
            $table->primary(['food_id', 'pantry_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_pantry_item');
        Schema::dropIfExists('food_restriction');
        Schema::dropIfExists('pantry_items');
        Schema::dropIfExists('restrictions');
        Schema::dropIfExists('foods');
    }
};
