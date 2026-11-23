<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // When a coordinator last moved this person back to shadowing. Only shadow shifts that ended after it count toward review again (ShadowProgress); null means every shadow shift counts.
            $table->timestamp('shadowing_since')->nullable()->after('cleared_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('shadowing_since');
        });
    }
};
