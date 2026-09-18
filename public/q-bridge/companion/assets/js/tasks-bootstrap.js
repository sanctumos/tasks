import { createShell } from './shell/create-shell.js';

const boot = window.__COMPANION_BOOT__ || {};
createShell({
  apiBase: boot.apiBase || '/q-bridge/api/v1/',
  useSessionAuth: true,
  csrfToken: boot.csrfToken || null,
  theme: boot.theme || 'dark',
  greeting: boot.greeting || 'Hello — ask Q anything.',
  sessionId: null,
  pageContext: boot.pageContext || null,
  pollIntervalMs: 3000,
  historyLimit: 40
}).catch((err) => {
  const el = document.getElementById('companion-app');
  if (el) el.textContent = String(err && err.message || err);
});
