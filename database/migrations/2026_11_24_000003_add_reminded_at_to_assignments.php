<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            // When the shift reminder job handled this place, whether or not an email went out. Null means it hasn't yet.
            $table->timestamp('reminded_at')->nullable()->after('no_show_at');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
