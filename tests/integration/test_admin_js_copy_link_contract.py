"""
Static regression: `.js-copy-link` must treat absolute `data-copy-url` as-is.

The bug fixed in eea0258 was `window.location.origin + absoluteUrl`, doubling hosts.
Fast test — no browser — runs everywhere CI runs pytest.
"""

from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
ADMIN_JS = REPO_ROOT / "public/assets/admin.js"


def test_bind_copy_link_branches_absolute_over_relative():
    txt = ADMIN_JS.read_text(encoding="utf-8")
    assert "function bindCopyLink()" in txt
    needle = "^https?:\\/\\//i.test(raw)"
    assert needle in txt, "Absolute-URL detection must remain in bindCopyLink (doubled-origin regression)"
    assert "Absolute URLs (public share links" in txt
    layout = (REPO_ROOT / "public/admin/_layout_bottom.php").read_text(encoding="utf-8")
    assert "admin.js" in layout and "v=" in layout
