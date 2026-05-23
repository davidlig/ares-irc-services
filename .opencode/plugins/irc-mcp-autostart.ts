import type { Plugin } from "@opencode-ai/plugin"
import { mkdirSync } from "node:fs"

type Env = Record<string, string>

let wrapperProcess: ReturnType<typeof Bun.spawn> | null = null

async function readEnvFileAsync(path: string): Promise<Env> {
  const file = Bun.file(path)
  if (!(await file.exists())) return {}

  const env: Env = {}
  const content = await file.text()

  for (const rawLine of content.split("\n")) {
    const line = rawLine.trim()
    if (!line || line.startsWith("#")) continue

    const separator = line.indexOf("=")
    if (-1 === separator) continue

    env[line.slice(0, separator)] = line.slice(separator + 1)
  }

  return env
}

function stopIrcProcess(): void {
  if (!wrapperProcess) return

  try {
    process.kill(wrapperProcess.pid, "SIGTERM")
  } catch {
    // Best effort shutdown; the wrapper also self-cleans when opencode exits.
  }

  wrapperProcess = null
}

function registerShutdownHooks(): void {
  process.once("exit", stopIrcProcess)
  process.once("SIGINT", () => {
    stopIrcProcess()
    process.exit(130)
  })
  process.once("SIGTERM", () => {
    stopIrcProcess()
    process.exit(143)
  })
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function waitForMcp(url: string, timeoutMs: number): Promise<void> {
  const deadline = Date.now() + timeoutMs

  while (Date.now() < deadline) {
    try {
      await fetch(url, { signal: AbortSignal.timeout(500) })
      return
    } catch {
      await sleep(250)
    }
  }

  throw new Error(`IRC MCP did not become ready within ${timeoutMs}ms at ${url}`)
}

export const IrcMcpAutostart: Plugin = async ({ directory }) => {
  const env = await readEnvFileAsync(`${directory}/.opencode/mcp.env`)
  const mcpPort = env.OPENCODE_IRC_MCP_PORT ?? "3005"
  const mcpUrl = `http://127.0.0.1:${mcpPort}/mcp`
  const readyTimeoutMs = Number(env.OPENCODE_IRC_MCP_READY_TIMEOUT_MS ?? "30000")
  const workdir = "/tmp/opencode-mcp-irc"

  mkdirSync(workdir, { recursive: true })

  wrapperProcess = Bun.spawn([
    "bash",
    "-lc",
    "parent_pid=\"$1\"; shift; setsid \"$@\" & child_pid=$!; cleanup() { kill -TERM \"-$child_pid\" 2>/dev/null || kill -TERM \"$child_pid\" 2>/dev/null || true; wait \"$child_pid\" 2>/dev/null || true; }; trap cleanup EXIT INT TERM HUP; while kill -0 \"$parent_pid\" 2>/dev/null; do if ! kill -0 \"$child_pid\" 2>/dev/null; then wait \"$child_pid\"; exit \"$?\"; fi; sleep 1; done; cleanup",
    "opencode-irc-mcp-wrapper",
    String(process.pid),
    "npx",
    "-y",
    "mcp-irc",
    "--url",
    env.OPENCODE_IRC_URL ?? "irc.example.net",
    "--port",
    env.OPENCODE_IRC_PORT ?? "6697",
    "--mcpPort",
    mcpPort,
    "--nick",
    env.OPENCODE_IRC_NICK ?? "AresMCP",
    "--randomize-nick-suffix",
    "-n",
    env.OPENCODE_IRC_HISTORY_LIMIT ?? "1000",
  ], {
    cwd: workdir,
    env: { ...process.env, ...env },
    stdin: "ignore",
    stdout: "ignore",
    stderr: "ignore",
  })

  registerShutdownHooks()
  await waitForMcp(mcpUrl, readyTimeoutMs)

  return {}
}

export default IrcMcpAutostart
