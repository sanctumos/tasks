/**
 * CanvasInitiationParser — CORE 100%
 */
const SCHEMA = 'sanctum.companion.ui-event';
const MAX_TITLE = 120;
const SEEN_CAP = 500;

function isPlainTitle(title) {
  if (title == null || title === undefined) return true;
  if (typeof title !== 'string') return false;
  if (title.length > MAX_TITLE) return false;
  if (/[<>&]/.test(title)) return false;
  if (/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/.test(title)) return false;
  return true;
}

export function createInitiationParser() {
  const seen = new Set();

  function remember(id) {
    seen.add(id);
    if (seen.size > SEEN_CAP) {
      const first = seen.values().next().value;
      seen.delete(first);
    }
  }

  return {
    reset() { seen.clear(); },
    seenHas(id) { return seen.has(id); },
    parse(raw) {
      if (!raw || typeof raw !== 'object') return { ok: false, reason: 'not-object' };
      if (raw.schema !== SCHEMA) return { ok: false, reason: 'schema' };
      if (raw.version !== 1) return { ok: false, reason: 'version' };
      if (typeof raw.event_id !== 'string' || raw.event_id.length < 8 || raw.event_id.length > 128) {
        return { ok: false, reason: 'event_id' };
      }
      if (!/^[A-Za-z0-9._:-]+$/.test(raw.event_id)) return { ok: false, reason: 'event_id-charset' };
      if (seen.has(raw.event_id)) return { ok: false, reason: 'duplicate', eventId: raw.event_id };
      if (raw.type !== 'canvas.open') return { ok: false, reason: 'type' };
      const payload = raw.payload;
      if (!payload || typeof payload !== 'object') return { ok: false, reason: 'payload' };
      const keys = Object.keys(payload);
      for (const k of keys) {
        if (k !== 'surface' && k !== 'title') return { ok: false, reason: 'payload-key' };
      }
      const surface = payload.surface == null ? 'primary' : payload.surface;
      if (surface !== 'primary') return { ok: false, reason: 'surface' };
      if (!isPlainTitle(payload.title)) return { ok: false, reason: 'title' };
      const title = payload.title == null ? null : String(payload.title).trim().slice(0, MAX_TITLE) || null;
      remember(raw.event_id);
      return {
        ok: true,
        event: {
          schema: SCHEMA,
          version: 1,
          event_id: raw.event_id,
          type: 'canvas.open',
          payload: { surface: 'primary', ...(title ? { title } : {}) }
        }
      };
    }
  };
}
