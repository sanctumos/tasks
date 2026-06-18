/**
 * Ask Q composer prototype — large paste → text attachment chip (Discord-style).
 * Patterns: web.dev/patterns/clipboard/paste-files, Tasks admin attachment rows.
 */
(function (global) {
    'use strict';

    const DEFAULTS = {
        pasteThresholdChars: 800,
        maxAttachmentBytes: 256 * 1024,
        maxAttachmentsPerSend: 3,
        maxCaptionChars: 2000,
    };

    function formatBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function stampName() {
        const d = new Date();
        const p = (x) => String(x).padStart(2, '0');
        return 'pasted-' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-'
            + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds()) + '.txt';
    }

    function makeId() {
        return 'att-' + Math.random().toString(36).slice(2, 10);
    }

    /**
     * @param {object} options
     * @param {HTMLTextAreaElement} options.textarea
     * @param {HTMLElement} options.chipContainer
     * @param {HTMLElement} options.hintEl
     * @param {function} options.onChange
     */
    function ComposerPasteManager(options) {
        this.textarea = options.textarea;
        this.chipContainer = options.chipContainer;
        this.hintEl = options.hintEl;
        this.onChange = options.onChange || function () {};
        this.config = Object.assign({}, DEFAULTS, options.config || {});
        this.pending = [];

        this._onPaste = this._onPaste.bind(this);
        this.textarea.addEventListener('paste', this._onPaste);
    }

    ComposerPasteManager.prototype.setThreshold = function (n) {
        this.config.pasteThresholdChars = Math.max(1, parseInt(n, 10) || DEFAULTS.pasteThresholdChars);
        this._refreshHint();
    };

    ComposerPasteManager.prototype._refreshHint = function () {
        if (!this.hintEl) return;
        const t = this.config.pasteThresholdChars;
        this.hintEl.textContent = 'Paste ≥ ' + t.toLocaleString() + ' chars becomes a text attachment chip '
            + '(max ' + formatBytes(this.config.maxAttachmentBytes) + ' each). '
            + 'Smaller paste stays inline.';
    };

    ComposerPasteManager.prototype._onPaste = function (e) {
        const clip = (e.clipboardData || window.clipboardData);
        if (!clip) return;

        const plain = clip.getData('text/plain');
        if (!plain || plain.length < this.config.pasteThresholdChars) {
            return; // browser default inline paste
        }

        e.preventDefault();

        const bytes = new TextEncoder().encode(plain).length;
        if (bytes > this.config.maxAttachmentBytes) {
            window.alert('Paste is ' + formatBytes(bytes) + '; prototype cap is '
                + formatBytes(this.config.maxAttachmentBytes) + '. Split or raise cap in PRD.');
            return;
        }

        if (this.pending.length >= this.config.maxAttachmentsPerSend) {
            window.alert('Max ' + this.config.maxAttachmentsPerSend + ' attachments per message in prototype.');
            return;
        }

        this.pending.push({
            id: makeId(),
            kind: 'text',
            filename: stampName(),
            mime_type: 'text/plain',
            size_bytes: bytes,
            text: plain,
        });
        this._renderChips();
        this.onChange();
    };

    ComposerPasteManager.prototype._renderChips = function () {
        const self = this;
        this.chipContainer.innerHTML = '';
        this.pending.forEach(function (att) {
            const chip = document.createElement('div');
            chip.className = 'attach-chip';
            chip.dataset.id = att.id;
            chip.innerHTML = '<span class="attach-chip__icon">📄</span>'
                + '<span class="attach-chip__name" title="' + att.filename + '">' + att.filename + '</span>'
                + '<span class="attach-chip__meta">' + formatBytes(att.size_bytes) + '</span>';
            const rm = document.createElement('button');
            rm.type = 'button';
            rm.setAttribute('aria-label', 'Remove attachment');
            rm.textContent = '×';
            rm.addEventListener('click', function () {
                self.remove(att.id);
            });
            chip.appendChild(rm);
            self.chipContainer.appendChild(chip);
        });
    };

    ComposerPasteManager.prototype.remove = function (id) {
        this.pending = this.pending.filter(function (a) { return a.id !== id; });
        this._renderChips();
        this.onChange();
    };

    ComposerPasteManager.prototype.clear = function () {
        this.pending = [];
        this._renderChips();
        this.onChange();
    };

    ComposerPasteManager.prototype.getCaption = function () {
        return (this.textarea.value || '').trim().slice(0, this.config.maxCaptionChars);
    };

    ComposerPasteManager.prototype.canSend = function () {
        return this.getCaption().length > 0 || this.pending.length > 0;
    };

    /**
     * Build wire payload (v1 proposed shape for q-bridge).
     */
    ComposerPasteManager.prototype.buildPayload = function () {
        const caption = this.getCaption();
        const attachments = this.pending.map(function (a) {
            return {
                id: a.id,
                kind: a.kind,
                filename: a.filename,
                mime_type: a.mime_type,
                size_bytes: a.size_bytes,
                text: a.text,
            };
        });

        let message = caption;
        if (attachments.length) {
            const blocks = attachments.map(function (a, i) {
                return '[Attached text ' + (i + 1) + ': ' + a.filename + ' (' + formatBytes(a.size_bytes) + ')]\n'
                    + a.text;
            });
            message = (caption ? caption + '\n\n' : '') + blocks.join('\n\n');
        }

        return {
            message: message,
            caption: caption,
            attachments: attachments,
            attachment_count: attachments.length,
            message_bytes: new TextEncoder().encode(message).length,
        };
    };

    ComposerPasteManager.prototype.renderBubbleHtml = function (payload) {
        let html = '';
        if (payload.caption) {
            html += '<div>' + escapeHtml(payload.caption) + '</div>';
        }
        payload.attachments.forEach(function (a) {
            html += '<div class="bubble-attach" data-preview-id="' + a.id + '">'
                + '<span>📄</span>'
                + '<span><strong>' + escapeHtml(a.filename) + '</strong> · ' + formatBytes(a.size_bytes) + '</span>'
                + '<button type="button" data-preview="' + a.id + '">Preview</button>'
                + '</div>';
        });
        return html || '<em>(empty)</em>';
    };

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    global.ComposerPasteManager = ComposerPasteManager;
    global.AskQComposerUtils = { formatBytes: formatBytes, escapeHtml: escapeHtml };
})(window);
