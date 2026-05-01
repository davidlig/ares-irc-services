# Documentation & Context7 MCP

Use this skill when you need current documentation for libraries, frameworks, or APIs used in the project.

---

## When to Use Context7 MCP

When you need documentation for ANY library used in this project, use Context7 MCP if available:

1. `context7_resolve-library-id` — find the library ID
2. `context7_query-docs` — ask the specific question
3. If unsatisfied → retry with `researchMode: true`

**NEVER rely solely on training data** — always verify with Context7 when available.

---

## Project Library Reference

| Library | Context7 ID | Version | Usage |
|---------|-------------|---------|-------|
| Symfony | `/symfony/symfony` | 7.4.x | Framework, DI, console, security, messenger, mailer, translation |
| Doctrine ORM | `/doctrine/orm` | 3.6.x | Entity mapping (XML), repositories, DQL, migrations |
| PHPUnit | `/phpunit/phpunit` | 13.x | Testing, `#[CoversClass]`, `#[Test]`, createStub/createMock |
| PHP CS Fixer | `/friendsofphp/php-cs-fixer` | 3.x | Code style rules, `.php-cs-fixer.dist.php` |
| Symfony Monolog | `/seldaek/monolog` | — | Logging, log levels, handlers |

### When to Query Each

| Task | Library to Query |
|------|-----------------|
| Symfony service configuration, DI, tags | `/symfony/symfony` |
| Console commands, `#[AsCommand]` | `/symfony/symfony` |
| Security voters, `Voter` class | `/symfony/symfony` |
| Messenger buses, handlers, middleware | `/symfony/symfony` |
| Mailer, email templates | `/symfony/symfony` |
| Translation component, `TranslatorInterface` | `/symfony/symfony` |
| Doctrine entity mapping, DQL queries | `/doctrine/orm` |
| Doctrine migrations, schema updates | `/doctrine/orm` |
| PHPUnit attributes, test doubles, coverage | `/phpunit/phpunit` |
| PHP CS Fixer rules, custom fixers | `/friendsofphp/php-cs-fixer` |
| Monolog configuration, custom handlers | `/seldaek/monolog` |

---

## Context7 Workflow Steps

### Step 1: Resolve Library ID

```
context7_resolve-library-id(
    libraryName: "Symfony",
    query: "How to configure tagged services in Symfony 7.4"
)
```

Pick the best match by: name, source reputation, snippet count, benchmark score.

### Step 2: Query Documentation

```
context7_query-docs(
    libraryId: "/symfony/symfony",
    query: "How to configure tagged iterator services in services.yaml"
)
```

### Step 3: Research Mode (if unsatisfied)

Same call with `researchMode: true` — more expensive but does deep repo + web search.

---

## Local Documentation

The project also has local docs for IRC protocol reference:

| Path | Content |
|------|---------|
| `docs/inspircd/` | InspIRCd v4 documentation |
| `docs/unrealircd/` | UnrealIRCd 6 documentation |
| `docs/rfc/` | RFC 1459, 2812, 7194 |
| `docs/src/anope-2.1/` | Anope reference implementation |
| `docs/src/inspircd-4.10.1/` | InspIRCd reference source |
| `docs/src/unrealircd-6.2.4/` | UnrealIRCd reference source |
