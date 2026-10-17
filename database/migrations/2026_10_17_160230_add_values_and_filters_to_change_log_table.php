<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_log', function (Blueprint $table) {
            $table->string('actor_name')->nullable()->after('actor_id'); // The actor's name when it happened. Null with a null actor_id means the system.
            $table->foreignId('affected_user_id')->nullable()->after('subject_id')->constrained('users')->nullOnDelete(); // The person an entry is about, so "everything about Robin" is one filter.
            $table->json('changes')->nullable()->after('details'); // Old and new values per field.

            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::table('change_log', function (Blueprint $table) {
            $table->dropIndex(['action']);
            $table->dropConstrainedForeignId('affected_user_id');
            $table->dropColumn(['actor_name', 'changes']);
        });
    }
};
