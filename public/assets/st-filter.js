/**
 * Shared client-side live filter (Search S3/S4).
 * Plain ES2019+ — no build step.
 *
 * stFilter.attach({
 *   input, items, chips?, countEl?, clearEl?,
 *   textOf?, chipMatch?, onChange?, urlParam?, debounceMs?
 * })
 *
 * stFilter.attachFind({
 *   input, root, contentSelector?, scopeSelector?,
 *   counterEl?, prevBtn?, nextBtn?, activeClass?, debounceMs?, minLength?
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
        if (!root) {
            return;
        }
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

    function highlightTextNodes(root, needle) {
        clearMarks(root);
        var q = String(needle || "").trim();
        if (!q || !root) {
            return [];
        }
        var re = new RegExp(escapeRegExp(q), "ig");
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        var textNodes = [];
        while (walker.nextNode()) {
            textNodes.push(walker.currentNode);
        }
        var marks = [];
        textNodes.forEach(function (textNode) {
            var text = textNode.nodeValue || "";
            if (!text || !re.test(text)) {
                return;
            }
            re.lastIndex = 0;
            var frag = document.createDocumentFragment();
            var last = 0;
            var m;
            while ((m = re.exec(text)) !== null) {
                if (m.index > last) {
                    frag.appendChild(document.createTextNode(text.slice(last, m.index)));
                }
                var mark = document.createElement("mark");
                mark.className = "st-hl";
                mark.textContent = m[0];
                frag.appendChild(mark);
                marks.push(mark);
                last = m.index + m[0].length;
            }
            if (last < text.length) {
                frag.appendChild(document.createTextNode(text.slice(last)));
            }
            if (textNode.parentNode) {
                textNode.parentNode.replaceChild(frag, textNode);
            }
        });
        return marks;
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
            if (node.children && node.children.length > 0 && !node.classList.contains("task-card__title") && !node.classList.contains("todo-row__title") && !node.hasAttribute("data-st-filter-title")) {
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
        // Empty string = non-stateful (no URL sync). Default only when omitted.
        var urlParam = Object.prototype.hasOwnProperty.call(options, "urlParam")
            ? options.urlParam
            : "q";
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

    /**
     * Find-in-thread: highlight matches in nested HTML, hop with prev/next.
     * Non-stateful by design (no URL).
     */
    function attachFind(options) {
        options = options || {};
        var input = options.input;
        var root = options.root;
        if (!input || !root) {
            return null;
        }
        var contentSelector = options.contentSelector || ".comment-body";
        var scopeSelector = options.scopeSelector || ".comment-item";
        var counterEl = options.counterEl || null;
        var prevBtn = options.prevBtn || null;
        var nextBtn = options.nextBtn || null;
        var activeClass = options.activeClass || "st-comment-hl";
        var debounceMs = typeof options.debounceMs === "number" ? options.debounceMs : 80;
        var minLength = typeof options.minLength === "number" ? options.minLength : 2;
        var marks = [];
        var index = 0;
        var timer = null;

        function clearActive() {
            root.querySelectorAll("." + activeClass).forEach(function (el) {
                el.classList.remove(activeClass);
            });
            root.querySelectorAll("mark.st-hl--active").forEach(function (el) {
                el.classList.remove("st-hl--active");
            });
        }

        function setActive(i) {
            clearActive();
            if (!marks.length) {
                if (counterEl) {
                    counterEl.textContent = "0/0";
                }
                return;
            }
            index = ((i % marks.length) + marks.length) % marks.length;
            var mark = marks[index];
            if (!mark || !mark.isConnected) {
                return;
            }
            mark.classList.add("st-hl--active");
            var scope = mark.closest(scopeSelector);
            if (scope) {
                scope.classList.add(activeClass);
            }
            try {
                mark.scrollIntoView({ block: "nearest", behavior: "smooth" });
            } catch (e) {
                mark.scrollIntoView(true);
            }
            if (counterEl) {
                counterEl.textContent = String(index + 1) + "/" + String(marks.length);
            }
        }

        function apply() {
            var q = String(input.value || "").trim();
            clearActive();
            root.querySelectorAll(contentSelector).forEach(function (el) {
                clearMarks(el);
            });
            marks = [];
            index = 0;
            if (q.length < minLength) {
                if (counterEl) {
                    counterEl.textContent = "";
                }
                return;
            }
            root.querySelectorAll(contentSelector).forEach(function (el) {
                var found = highlightTextNodes(el, q);
                for (var i = 0; i < found.length; i++) {
                    marks.push(found[i]);
                }
            });
            if (counterEl && !marks.length) {
                counterEl.textContent = "0/0";
            }
            setActive(0);
        }

        function schedule() {
            if (timer) {
                clearTimeout(timer);
            }
            timer = setTimeout(apply, debounceMs);
        }

        input.addEventListener("input", schedule);
        input.addEventListener("search", schedule);
        input.addEventListener("keydown", function (ev) {
            if (ev.key === "Enter") {
                ev.preventDefault();
                if (ev.shiftKey) {
                    setActive(index - 1);
                } else {
                    setActive(index + 1);
                }
            }
        });

        if (prevBtn) {
            prevBtn.addEventListener("click", function (ev) {
                ev.preventDefault();
                setActive(index - 1);
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener("click", function (ev) {
                ev.preventDefault();
                setActive(index + 1);
            });
        }

        apply();
        return {
            apply: apply,
            next: function () {
                setActive(index + 1);
            },
            prev: function () {
                setActive(index - 1);
            },
            getMatchCount: function () {
                return marks.length;
            },
            getIndex: function () {
                return marks.length ? index : -1;
            },
        };
    }

    global.stFilter = {
        attach: attach,
        attachFind: attachFind,
        normalize: normalize,
        clearMarks: clearMarks,
        highlightTextNodes: highlightTextNodes,
    };
})(typeof window !== "undefined" ? window : this);
