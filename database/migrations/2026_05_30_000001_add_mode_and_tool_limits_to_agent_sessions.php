<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->string('mode')->default('readonly')->after('status');
            $table->json('allowed_tools')->nullable()->after('mode');
            $table->integer('max_actions_per_step')->default(5)->after('max_steps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropColumn(['mode', 'allowed_tools', 'max_actions_per_step']);
        });
    }
};
