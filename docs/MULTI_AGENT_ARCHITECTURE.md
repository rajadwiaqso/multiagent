# Multi-Agent Architecture

## Two Codebases

`rexmarket-agent` is the Laravel orchestrator. It stores workspaces, agents, sessions, messages, tasks, actions, commits, safety configuration, and command history.

`rexmarket` is the target Laravel + Vue + Inertia + Tailwind workspace. It is not nested in this repository. The orchestrator locates it through:

```env
TARGET_WORKSPACE_PATH="C:\Users\Raja Dwi Aqso\Documents\rexmarket"
```

## Mission Flow

1. Initialize a workspace record with `agents:workspace:init`.
2. Create a mission with `agents:mission`.
3. The orchestrator creates or checks out an `agent/*` branch in the target workspace.
4. Each `agents:step` picks the next enabled agent by `sort_order`.
5. Session state, messages, tasks, actions, and summaries are stored in the orchestrator database.
6. Target workspace reads, writes, git operations, and commands go through dedicated services.

## Agent Cycle

The default seed creates three agents:

- Planner: breaks a mission into scoped work and task records.
- Implementer: prepares implementation actions. The first version uses a no-op stub.
- Reviewer: records safety and review actions. The first version uses a no-op stub.

The LLM integration is intentionally fake in v1 through `FakeLlmClient`. Replace the `LlmClient` binding later when a real provider is ready.

## JSON Action Protocol

Agent output is processed as JSON:

```json
{
  "message": "Human-readable agent note.",
  "actions": [
    { "tool": "read_file", "path": "routes/web.php" },
    { "tool": "list_files", "path": "app/Models" },
    { "tool": "apply_patch", "patch": "..." },
    { "tool": "run_command", "command": "php artisan test" },
    { "tool": "git_status" },
    { "tool": "git_diff" },
    { "tool": "commit", "message": "feat(scope): message" }
  ],
  "next_agent": "implementer",
  "status": "continue"
}
```

`AgentActionProcessor` records every requested action in `agent_actions`, dispatches allowed tools through workspace services, leaves approval-required commands pending, and records blocked or failed actions without stopping later safe actions.

## Session Modes

Each session has a `mode`, optional `allowed_tools`, and `max_actions_per_step`.

- `readonly`: only `read_file`, `list_files`, `git_status`, and `git_diff` run directly. Mutating tools and commands are blocked.
- `suggest`: read-only tools run directly; `apply_patch`, `run_command`, and `commit` are recorded as pending approval.
- `sandbox`: read-only tools and safe `apply_patch` can run directly on the exact session branch; `commit` and approval-required commands stay pending.
- `auto`: read-only tools, safe `apply_patch`, safe commands, and safe commits can run directly on the exact session branch.

If `allowed_tools` is set, it becomes a per-session allow-list on top of the mode. Any action beyond `max_actions_per_step` is stored as blocked. Patch and commit actions must run on the exact `agent_sessions.agent_branch`, not only any `agent/*` branch. Commits require approval when they touch more than 10 files, migrations, config files, deletions, or protected paths.

Workflow control is intentionally conservative:

- `waiting_approval` from the LLM pauses the session and ignores `next_agent`.
- Max action overflow records `blocked_reason=max_actions_per_step_exceeded` and pauses by default.
- Repeating the same `next_agent` more than twice pauses with `agent_loop_detected`.
- Reaching `max_steps` without LLM `status=completed` pauses with `max_steps_reached`.
- A session completes only when the LLM returns `status=completed` and the step has no critical blocked or failed action.
- In `readonly`, Planner can continue to Planner for read-only exploration up to `readonly_planner_max_self_loops` steps, then the orchestrator routes to Reviewer. Invalid `next_agent` values fall back to Reviewer only when all actions in the step were read-only and successful.
- Reviewer can return `status=completed` to complete the session. Reviewer -> Planner is allowed once for extra context; repeated Reviewer -> Planner loops pause with `agent_loop_detected`.

## Safety Guard

The orchestrator blocks:

- Path traversal and absolute agent file paths.
- File changes outside the configured workspace.
- Writes or patches while the target branch is not `agent/*`.
- Commits to `main`, `master`, `develop`, `production`, or `staging`.
- Protected paths such as `.env`, `vendor/`, `node_modules/`, `config/database.php`, and sensitive service folders.
- Blocked commands such as `rm -rf`, `sudo`, destructive migrations, deploy, SSH, SCP, and recursive `chmod 777`.

Approval-required commands are represented structurally, but protected path changes are blocked in this first version.

## Branch Strategy

The target workspace keeps normal product branches untouched. Agent missions run on a branch using the workspace prefix, defaulting to:

```text
agent/<mission-name>
```

The system does not push, merge, or deploy.

## Command Usage

```bash
php artisan agents:workspace:init
php artisan agents:mission "Tambah fitur wishlist produk" --branch=agent/wishlist-product
php artisan agents:step 1
php artisan agents:run 1
php artisan agents:diff 1
php artisan agents:report 1
php artisan agents:pause 1
php artisan agents:stop 1
```
