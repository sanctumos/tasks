/**
 * Global admin omnibox — live dropdown against GET /api/search.php.
 * Progressive enhancement: form GETs /admin/search.php when JS is off.
 * Bar is collapsible (desktop) / popover (mobile); opened via Search nav or Ctrl/Cmd+K.
 */
(function () {
    "use strict";

    var form = document.getElementById("st-omnibox");
    if (!form) {
        return;
    }
    var input = document.getElementById("st-omnibox-input");
    var drop = document.getElementById("st-omnibox-drop");
    var bar = document.getElementById("st-searchbar");
    var toggleBtn = document.getElementById("st-omnibox-toggle");
    var closeBtn = document.getElementById("st-omnibox-close");
    var backdrop = document.getElementById("st-omnibox-backdrop");
    if (!input || !drop) {
        return;
    }

    var DEBOUNCE_MS = 250;
    var timer = null;
    var abortCtrl = null;
    var flatItems = [];
    var activeIdx = -1;
    var lastQ = "";
    var lastTotal = 0;
    var fetchGen = 0;
    var dropOpen = false;

    var GROUP_META = {
        tasks: { label: "Tasks", icon: "bi-check2-square", cls: "task" },
        documents: { label: "Documents", icon: "bi-file-text", cls: "doc" },
        users: { label: "People", icon: "bi-person", cls: "user" },
        projects: { label: "Boards", icon: "bi-kanban", cls: "project" },
    };

    function isEditableTarget(el) {
        if (!el || !el.tagName) {
            return false;
        }
        var tag = el.tagName.toLowerCase();
        if (tag === "input" || tag === "textarea" || tag === "select") {
            return true;
        }
        return !!el.isContentEditable;
    }

    function isMobilePopover() {
        return window.matchMedia("(max-width: 991.98px)").matches;
    }

    function isBarOpen() {
        return !!(bar && bar.classList.contains("is-open") && !bar.hidden);
    }

    function collapseNavIfOpen() {
        var nav = document.getElementById("adminNavbar");
        if (!nav || !nav.classList.contains("show")) {
            return;
        }
        var toggler = document.querySelector('.navbar-toggler[data-bs-target="#adminNavbar"]');
        if (typeof bootstrap !== "undefined" && bootstrap.Collapse) {
            bootstrap.Collapse.getOrCreateInstance(nav, { toggle: false }).hide();
        } else if (toggler) {
            toggler.click();
        }
    }

    function openBar(opts) {
        opts = opts || {};
        if (bar) {
            bar.hidden = false;
            bar.classList.add("is-open");
            if (isMobilePopover()) {
                collapseNavIfOpen();
                document.body.classList.add("st-searchbar-lock");
            }
        }
        if (toggleBtn) {
            toggleBtn.setAttribute("aria-expanded", "true");
            toggleBtn.classList.add("is-active");
        }
        if (opts.focus !== false) {
            window.setTimeout(function () {
                input.focus();
                if (opts.select !== false) {
                    input.select();
                }
            }, 0);
        }
    }

    function closeBar() {
        closeDrop();
        if (bar) {
            bar.classList.remove("is-open");
            bar.hidden = true;
        }
        document.body.classList.remove("st-searchbar-lock");
        if (toggleBtn) {
            toggleBtn.setAttribute("aria-expanded", "false");
            toggleBtn.classList.remove("is-active");
        }
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function highlight(title, q) {
        var safe = escapeHtml(title);
        var needle = String(q || "").trim();
        if (needle.length < 2) {
            return safe;
        }
        var lower = safe.toLowerCase();
        var nLower = escapeHtml(needle).toLowerCase();
        var idx = lower.indexOf(nLower);
        if (idx < 0) {
            return safe;
        }
        return (
            safe.slice(0, idx) +
            '<mark class="st-hl">' +
            safe.slice(idx, idx + nLower.length) +
            "</mark>" +
            safe.slice(idx + nLower.length)
        );
    }

    function itemSubline(item) {
        var entity = item.entity || "";
        if (entity === "task") {
            var bits = [];
            if (item.project_name) {
                bits.push(item.project_name);
            }
            if (item.status) {
                bits.push(item.status);
            }
            if (item.id) {
                bits.push("#" + item.id);
            }
            return bits.join(" · ");
        }
        if (entity === "document") {
            return item.project_name || "";
        }
        if (entity === "user") {
            return item.role || item.person_kind || "";
        }
        if (entity === "project") {
            return item.status || "";
        }
        return "";
    }

    function closeDrop() {
        dropOpen = false;
        fetchGen += 1;
        drop.hidden = true;
        drop.innerHTML = "";
        input.setAttribute("aria-expanded", "false");
        flatItems = [];
        activeIdx = -1;
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
        if (abortCtrl) {
            abortCtrl.abort();
            abortCtrl = null;
        }
    }

    function openDrop() {
        dropOpen = true;
        drop.hidden = false;
        input.setAttribute("aria-expanded", "true");
        positionMobileDrop();
    }

    function positionMobileDrop() {
        if (isMobilePopover()) {
            drop.style.top = "";
            return;
        }
        if (window.matchMedia("(max-width: 575.98px)").matches) {
            var rect = form.getBoundingClientRect();
            drop.style.top = Math.round(rect.bottom + 6) + "px";
        } else {
            drop.style.top = "";
        }
    }

    function setActive(idx) {
        var nodes = drop.querySelectorAll(".st-omnibox__item");
        if (!nodes.length) {
            activeIdx = -1;
            return;
        }
        if (idx < 0) {
            idx = nodes.length - 1;
        }
        if (idx >= nodes.length) {
            idx = 0;
        }
        activeIdx = idx;
        nodes.forEach(function (n, i) {
            n.classList.toggle("is-active", i === activeIdx);
        });
        var active = nodes[activeIdx];
        if (active && typeof active.scrollIntoView === "function") {
            active.scrollIntoView({ block: "nearest" });
        }
    }

    function render(payload) {
        var q = (payload && payload.q) || lastQ;
        var groups = (payload && payload.groups) || {};
        var counts = (payload && payload.counts) || {};
        flatItems = [];
        var total = 0;
        Object.keys(GROUP_META).forEach(function (key) {
            total += Number(counts[key] || 0);
        });
        lastTotal = total;

        var html = "";
        var any = false;
        Object.keys(GROUP_META).forEach(function (key) {
            var meta = GROUP_META[key];
            var items = groups[key] || [];
            if (!items.length) {
                return;
            }
            any = true;
            html += '<div class="st-omnibox__group">' + escapeHtml(meta.label) + "</div>";
            items.forEach(function (item) {
                var idx = flatItems.length;
                flatItems.push(item);
                var title = item.title || item.name || "";
                var sub = itemSubline(item);
                var url = item.url || "#";
                html +=
                    '<a class="st-omnibox__item" role="option" data-idx="' +
                    idx +
                    '" href="' +
                    escapeHtml(url) +
                    '">' +
                    '<span class="st-omnibox__icon st-omnibox__icon--' +
                    meta.cls +
                    '"><i class="bi ' +
                    meta.icon +
                    '" aria-hidden="true"></i></span>' +
                    '<span class="st-omnibox__text">' +
                    '<span class="st-omnibox__title">' +
                    highlight(title, q) +
                    "</span>" +
                    (sub
                        ? '<span class="st-omnibox__sub">' + escapeHtml(sub) + "</span>"
                        : "") +
                    "</span></a>";
            });
        });

        if (!any) {
            html = '<div class="st-omnibox__empty">No results</div>';
        } else {
            html +=
                '<div class="st-omnibox__foot">' +
                "<span>↑↓ navigate · ↵ open · esc close</span>" +
                "<span><strong>Enter</strong> for all " +
                total +
                " results</span></div>";
        }

        drop.innerHTML = html;
        openDrop();
        setActive(any ? 0 : -1);
    }

    function fetchResults(q) {
        lastQ = q;
        var gen = ++fetchGen;
        if (abortCtrl) {
            abortCtrl.abort();
        }
        abortCtrl = typeof AbortController !== "undefined" ? new AbortController() : null;
        var url = "/api/search.php?q=" + encodeURIComponent(q) + "&limit=5";
        var opts = { credentials: "same-origin", headers: { Accept: "application/json" } };
        if (abortCtrl) {
            opts.signal = abortCtrl.signal;
        }
        fetch(url, opts)
            .then(function (r) {
                if (gen !== fetchGen) {
                    return null;
                }
                if (!r.ok) {
                    throw new Error("search " + r.status);
                }
                return r.json();
            })
            .then(function (body) {
                if (body === null || gen !== fetchGen) {
                    return;
                }
                var data = body && body.data ? body.data : body;
                render(data || {});
            })
            .catch(function (err) {
                if (gen !== fetchGen) {
                    return;
                }
                if (err && err.name === "AbortError") {
                    return;
                }
                drop.innerHTML = '<div class="st-omnibox__empty">Search failed</div>';
                openDrop();
            });
    }

    function scheduleFetch() {
        var q = String(input.value || "").trim();
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
        if (q.length < 2) {
            closeDrop();
            return;
        }
        timer = setTimeout(function () {
            fetchResults(q);
        }, DEBOUNCE_MS);
    }

    input.addEventListener("input", scheduleFetch);
    input.addEventListener("focus", function () {
        var q = String(input.value || "").trim();
        if (q.length >= 2 && drop.hidden) {
            fetchResults(q);
        }
    });

    form.addEventListener("submit", function (e) {
        if (!drop.hidden && activeIdx >= 0 && flatItems[activeIdx]) {
            e.preventDefault();
            window.location.href = flatItems[activeIdx].url;
        }
    });

    input.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            e.preventDefault();
            if (!drop.hidden) {
                closeDrop();
                return;
            }
            closeBar();
            if (toggleBtn) {
                toggleBtn.focus();
            }
            return;
        }
        if (drop.hidden) {
            return;
        }
        if (e.key === "ArrowDown") {
            e.preventDefault();
            setActive(activeIdx + 1);
        } else if (e.key === "ArrowUp") {
            e.preventDefault();
            setActive(activeIdx - 1);
        }
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "/" || ((e.ctrlKey || e.metaKey) && (e.key === "k" || e.key === "K"))) {
            if (isEditableTarget(e.target) && e.target !== input) {
                return;
            }
            e.preventDefault();
            openBar({ focus: true, select: true });
        }
        if (e.key === "Escape" && isBarOpen() && e.target !== input) {
            closeBar();
        }
    });

    document.addEventListener("click", function (e) {
        if (!isBarOpen()) {
            return;
        }
        if (form.contains(e.target) || drop.contains(e.target)) {
            return;
        }
        if (toggleBtn && toggleBtn.contains(e.target)) {
            return;
        }
        // Desktop: click outside closes dropdown only; mobile popover uses backdrop.
        if (!isMobilePopover()) {
            closeDrop();
        }
    });

    if (toggleBtn) {
        toggleBtn.addEventListener("click", function (e) {
            e.preventDefault();
            if (isBarOpen()) {
                closeBar();
            } else {
                openBar({ focus: true, select: true });
            }
        });
    }
    if (closeBtn) {
        closeBtn.addEventListener("click", function (e) {
            e.preventDefault();
            closeBar();
            if (toggleBtn) {
                toggleBtn.focus();
            }
        });
    }
    if (backdrop) {
        backdrop.addEventListener("click", function (e) {
            e.preventDefault();
            closeBar();
        });
    }

    window.addEventListener("resize", function () {
        if (!isBarOpen()) {
            return;
        }
        if (isMobilePopover()) {
            document.body.classList.add("st-searchbar-lock");
        } else {
            document.body.classList.remove("st-searchbar-lock");
        }
        if (!drop.hidden) {
            positionMobileDrop();
        }
    });
})();
