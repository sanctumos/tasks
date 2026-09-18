/**
 * Composer — input enablement, submit, Enter-to-send.
 * Paste-manager remains optional (window.ComposerPasteManager / paste-manager.js).
 */

/**
 * @param {object} opts
 * @param {HTMLFormElement} opts.formEl
 * @param {HTMLTextAreaElement} opts.inputEl
 * @param {HTMLButtonElement} opts.sendBtn
 * @param {{ on: Function }} opts.lifecycle
 * @param {() => boolean} [opts.isBusy]
 * @param {(text: string) => void|Promise<void>} opts.onSubmit
 */
export function createComposer({ formEl, inputEl, sendBtn, lifecycle, isBusy, onSubmit }) {
  if (!formEl || !inputEl || !sendBtn) throw new Error('composer elements required');

  function updateSendEnabled() {
    const busy = typeof isBusy === 'function' ? isBusy() : false;
    sendBtn.disabled = !inputEl.value.trim() || busy;
  }

  lifecycle.on(inputEl, 'input', updateSendEnabled);

  lifecycle.on(formEl, 'submit', async (e) => {
    e.preventDefault();
    const text = inputEl.value.trim();
    if (!text) return;
    if (typeof isBusy === 'function' && isBusy()) return;
    inputEl.value = '';
    updateSendEnabled();
    await onSubmit(text);
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
    focus: () => inputEl.focus(),
    getValue: () => inputEl.value,
    setValue(v) {
      inputEl.value = v == null ? '' : String(v);
      updateSendEnabled();
    },
    clear() {
      inputEl.value = '';
      updateSendEnabled();
    }
  };
}

export default { createComposer };
