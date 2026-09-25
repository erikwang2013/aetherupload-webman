# aetherupload-webman

> 大文件分块上传插件：断线续传 / 秒传 / 完整性校验，框架无关内核 + 七个宿主适配器

## Project Overview

**它是什么**：一个 PHP composer 包。浏览器端把大文件切片，逐块追加到服务端的临时文件，落盘时用文件内容的
md5 命名 —— 于是上传、断线续传、秒传、去重、校验共用同一套机制，不占内存，也不需要数据库表。
移植自 [AetherUpload-Laravel](https://github.com/peinhu/AetherUpload-Laravel)。

**Tech Stack**: PHP 8.0–8.4（webman + Workerman 常驻进程模型为原生场景）· PHPUnit 9.6 / 10.5 · Redis（仅秒传，可选）
**Architecture**: 端口-适配器（ports & adapters）。内核 `src/` 只经静态门面 `Runtime` 与 `src/Contract/` 的
11 个接口访问宿主；七个适配器在 `src/Adapter/{Webman,Laravel,ThinkPhp,Symfony,Slim,Hyperf,Yii}/`。
可变状态一律进 `RequestContext`（每请求/每协程），适配器与 `Runtime` 只持有不可变绑定。

## Quick Start

### Installation
```bash
composer install --no-interaction
```

### Test
```bash
vendor/bin/phpunit --no-coverage                              # 单元套件（替身，不加载任何框架）
vendor/bin/phpunit -c tests/Integration/webman/phpunit.xml    # webman 端到端（起真服务 + curl）
bash tests/Integration/laravel/ci.sh                          # 其余六个同构（laravel/thinkphp/symfony/slim/hyperf/yii）
```

### PHP 版本矩阵
```bash
docker run --rm -v $PWD:/app -w /app php:8.0-cli-alpine php vendor/bin/phpunit --no-coverage
```

### Development
没有构建步骤 —— PHP 无编译、无打包，`composer install` 之后直接改代码跑测试。
改内核前先读 `docs/HARNESS.md`（测试契约）；改配置键要同步 `config/app.php`、`config/aetherupload.php`
与 Symfony 的 `Configuration` 树，`tests/ConfigParityTest.php` 会锁死三者一致。

## Agent Coordination

### Swarm Configuration

This project uses hierarchical swarm coordination for complex tasks:

| Setting | Value | Purpose |
|---------|-------|---------|
| Topology | `hierarchical` | Queen-led coordination (anti-drift) |
| Max Agents | 8 | Optimal team size |
| Strategy | `specialized` | Clear role boundaries |
| Consensus | `raft` | Leader-based consistency |

### When to Use Swarms

**Invoke swarm for:**
- Multi-file changes (3+ files)
- New feature implementation
- Cross-module refactoring
- API changes with tests
- Security-related changes
- Performance optimization

**Skip swarm for:**
- Single file edits
- Simple bug fixes (1-2 lines)
- Documentation updates
- Configuration changes

### Available Skills

Use `$skill-name` syntax to invoke:

| Skill | Use Case |
|-------|----------|
| `$swarm-orchestration` | Multi-agent task coordination |
| `$memory-management` | Pattern storage and retrieval |
| `$sparc-methodology` | Structured development workflow |
| `$security-audit` | Security scanning and CVE detection |

### Agent Types

| Type | Role | Use Case |
|------|------|----------|
| `researcher` | Requirements analysis | Understanding scope |
| `architect` | System design | Planning structure |
| `coder` | Implementation | Writing code |
| `tester` | Test creation | Quality assurance |
| `reviewer` | Code review | Security and quality |

## Execution Model

- **claude-flow** = LEDGER (coordinates: memory, routing, swarm state)
- **Codex** = EXECUTOR (writes code, runs tests, creates files)

**Critical rule:** DON'T STOP after calling claude-flow commands. Coordination commands return instantly — continue immediately with the next implementation step.

## Ruflo + Codex Automated Workflow

Ruflo is the coordination ledger and policy decision point; Codex workers execute code, tests, and commands. A Ruflo coordination call records work but never replaces implementation.

Use `guidance_brain({ mode: "recommend", task: "..." })` when the task can
benefit from Ruflo-specific capabilities. Its live registry is authoritative
for tool presence; registration alone does not prove configuration,
reachability, health, or authorization. If it is not registered, use compatible
`guidance_recommend`, CLI discovery, and repository instructions.

1. **Recall** — search AgentDB memory and relevant ADRs for patterns and constraints.
2. **Inspect** — read source, runtime, dependency, policy, and health state.
3. **Route** — choose the smallest capable topology, agents, skills, and tools.
4. **Plan** — define acceptance criteria, safety envelope, ownership, and validation.
5. **Execute** — Codex workers implement in isolated scopes; Ruflo records coordination.
6. **Test** — run focused tests, regression tests, and failure-path checks.
7. **Validate** — check types, security, policy, compatibility, and artifact integrity.
8. **Benchmark** — compare a source-bound candidate with a source-bound baseline.
9. **Optimize** — improve measured bottlenecks without weakening the safety envelope.
10. **Receipt** — bind claims, evidence, and decisions to exact source/build inputs.
11. **Handoff** — reconcile concurrent work and disclose unresolved limitations.
12. **Publish** — only an independently authorized release gate may publish immutable artifacts.

### Concurrency and authority invariants

- Never allow two writers in one worktree.
- Read-only research agents may share a checkout; writing agents may not.
- A child may drop capabilities but can never add tools, servers, namespaces, network access, spend, concurrency, or delegation depth.
- Cancel dependent and not-yet-started sibling work when policy denies an action or a required dependency fails.
- MetaHarness may benchmark candidates concurrently, but it cannot promote, serve, or expand its own SafetyEnvelope.
- Only the integration agent changes shared manifests or lockfiles.
- Do not auto-commit, push, merge, release, or delete worktrees unless the user authorized that operation.
- Every consequential action must produce a policy decision receipt; production, destructive, spend, and promotion actions may require human approval.

### Repository harness adapter

When tracked repository instructions define a local collaboration harness:

1. Assign the isolated worktree before starting a writing session.
2. Start or register the session, inspect current claims, and acquire only the
   exact paths, resources, and development ports needed for the task.
3. Renew leases during long work, check acknowledged inbox messages at integration
   boundaries, and release claims when handing off or ending.
4. Record focused and integration evidence against the exact source state,
   then let the designated integration owner decide release.

A repository lease coordinates ownership; it does not grant authorization.
In-memory reference adapters demonstrate semantics but are not distributed,
restart-durable release authorities.
The worker still needs the current ADR-324/325 action capability and fencing
epoch for every protected side effect. Heartbeat and lease expiry establish
liveness; a PID is diagnostic only. HEAD alone is not an exact source-state
identity when tracked or untracked changes exist, so a release receipt must
bind a clean commit or an immutable snapshot including those changes.


## MCP Integration

Use MCP tools for coordination, then keep coding:

| Tool | Purpose | Example |
|------|---------|---------|
| `swarm_init` | Start coordination | `swarm_init({topology: "hierarchical"})` |
| `memory_store` | Save patterns | `memory_store({key: "auth", value: "JWT"})` |
| `memory_search` | Find patterns | `memory_search({query: "auth patterns"})` |
| `task_orchestrate` | Assign work | `task_orchestrate({task: "implement"})` |

## Code Standards

### File Organization
- **NEVER** save to root folder
- `/src` - Source code files
- `/tests` - Test files
- `/docs` - Documentation
- `/config` - Configuration files

### Quality Rules
- Files under 500 lines
- No hardcoded secrets
- Input validation at boundaries
- Typed interfaces for public APIs
- TDD London School (mock-first) preferred

### Commit Messages
```
<type>(<scope>): <description>

[optional body]
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `chore`

Do not add a `Co-Authored-By` trailer unless the repository explicitly
configures and authorizes that attribution.

## Security

### Critical Rules
- NEVER commit secrets, credentials, or .env files
- NEVER hardcode API keys
- Always validate user input
- Use parameterized queries for SQL
- Sanitize output to prevent XSS

### Path Security
- Validate all file paths
- Prevent directory traversal (../)
- Use absolute paths internally

## Memory System

### Storing Patterns
```bash
npx @claude-flow/cli memory store \
  --key "pattern-name" \
  --value "pattern description" \
  --namespace patterns
```

### Searching Memory
```bash
npx @claude-flow/cli memory search \
  --query "search terms" \
  --namespace patterns
```

## Quick Commands

```bash
npx @claude-flow/cli memory search --query "relevant patterns"
npx @claude-flow/cli hooks route --task "current task description"
npx @claude-flow/cli swarm init --topology hierarchical
npx @claude-flow/cli hooks pre-task --description "task summary"
```

## Links

- 本仓库：https://github.com/erikwang2013/aetherupload-webman
- Issue 反馈：https://github.com/erikwang2013/aetherupload-webman/issues
- 上游移植来源：https://github.com/peinhu/AetherUpload-Laravel

> 本文档的 Agent Coordination / Execution Model / MCP Integration 等章节来自 ruflo 初始化模板，
> 与本包的代码无关；只把上面「Project Overview / Quick Start」当真。
