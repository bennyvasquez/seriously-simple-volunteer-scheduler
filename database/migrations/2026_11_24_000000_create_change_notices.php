<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per person per shift whose time changed. Rows made by one edit for one person share a batch, which is what their one email and one answer cover. A row is open until the person confirms it, or until a newer change to the shift or the person leaving it ends it.
        Schema::create('change_notices', function (Blueprint $table) {
            $table->id();
            $table->char('batch', 26);
            $table->foreignId('user_id')->constrained();
            $table->foreignId('shift_id')->constrained();
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->timestamp('was_starts_at');
            $table->timestamp('was_ends_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('lead_told_at')->nullable();
            $table->timestamps();

            $table->index('batch');
            $table->index(['shift_id', 'user_id']);
            $table->index('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_notices');
    }
};
