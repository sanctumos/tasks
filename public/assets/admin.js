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

    // ---------- Recurrence builder ----------
    function parseRRule(raw) {
        const state = { freq: "", interval: 1, byday: [], bymonthday: "", count: "", until: "", custom: "" };
        if (!raw) return state;
        const bare = raw.replace(/^RRULE:/i, "");
        const parts = {};
        bare.split(";").forEach((kv) => {
            if (!kv || kv.indexOf("=") < 0) return;
            const idx = kv.indexOf("=");
            parts[kv.slice(0, idx).trim().toUpperCase()] = kv.slice(idx + 1).trim();
        });
        const known = ["DAILY", "WEEKLY", "MONTHLY", "YEARLY"];
        if (!known.includes((parts.FREQ || "").toUpperCase())) {
            state.freq = "CUSTOM";
            state.custom = raw;
            return state;
        }
        state.freq = parts.FREQ.toUpperCase();
        state.interval = Math.max(1, parseInt(parts.INTERVAL || "1", 10) || 1);
        if (parts.BYDAY) state.byday = parts.BYDAY.split(",").map((d) => d.trim().toUpperCase()).filter(Boolean);
        if (parts.BYMONTHDAY) state.bymonthday = parts.BYMONTHDAY;
        if (parts.COUNT) state.count = parts.COUNT;
        if (parts.UNTIL) state.until = parts.UNTIL;
        return state;
    }

    function buildRRule(state) {
        if (!state.freq) return "";
        if (state.freq === "CUSTOM") return state.custom.trim();
        const out = ["FREQ=" + state.freq];
        const interval = Math.max(1, parseInt(state.interval, 10) || 1);
        if (interval !== 1) out.push("INTERVAL=" + interval);
        if (state.freq === "WEEKLY" && state.byday && state.byday.length) {
            const order = { MO: 1, TU: 2, WE: 3, TH: 4, FR: 5, SA: 6, SU: 7 };
            const sorted = state.byday.slice().sort((a, b) => (order[a] || 9) - (order[b] || 9));
            out.push("BYDAY=" + sorted.join(","));
        }
        if (state.freq === "MONTHLY" && state.bymonthday) {
            const md = parseInt(state.bymonthday, 10);
            if (md >= 1 && md <= 31) out.push("BYMONTHDAY=" + md);
        }
        if (state.count) {
            const c = parseInt(state.count, 10);
            if (c > 0) out.push("COUNT=" + c);
        } else if (state.until) {
            const u = state.until.replace(/-/g, "");
            if (/^\d{8}$/.test(u)) out.push("UNTIL=" + u + "T235959Z");
        }
        return out.join(";");
    }

    function humanizeRRule(state) {
        if (!state.freq) return "Does not repeat";
        if (state.freq === "CUSTOM") return state.custom.trim() || "Custom RRULE";
        const interval = Math.max(1, parseInt(state.interval, 10) || 1);
        const word = { DAILY: ["day", "days"], WEEKLY: ["week", "weeks"], MONTHLY: ["month", "months"], YEARLY: ["year", "years"] }[state.freq];
        const base = interval === 1 ? "Every " + word[0] : "Every " + interval + " " + word[1];
        const parts = [];
        if (state.freq === "WEEKLY" && state.byday.length) {
            const map = { MO: "Mon", TU: "Tue", WE: "Wed", TH: "Thu", FR: "Fri", SA: "Sat", SU: "Sun" };
            parts.push("on " + state.byday.map((d) => map[d] || d).join(", "));
        }
        if (state.freq === "MONTHLY" && state.bymonthday) {
            parts.push("on day " + state.bymonthday);
        }
        let tail = "";
        if (state.count) {
            const c = parseInt(state.count, 10);
            if (c > 0) tail = " for " + c + " time" + (c === 1 ? "" : "s");
        } else if (state.until) {
            tail = " until " + state.until;
        }
        return base + (parts.length ? " " + parts.join(" ") : "") + tail;
    }

    function bindRecurrenceBuilder() {
        const modal = document.getElementById("recurrenceModal");
        if (!modal) return;
        const builder = modal.querySelector(".recurrence-builder");
        const initial = builder ? builder.getAttribute("data-initial-rrule") || "" : "";

        const elFreq = modal.querySelector("#rr-freq");
        const elInterval = modal.querySelector("#rr-interval");
        const elIntervalUnit = modal.querySelector("#rr-interval-unit");
        const elMonthday = modal.querySelector("#rr-monthday");
        const elCount = modal.querySelector("#rr-count");
        const elUntil = modal.querySelector("#rr-until");
        const elCustom = modal.querySelector("#rr-custom");
        const elSummaryText = modal.querySelector("#rr-summary-text");
        const elSummaryRule = modal.querySelector("#rr-summary-rule");
        const elOutput = document.getElementById("recurrence-rule-output");
        const weekdayBoxes = Array.from(modal.querySelectorAll(".rr-weekday-input"));
        const endRadios = Array.from(modal.querySelectorAll('input[name="rr-end"]'));
        const sectionsSet = Array.from(modal.querySelectorAll(".rr-when-set"));
        const sectionWeekly = modal.querySelector(".rr-when-weekly");
        const sectionMonthly = modal.querySelector(".rr-when-monthly");
        const sectionCustom = modal.querySelector(".rr-when-custom");

        function readState() {
            const freq = elFreq.value;
            const byday = weekdayBoxes.filter((cb) => cb.checked).map((cb) => cb.value);
            const endChoice = (endRadios.find((r) => r.checked) || {}).value || "never";
            return {
                freq: freq,
                interval: elInterval.value,
                byday: byday,
                bymonthday: elMonthday.value,
                count: endChoice === "count" ? elCount.value : "",
                until: endChoice === "until" ? elUntil.value : "",
                custom: elCustom.value,
            };
        }

        function applyState(state) {
            elFreq.value = state.freq || "";
            elInterval.value = state.interval || 1;
            elMonthday.value = state.bymonthday || "";
            elCustom.value = state.custom || "";
            weekdayBoxes.forEach((cb) => { cb.checked = state.byday.indexOf(cb.value) !== -1; });
            elCount.value = state.count || (state.count === "" ? 10 : state.count);
            if (state.until && /^\d{8}/.test(state.until)) {
                const y = state.until.slice(0, 4), m = state.until.slice(4, 6), d = state.until.slice(6, 8);
                elUntil.value = y + "-" + m + "-" + d;
            } else {
                elUntil.value = state.until || "";
            }
            const endChoice = state.count ? "count" : (state.until ? "until" : "never");
            endRadios.forEach((r) => { r.checked = (r.value === endChoice); });
        }

        function refreshVisibility() {
            const f = elFreq.value;
            const isSet = !!f && f !== "CUSTOM";
            sectionsSet.forEach((el) => el.classList.toggle("d-none", !isSet));
            sectionWeekly.classList.toggle("d-none", f !== "WEEKLY");
            sectionMonthly.classList.toggle("d-none", f !== "MONTHLY");
            sectionCustom.classList.toggle("d-none", f !== "CUSTOM");
            const unitMap = { DAILY: "days", WEEKLY: "weeks", MONTHLY: "months", YEARLY: "years" };
            elIntervalUnit.textContent = unitMap[f] || "";
            const endChoice = (endRadios.find((r) => r.checked) || {}).value || "never";
            elCount.disabled = endChoice !== "count";
            elUntil.disabled = endChoice !== "until";
        }

        function refreshSummary() {
            const state = readState();
            const rule = buildRRule(state);
            elSummaryText.textContent = humanizeRRule(state);
            elSummaryRule.textContent = rule || "";
            if (elOutput) elOutput.value = rule;
        }

        const initialState = parseRRule(initial);
        applyState(initialState);
        refreshVisibility();
        refreshSummary();

        modal.addEventListener("change", () => { refreshVisibility(); refreshSummary(); });
        modal.addEventListener("input", () => { refreshSummary(); });
    }

    document.addEventListener("DOMContentLoaded", () => {
        document.querySelectorAll("form.js-autosave-form").forEach(bindAutosaveForm);
        bindInlineEdit();
        bindCopyLink();
        bindRecurrenceBuilder();

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
