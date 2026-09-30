/**
 * Shared client-side live filter (Search S3/S4).
 * Plain ES2019+ — no build step.
 *
 * stFilter.attach({
 *   input, items, chips?, countEl?, clearEl?,
 *   textOf?, chipMatch?, onChange?, urlParam?, debounceMs?
 * })
 */
(function (global) {
    "use strict";

    function normalize(s) {
        return String(s || "").toLowerCase().replace(/\s+/g, " ").trim();
    }

    function defaultTextOf(el) {
        return el.getAttribute("data-st-filter-text") || el.textContent || "";
    }

    function escapeRegExp(s) {
        return String(s).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    }

    function clearMarks(root) {
        root.querySelectorAll("mark.st-hl").forEach(function (m) {
            var parent = m.parentNode;
            if (!parent) {
                return;
            }
            while (m.firstChild) {
                parent.insertBefore(m.firstChild, m);
            }
            parent.removeChild(m);
            parent.normalize();
        });
    }

    function highlightIn(el, q) {
        clearMarks(el);
        var needle = String(q || "").trim();
        if (needle.length < 2) {
            return;
        }
        var targets = el.querySelectorAll(".task-card__title, .todo-row__title, [data-st-filter-title]");
        if (!targets.length) {
            targets = [el];
        }
        var re = new RegExp("(" + escapeRegExp(needle) + ")", "ig");
        targets.forEach(function (node) {
            if (node.children && node.children.length > 0 && !node.classList.contains("task-card__title") && !node.classList.contains("todo-row__title")) {
                return;
            }
            var text = node.textContent || "";
            if (!re.test(text)) {
                return;
            }
            re.lastIndex = 0;
            var html = text.replace(re, '<mark class="st-hl">$1</mark>');
            node.innerHTML = html;
        });
    }

    function attach(options) {
        options = options || {};
        var input = options.input;
        var items = options.items;
        if (!input || !items) {
            return null;
        }
        var itemList = typeof items.length === "number" ? Array.prototype.slice.call(items) : [];
        var chips = options.chips ? Array.prototype.slice.call(options.chips) : [];
        var countEl = options.countEl || null;
        var clearEl = options.clearEl || null;
        var textOf = typeof options.textOf === "function" ? options.textOf : defaultTextOf;
        var chipMatch = typeof options.chipMatch === "function" ? options.chipMatch : null;
        var onChange = typeof options.onChange === "function" ? options.onChange : null;
        var urlParam = options.urlParam || "q";
        var debounceMs = typeof options.debounceMs === "number" ? options.debounceMs : 120;
        var total = itemList.length;
        var timer = null;
        var chipState = {};

        chips.forEach(function (chip) {
            var name = chip.getAttribute("data-st-chip") || "";
            if (!name) {
                return;
            }
            chipState[name] = chip.classList.contains("is-on") || chip.getAttribute("aria-pressed") === "true";
        });

        function activeChips() {
            return Object.keys(chipState).filter(function (k) {
                return !!chipState[k];
            });
        }

        function syncUrl(q) {
            if (!urlParam) {
                return;
            }
            try {
                var u = new URL(window.location.href);
                var trimmed = String(q || "").trim();
                if (trimmed) {
                    u.searchParams.set(urlParam, trimmed);
                } else {
                    u.searchParams.delete(urlParam);
                }
                var chipsOn = activeChips();
                u.searchParams.delete("chip");
                chipsOn.forEach(function (c) {
                    u.searchParams.append("chip", c);
                });
                var next = u.pathname + u.search + u.hash;
                if (next !== window.location.pathname + window.location.search + window.location.hash) {
                    history.replaceState({ stFilter: 1 }, "", next);
                }
            } catch (e) {}
        }

        function apply() {
            var q = input.value || "";
            var qn = normalize(q);
            var chipsOn = activeChips();
            var visible = 0;
            itemList.forEach(function (el) {
                var textOk = !qn || normalize(textOf(el)).indexOf(qn) !== -1;
                var chipOk = true;
                if (chipOk && chipsOn.length && chipMatch) {
                    for (var i = 0; i < chipsOn.length; i++) {
                        if (!chipMatch(el, chipsOn[i])) {
                            chipOk = false;
                            break;
                        }
                    }
                } else if (chipsOn.length && !chipMatch) {
                    for (var j = 0; j < chipsOn.length; j++) {
                        var flag = el.getAttribute("data-st-chip-" + chipsOn[j]);
                        if (flag !== "1" && flag !== "true") {
                            chipOk = false;
                            break;
                        }
                    }
                }
                var show = textOk && chipOk;
                el.hidden = !show;
                el.style.display = show ? "" : "none";
                if (show) {
                    visible++;
                    highlightIn(el, qn);
                } else {
                    clearMarks(el);
                }
            });
            if (countEl) {
                countEl.innerHTML =
                    "<strong>" +
                    visible +
                    "</strong> of " +
                    total +
                    (total === 1 ? " item" : " items");
            }
            syncUrl(q);
            if (onChange) {
                onChange({ q: q, chips: chipsOn, visible: visible, total: total });
            }
        }

        function schedule() {
            if (timer) {
                clearTimeout(timer);
            }
            timer = setTimeout(apply, debounceMs);
        }

        input.addEventListener("input", schedule);
        input.addEventListener("search", schedule);

        chips.forEach(function (chip) {
            chip.addEventListener("click", function (ev) {
                ev.preventDefault();
                var name = chip.getAttribute("data-st-chip") || "";
                if (!name) {
                    return;
                }
                chipState[name] = !chipState[name];
                chip.classList.toggle("is-on", chipState[name]);
                chip.setAttribute("aria-pressed", chipState[name] ? "true" : "false");
                apply();
            });
        });

        if (clearEl) {
            clearEl.addEventListener("click", function (ev) {
                ev.preventDefault();
                input.value = "";
                Object.keys(chipState).forEach(function (k) {
                    chipState[k] = false;
                });
                chips.forEach(function (chip) {
                    chip.classList.remove("is-on");
                    chip.setAttribute("aria-pressed", "false");
                });
                apply();
            });
        }

        apply();
        return { apply: apply, getVisibleCount: function () {
            return itemList.filter(function (el) {
                return !el.hidden;
            }).length;
        } };
    }

    global.stFilter = { attach: attach, normalize: normalize };
})(typeof window !== "undefined" ? window : this);
