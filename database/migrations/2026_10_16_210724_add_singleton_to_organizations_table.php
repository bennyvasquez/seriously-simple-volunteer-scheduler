<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Every row gets the same value and the column is unique, so the database holds at most one organization.
            $table->boolean('singleton')->default(true)->unique();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['singleton']);
            $table->dropColumn('singleton');
        });
    }
};
