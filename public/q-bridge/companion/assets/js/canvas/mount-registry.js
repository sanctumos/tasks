/**
 * CanvasHost mount/unmount — CORE 100%
 */
export function createMountRegistry({ getRoot }) {
  let adapter = null;
  let lease = null;
  let mounting = false;

  async function registerRenderer(next) {
    if (!next || typeof next.mount !== 'function') throw new Error('invalid adapter');
    if (lease) await unmount('replace-adapter');
    adapter = next;
    return () => {
      if (adapter === next) adapter = null;
    };
  }

  async function mount(context = {}) {
    if (mounting) throw new Error('mount in progress');
    if (!adapter) throw new Error('no adapter');
    if (lease) await unmount('remount');
    const root = getRoot();
    if (!root) throw new Error('no canvas root');
    root.replaceChildren();
    mounting = true;
    const ac = new AbortController();
    try {
      const result = await adapter.mount({
        root,
        signal: ac.signal,
        session: context.session || null,
        initiation: context.initiation || null,
        dispatchAction: context.dispatchAction || (() => Promise.reject(new Error('unsupported-action')))
      });
      const unmountFn = result && typeof result.unmount === 'function' ? result.unmount : (async () => {});
      lease = { adapterId: adapter.id || 'anonymous', abort: ac, unmount: unmountFn };
      return lease;
    } catch (err) {
      root.replaceChildren();
      throw err;
    } finally {
      mounting = false;
    }
  }

  async function unmount(reason = 'unmount') {
    if (!lease) return;
    const current = lease;
    lease = null;
    try { current.abort.abort(); } catch {}
    try { await current.unmount(reason); } catch {}
    const root = getRoot();
    if (root) root.replaceChildren();
  }

  return {
    registerRenderer,
    mount,
    unmount,
    getLease: () => lease,
    getAdapter: () => adapter
  };
}
