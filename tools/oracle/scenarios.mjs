/**
 * The conversations both servers must hold identically.
 *
 * Each scenario drives unmodified providers and nothing else — no reaching
 * into either server. Whatever a scenario cannot express, the differential
 * cannot check, so the list is the real definition of what "matches
 * Hocuspocus" means here.
 *
 * Timing is deliberately generous. The comparison is of what was said, not how
 * fast, and a scenario that races produces a diff that is about the harness.
 */
export const SCENARIOS = [
  {
    name: 'handshake',
    describes: 'one client connects and reaches synced state',
    async run({ connect, sleep }) {
      connect()
      await sleep(600)
    },
  },

  {
    name: 'first-edit',
    describes: 'the first client seeds an empty document',
    async run({ connect, sleep }) {
      const a = connect()
      await sleep(500)
      a.text.insert(0, 'hello')
      await sleep(500)
    },
  },

  {
    name: 'late-joiner',
    describes: 'a second client arrives after the document has content',
    async run({ connect, sleep }) {
      const a = connect()
      await sleep(500)
      a.text.insert(0, 'hello')
      await sleep(500)
      connect()
      await sleep(800)
    },
  },

  {
    name: 'concurrent-edits',
    describes: 'two clients edit and both see the other',
    async run({ connect, sleep }) {
      const a = connect()
      await sleep(400)
      const b = connect()
      await sleep(600)
      a.text.insert(0, 'from A')
      await sleep(400)
      b.text.insert(b.text.length, ' and B')
      await sleep(600)
    },
  },

  {
    name: 'deletion',
    describes: 'an edit that removes content rather than adding it',
    async run({ connect, sleep }) {
      const a = connect()
      await sleep(400)
      a.text.insert(0, 'keep this away')
      await sleep(400)
      const b = connect()
      await sleep(500)
      a.text.delete(9, 5)
      await sleep(600)
    },
  },

  {
    name: 'awareness-presence',
    describes: 'a cursor appears, and a later client is told about it',
    async run({ connect, sleep }) {
      const a = connect()
      await sleep(500)
      a.provider.setAwarenessField('user', { name: 'Alice' })
      await sleep(500)
      const b = connect()
      await sleep(800)
      b.provider.setAwarenessField('user', { name: 'Bob' })
      await sleep(800)
    },
  },

  {
    name: 'awareness-departure',
    describes: 'a cursor is withdrawn when its client goes away',
    async run({ connect, sleep }) {
      const a = connect()
      await sleep(400)
      a.provider.setAwarenessField('user', { name: 'Alice' })
      const b = connect()
      await sleep(600)
      b.provider.setAwarenessField('user', { name: 'Bob' })
      await sleep(500)
      b.provider.destroy()
      await sleep(900)
    },
  },

  {
    name: 'read-only-reads',
    describes: 'a viewer receives the document',
    scope: 'readonly',
    async run({ connect, sleep }) {
      connect()
      await sleep(700)
    },
  },

  {
    name: 'read-only-writes',
    describes: 'a viewer tries to change the document',
    scope: 'readonly',
    async run({ connect, sleep }) {
      const a = connect()
      await sleep(600)
      a.text.insert(0, 'viewer wrote this')
      await sleep(700)
    },
  },

  {
    name: 'stateless',
    describes: 'a client sends a stateless message',
    async run({ connect, sleep }) {
      const a = connect()
      await sleep(500)
      a.provider.sendStateless(JSON.stringify({ hello: 'server' }))
      await sleep(600)
    },
  },

  {
    name: 'reconnect',
    describes: 'a client that loses its socket and comes back',
    async run({ connect, sleep }) {
      const a = connect()
      await sleep(500)
      a.text.insert(0, 'before')
      await sleep(400)
      a.provider.configuration.websocketProvider.webSocket.close()
      await sleep(2500)
      a.text.insert(a.text.length, ' after')
      await sleep(700)
    },
  },
]
