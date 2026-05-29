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
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('path');
            $table->string('type')->nullable();
            $table->string('base_branch')->default('develop');
            $table->string('agent_branch_prefix')->default('agent');
            $table->json('protected_paths')->nullable();
            $table->json('allowed_commands')->nullable();
            $table->json('approval_required_commands')->nullable();
            $table->json('blocked_commands')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('role')->index();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('api_key_name')->nullable();
            $table->longText('system_prompt');
            $table->boolean('enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->longText('mission');
            $table->enum('status', ['pending', 'running', 'paused', 'stopped', 'completed', 'failed'])->default('pending');
            $table->string('base_branch');
            $table->string('agent_branch');
            $table->foreignId('current_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->integer('current_step')->default(0);
            $table->integer('max_steps')->default(20);
            $table->longText('summary_context')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('agent_sessions')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->enum('role', ['system', 'user', 'assistant', 'tool']);
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'created_at']);
        });

        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('agent_sessions')->cascadeOnDelete();
            $table->foreignId('created_by_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('assigned_to_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'done', 'failed', 'cancelled'])->default('pending');
            $table->longText('result')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'status']);
        });

        Schema::create('agent_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('agent_sessions')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('type');
            $table->json('payload');
            $table->enum('status', ['pending', 'approved', 'rejected', 'running', 'done', 'failed', 'blocked'])->default('pending');
            $table->boolean('requires_approval')->default(false);
            $table->longText('result')->nullable();
            $table->longText('error')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'status']);
        });

        Schema::create('agent_commits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('agent_sessions')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('branch');
            $table->string('commit_hash')->nullable();
            $table->string('commit_message');
            $table->json('changed_files')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'branch']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_commits');
        Schema::dropIfExists('agent_actions');
        Schema::dropIfExists('agent_tasks');
        Schema::dropIfExists('agent_messages');
        Schema::dropIfExists('agent_sessions');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('workspaces');
    }
};
