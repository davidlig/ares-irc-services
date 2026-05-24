# .agents — Skills & Instructions

This directory contains **skills** (domain- and task-specific instructions) for the AI assistant. They complement `AGENTS.md`, which defines global non-negotiable rules.

Content is grouped **by type** in subdirectories. Each type has a `README.md` (entry point) and supporting files for detailed guidance.

## Available Types

| Type | Location | Use When |
|------|----------|----------|
| **Workflow** | `.agents/workflow.md` | Parallel execution, pre-commit chain, bug workflow |
| **Documentation** | `.agents/documentation.md` | Context7 MCP for library/framework docs |
| **Architecture** | `.agents/architecture/` | Bounded contexts, entities, events, drop cleanup |
| **Database** | `.agents/database/` | Doctrine ORM, XML mapping, migrations |
| **Services** | `.agents/services/` | Core vs Services, commands, permissions, translations, bots |
| **Testing** | `.agents/testing/` | Test rules, patterns, coverage priorities |
| **Protocol** | `.agents/protocol/` | IRCd protocol modules, adding new IRCd |
| **Memory** | `.agents/memory/` | Daemon memory management, Doctrine clear |

## File Index

```
.agents/
├── README.md                          ← This file
├── workflow.md                        ← Workflow & operations
├── documentation.md                   ← Context7 MCP + project libraries
│
├── architecture/
│   ├── README.md                      ← Bounded contexts, layers, Port boundary
│   ├── entities.md                    ← Entity design patterns
│   ├── events.md                      ← Domain events & subscribers
│   └── drop-cleanup.md               ← Ref cleanup on DropEvent
│
├── database/
│   └── README.md                      ← Doctrine ORM, XML mapping, migrations
│
├── services/
│   ├── README.md                      ← Core vs Services overview
│   ├── commands.md                    ← Command handler structure
│   ├── commands-permissions.md        ← Authorization, voters, IRCop sync
│   ├── commands-translations.md       ← i18n YAML, IRC colors, 14-language rule
│   ├── commands-testing.md            ← Test patterns for commands
│   ├── live-mcp-testing.md            ← IRC/MariaDB live validation safety
│   ├── bots.md                        ← New bot/service implementation
│   ├── help-design.md                 ← Unified HELP format
│   ├── debug-actions.md               ← Debug logging for IRCop commands
│   └── ircop-commands.md             ← IRCop permission system
│
├── testing/
│   ├── README.md                      ← Core testing rules
│   ├── testing-patterns.md            ← Patterns by layer/type
│   └── testing-coverage-priorities.md ← Test priorities map
│
├── protocol/
│   ├── README.md                      ← Protocol modules, multi-IRCd
│   └── adding-new-ircd.md            ← Checklist for new IRCd
│
└── memory/
    └── README.md                      ← Daemon memory management
```

## Usage

1. **AGENTS.md** always applies (non-negotiable rules)
2. For a given task, find the matching type above and read its `README.md`
3. Use the other `.md` files in that directory for full detail
4. All skills are English-only
