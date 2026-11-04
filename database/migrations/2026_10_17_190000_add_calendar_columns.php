<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('color')->default('blue');
        });

        Schema::table('weekly_slots', function (Blueprint $table) {
            $table->string('title', 80)->nullable();
            $table->boolean('is_rotation')->default(false);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->string('title', 80)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('area_closure_id')->nullable()->constrained('area_closures')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_closure_id');
            $table->dropColumn(['title', 'cancelled_at', 'cancel_reason', 'edited_at']);
        });

        Schema::table('weekly_slots', function (Blueprint $table) {
            $table->dropColumn(['title', 'is_rotation']);
        });

        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
