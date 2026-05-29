<?php

namespace App\Models;

use Database\Factories\AgentCommitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCommit extends Model
{
    /** @use HasFactory<AgentCommitFactory> */
    use HasFactory;

    protected $fillable = [
        'session_id',
        'agent_id',
        'workspace_id',
        'branch',
        'commit_hash',
        'commit_message',
        'changed_files',
    ];

    protected function casts(): array
    {
        return [
            'changed_files' => 'array',
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

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
