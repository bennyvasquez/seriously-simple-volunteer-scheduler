<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_slots', function (Blueprint $table) {
            $table->renameColumn('headcount', 'people_needed');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->renameColumn('headcount', 'people_needed');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_slots', function (Blueprint $table) {
            $table->renameColumn('people_needed', 'headcount');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->renameColumn('people_needed', 'headcount');
        });
    }
};
