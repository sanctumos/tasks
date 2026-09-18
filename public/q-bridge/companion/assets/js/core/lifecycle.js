/**
 * Lifecycle disposer — CORE 100%
 */
export function createLifecycle(name = 'shell') {
  const timers = new Set();
  const intervals = new Set();
  const aborts = new Set();
  const unsubs = new Set();
  let destroyed = false;

  function guard() {
    if (destroyed) throw new Error(`${name} already destroyed`);
  }

  return {
    get destroyed() { return destroyed; },
    setTimeout(fn, ms) {
      guard();
      const id = setTimeout(() => { timers.delete(id); fn(); }, ms);
      timers.add(id);
      return id;
    },
    clearTimeout(id) {
      clearTimeout(id);
      timers.delete(id);
    },
    setInterval(fn, ms) {
      guard();
      const id = setInterval(fn, ms);
      intervals.add(id);
      return id;
    },
    clearInterval(id) {
      clearInterval(id);
      intervals.delete(id);
    },
    abortController() {
      guard();
      const ac = new AbortController();
      aborts.add(ac);
      return ac;
    },
    on(target, type, handler, opts) {
      guard();
      target.addEventListener(type, handler, opts);
      const off = () => target.removeEventListener(type, handler, opts);
      unsubs.add(off);
      return off;
    },
    track(unsubscribe) {
      guard();
      unsubs.add(unsubscribe);
      return unsubscribe;
    },
    destroy() {
      if (destroyed) return;
      destroyed = true;
      for (const id of timers) clearTimeout(id);
      timers.clear();
      for (const id of intervals) clearInterval(id);
      intervals.clear();
      for (const ac of aborts) { try { ac.abort(); } catch {} }
      aborts.clear();
      for (const off of unsubs) { try { off(); } catch {} }
      unsubs.clear();
    }
  };
}
