/**
 * Canvas host — open/close empty pane + mount registry
 */
import { createInitiationParser } from './initiation-parser.js';
import { createMountRegistry } from './mount-registry.js';

export function createCanvasHost({ rootEl, sectionEl, store, emit }) {
  const parser = createInitiationParser();
  const registry = createMountRegistry({
    getRoot: () => rootEl.querySelector('#sanctum-canvas-root') || document.getElementById('sanctum-canvas-root')
  });

  function ensureRoot() {
    let root = document.getElementById('sanctum-canvas-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'sanctum-canvas-root';
      sectionEl.appendChild(root);
    }
    return root;
  }

  function open(initiation) {
    const title = initiation && initiation.payload && initiation.payload.title;
    sectionEl.hidden = false;
    sectionEl.dataset.canvasState = 'empty';
    sectionEl.setAttribute('aria-label', title ? `Canvas: ${title}` : 'Canvas');
    ensureRoot();
    store.patch({ canvas: { mode: 'open-empty', title: title || null, eventId: initiation && initiation.event_id } });
    emit('sanctum:canvas-open', { initiation });
    // viewport class on body
    document.body.dataset.companionMode = window.matchMedia('(max-width: 800px)').matches ? 'mobile-canvas' : 'desktop-split';
  }

  async function close(reason = 'dismiss') {
    await registry.unmount(reason);
    sectionEl.hidden = true;
    sectionEl.dataset.canvasState = 'closed';
    store.patch({ canvas: { mode: 'closed', title: null, eventId: null } });
    document.body.dataset.companionMode = 'chat';
    emit('sanctum:canvas-close', { reason });
  }

  function handleUiEvent(raw) {
    const parsed = parser.parse(raw);
    if (!parsed.ok) return parsed;
    open(parsed.event);
    return parsed;
  }

  return {
    parser,
    registry,
    open,
    close,
    handleUiEvent,
    getRoot: () => document.getElementById('sanctum-canvas-root')
  };
}
