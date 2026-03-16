# .agents — Context-specific skills and instructions

This directory contains **skills** (domain- or task-specific instructions) that the agent should consult when the work requires it. They do not replace `AGENTS.md`, which defines the project's global rules.

Content is grouped **by type** in subdirectories. Each type has a `README.md` (short skill) and any supporting `.md` files.

## Usage

- **AGENTS.md** always applies.
- For a given task, open the matching type folder under `.agents/<type>/` and read its `README.md`; use the other `.md` files there for full detail.

## Available types

| Type     | Path                  | When to use |
|----------|-----------------------|-------------|
| Testing  | `.agents/testing/`    | Add tests, review coverage, prioritise what to test, run PHPUnit. |
| Memory   | `.agents/memory/`     | Long-running daemons, memory leaks, Doctrine clear, GC cycles. |
| Protocol | `.agents/protocol/`   | IRCd protocol modules, wire format, adding new IRCd support. |
| Services | `.agents/services/`   | Core vs Services architecture, Ports, Bots, adding new service, HELP design. |

## Adding a new type

1. Create a directory `.agents/<type>/`.
2. Add `README.md` with the short skill (conventions, commands, entry points).
3. Add any further `.md` docs (e.g. priorities, reference) in that directory.
4. Register the type in the table above and, if relevant, in the corresponding section of `AGENTS.md`.