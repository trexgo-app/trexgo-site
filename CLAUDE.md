@../rules/collaboration.md

# Development instructions

Read the workspace collaboration and documentation rules before editing.
Answer users in Russian; keep methodology instructions in English.
Preserve unrelated edits and repository conventions. Use isolated worktrees.

Detailed project documentation lives in the private knowledge repository:
https://github.com/vanss13-ops/trexgo-knowledge-base/blob/main/documentation/site/index.md
Before setup, troubleshooting, querying production data, or changing runtime
configuration, read the relevant operations/testing guide and the technical
development context in documentation/site/development/agent-context.md.

Update canonical documentation when behavior, interfaces, data, configuration,
or operations change. Link paired repository changes and reviewed code SHA.
Keep repository/module README files concise; do not duplicate detailed pages.
Local module AGENTS.md rules also apply; their CLAUDE.md files import them.

Never commit secrets. Do not expose private knowledge in the public website.
Get product facts from knowledge and cite sources; do not invent facts.
Keep executable schemas, fixtures, migrations, generated contracts and types
in their code repository. Verify proportionately and inspect diff/status.
Pure documentation uses the workflow exception; runtime changes must reach
production and be verified in the same session. No blocking documentation CI.
