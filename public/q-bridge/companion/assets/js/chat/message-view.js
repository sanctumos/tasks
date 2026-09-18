/**
 * Message view — append/clear chat bubbles (text-safe by default).
 * Optional markdown via ./markdown.js ES export when rich rendering is enabled.
 */
import { toHtml as markdownToHtml } from './markdown.js';

export function escapeText(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/**
 * @param {HTMLElement} messagesEl
 * @param {{ role: string, text?: string, id?: string|number, html?: boolean }} opts
 */
export function appendMessage(messagesEl, { role, text, id, html = false }) {
  if (!messagesEl) throw new Error('messagesEl required');
  const div = document.createElement('div');
  div.className = 'companion-msg companion-msg--' + role;
  if (id != null) div.dataset.id = String(id);
  const body = document.createElement('div');
  body.className = 'companion-msg-body';
  const raw = text == null ? '' : String(text);
  if (html) {
    body.innerHTML = markdownToHtml(raw);
  } else {
    body.textContent = raw;
  }
  div.appendChild(body);
  messagesEl.appendChild(div);
  messagesEl.scrollTop = messagesEl.scrollHeight;
  return div;
}

export function clearMessages(messagesEl) {
  if (messagesEl) messagesEl.replaceChildren();
}

export function createMessageView({ messagesEl, useMarkdown = false }) {
  return {
    append(msg) {
      return appendMessage(messagesEl, { ...msg, html: !!useMarkdown });
    },
    clear() {
      clearMessages(messagesEl);
    },
    el: messagesEl
  };
}

export default { escapeText, appendMessage, clearMessages, createMessageView };
