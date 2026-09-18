/**
 * Composer — input enablement, submit, Enter-to-send.
 * Large-paste attachments via ComposerPasteManager (Ask Q parity).
 */
import { ComposerPasteManager } from './paste-manager.js';

/**
 * @param {object} opts
 * @param {HTMLFormElement} opts.formEl
 * @param {HTMLTextAreaElement} opts.inputEl
 * @param {HTMLButtonElement} opts.sendBtn
 * @param {{ on: Function }} opts.lifecycle
 * @param {() => boolean} [opts.isBusy]
 * @param {(payload: { message: string, caption: string, attachments: array, attachment_count?: number }) => void|Promise<void>} opts.onSubmit
 * @param {HTMLElement} [opts.chipContainer] — when set, wires paste-manager
 */
export function createComposer({ formEl, inputEl, sendBtn, lifecycle, isBusy, onSubmit, chipContainer }) {
  if (!formEl || !inputEl || !sendBtn) throw new Error('composer elements required');

  let paste = null;
  if (chipContainer) {
    paste = new ComposerPasteManager({
      textarea: inputEl,
      chipContainer,
      onChange: () => updateSendEnabled(),
    });
  }

  function updateSendEnabled() {
    const busy = typeof isBusy === 'function' ? isBusy() : false;
    const can = paste ? paste.canSend() : !!inputEl.value.trim();
    sendBtn.disabled = !can || busy;
  }

  lifecycle.on(inputEl, 'input', updateSendEnabled);

  lifecycle.on(formEl, 'submit', async (e) => {
    e.preventDefault();
    if (typeof isBusy === 'function' && isBusy()) return;

    let payload;
    if (paste) {
      if (!paste.canSend()) return;
      payload = paste.buildPayload();
      inputEl.value = '';
      paste.clear();
    } else {
      const text = inputEl.value.trim();
      if (!text) return;
      inputEl.value = '';
      payload = { message: text, caption: text, attachments: [], attachment_count: 0 };
    }
    updateSendEnabled();
    await onSubmit(payload);
    updateSendEnabled();
  });

  lifecycle.on(inputEl, 'keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      formEl.requestSubmit();
    }
  });

  updateSendEnabled();

  return {
    updateSendEnabled,
    paste,
    focus: () => inputEl.focus(),
    getValue: () => inputEl.value,
    setValue(v) {
      inputEl.value = v == null ? '' : String(v);
      updateSendEnabled();
    },
    clear() {
      inputEl.value = '';
      if (paste) paste.clear();
      updateSendEnabled();
    },
  };
}

export default { createComposer };
