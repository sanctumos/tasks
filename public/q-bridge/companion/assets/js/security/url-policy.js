/**
 * UrlPolicy — CORE 100%
 * Allow http/https/mailto and same-origin relative paths.
 */
export function isAllowedUrl(raw, { allowRelative = true } = {}) {
  if (raw == null) return false;
  let s = String(raw).trim();
  if (!s) return false;
  // strip controls
  if (/[\u0000-\u001f\u007f]/.test(s)) return false;
  s = s.replace(/[\s\u00a0]+/g, '');
  const lower = s.toLowerCase();
  if (lower.startsWith('javascript:') || lower.startsWith('data:') || lower.startsWith('vbscript:')) {
    return false;
  }
  if (s.startsWith('//')) return false; // protocol-relative rejected in v1
  if (allowRelative && (s.startsWith('/') || s.startsWith('./') || s.startsWith('../') || s.startsWith('#'))) {
    if (s.includes(':') && !s.startsWith('#')) return false;
    return true;
  }
  try {
    const u = new URL(s);
    if (u.username || u.password) return false;
    return u.protocol === 'http:' || u.protocol === 'https:' || u.protocol === 'mailto:';
  } catch {
    return false;
  }
}

export function safeHref(raw, opts) {
  return isAllowedUrl(raw, opts) ? String(raw).trim() : null;
}

export function externalLinkAttrs(href) {
  try {
    const u = new URL(href, 'https://example.invalid');
    if (u.protocol === 'http:' || u.protocol === 'https:') {
      return { target: '_blank', rel: 'noopener noreferrer' };
    }
  } catch {}
  return {};
}
