/**
 * Generate Profile 1 provider transcripts.
 *
 * ## Provenance, stated plainly
 *
 * These frames are assembled from the same primitives the provider assembles
 * them from — `@hocuspocus/common`'s auth writers, `y-protocols`, and lib0 —
 * following the frame construction in each of the provider's OutgoingMessage
 * classes (`@hocuspocus/provider/src/OutgoingMessages/*.ts`). They are *not*
 * captured from a running provider: the provider does not export those classes,
 * and driving a live one needs a socket to connect to.
 *
 * That is a real limitation and worth naming. A transcript built this way
 * proves the PHP reader agrees with the protocol as written; it cannot prove
 * the provider does what its source says. Closing that gap needs a running
 * server for a real provider to talk to, which is where the roadmap puts it —
 * the Phase 5 exit gate is an unmodified provider reaching synced state against
 * the daemon, and that test belongs there rather than here.
 *
 * Deterministic: no timestamps, so regeneration either produces an empty diff
 * or a real disagreement.
 */
import { createRequire } from 'node:module'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import * as encoding from 'lib0/encoding'
import * as syncProtocol from 'y-protocols/sync'
import { Awareness, encodeAwarenessUpdate } from 'y-protocols/awareness'
import * as Y from 'yjs'
import {
  writeAuthenticated,
  writeAuthentication,
  writePermissionDenied,
  writeTokenSyncRequest,
} from '@hocuspocus/common'

const require = createRequire(import.meta.url)
const here = dirname(fileURLToPath(import.meta.url))
const fixtureRoot = join(here, '..', '..', 'fixtures', 'profile-1')

const base64 = (bytes) => Buffer.from(bytes).toString('base64')

/**
 * Versions come from the lockfile rather than each package's own manifest,
 * because some of these packages do not expose `package.json` through their
 * exports map. The lockfile is the authoritative pin anyway.
 */
const lockfile = require(join(here, 'package-lock.json'))
const versionOf = (name) => lockfile.packages[`node_modules/${name}`].version

const DOCUMENT = '4711'
const TOKEN = 'a-signed-jwt.with.parts'

const document = new Y.Doc()
document.clientID = 900
document.getText('text').insert(0, 'provider transcript 😀')
document.getMap('meta').set('title', 'Fixture')

const awareness = new Awareness(document)
awareness.meta.set(900, { clock: 1, lastUpdated: 0 })
awareness.states.set(900, { name: 'Ada', cursor: { anchor: 1, head: 4 } })

const cases = []

/**
 * Every frame is the document name, the message type, then the payload — the
 * shape each OutgoingMessage class writes.
 */
const frame = (name, description, direction, type, build) => {
  const encoder = encoding.createEncoder()
  encoding.writeVarString(encoder, DOCUMENT)
  encoding.writeVarUint(encoder, type)
  build(encoder)

  cases.push({
    name,
    description,
    direction,
    documentName: DOCUMENT,
    type,
    bytes: base64(encoding.toUint8Array(encoder)),
  })
}

const client = (name, description, type, build) => frame(name, description, 'client-to-server', type, build)
const server = (name, description, type, build) => frame(name, description, 'server-to-client', type, build)

// AuthenticationMessage
client('auth-token', 'The client presenting its token.', 2, (e) => writeAuthentication(e, TOKEN))

// SyncStepOneMessage / SyncStepTwoMessage / UpdateMessage
client('sync-step1', 'The client opening a sync.', 0, (e) => syncProtocol.writeSyncStep1(e, document))
client('sync-step2', 'The client answering our sync step one.', 0, (e) =>
  syncProtocol.writeSyncStep2(e, document, Y.encodeStateVector(new Y.Doc())))
client('sync-update', 'The client broadcasting an edit.', 0, (e) =>
  syncProtocol.writeUpdate(e, Y.encodeStateAsUpdate(document)))

// AwarenessMessage
client('awareness', 'The client announcing its presence.', 1, (e) =>
  encoding.writeVarUint8Array(e, encodeAwarenessUpdate(awareness, [900])))

// QueryAwarenessMessage, StatelessMessage, CloseMessage
client('query-awareness', 'The client asking who else is here.', 3, () => {})
client('stateless', 'An application-defined string.', 5, (e) =>
  encoding.writeVarString(e, '{"kind":"ping","n":1}'))
client('close', 'The client leaving this document.', 7, () => {})

// Server-originated frames. The provider only reads these.
server('auth-token-request', 'The server asking for a token.', 2, writeTokenSyncRequest)
server('auth-authenticated-readwrite', 'The server granting write access.', 2, (e) =>
  writeAuthenticated(e, 'read-write'))
server('auth-authenticated-readonly', 'The server granting read-only access.', 2, (e) =>
  writeAuthenticated(e, 'readonly'))
server('auth-permission-denied', 'The server refusing access.', 2, (e) =>
  writePermissionDenied(e, 'You may not open this document.'))
server('sync-status-accepted', 'The server accepting an update.', 8, (e) => encoding.writeVarInt(e, 1))
server('sync-status-rejected', 'The server refusing an update.', 8, (e) => encoding.writeVarInt(e, 0))
server('awareness-broadcast', 'The server relaying presence to everyone else.', 1, (e) =>
  encoding.writeVarUint8Array(e, encodeAwarenessUpdate(awareness, [900])))
server('sync-step1-from-server', 'The server opening a sync.', 0, (e) =>
  syncProtocol.writeSyncStep1(e, document))

mkdirSync(fixtureRoot, { recursive: true })
writeFileSync(
  join(fixtureRoot, 'provider-transcripts.json'),
  `${JSON.stringify(
    {
      profile: 1,
      provenance:
        'Assembled from @hocuspocus/common, y-protocols, and lib0 following the frame construction in @hocuspocus/provider/src/OutgoingMessages/*.ts. Not captured from a running provider — see the note at the top of generate-transcripts.mjs.',
      document: DOCUMENT,
      token: TOKEN,
      packages: {
        '@hocuspocus/provider': versionOf('@hocuspocus/provider'),
        '@hocuspocus/common': versionOf('@hocuspocus/common'),
        yjs: versionOf('yjs'),
        'y-protocols': versionOf('y-protocols'),
        lib0: versionOf('lib0'),
      },
      cases,
    },
    null,
    2,
  )}\n`,
)

console.log(`wrote fixtures/profile-1/provider-transcripts.json (${cases.length} frames)`)

// The Awareness constructor starts an expiry interval that would keep this
// process alive forever.
awareness.destroy()
