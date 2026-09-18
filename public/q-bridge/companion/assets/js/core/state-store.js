/**
 * Orthogonal shell state store
 */
const DEFAULT = Object.freeze({
  session: 'booting',
  transport: 'idle',
  chat: { history: 'loading', messages: [], draft: '', attachments: [], responseSince: null, nextEventId: 0 },
  canvas: { mode: 'closed', title: null, eventId: null },
  chrome: 'hidden',
  viewport: 'desktop-chat',
  lifecycle: 'new'
});

export function createStateStore(initial = {}) {
  let state = structuredClone({ ...DEFAULT, ...initial, chat: { ...DEFAULT.chat, ...(initial.chat || {}) }, canvas: { ...DEFAULT.canvas, ...(initial.canvas || {}) } });
  const listeners = new Set();

  function snapshot() {
    return structuredClone(state);
  }

  function patch(partial) {
    const next = { ...state, ...partial };
    if (partial.chat) next.chat = { ...state.chat, ...partial.chat };
    if (partial.canvas) next.canvas = { ...state.canvas, ...partial.canvas };
    state = next;
    const snap = snapshot();
    for (const l of listeners) l(snap);
    return snap;
  }

  return {
    get: snapshot,
    patch,
    subscribe(fn) {
      listeners.add(fn);
      return () => listeners.delete(fn);
    }
  };
}
