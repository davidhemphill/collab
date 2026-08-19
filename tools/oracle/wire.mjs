/**
 * Decode a provider frame into a description that can be compared.
 *
 * Raw bytes cannot be diffed between two servers: client IDs are random, so
 * every update and every awareness entry differs on every run even when the
 * two servers behave identically. What can be diffed is what a frame *says* —
 * its type, its direction, and the fields that carry meaning — with client IDs
 * replaced by stable aliases in order of first appearance.
 *
 * The decoding follows @hocuspocus/provider's MessageReceiver and the
 * OutgoingMessage classes, which is the same source the transcripts follow.
 */
import * as decoding from 'lib0/decoding'
import * as Y from 'yjs'

export const MessageType = {
  Sync: 0,
  Awareness: 1,
  Auth: 2,
  QueryAwareness: 3,
  Stateless: 5,
  CLOSE: 7,
  SyncStatus: 8,
}

const SyncType = { 0: 'Step1', 1: 'Step2', 2: 'Update' }
const AuthType = { 0: 'Token', 1: 'PermissionDenied', 2: 'Authenticated' }

/**
 * Stable names for client IDs.
 *
 * The alias is per-run, not per-connection, so the same document client gets
 * the same name in both transcripts as long as the scenario introduces them in
 * the same order — which is what the scenario script guarantees.
 */
export function aliases() {
  const seen = new Map()

  return (id) => {
    if (!seen.has(id)) {
      seen.set(id, `client${seen.size + 1}`)
    }

    return seen.get(id)
  }
}

/** A state vector as `{client: clock}` with aliased clients, key-sorted. */
const describeStateVector = (bytes, alias) => {
  const decoder = decoding.createDecoder(bytes)
  const size = decoding.readVarUint(decoder)
  const entries = []

  for (let i = 0; i < size; i++) {
    const client = decoding.readVarUint(decoder)
    const clock = decoding.readVarUint(decoder)
    entries.push([alias(client), clock])
  }

  return Object.fromEntries(entries.sort(([a], [b]) => a.localeCompare(b)))
}

/**
 * An update by what it carries, not by its bytes.
 *
 * Two servers can legitimately encode the same change differently — one may
 * merge where the other relays — so the comparable fact is which clients
 * appear, how far their clocks reach, and whether anything is deleted.
 */
const describeUpdate = (bytes, alias) => {
  if (bytes.length === 0) {
    return { empty: true }
  }

  const doc = new Y.Doc()

  try {
    Y.applyUpdate(doc, bytes)
  } catch (error) {
    return { undecodable: String(error) }
  }

  const vector = describeStateVector(Y.encodeStateVector(doc), alias)
  const deletions = []
  const { ds } = Y.decodeUpdate(bytes)

  for (const [client, ranges] of ds.clients ?? new Map()) {
    deletions.push([alias(client), ranges.map((r) => `${r.clock}+${r.len}`).join(',')])
  }

  return {
    reaches: vector,
    deletes: Object.fromEntries(deletions.sort(([a], [b]) => a.localeCompare(b))),
  }
}

const describeAwareness = (bytes, alias) => {
  const decoder = decoding.createDecoder(bytes)
  const count = decoding.readVarUint(decoder)
  const entries = []

  for (let i = 0; i < count; i++) {
    const client = decoding.readVarUint(decoder)
    decoding.readVarUint(decoder) // clock; a counter, not a comparable fact
    const state = decoding.readVarString(decoder)
    entries.push([alias(client), state === 'null' ? null : JSON.parse(state)])
  }

  return Object.fromEntries(entries.sort(([a], [b]) => a.localeCompare(b)))
}

/**
 * @param {Uint8Array} bytes One whole frame.
 * @param {(id: number) => string} alias
 */
export function describe(bytes, alias) {
  const decoder = decoding.createDecoder(bytes)
  const document = decoding.readVarString(decoder)
  const type = decoding.readVarUint(decoder)

  switch (type) {
    case MessageType.Sync: {
      const syncType = decoding.readVarUint(decoder)
      const payload = decoding.readVarUint8Array(decoder)

      return {
        document,
        message: `Sync/${SyncType[syncType] ?? syncType}`,
        ...(syncType === 0
          ? { asks: describeStateVector(payload, alias) }
          : { carries: describeUpdate(payload, alias) }),
      }
    }

    case MessageType.Awareness:
      return {
        document,
        message: 'Awareness',
        states: describeAwareness(decoding.readVarUint8Array(decoder), alias),
      }

    case MessageType.Auth: {
      const authType = decoding.readVarUint(decoder)
      const name = AuthType[authType] ?? authType

      if (authType === 0) {
        // A token with nothing after it is the server asking for one.
        return decoding.hasContent(decoder)
          ? { document, message: 'Auth/Token', token: '<token>' }
          : { document, message: 'Auth/TokenRequest' }
      }

      return { document, message: `Auth/${name}`, value: decoding.readVarString(decoder) }
    }

    case MessageType.QueryAwareness:
      return { document, message: 'QueryAwareness' }

    case MessageType.SyncStatus:
      return { document, message: 'SyncStatus', applied: decoding.readVarInt(decoder) === 1 }

    case MessageType.Stateless:
      return { document, message: 'Stateless', payload: decoding.readVarString(decoder) }

    case MessageType.CLOSE:
      // The provider's CloseMessage writes no reason, though its receiver
      // reads one. Tolerate both shapes rather than record the client's own
      // asymmetry as a difference between two servers.
      return {
        document,
        message: 'Close',
        ...(decoding.hasContent(decoder) ? { reason: decoding.readVarString(decoder) } : {}),
      }

    default:
      return { document, message: `Unknown(${type})` }
  }
}

/** One line per frame, stable enough to diff with a string comparison. */
export const line = (direction, described) =>
  `${direction} ${described.document} ${described.message}` +
  Object.entries(described)
    .filter(([key]) => key !== 'document' && key !== 'message')
    .map(([key, value]) => ` ${key}=${JSON.stringify(value)}`)
    .join('')
