<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 is Sunday to 6 is Saturday, as Carbon numbers them.
            $table->time('start_time');             // Local wall-clock time in the organization's time zone.
            $table->time('end_time');
            $table->unsignedSmallInteger('headcount')->default(1);
            $table->unsignedSmallInteger('shadow_spots')->default(0);
            $table->string('frequency')->default('weekly');
            $table->date('anchor_date')->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_slots');
    }
};
