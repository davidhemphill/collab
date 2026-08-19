/**
 * Every frame Hocuspocus sends, against every frame this server sends.
 *
 * The transcript fixtures prove the PHP reader agrees with the protocol as
 * written. They cannot prove the two servers *behave* alike, because a
 * transcript is one message and behaviour is a conversation: what the server
 * volunteers on connect, what it relays to whom, what it answers when a
 * read-only client writes. Those are the divergences that reach a person.
 *
 * So this runs the same scripted client session twice — once against a real
 * @hocuspocus/server 3.4.4, once against the PHP daemon — records every frame
 * in both directions through a wrapped WebSocket, and diffs the two
 * transcripts.
 *
 * Client IDs are random, so raw bytes would differ on every run even for two
 * servers behaving identically. `wire.mjs` describes a frame by what it says
 * and aliases client IDs by order of appearance, which is what makes the two
 * runs comparable.
 *
 * Usage:
 *   node tools/oracle/differential.mjs [--scenario name] [--verbose]
 *
 * The PHP daemon is started by this script from `harness/server.php`, so the
 * only thing to have running beforehand is nothing at all.
 */
import { spawn } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { HocuspocusProvider } from '@hocuspocus/provider'
import * as Y from 'yjs'
import { aliases, describe, line } from './wire.mjs'
import { SCENARIOS } from './scenarios.mjs'

const here = dirname(fileURLToPath(import.meta.url))

const flag = (name, fallback = null) => {
  const index = process.argv.indexOf(`--${name}`)

  return index === -1 ? fallback : (process.argv[index + 1] ?? true)
}

const verbose = process.argv.includes('--verbose')
const only = flag('scenario')

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * A frame this decoder cannot read is a finding, not a crash: the whole point
 * is to compare what the two servers send, and "one of them sent something
 * unreadable" is exactly the kind of answer worth having.
 */
const safely = (direction, bytes, alias) => {
  try {
    return line(direction, describe(bytes, alias))
  } catch (error) {
    return `${direction} <undecodable ${bytes.length}B: ${error.message}> ${Buffer.from(bytes).toString('hex').slice(0, 80)}`
  }
}

/**
 * A WebSocket that writes every frame it carries into a transcript.
 *
 * The provider is given this in place of the global, so nothing about the
 * client's behaviour changes — it is the same unmodified provider, with a
 * recorder around the wire.
 */
function recordingSocket(transcript, alias) {
  return class RecordingWebSocket extends WebSocket {
    constructor(url, protocols) {
      super(url, protocols)
      this.binaryType = 'arraybuffer'

      this.addEventListener('message', (event) => {
        const bytes = new Uint8Array(
          event.data instanceof ArrayBuffer ? event.data : event.data.buffer ?? [],
        )

        if (bytes.length > 0) {
          transcript.push(safely('<-', bytes, alias))
        }
      })
    }

    send(payload) {
      const bytes = payload instanceof Uint8Array ? payload : new Uint8Array(payload)
      transcript.push(safely('->', bytes, alias))
      super.send(payload)
    }
  }
}

/** Start the PHP daemon and wait for it to say which port it took. */
function startPhp(port, scope) {
  const child = spawn('php', [join(here, 'harness', 'server.php')], {
    env: { ...process.env, PORT: String(port), SCOPE: scope },
    stdio: ['ignore', 'inherit', 'pipe'],
  })

  return new Promise((resolve, reject) => {
    const failed = setTimeout(() => reject(new Error('the PHP daemon did not start')), 8000)

    child.stderr.on('data', (chunk) => {
      const text = String(chunk)

      if (text.includes('LISTENING')) {
        clearTimeout(failed)
        resolve(child)
      } else {
        process.stderr.write(`[php] ${text}`)
      }
    })
  })
}

/** Start a real Hocuspocus with the same policy the PHP harness applies. */
async function startHocuspocus(port, scope) {
  const { Server } = await import('@hocuspocus/server')

  const server = new Server({
    port,
    quiet: true,
    onAuthenticate: async ({ connectionConfig }) => {
      connectionConfig.readOnly = scope === 'readonly'

      return { user: 'harness' }
    },
  })

  await server.listen()

  return server
}

/**
 * Run one scenario against one server and return its transcript.
 *
 * The alias table is per-run and per-scenario, so `client1` means "the first
 * document client this run saw" on both sides.
 */
async function record(url, scenario) {
  const transcript = []
  const alias = aliases()
  const Socket = recordingSocket(transcript, alias)
  const clients = []

  const connect = (options = {}) => {
    const doc = options.document ?? new Y.Doc()
    const provider = new HocuspocusProvider({
      url,
      name: scenario.document ?? '4711',
      document: doc,
      token: options.token ?? 'good',
      WebSocketPolyfill: Socket,
      onAuthenticationFailed: () => {},
      ...options.provider,
    })

    const client = { doc, provider, text: doc.getText('t') }
    clients.push(client)

    return client
  }

  await scenario.run({ connect, sleep, transcript, Y })

  for (const { provider } of clients) {
    provider.destroy()
  }

  await sleep(150)

  return transcript
}

/**
 * Compare two transcripts line by line and report the first divergence and
 * everything after it, which is usually one cause with a tail of consequences.
 */
function diff(expected, actual) {
  const findings = []
  const length = Math.max(expected.length, actual.length)

  for (let i = 0; i < length; i++) {
    if (expected[i] !== actual[i]) {
      findings.push({ at: i, hocuspocus: expected[i] ?? '(nothing)', collab: actual[i] ?? '(nothing)' })
    }
  }

  return findings
}

const results = []

for (const scenario of SCENARIOS) {
  if (only && scenario.name !== only) {
    continue
  }

  const scope = scenario.scope ?? 'read-write'
  const hocuspocusPort = 42300 + SCENARIOS.indexOf(scenario) * 2
  const phpPort = hocuspocusPort + 1

  const hocuspocus = await startHocuspocus(hocuspocusPort, scope)
  const expected = await record(`ws://127.0.0.1:${hocuspocusPort}`, scenario)
  await hocuspocus.destroy()

  const php = await startPhp(phpPort, scope)
  const actual = await record(`ws://127.0.0.1:${phpPort}`, scenario)
  php.kill('SIGKILL')

  const findings = diff(expected, actual)
  results.push({ scenario, findings, expected, actual })

  const mark = findings.length === 0 ? '[32m✓[0m' : '[31m✗[0m'
  console.log(`${mark} ${scenario.name} — ${scenario.describes}`)

  if (findings.length > 0 || verbose) {
    for (const { at, hocuspocus: h, collab: c } of findings) {
      console.log(`    ${String(at).padStart(3)}  hocuspocus  ${h}`)
      console.log(`         collab      ${c}`)
    }
  }

  if (verbose) {
    console.log('    --- hocuspocus ---')
    expected.forEach((l, i) => console.log(`    ${String(i).padStart(3)}  ${l}`))
    console.log('    --- collab ---')
    actual.forEach((l, i) => console.log(`    ${String(i).padStart(3)}  ${l}`))
  }
}

const failed = results.filter((r) => r.findings.length > 0)

console.log()

if (failed.length === 0) {
  console.log(`All ${results.length} scenarios produced identical transcripts.`)
  process.exit(0)
}

console.log(
  `${failed.length} of ${results.length} scenarios diverge: ${failed.map((f) => f.scenario.name).join(', ')}`,
)
process.exit(1)
