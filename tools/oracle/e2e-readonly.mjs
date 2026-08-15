/**
 * Read-only permission, checked from the client side.
 *
 * The PHP suite proves the session refuses the update. This proves the refusal
 * is what a real editor experiences: the other person never sees the text.
 */
import { HocuspocusProvider } from '@hocuspocus/provider'
import * as Y from 'yjs'

const [url, name, ownerToken, readerToken] = process.argv.slice(2)

const wait = (ms) => new Promise((r) => setTimeout(r, ms))
const until = (check, what, ms = 8000) => Promise.race([
  new Promise((resolve) => { const t = () => (check() ? resolve() : setTimeout(t, 50)); t() }),
  new Promise((_, rej) => setTimeout(() => rej(new Error(`timed out: ${what}`)), ms)),
])

function connect(token) {
  const doc = new Y.Doc()
  const state = { synced: false }
  const provider = new HocuspocusProvider({
    url, name, token, document: doc, WebSocketPolyfill: WebSocket,
    onSynced: () => { state.synced = true },
  })
  return { doc, provider, state }
}

let failures = 0
const check = (ok, label, detail = '') => {
  if (!ok) failures++
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? ` — ${detail}` : ''}`)
}

const owner = connect(ownerToken)
const reader = connect(readerToken)

await until(() => owner.state.synced, 'owner to sync')
await until(() => reader.state.synced, 'reader to sync')
check(true, 'A read-only client is allowed to connect and read')

const before = owner.doc.getText('content').toString()
check(reader.doc.getText('content').toString() === before,
  'The read-only client receives the existing document', JSON.stringify(before))

// The reader types. Yjs applies it in that browser, but the server must refuse
// it, so it must never reach the owner.
reader.doc.getText('content').insert(0, 'SNEAKY')
await wait(2000)

check(!owner.doc.getText('content').toString().includes('SNEAKY'),
  'Text from a read-only client never reaches the other person',
  JSON.stringify(owner.doc.getText('content').toString()))

// Reading still works in the other direction.
owner.doc.getText('content').insert(0, 'OK:')
await until(() => reader.doc.getText('content').toString().includes('OK:'), 'owner edit to reach reader')
check(true, 'The read-only client still receives new text from the owner')

owner.provider.destroy(); reader.provider.destroy()
console.log(`\n${failures === 0 ? 'all checks passed' : failures + ' check(s) failed'}`)
process.exit(failures === 0 ? 0 : 1)
