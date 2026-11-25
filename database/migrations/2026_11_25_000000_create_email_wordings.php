<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The organization's edited email lines, one row per line, keyed like lang/en/emails.php ("shift_reminder.opening"). A line without a row reads as the file's default; putting a line back to the default deletes its row.
        Schema::create('email_wordings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('text');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_wordings');
    }
};
