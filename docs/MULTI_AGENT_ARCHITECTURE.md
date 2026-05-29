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
