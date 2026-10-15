<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->foreignId('weekly_slot_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('local_date');      // The date in the organization's time zone.
            $table->dateTime('starts_at');   // UTC.
            $table->dateTime('ends_at');     // UTC.
            $table->unsignedSmallInteger('headcount');
            $table->unsignedSmallInteger('shadow_spots')->default(0);
            $table->timestamps();

            $table->unique(['weekly_slot_id', 'local_date']);
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
