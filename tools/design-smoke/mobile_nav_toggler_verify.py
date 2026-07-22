#!/usr/bin/env python3
"""Verify mobile navbar toggler is visible (not white-on-white) for light skins.

# Run: tools/design-smoke/.venv/bin/python tools/design-smoke/mobile_nav_toggler_verify.py
"""
from __future__ import annotations

import os
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = os.environ.get("TASKS_SMOKE_BASE", "https://tasks.decisionsciencecorp.com").rstrip("/")
OUT = Path(__file__).resolve().parent / "output" / "mobile-nav-toggler"
OUT.mkdir(parents=True, exist_ok=True)


def luminance(rgb: tuple[int, int, int]) -> float:
    r, g, b = [c / 255.0 for c in rgb]
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def parse_rgb(css: str) -> tuple[int, int, int]:
    # "rgb(0, 0, 0)" or "rgba(0, 0, 0, 1)"
    inner = css[css.find("(") + 1 : css.rfind(")")]
    parts = [p.strip() for p in inner.split(",")]
    return int(float(parts[0])), int(float(parts[1])), int(float(parts[2]))


def main() -> int:
    user = os.environ.get("TASKS_DSC_OTTOVERNAL_USERNAME", "")
    password = os.environ.get("TASKS_DSC_OTTOVERNAL_PASSWORD", "")
    if not user or not password:
        print("FAIL: need TASKS_DSC_OTTOVERNAL_USERNAME/PASSWORD")
        return 1

    failures: list[str] = []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page(viewport={"width": 390, "height": 844})
        try:
            page.goto(f"{BASE}/admin/login.php", wait_until="networkidle", timeout=60000)
            page.fill('input[name="username"]', user)
            page.fill('input[name="password"]', password)
            page.locator('button[type="submit"]').click()
            page.wait_for_url("**/admin/**", timeout=30000)
            page.goto(f"{BASE}/admin/", wait_until="networkidle", timeout=60000)

            skin = page.evaluate("document.documentElement.getAttribute('data-skin-comp')")
            toggler = page.locator(".admin-nav .navbar-toggler")
            if toggler.count() == 0:
                failures.append("no .navbar-toggler in DOM")
            else:
                if not toggler.is_visible():
                    failures.append("toggler not visible")
                box = toggler.bounding_box()
                if not box or box["width"] < 8 or box["height"] < 8:
                    failures.append(f"toggler box tiny: {box}")

                # Sample center pixel of toggler icon area vs nav background
                icon = page.locator(".admin-nav .navbar-toggler-icon")
                icon_box = icon.bounding_box()
                nav_bg = page.evaluate(
                    """() => {
                      const nav = document.querySelector('.admin-nav');
                      return getComputedStyle(nav).backgroundColor;
                    }"""
                )
                filter_css = page.evaluate(
                    """() => {
                      const el = document.querySelector('.admin-nav .navbar-toggler-icon');
                      return el ? getComputedStyle(el).filter : '';
                    }"""
                )
                page.screenshot(path=str(OUT / f"home-mobile-{skin}.png"), full_page=False)
                toggler.click()
                page.wait_for_timeout(400)
                collapse = page.locator("#adminNavbar")
                if not collapse.evaluate("el => el.classList.contains('show')"):
                    failures.append("click did not open #adminNavbar")
                page.screenshot(path=str(OUT / f"home-mobile-{skin}-open.png"), full_page=False)

                print(f"skin={skin} nav_bg={nav_bg} icon_filter={filter_css!r} icon_box={icon_box}")
                # Light skins (hey/ledger) must darken the icon
                if skin in ("hey", "ledger"):
                    if "brightness(0)" not in filter_css and "invert" not in filter_css:
                        failures.append(
                            f"{skin}: toggler-icon filter missing darkening (got {filter_css!r})"
                        )
                    bg = parse_rgb(nav_bg)
                    if luminance(bg) < 0.5:
                        failures.append(f"{skin}: expected light nav bg, got {nav_bg}")
        except Exception as exc:  # noqa: BLE001
            failures.append(str(exc))
        finally:
            page.close()
            browser.close()

    if failures:
        print("FAIL:", "; ".join(failures))
        return 1
    print(f"OK — screenshots in {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
