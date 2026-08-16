import { HocuspocusProvider } from '@hocuspocus/provider'
import * as Y from 'yjs'

const config = window.COLLAB

const doc = new Y.Doc()
const text = doc.getText('content')

const $ = (id) => document.getElementById(id)
const area = $('editor')
const status = $('status')
const peers = $('peers')
const log = $('log')

const say = (message) => {
  const line = document.createElement('div')
  line.textContent = `${new Date().toLocaleTimeString()}  ${message}`
  log.prepend(line)
}

const provider = new HocuspocusProvider({
  url: config.url,
  name: config.document,
  token: config.token,
  document: doc,
  onStatus: ({ status: s }) => {
    status.textContent = s
    status.className = s === 'connected' ? 'ok' : 'bad'
  },
  onSynced: () => say('synced with the server'),
  onAuthenticated: () => say(`authenticated, permission: ${provider.authorizedScope ?? 'unknown'}`),
  onAuthenticationFailed: ({ reason }) => {
    status.textContent = 'refused'
    status.className = 'bad'
    say(`refused: ${reason}`)
  },
})

provider.setAwarenessField('user', { name: config.name, color: config.color })

// Send only what changed, so two people typing in different places do not
// overwrite each other. Replacing the whole text would do exactly that.
let applying = false

area.addEventListener('input', () => {
  if (applying) return

  const next = area.value
  const previous = text.toString()
  if (next === previous) return

  let start = 0
  while (start < next.length && start < previous.length && next[start] === previous[start]) start++

  let end = 0
  while (
    end < next.length - start &&
    end < previous.length - start &&
    next[next.length - 1 - end] === previous[previous.length - 1 - end]
  ) end++

  doc.transact(() => {
    const removed = previous.length - start - end
    if (removed > 0) text.delete(start, removed)
    const added = next.slice(start, next.length - end)
    if (added) text.insert(start, added)
  })
})

text.observe(() => {
  const value = text.toString()
  if (area.value === value) return

  const at = area.selectionStart
  applying = true
  area.value = value
  area.selectionStart = area.selectionEnd = Math.min(at, value.length)
  applying = false
})

provider.awareness.on('change', () => {
  const others = Array.from(provider.awareness.getStates().entries())
    .filter(([id]) => id !== doc.clientID)
    .map(([, state]) => state.user)
    .filter(Boolean)

  peers.innerHTML = ''
  if (others.length === 0) {
    peers.innerHTML = '<em>nobody else is here</em>'
    return
  }

  for (const person of others) {
    const chip = document.createElement('span')
    chip.className = 'chip'
    chip.style.background = person.color
    chip.textContent = person.name
    peers.append(chip)
  }
})

window.provider = provider
