<?php

use App\Actions\Accounts\ClearPerson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // When a coordinator cleared this person for regular shifts (spec section 9). Until then they can shadow but can't take shifts or get cover requests.
            $table->timestamp('cleared_at')->nullable()->after('cover_request_emails');
        });

        Schema::table('assignments', function (Blueprint $table) {
            // A shadow who didn't come. A no-show shadow shift doesn't count toward clearance.
            $table->timestamp('no_show_at')->nullable()->after('type');
        });

        Schema::table('organizations', function (Blueprint $table) {
            // How many shadow shifts someone works before they're ready for review.
            $table->unsignedTinyInteger('required_shadow_shifts')->default(2)->after('weeks_ahead');
        });

        // Holding a role clears you, so everyone who already holds one is cleared by the system, each with a change log entry. Schema changes commit on their own in MariaDB, so this runs in its own transaction after them.
        app(ClearPerson::class)->clearRoleHolders();
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('required_shadow_shifts');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('no_show_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cleared_at');
        });
    }
};
