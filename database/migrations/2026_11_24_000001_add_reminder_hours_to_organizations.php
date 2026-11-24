<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // How many hours before a shift its reminder email goes out. Null means reminders are off. Existing organizations get 24.
            $table->unsignedTinyInteger('reminder_hours')->nullable()->default(24)->after('required_shadow_shifts');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('reminder_hours');
        });
    }
};
