<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'path',
        'type',
        'base_branch',
        'agent_branch_prefix',
        'protected_paths',
        'allowed_commands',
        'approval_required_commands',
        'blocked_commands',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'protected_paths' => 'array',
            'allowed_commands' => 'array',
            'approval_required_commands' => 'array',
            'blocked_commands' => 'array',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AgentSession::class);
    }

    public function commits(): HasMany
    {
        return $this->hasMany(AgentCommit::class);
    }
}
