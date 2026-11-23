<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per released place on a shift. It carries the release note and the escalation ladder's timestamps, and is filled when someone takes the place.
        Schema::create('cover_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained();
            $table->foreignId('released_by')->constrained('users');
            $table->text('note')->nullable();
            // How many volunteers the first cover request email went to, for the urgent email.
            $table->unsignedInteger('notified_count')->default(0);
            $table->timestamp('resent_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('filled_by')->nullable()->constrained('users');
            $table->timestamp('filled_at')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'filled_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            // "Email me when someone can't make a shift I could take", on by default.
            $table->boolean('cover_request_emails')->default(true)->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cover_request_emails');
        });

        Schema::dropIfExists('cover_requests');
    }
};
