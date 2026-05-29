# RexMarket Agent Orchestrator Rules

This project is `rexmarket-agent`, a local Laravel orchestrator for multi-agent work.

- Do not implement RexMarket product changes directly inside this codebase.
- Do not edit the target RexMarket workspace except through the workspace service layer.
- Resolve the target project from `TARGET_WORKSPACE_PATH`.
- Run target commands with the target workspace as the working directory.
- Keep target work on `agent/*` branches or the configured workspace agent branch prefix.
- Do not hardcode API keys, provider tokens, or other secrets.
- Do not add production deploy, push, merge, SSH, or release automation.
- Keep the first version local, sandboxed, CLI-driven, and safety-focused.
- Treat protected paths as blocked until an explicit approval workflow is implemented.
