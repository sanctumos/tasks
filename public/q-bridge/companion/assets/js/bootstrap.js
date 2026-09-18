import { createShell } from './shell/create-shell.js';

createShell({
  apiBase: '/api/v1/',
  useSessionAuth: false,
  csrfToken: null,
  theme: 'dark',
  greeting: 'Hello — fullscreen companion lab. Try /canvas to open the empty pane.',
  sessionId: 'session_lab_user_1',
  pollIntervalMs: 1500
}).catch((err) => {
  const el = document.getElementById('companion-app');
  if (el) el.textContent = String(err && err.message || err);
});
