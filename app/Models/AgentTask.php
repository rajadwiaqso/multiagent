<?php

namespace App\Models;

use Database\Factories\AgentTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTask extends Model
{
    /** @use HasFactory<AgentTaskFactory> */
    use HasFactory;

    protected $fillable = [
        'session_id',
        'created_by_agent_id',
        'assigned_to_agent_id',
        'title',
        'description',
        'status',
        'result',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class, 'session_id');
    }

    public function createdByAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'created_by_agent_id');
    }

    public function assignedToAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'assigned_to_agent_id');
    }
}
