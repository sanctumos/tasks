/* Sanctum Tasks admin runtime helpers
 * - Autosave inline forms (selects + text inputs) with toast feedback
 * - Toast helper
 * - View switcher (board <-> list) backed by localStorage
 */
(function () {
    "use strict";

    const TOAST_HOST_ID = "st-toast-host";
    function ensureHost() {
        let host = document.getElementById(TOAST_HOST_ID);
        if (!host) {
            host = document.createElement("div");
            host.id = TOAST_HOST_ID;
            host.className = "st-toast-host";
            document.body.appendChild(host);
        }
        return host;
    }

    function toast(message, kind) {
        const host = ensureHost();
        const el = document.createElement("div");
        el.className = "st-toast" + (kind === "error" ? " st-toast--err" : "");
        const icon = document.createElement("i");
        icon.className = "bi " + (kind === "error" ? "bi-exclamation-triangle-fill" : "bi-check-circle-fill");
        el.appendChild(icon);
        const span = document.createElement("span");
        span.textContent = message;
        el.appendChild(span);
        host.appendChild(el);
        requestAnimationFrame(() => el.classList.add("is-visible"));
        setTimeout(() => {
            el.classList.remove("is-visible");
            setTimeout(() => el.remove(), 250);
        }, 1800);
    }
    window.stToast = toast;

    function bindAutosaveForm(form) {
        if (form.dataset.autosaveBound === "1") return;
        form.dataset.autosaveBound = "1";

        const submit = async () => {
            const data = new FormData(form);
            try {
                const res = await fetch(form.getAttribute("action") || window.location.href, {
                    method: form.getAttribute("method") || "POST",
                    headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" },
                    body: data,
                    credentials: "same-origin",
                });
                if (!res.ok) {
                    const body = await res.json().catch(() => ({}));
                    toast(body.error || ("Update failed (" + res.status + ")"), "error");
                    return;
                }
                let body = null;
                try { body = await res.json(); } catch (_) {}
                if (body && body.success === false) {
                    toast(body.error || "Update failed", "error");
                    return;
                }
                toast("Saved");
                if (form.dataset.autosaveReload === "1") {
                    setTimeout(() => window.location.reload(), 250);
                }
            } catch (e) {
                toast("Network error", "error");
            }
        };

        form.querySelectorAll(".js-autosave").forEach((el) => {
            el.addEventListener("change", submit);
        });
        form.querySelectorAll(".js-autosave-blur").forEach((el) => {
            el.addEventListener("blur", () => {
                if (el.dataset.lastValue !== el.value) {
                    el.dataset.lastValue = el.value;
                    submit();
                }
            });
            el.addEventListener("focus", () => { el.dataset.lastValue = el.value; });
        });
    }

    function bindInlineEdit() {
        document.querySelectorAll(".js-inline-edit-toggle").forEach((btn) => {
            btn.addEventListener("click", (e) => {
                const targetId = btn.getAttribute("data-edit-target");
                if (!targetId) return;
                e.preventDefault();
                const form = document.getElementById(targetId);
                if (!form) return;
                const display = document.querySelector('.js-inline-edit-target[data-edit-target="' + targetId + '"]');
                form.classList.remove("d-none");
                if (display) display.classList.add("d-none");
                const focusEl = form.querySelector("textarea, input[type=text], input:not([type=hidden])");
                if (focusEl) focusEl.focus();
            });
        });
        document.querySelectorAll(".js-inline-edit-cancel").forEach((btn) => {
            btn.addEventListener("click", (e) => {
                const targetId = btn.getAttribute("data-edit-target");
                if (!targetId) return;
                e.preventDefault();
                const form = document.getElementById(targetId);
                if (!form) return;
                const display = document.querySelector('.js-inline-edit-target[data-edit-target="' + targetId + '"]');
                form.classList.add("d-none");
                if (display) display.classList.remove("d-none");
            });
        });
    }

    function bindCopyLink() {
        document.querySelectorAll(".js-copy-link").forEach((el) => {
            el.addEventListener("click", (e) => {
                e.preventDefault();
                const path = el.getAttribute("data-copy-url") || window.location.pathname + window.location.search;
                const url = window.location.origin + path;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(
                        () => toast("Link copied"),
                        () => toast("Copy failed", "error")
                    );
                } else {
                    try {
                        const ta = document.createElement("textarea");
                        ta.value = url; document.body.appendChild(ta);
                        ta.select(); document.execCommand("copy");
                        document.body.removeChild(ta);
                        toast("Link copied");
                    } catch (_) { toast("Copy failed", "error"); }
                }
            });
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        document.querySelectorAll("form.js-autosave-form").forEach(bindAutosaveForm);
        bindInlineEdit();
        bindCopyLink();

        // View switcher (board <-> list)
        document.querySelectorAll("[data-view-switch]").forEach((btn) => {
            btn.addEventListener("click", (e) => {
                e.preventDefault();
                const target = btn.getAttribute("data-view-switch");
                const root = document.querySelector("[data-view-root]");
                if (!root) return;
                root.dataset.view = target;
                document.querySelectorAll("[data-view-switch]").forEach((b) => {
                    b.classList.toggle("active", b.getAttribute("data-view-switch") === target);
                });
                try { localStorage.setItem("st_tasks_view", target); } catch (_) {}
            });
        });
        const root = document.querySelector("[data-view-root]");
        if (root) {
            let saved = null;
            try { saved = localStorage.getItem("st_tasks_view"); } catch (_) {}
            if (saved) {
                root.dataset.view = saved;
                document.querySelectorAll("[data-view-switch]").forEach((b) => {
                    b.classList.toggle("active", b.getAttribute("data-view-switch") === saved);
                });
            }
        }
    });
})();
