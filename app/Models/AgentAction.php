<?php

namespace App\Models;

use Database\Factories\AgentActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentAction extends Model
{
    /** @use HasFactory<AgentActionFactory> */
    use HasFactory;

    protected $fillable = [
        'session_id',
        'agent_id',
        'type',
        'payload',
        'status',
        'requires_approval',
        'result',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'requires_approval' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class, 'session_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
