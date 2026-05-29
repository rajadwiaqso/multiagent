<?php

namespace App\Models;

use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'role',
        'provider',
        'model',
        'api_key_name',
        'system_prompt',
        'enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AgentSession::class, 'current_agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AgentTask::class, 'assigned_to_agent_id');
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(AgentTask::class, 'created_by_agent_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AgentAction::class);
    }

    public function commits(): HasMany
    {
        return $this->hasMany(AgentCommit::class);
    }
}
