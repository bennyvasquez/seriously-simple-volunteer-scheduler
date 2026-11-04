<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable();
            $table->string('member_id', 64)->nullable()->unique();
            $table->boolean('is_removed_person')->default(false);
        });

        self::backfillInvitedAt();

        Schema::table('areas', function (Blueprint $table) {
            $table->string('lead_name', 40)->nullable();
        });

        // Two removed people can share a past shift, so one person on one shift is enforced by the actions that add people. The foreign key needs an index on shift_id, so add the plain one before dropping the unique one.
        Schema::table('assignments', function (Blueprint $table) {
            $table->index('shift_id');
        });
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropUnique(['shift_id', 'user_id']);
        });
    }

    /** Everyone already in the database counts as invited, from the day their account was created (or now, for a row with no creation time). */
    public static function backfillInvitedAt(): void
    {
        DB::table('users')->whereNull('invited_at')->update(['invited_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->unique(['shift_id', 'user_id']);
        });
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropIndex(['shift_id']);
        });

        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('lead_name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['member_id']);
            $table->dropColumn(['invited_at', 'member_id', 'is_removed_person']);
        });
    }
};
