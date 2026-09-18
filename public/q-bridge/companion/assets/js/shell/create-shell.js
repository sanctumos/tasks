/**
 * Fullscreen companion shell
 */
import { createLifecycle } from '../core/lifecycle.js';
import { createStateStore } from '../core/state-store.js';
import { createBridgeClient } from '../chat/bridge-client.js';
import { createMessageView, escapeText } from '../chat/message-view.js';
import { createComposer } from '../chat/composer.js';
import { createCanvasHost } from '../canvas/canvas-host.js';
import { createChromeController } from '../chrome/chrome-controller.js';

function emit(name, detail) {
  document.dispatchEvent(new CustomEvent(name, { detail }));
}

export async function createShell(config) {
  const root = document.querySelector(config.mount || '#companion-app');
  if (!root) throw new Error('mount missing');
  const lifecycle = createLifecycle('companion');
  const store = createStateStore({ lifecycle: 'started', session: 'booting' });

  root.innerHTML = `
    <div class="companion-shell" data-theme="${escapeText(config.theme || 'light')}">
      <header class="companion-header" data-chrome-panel hidden>
        <div class="companion-brand">Q</div>
        <button type="button" class="companion-chrome-hide" aria-label="Hide chrome">Hide</button>
      </header>
      <div class="companion-layout">
        <main class="companion-chat" aria-label="Conversation with Q">
          <div class="companion-status" id="companion-status" aria-live="polite"></div>
          <div class="companion-messages" id="companion-messages" role="log" aria-relevant="additions"></div>
          <form class="companion-composer" id="companion-composer">
            <label class="visually-hidden" for="companion-input">Message</label>
            <textarea id="companion-input" rows="1" placeholder="Message Q…"></textarea>
            <button type="submit" id="companion-send" disabled>Send</button>
          </form>
        </main>
        <section class="companion-canvas" id="companion-canvas" aria-label="Canvas" data-canvas-state="closed" hidden>
          <div class="companion-canvas-toolbar">
            <span class="companion-canvas-title" id="companion-canvas-title">Canvas</span>
            <button type="button" id="companion-canvas-dismiss" aria-label="Close canvas">Close</button>
          </div>
          <div id="sanctum-canvas-root"></div>
        </section>
      </div>
      <button type="button" class="companion-summon" id="companion-summon" aria-label="Show companion controls">Q</button>
    </div>
  `;

  const messagesEl = root.querySelector('#companion-messages');
  const inputEl = root.querySelector('#companion-input');
  const sendBtn = root.querySelector('#companion-send');
  const statusEl = root.querySelector('#companion-status');
  const canvasSection = root.querySelector('#companion-canvas');
  const chromePanel = root.querySelector('[data-chrome-panel]');
  const formEl = root.querySelector('#companion-composer');

  const messages = createMessageView({ messagesEl, useMarkdown: false });

  const canvas = createCanvasHost({
    rootEl: root,
    sectionEl: canvasSection,
    store,
    emit
  });

  const chrome = createChromeController({
    store,
    lifecycle,
    onEscapeCascade(e) {
      const snap = store.get();
      if (snap.chrome === 'summoned') {
        e.preventDefault();
        chrome.hide();
        chromePanel.hidden = true;
        return;
      }
      if (snap.canvas.mode !== 'closed') {
        e.preventDefault();
        canvas.close('escape');
        inputEl.focus();
      }
    }
  });

  lifecycle.on(root.querySelector('#companion-summon'), 'click', () => {
    chrome.show();
    chromePanel.hidden = false;
  });
  lifecycle.on(root.querySelector('.companion-chrome-hide'), 'click', () => {
    chrome.hide();
    chromePanel.hidden = true;
  });
  lifecycle.on(root.querySelector('#companion-canvas-dismiss'), 'click', async () => {
    await canvas.close('button');
    inputEl.focus();
  });

  function setStatus(text) {
    statusEl.textContent = text || '';
  }

  const bridge = createBridgeClient({
    apiBase: config.apiBase,
    csrfToken: config.csrfToken || null,
    useSessionAuth: !!config.useSessionAuth,
    lifecycle
  });

  let sessionId = config.sessionId || null;

  const composer = createComposer({
    formEl,
    inputEl,
    sendBtn,
    lifecycle,
    isBusy: () => store.get().transport === 'sending',
    async onSubmit(text) {
      if (!sessionId) return;
      store.patch({ transport: 'sending' });
      composer.updateSendEnabled();
      messages.append({ role: 'user', text });
      try {
        await bridge.postMessage({ sessionId, message: text, pageContext: config.pageContext || null });
        store.patch({ transport: 'waiting' });
        setStatus('Waiting for Q…');
      } catch (err) {
        store.patch({ transport: 'failed' });
        messages.append({ role: 'system', text: err.message || 'Send failed' });
        setStatus('');
      }
    }
  });

  async function bootstrap() {
    setStatus('Connecting…');
    try {
      if (!sessionId) {
        const sess = await bridge.userSession();
        sessionId = sess.session_id || sess.sessionId || (sess.data && sess.data.session_id);
      }
      if (!sessionId) throw new Error('No session');
      const hist = await bridge.history(config.historyLimit || 40, sessionId);
      const rows = (hist && (hist.messages || hist.history || hist.items)) || [];
      messages.clear();
      if (!rows.length) {
        messages.append({ role: 'system', text: config.greeting || 'Hello — ask Q anything.' });
      } else {
        for (const row of rows) {
          const role = row.role || (row.response != null ? 'assistant' : 'user');
          const text = row.message || row.response || row.text || '';
          messages.append({
            role: role === 'assistant' || role === 'q' ? 'assistant' : (role === 'system' ? 'system' : 'user'),
            text,
            id: row.id
          });
        }
      }
      store.patch({ session: 'authenticated', chat: { history: 'ready' }, lifecycle: 'started' });
      setStatus('');
      // Advance cursors once without applying historical ui_events (reload must not reopen canvas).
      try {
        const warm = await bridge.pollResponses({ sessionId });
        // Do not render warm.responses / warm.uiEvents — history already hydrated.
        void warm;
      } catch (_) { /* first poll may fail offline; polling loop will retry */ }
      document.body.dataset.companionMode = 'chat';
      bridge.startPolling({
        sessionId,
        intervalMs: config.pollIntervalMs || 3000,
        onTick(err, result) {
          if (err) {
            store.patch({ transport: 'retrying' });
            setStatus('Reconnecting…');
            return;
          }
          store.patch({ transport: 'idle' });
          setStatus('');
          for (const r of result.responses || []) {
            messages.append({ role: 'assistant', text: r.response || '', id: r.id });
            store.patch({ transport: 'idle', chat: { ...store.get().chat } });
          }
          for (const ev of result.uiEvents || []) {
            const parsed = canvas.handleUiEvent(ev);
            if (parsed.ok) {
              const t = parsed.event.payload.title;
              root.querySelector('#companion-canvas-title').textContent = t || 'Canvas';
            }
          }
        }
      });
    } catch (err) {
      store.patch({ session: 'failed' });
      setStatus(err.message || 'Failed to start');
      throw err;
    }
  }

  await bootstrap();

  const api = {
    version: '0.1.0',
    store,
    bridge,
    canvas,
    chrome,
    async destroy(reason = 'destroy') {
      await canvas.close(reason);
      bridge.dispose();
      lifecycle.destroy();
      store.patch({ lifecycle: 'destroyed' });
      root.replaceChildren();
    },
    state: () => store.get(),
    on(eventName, handler) {
      const fn = (e) => handler(e.detail, e);
      document.addEventListener(eventName, fn);
      return () => document.removeEventListener(eventName, fn);
    },
    chat: {
      send: (payload) => bridge.postMessage({ sessionId, message: payload.message || payload }),
      focus: () => composer.focus()
    },
    dispatchAction() {
      return Promise.reject(new Error('unsupported-action'));
    }
  };

  // Public global for Merge / non-module hosts
  window.SanctumCompanion = {
    version: api.version,
    start: async () => api,
    destroy: (reason) => api.destroy(reason),
    state: () => api.state(),
    on: api.on,
    chat: api.chat,
    canvas: {
      open: (initiation) => canvas.open(initiation),
      close: (reason) => canvas.close(reason),
      getRoot: () => canvas.getRoot(),
      registerRenderer: (a) => canvas.registry.registerRenderer(a),
      mount: (ctx) => canvas.registry.mount(ctx),
      unmount: (reason) => canvas.registry.unmount(reason)
    },
    chrome: {
      show: () => { chrome.show(); chromePanel.hidden = false; },
      hide: () => { chrome.hide(); chromePanel.hidden = true; },
      toggle: () => chrome.toggle()
    },
    dispatchAction: api.dispatchAction
  };

  document.body.dataset.companionMode = 'chat';
  document.body.dataset.chrome = 'hidden';
  return api;
}

export default { createShell };
