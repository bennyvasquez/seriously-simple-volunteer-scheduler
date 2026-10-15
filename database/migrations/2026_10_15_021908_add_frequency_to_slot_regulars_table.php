<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slot_regulars', function (Blueprint $table) {
            $table->string('frequency')->default('weekly')->after('user_id'); // Lets two people split a weekly slot by alternate weeks.
            $table->date('anchor_date')->nullable()->after('frequency');
        });
    }

    public function down(): void
    {
        Schema::table('slot_regulars', function (Blueprint $table) {
            $table->dropColumn(['frequency', 'anchor_date']);
        });
    }
};
