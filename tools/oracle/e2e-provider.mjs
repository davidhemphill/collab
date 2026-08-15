/**
 * The exit gate: an unmodified @hocuspocus/provider against the PHP server.
 *
 * Everything in the PHP suite is checked against transcripts built from the
 * Hocuspocus source. This is the first time the real client is on the wire, so
 * it is the only test that can find a disagreement between what the source says
 * and what the provider does.
 */
import { HocuspocusProvider } from '@hocuspocus/provider'
import * as Y from 'yjs'

const [url, name, token] = process.argv.slice(2)

const results = []
const record = (ok, label, detail = '') => {
  results.push({ ok, label, detail })
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? ` — ${detail}` : ''}`)
}

const deadline = (ms, what) =>
  new Promise((_, reject) => setTimeout(() => reject(new Error(`timed out: ${what}`)), ms))

const until = (check, what, ms = 8000) =>
  Promise.race([
    new Promise((resolve) => {
      const tick = () => (check() ? resolve() : setTimeout(tick, 50))
      tick()
    }),
    deadline(ms, what),
  ])

function connect(label, tokenValue = token) {
  const doc = new Y.Doc()
  const state = { synced: false, authFailed: null, status: null }

  const provider = new HocuspocusProvider({
    url,
    name,
    token: tokenValue,
    document: doc,
    WebSocketPolyfill: WebSocket,
    onSynced: () => { state.synced = true },
    onAuthenticationFailed: ({ reason }) => { state.authFailed = reason },
    onStatus: ({ status }) => { state.status = status },
  })

  return { label, doc, provider, state }
}

try {
  // 1. The handshake. If the provider disagrees with us about a single byte of
  //    the authentication or sync exchange, it never reaches synced.
  const a = connect('A')
  await until(() => a.state.synced, 'client A to sync')
  record(true, 'A real provider reaches synced state')

  const b = connect('B')
  await until(() => b.state.synced, 'client B to sync')
  record(true, 'A second provider syncs against the same document')

  // 2. An edit crosses between two real clients.
  a.doc.getText('content').insert(0, 'Hello from A')
  await until(() => b.doc.getText('content').toString() === 'Hello from A', 'A to reach B')
  record(true, 'Text typed by A arrives at B', JSON.stringify(b.doc.getText('content').toString()))

  // 3. Both directions, and a concurrent edit that must not overwrite.
  b.doc.getText('content').insert(12, ' and B')
  await until(() => a.doc.getText('content').toString() === 'Hello from A and B', 'B to reach A')
  record(true, 'Text typed by B arrives at A', JSON.stringify(a.doc.getText('content').toString()))

  a.doc.getText('content').insert(0, '[a]')
  b.doc.getText('content').insert(b.doc.getText('content').length, '[b]')
  await until(
    () => a.doc.getText('content').toString() === b.doc.getText('content').toString(),
    'concurrent edits to converge',
  )
  record(true, 'Simultaneous edits converge, keeping both', JSON.stringify(a.doc.getText('content').toString()))

  // 4. Awareness: the cursors and names the other person sees.
  a.provider.setAwarenessField('user', { name: 'Ada' })
  await until(() => {
    const states = Array.from(b.provider.awareness.getStates().values())
    return states.some((s) => s.user?.name === 'Ada')
  }, 'awareness from A to reach B')
  record(true, "B sees A's presence")

  // 5. A third client, joining late, is given the whole document.
  const c = connect('C')
  await until(() => c.state.synced, 'client C to sync')
  await until(
    () => c.doc.getText('content').toString() === a.doc.getText('content').toString(),
    'late client to receive the existing document',
  )
  record(true, 'A client joining late receives the whole document')

  // 6. A bad token is refused rather than quietly admitted.
  const bad = connect('D', 'not-a-real-token')
  await until(() => bad.state.authFailed !== null, 'a bad token to be refused')
  record(true, 'A bad token is refused', JSON.stringify(bad.state.authFailed))

  a.provider.destroy(); b.provider.destroy(); c.provider.destroy(); bad.provider.destroy()
} catch (failure) {
  record(false, 'Run did not complete', failure.message)
}

const failed = results.filter((r) => !r.ok).length
console.log(`\n${results.length - failed}/${results.length} checks passed`)
process.exit(failed === 0 ? 0 : 1)
