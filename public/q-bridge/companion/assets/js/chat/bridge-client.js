/**
 * BridgeClient — CORE 100%
 * Same-origin session or lab mode; never embeds poll/admin keys.
 */
export function createBridgeClient({ apiBase, csrfToken = null, useSessionAuth = true, lifecycle, fetchImpl = fetch }) {
  if (!apiBase) throw new Error('apiBase required');
  let disposed = false;
  let responseSince = null;
  let nextEventId = 0;
  let pollTimer = null;
  let backoffMs = 3000;
  const pollHandlers = new Set();

  function url(action, params) {
    let base = String(apiBase || '');
    if (!base.includes('?')) {
      if (!base.endsWith('/')) base += '/';
      base += '?action=' + encodeURIComponent(action);
    } else {
      base += '&action=' + encodeURIComponent(action);
    }
    if (params) {
      for (const [k, v] of Object.entries(params)) {
        if (v == null) continue;
        base += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(String(v));
      }
    }
    return base;
  }

  async function request(action, { method = 'GET', body = null, params = null, signal = null } = {}) {
    if (disposed) throw new Error('bridge disposed');
    const headers = { 'Accept': 'application/json' };
    if (body != null) headers['Content-Type'] = 'application/json';
    if (useSessionAuth && csrfToken && method !== 'GET') headers['X-CSRF-Token'] = csrfToken;
    const opts = { method, headers, credentials: useSessionAuth ? 'same-origin' : 'omit', signal };
    if (body != null) opts.body = JSON.stringify(body);
    const res = await fetchImpl(url(action, params), opts);
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { data = { success: false, message: 'invalid-json', raw: text.slice(0, 200) }; }
    if (!res.ok) {
      const err = new Error((data && data.message) || ('HTTP ' + res.status));
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  async function userSession() {
    const data = await request('user_session');
    return data && data.data ? data.data : data;
  }

  async function history(limit = 50, sessionId = null) {
    const params = { limit };
    if (sessionId) params.session_id = sessionId;
    const data = await request('history', { params });
    return data && data.data ? data.data : data;
  }

  async function postMessage({ sessionId, message, metadata = null, attachments = null, pageContext = null }) {
    const body = { session_id: sessionId, message };
    if (metadata) body.metadata = metadata;
    if (attachments) body.attachments = attachments;
    if (pageContext) body.page_context = pageContext;
    return request('messages', { method: 'POST', body });
  }

  async function pollResponses({ sessionId }) {
    const params = { session_id: sessionId };
    if (responseSince) params.since = responseSince;
    if (nextEventId) params.since_event_id = nextEventId;
    const data = await request('responses', { params });
    const payload = (data && data.data) ? data.data : data;
    const responses = (payload && payload.responses) || [];
    const uiEvents = (payload && payload.ui_events) || [];
    for (const r of responses) {
      if (r.timestamp) responseSince = r.timestamp;
    }
    if (payload && payload.next_event_id != null) {
      nextEventId = Math.max(nextEventId, Number(payload.next_event_id) || 0);
    } else {
      for (const e of uiEvents) {
        if (e.id != null) nextEventId = Math.max(nextEventId, Number(e.id) || 0);
      }
    }
    return { responses, uiEvents, sessionId: payload && payload.session_id };
  }

  function startPolling({ sessionId, intervalMs = 3000, onTick }) {
    stopPolling();
    const tick = async () => {
      if (disposed) return;
      try {
        const result = await pollResponses({ sessionId });
        backoffMs = intervalMs;
        if (onTick) onTick(null, result);
        for (const h of pollHandlers) h(null, result);
      } catch (err) {
        backoffMs = Math.min(45000, Math.floor(backoffMs * 1.5));
        if (onTick) onTick(err, null);
        for (const h of pollHandlers) h(err, null);
      } finally {
        if (!disposed) {
          pollTimer = (lifecycle || { setTimeout: (f, m) => setTimeout(f, m) }).setTimeout(tick, backoffMs);
        }
      }
    };
    pollTimer = (lifecycle || { setTimeout: (f, m) => setTimeout(f, m) }).setTimeout(tick, 0);
  }

  function stopPolling() {
    if (pollTimer && lifecycle && lifecycle.clearTimeout) lifecycle.clearTimeout(pollTimer);
    else if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
  }

  function onPoll(handler) {
    pollHandlers.add(handler);
    return () => pollHandlers.delete(handler);
  }

  function dispose() {
    disposed = true;
    stopPolling();
    pollHandlers.clear();
  }

  return {
    request,
    userSession,
    history,
    postMessage,
    pollResponses,
    startPolling,
    stopPolling,
    onPoll,
    dispose,
    getCursors: () => ({ responseSince, nextEventId }),
    setCursors: (c) => {
      if (c.responseSince != null) responseSince = c.responseSince;
      if (c.nextEventId != null) nextEventId = c.nextEventId;
    }
  };
}
