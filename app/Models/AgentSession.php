<?php

namespace App\Models;

use Database\Factories\AgentSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentSession extends Model
{
    /** @use HasFactory<AgentSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'title',
        'mission',
        'status',
        'base_branch',
        'agent_branch',
        'current_agent_id',
        'current_step',
        'max_steps',
        'summary_context',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'max_steps' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function currentAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'current_agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class, 'session_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AgentTask::class, 'session_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AgentAction::class, 'session_id');
    }

    public function commits(): HasMany
    {
        return $this->hasMany(AgentCommit::class, 'session_id');
    }
}
