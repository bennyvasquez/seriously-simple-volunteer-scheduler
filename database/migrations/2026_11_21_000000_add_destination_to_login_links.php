<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_links', function (Blueprint $table) {
            // The page on this site the person was heading to when they asked for a sign-in link, so opening the link in another browser still takes them there. Empty for every other kind of link.
            $table->string('destination', 500)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('login_links', function (Blueprint $table) {
            $table->dropColumn('destination');
        });
    }
};
