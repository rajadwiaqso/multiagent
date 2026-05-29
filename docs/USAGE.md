# Usage

## Setup

Set the target workspace path in `.env`:

```env
TARGET_WORKSPACE_PATH="C:\Users\Raja Dwi Aqso\Documents\rexmarket"
AGENT_LLM_DRIVER=fake
```

For an OpenAI-compatible provider, set:

```env
AGENT_LLM_DRIVER=openai-compatible
AGENT_LLM_BASE_URL=https://provider.example/v1
AGENT_LLM_API_KEY=...
AGENT_LLM_MODEL=your-model
```

Run migrations and seed the default agents:

```bash
php artisan migrate --seed
```

Initialize the RexMarket workspace record:

```bash
php artisan agents:workspace:init
```

## First Mission

Create a mission and agent branch:

```bash
php artisan agents:mission "Tambah fitur wishlist produk" --branch=agent/wishlist-product
```

Choose a session mode when creating a mission:

```bash
php artisan agents:mission "Audit route marketplace" --branch=agent/audit-route --mode=readonly
php artisan agents:mission "Coba patch kecil" --branch=agent/sandbox-patch --mode=sandbox --max-actions-per-step=5
```

Restrict tools for a mission:

```bash
php artisan agents:mission "Inspect models" --branch=agent/inspect-models --allow-tools=read_file,list_files,git_status,git_diff
```

Update mode or tools later:

```bash
php artisan agents:mode 1 sandbox
php artisan agents:tools 1 --allow=read_file,list_files,git_status,git_diff
```

Run one step:

```bash
php artisan agents:step 1
```

Run until completion, stop, failure, max steps, or approval:

```bash
php artisan agents:run 1 --until-approval
```

Session status notes:

- `paused` means the orchestrator intentionally stopped and stored a reason in session metadata.
- `paused_reason=waiting_approval` means the LLM asked for approval or the run stopped on an approval boundary.
- `paused_reason=max_actions_per_step_exceeded` means the LLM returned more actions than the session limit.
- `paused_reason=max_steps_reached` means the step budget ended before the LLM returned `status: completed`.
- `paused_reason=agent_loop_detected` means the same `next_agent` repeated too many times.

`max_actions_per_step` defaults to `5`. Extra actions are recorded as blocked, and the session pauses by default so the operator can inspect the report before continuing.

For real LLM work in `readonly` mode, the safe default workflow is:

```text
Planner -> Planner -> ... -> Reviewer -> completed
Planner -> Reviewer -> completed
Planner -> Implementer -> Reviewer -> completed
```

Planner can keep reading with read-only tools for a few steps. The default `readonly_planner_max_self_loops` is `3`; when the limit is reached, the orchestrator routes to Reviewer and records `readonly_planner_self_loop_limit_reached`.

Invalid `next_agent` values fall back to Reviewer in readonly mode only when the step used safe read-only actions. When the LLM sends `status: waiting_approval`, the orchestrator ignores `next_agent` and pauses the session.

Inspect target diff:

```bash
php artisan agents:diff 1
```

View a full report:

```bash
php artisan agents:report 1
```

Pause or stop:

```bash
php artisan agents:pause 1
php artisan agents:stop 1
```

## Notes

- `FakeLlmClient` is a placeholder and does not call any real model provider.
- `OpenAiCompatibleLlmClient` uses Laravel HTTP Client and expects chat completions responses.
- The implementer stub does not write target code yet.
- All target code changes must go through workspace services and must be on an `agent/*` branch.
- The system does not push, merge, deploy, or store API keys.
