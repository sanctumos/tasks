/**
 * Escape/summon chrome stub (Doc #907 F11 posture)
 */
export function createChromeController({ store, lifecycle, onEscapeCascade, panelEl }) {
  function show() {
    store.patch({ chrome: 'summoned' });
    document.body.dataset.chrome = 'summoned';
    if (panelEl) panelEl.hidden = false;
    document.dispatchEvent(new CustomEvent('sanctum:chrome-show'));
  }
  function hide() {
    store.patch({ chrome: 'hidden' });
    document.body.dataset.chrome = 'hidden';
    if (panelEl) panelEl.hidden = true;
    document.dispatchEvent(new CustomEvent('sanctum:chrome-hide'));
  }
  function toggle() {
    const snap = store.get();
    if (snap.chrome === 'summoned') hide();
    else show();
  }

  lifecycle.on(document, 'keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (onEscapeCascade) onEscapeCascade(e);
  });

  return { show, hide, toggle };
}
