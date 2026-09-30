/**
 * Home cross-project board — debounced live filter against ?partial=results.
 * Progressive enhancement: submit / clear still do full GET without JS.
 */
(function () {
    "use strict";

    var form = document.getElementById("st-home-filter-form");
    var host = document.getElementById("st-home-results");
    if (!form || !host || form.getAttribute("data-st-home-live-filter") !== "1") {
        return;
    }

    var DEBOUNCE_MS = 300;
    var timer = null;
    var abortCtrl = null;
    var fetchGen = 0;
    var spinner = form.querySelector(".st-livebar__spinner");
    var countEl = form.querySelector(".st-home-live-count");
    var subtitle = document.querySelector(".st-home-master-count");
    var actions = form.querySelector(".filter-bar__actions");
    var baselineTotal = parseInt(host.getAttribute("data-total-count") || "0", 10) || 0;
    var hadFiltersOnLoad = hasActiveFilters();

    function hasActiveFilters() {
        var fd = new FormData(form);
        var keys = ["q", "status", "tag", "priority", "assigned_to_user_id", "project", "project_id", "mine", "exclude_done"];
        for (var i = 0; i < keys.length; i++) {
            var v = fd.get(keys[i]);
            if (v != null && String(v).trim() !== "") {
                return true;
            }
        }
        return false;
    }

    function setLoading(on) {
        form.classList.toggle("is-loading", !!on);
        if (spinner) {
            spinner.hidden = !on;
        }
    }

    function formatCount(n, filtered) {
        var unit = n === 1 ? "task" : "tasks";
        if (filtered && baselineTotal > 0 && n !== baselineTotal) {
            return "<strong>" + n + "</strong> of " + baselineTotal + " " + unit + ' · <a href="/admin/" class="st-home-filter-clear">clear</a>';
        }
        if (filtered) {
            return n + " " + unit + ' · <a href="/admin/" class="st-home-filter-clear">clear</a>';
        }
        return n + " " + unit;
    }

    function updateCountUi(n, filtered) {
        if (countEl) {
            countEl.innerHTML = formatCount(n, filtered);
        }
        if (subtitle) {
            var unit = n === 1 ? "task" : "tasks";
            subtitle.textContent = n + " " + unit + " across every project you can reach.";
        }
    }

    function paramsFromForm() {
        var fd = new FormData(form);
        var params = new URLSearchParams();
        fd.forEach(function (value, key) {
            if (value == null) {
                return;
            }
            var s = String(value).trim();
            if (s === "") {
                return;
            }
            params.set(key, s);
        });
        return params;
    }

    function syncUrl(params) {
        var qs = params.toString();
        var next = "/admin/" + (qs ? "?" + qs : "");
        if (window.location.pathname + window.location.search !== next) {
            history.replaceState({ stHomeLiveFilter: 1 }, "", next);
        }
    }

    function rebindViewRoot() {
        var root = host.querySelector("[data-view-root]");
        if (!root) {
            return;
        }
        var name = root.getAttribute("data-view") || "board";
        host.querySelectorAll("[data-when-view]").forEach(function (el) {
            el.style.display = el.getAttribute("data-when-view") === name ? "" : "none";
        });
    }

    function fetchResults() {
        var params = paramsFromForm();
        var filtered = hasActiveFilters();
        syncUrl(params);

        var reqParams = new URLSearchParams(params.toString());
        reqParams.set("partial", "results");
        var url = "/admin/?" + reqParams.toString();

        if (abortCtrl) {
            try {
                abortCtrl.abort();
            } catch (e) {}
        }
        abortCtrl = typeof AbortController !== "undefined" ? new AbortController() : null;
        var gen = ++fetchGen;
        setLoading(true);

        var opts = { credentials: "same-origin", headers: { Accept: "text/html" } };
        if (abortCtrl) {
            opts.signal = abortCtrl.signal;
        }

        fetch(url, opts)
            .then(function (res) {
                if (!res.ok) {
                    throw new Error("partial " + res.status);
                }
                var hdr = res.headers.get("X-Total-Count");
                return res.text().then(function (html) {
                    return { html: html, hdr: hdr };
                });
            })
            .then(function (payload) {
                if (gen !== fetchGen) {
                    return;
                }
                var wrap = document.createElement("div");
                wrap.innerHTML = payload.html.trim();
                var next = wrap.firstElementChild;
                if (!next || !host.parentNode) {
                    throw new Error("bad fragment");
                }
                host.parentNode.replaceChild(next, host);
                host = next;
                var n = parseInt(host.getAttribute("data-total-count") || payload.hdr || "0", 10) || 0;
                if (!filtered) {
                    baselineTotal = n;
                    hadFiltersOnLoad = false;
                }
                updateCountUi(n, filtered);
                rebindViewRoot();
            })
            .catch(function (err) {
                if (err && err.name === "AbortError") {
                    return;
                }
                window.location.href = "/admin/" + (params.toString() ? "?" + params.toString() : "");
            })
            .finally(function () {
                if (gen === fetchGen) {
                    setLoading(false);
                }
            });
    }

    function scheduleFetch() {
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(fetchResults, DEBOUNCE_MS);
    }

    function clearFilters(ev) {
        if (ev) {
            ev.preventDefault();
        }
        var master = document.querySelector(".st-home-master");
        var path = master ? master.getAttribute("data-st-home-path") : "";
        // Light path has no unfiltered results region — leave via full navigation.
        if (path === "light") {
            window.location.href = "/admin/";
            return;
        }
        form.querySelectorAll('input[name="q"], input[name="tag"], input[name="project"]').forEach(function (el) {
            el.value = "";
        });
        form.querySelectorAll("select").forEach(function (sel) {
            sel.value = "";
            if (sel.name === "sort_by") {
                sel.value = "updated_at";
            }
            if (sel.name === "sort_dir") {
                sel.value = "DESC";
            }
        });
        form.querySelectorAll('input[name="mine"], input[name="exclude_done"]').forEach(function (el) {
            el.remove();
        });
        hadFiltersOnLoad = false;
        if (timer) {
            clearTimeout(timer);
        }
        fetchResults();
    }

    form.addEventListener("input", function (ev) {
        var t = ev.target;
        if (!t || !form.contains(t)) {
            return;
        }
        if (t.matches('input[name="q"], input[name="tag"], input[name="project"]')) {
            scheduleFetch();
        }
    });

    form.addEventListener("change", function (ev) {
        var t = ev.target;
        if (!t || !form.contains(t)) {
            return;
        }
        if (t.matches("select")) {
            scheduleFetch();
        }
    });

    form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        if (timer) {
            clearTimeout(timer);
        }
        fetchResults();
    });

    document.addEventListener("click", function (ev) {
        var clear = ev.target.closest(".st-home-filter-clear");
        if (clear && (form.contains(clear) || (countEl && countEl.contains(clear)))) {
            clearFilters(ev);
            return;
        }
        if (!actions || !actions.contains(ev.target)) {
            return;
        }
        var mineBtn = ev.target.closest("a.btn");
        if (!mineBtn) {
            return;
        }
        var label = (mineBtn.textContent || "").replace(/\s+/g, " ").trim();
        if (label.indexOf("Assigned to me") === -1) {
            return;
        }
        ev.preventDefault();
        var mineInput = form.querySelector('input[name="mine"]');
        var turningOn = !mineInput;
        if (turningOn) {
            mineInput = document.createElement("input");
            mineInput.type = "hidden";
            mineInput.name = "mine";
            mineInput.value = "1";
            form.insertBefore(mineInput, form.firstChild);
            mineBtn.classList.remove("btn-outline-primary");
            mineBtn.classList.add("btn-primary");
            mineBtn.setAttribute("aria-current", "true");
        } else {
            mineInput.remove();
            mineBtn.classList.add("btn-outline-primary");
            mineBtn.classList.remove("btn-primary");
            mineBtn.removeAttribute("aria-current");
        }
        if (timer) {
            clearTimeout(timer);
        }
        fetchResults();
    });

    if (hadFiltersOnLoad) {
        fetch("/admin/?partial=results", { credentials: "same-origin", headers: { Accept: "text/html" } })
            .then(function (res) {
                return res.ok ? res.headers.get("X-Total-Count") : null;
            })
            .then(function (hdr) {
                var n = parseInt(hdr || "0", 10);
                if (n > 0) {
                    baselineTotal = n;
                    updateCountUi(parseInt(host.getAttribute("data-total-count") || "0", 10) || 0, true);
                }
            })
            .catch(function () {});
    }
})();
