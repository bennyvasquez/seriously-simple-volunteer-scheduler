<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Whether this person gets the reminder email before a shift they're on. On by default; changed on the Emails page.
            $table->boolean('reminder_emails')->default(true)->after('cover_request_emails');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reminder_emails');
        });
    }
};
