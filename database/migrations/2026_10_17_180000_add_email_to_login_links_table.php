<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_links', function (Blueprint $table) {
            // The new address an email change link confirms. Empty for every other kind of link.
            $table->string('email')->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('login_links', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
