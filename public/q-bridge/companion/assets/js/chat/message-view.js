/**
 * Message view — append/clear chat bubbles.
 * Matches Ask Q: decode HTML entities then safe markdown when enabled.
 */
import { toHtml as markdownToHtml } from './markdown.js';

export function escapeText(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/** Decode entities (&#039; etc.) without executing markup. */
export function decodeEntities(text) {
  const ta = document.createElement('textarea');
  ta.innerHTML = String(text == null ? '' : text);
  return ta.value;
}

/**
 * @param {HTMLElement} messagesEl
 * @param {{ role: string, text?: string, id?: string|number, html?: boolean, safeHtml?: string }} opts
 */
export function appendMessage(messagesEl, { role, text, id, html = false, safeHtml = null }) {
  if (!messagesEl) throw new Error('messagesEl required');
  const div = document.createElement('div');
  div.className = 'companion-msg companion-msg--' + role;
  if (id != null) div.dataset.id = String(id);
  const body = document.createElement('div');
  body.className = 'companion-msg-body';
  if (safeHtml != null) {
    body.classList.add('sanctum-composer-bubble');
    body.innerHTML = String(safeHtml);
  } else {
    const raw = decodeEntities(text == null ? '' : String(text));
    if (html) {
      body.innerHTML = markdownToHtml(raw);
    } else {
      body.textContent = raw;
    }
  }
  div.appendChild(body);
  messagesEl.appendChild(div);
  messagesEl.scrollTop = messagesEl.scrollHeight;
  return div;
}

export function clearMessages(messagesEl) {
  if (!messagesEl) return;
  messagesEl.replaceChildren();
}

export function createMessageView({ messagesEl, useMarkdown = true }) {
  return {
    append(msg) {
      const { safeHtml, ...rest } = msg || {};
      return appendMessage(messagesEl, {
        ...rest,
        html: safeHtml != null ? false : !!useMarkdown,
        safeHtml: safeHtml != null ? safeHtml : null,
      });
    },
    clear() {
      clearMessages(messagesEl);
    },
    el: messagesEl
  };
}

export default { escapeText, decodeEntities, appendMessage, clearMessages, createMessageView };
