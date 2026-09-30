#!/usr/bin/env python3
"""Playwright W24: search ACL — member cannot see People / outsider mentions.

Ephemeral PHP server (child; killed in finally).
Asserts: member omnibox has no People for outsider; search-users empty;
admin omnibox People still finds the member. Screenshots under output/.
"""
from __future__ import annotations

import json
import os
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

OUT = Path(__file__).resolve().parent / "output"
DESKTOP = (1280, 800)
API_KEY = "a" * 64
ADMIN_USER = "admin"
ADMIN_PASS = "AdminPass123456!"
MEMBER_PASS = "MemberPass123456"


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


def api_post(base: str, path: str, payload: dict, api_key: str = API_KEY) -> dict:
    req = urllib.request.Request(
        f"{base}{path}",
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json", "X-API-Key": api_key},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode())


def api_get(base: str, path: str, api_key: str = API_KEY) -> dict:
    req = urllib.request.Request(
        f"{base}{path}",
        headers={"X-API-Key": api_key},
        method="GET",
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode())


def unwrap(payload: dict) -> dict:
    return payload.get("data") or payload


def login(page, base: str, username: str, password: str) -> None:
    page.goto(f"{base}/admin/login.php", wait_until="networkidle")
    page.locator('input[name="username"]').fill(username)
    page.locator('input[name="password"]').fill(password)
    page.get_by_role("button", name="Sign in").click()
    page.wait_for_load_state("networkidle", timeout=30000)
    if "login.php" in page.url:
        raise RuntimeError(f"login failed for {username}: {page.url}")


def seed(base: str, tag: str) -> dict:
    member_user = f"aclmem_{tag}"
    peer_user = f"aclpeer_{tag}"
    out_user = f"aclout_{tag}"

    member = unwrap(
        api_post(
            base,
            "/api/create-user.php",
            {
                "username": member_user,
                "password": MEMBER_PASS,
                "role": "member",
                "must_change_password": False,
                "create_api_key": True,
                "api_key_name": "member-acl",
            },
        )
    )
    member_id = int((member.get("user") or {}).get("id") or 0)
    member_key = str(member.get("api_key") or "")
    if member_id <= 0 or not member_key:
        raise RuntimeError(f"member create failed: {member}")

    peer = unwrap(
        api_post(
            base,
            "/api/create-user.php",
            {
                "username": peer_user,
                "password": MEMBER_PASS,
                "role": "member",
                "must_change_password": False,
            },
        )
    )
    peer_id = int((peer.get("user") or {}).get("id") or 0)
    out = unwrap(
        api_post(
            base,
            "/api/create-user.php",
            {
                "username": out_user,
                "password": MEMBER_PASS,
                "role": "member",
                "must_change_password": False,
            },
        )
    )
    out_id = int((out.get("user") or {}).get("id") or 0)

    proj = unwrap(
        api_post(
            base,
            "/api/create-directory-project.php",
            {"name": f"AclBoard {tag}", "all_access": False},
        )
    )
    project_id = int((proj.get("project") or proj).get("id") or 0)
    for uid in (member_id, peer_id):
        api_post(
            base,
            "/api/add-project-member.php",
            {"project_id": project_id, "user_id": uid, "role": "member"},
        )

    lists = unwrap(api_get(base, f"/api/list-todo-lists.php?project_id={project_id}"))
    list_id = int((lists.get("todo_lists") or [{}])[0].get("id") or 0)
    api_post(
        base,
        "/api/create-task.php",
        {
            "title": f"Task aclseed_{tag}",
            "status": "todo",
            "project_id": project_id,
            "list_id": list_id,
        },
    )

    return {
        "member_user": member_user,
        "peer_user": peer_user,
        "out_user": out_user,
        "member_key": member_key,
        "out_id": out_id,
    }


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright not installed", file=sys.stderr)
        return 1

    repo = Path(__file__).resolve().parents[2]
    public = repo / "public"
    php = shutil.which("php")
    if not php:
        print("php not found", file=sys.stderr)
        return 1

    tmp = Path(tempfile.mkdtemp(prefix="search-acl-"))
    db = tmp / "tasks.db"
    env = os.environ.copy()
    env.update(
        {
            "TASKS_DB_PATH": str(db),
            "TASKS_BOOTSTRAP_ADMIN_USERNAME": ADMIN_USER,
            "TASKS_BOOTSTRAP_ADMIN_PASSWORD": ADMIN_PASS,
            "TASKS_BOOTSTRAP_API_KEY": API_KEY,
            "TASKS_PASSWORD_COST": "8",
            "TASKS_SESSION_COOKIE_SECURE": "0",
        }
    )

    port = free_port()
    base = f"http://127.0.0.1:{port}"
    proc = subprocess.Popen(
        [php, "-S", f"127.0.0.1:{port}", "-t", str(public)],
        cwd=str(public),
        env=env,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    OUT.mkdir(parents=True, exist_ok=True)
    try:
        ready = False
        for _ in range(120):
            try:
                with urllib.request.urlopen(f"{base}/api/health.php", timeout=3) as r:
                    if r.status in (200, 401):
                        ready = True
                        break
            except urllib.error.HTTPError as e:
                if e.code in (200, 401):
                    ready = True
                    break
            except Exception:
                pass
            time.sleep(0.25)
        if not ready:
            print("server not ready", file=sys.stderr)
            return 1

        con = sqlite3.connect(db)
        con.execute(
            "UPDATE users SET must_change_password = 0 WHERE username = ?",
            (ADMIN_USER,),
        )
        con.commit()
        con.close()

        tag = os.urandom(3).hex()
        ctx = seed(base, tag)
        member_user = ctx["member_user"]
        peer_user = ctx["peer_user"]
        out_user = ctx["out_user"]
        member_key = ctx["member_key"]

        # HTTP wire (same keys the browser would use via API)
        peer_hit = unwrap(
            api_get(
                base,
                f"/api/search-users.php?q={peer_user}&limit=10",
                api_key=member_key,
            )
        )
        peer_names = [u.get("username") for u in peer_hit.get("users") or []]
        assert peer_user in peer_names, peer_names

        out_miss = unwrap(
            api_get(
                base,
                f"/api/search-users.php?q={out_user}&limit=10",
                api_key=member_key,
            )
        )
        assert (out_miss.get("users") or []) == [], out_miss

        omni_member = unwrap(
            api_get(base, f"/api/search.php?q={out_user}&limit=5", api_key=member_key)
        )
        assert (omni_member.get("groups") or {}).get("users") == []
        assert int((omni_member.get("counts") or {}).get("users") or 0) == 0

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            try:
                page = browser.new_page(viewport={"width": DESKTOP[0], "height": DESKTOP[1]})
                login(page, base, member_user, MEMBER_PASS)
                page.goto(f"{base}/admin/", wait_until="networkidle")
                page.keyboard.press("Control+k")
                page.wait_for_selector("#st-searchbar.is-open #st-omnibox-input", timeout=10000)
                page.locator("#st-omnibox-input").fill(out_user)
                page.wait_for_timeout(800)
                # No People group for outsider
                assert page.locator('.st-omnibox__group', has_text="People").count() == 0
                page.screenshot(path=str(OUT / "search_acl_member_no_people.png"), full_page=False)

                # Session fetch of search-users must also be empty
                su = page.evaluate(
                    """async (q) => {
                        const r = await fetch('/api/search-users.php?q=' + encodeURIComponent(q) + '&limit=10');
                        return await r.json();
                    }""",
                    out_user,
                )
                su_users = (su.get("data") or su).get("users") or []
                assert su_users == [], su

                page.goto(f"{base}/admin/search.php?q={out_user}", wait_until="networkidle")
                html = page.content()
                assert 'data-group="users"' not in html
                assert "/admin/users.php?q=" not in html
                page.screenshot(path=str(OUT / "search_acl_member_full_search.png"), full_page=True)

                page.close()

                # Fresh context as admin — People still finds the member
                admin_page = browser.new_page(viewport={"width": DESKTOP[0], "height": DESKTOP[1]})
                login(admin_page, base, ADMIN_USER, ADMIN_PASS)
                admin_page.goto(f"{base}/admin/", wait_until="networkidle")
                admin_page.keyboard.press("Control+k")
                admin_page.wait_for_selector("#st-searchbar.is-open #st-omnibox-input", timeout=10000)
                admin_page.locator("#st-omnibox-input").fill(member_user)
                admin_page.locator(".st-omnibox__group", has_text="People").wait_for(state="visible", timeout=10000)
                assert admin_page.locator(".st-omnibox__item", has_text=member_user).count() >= 1
                admin_page.screenshot(path=str(OUT / "search_acl_admin_people.png"), full_page=False)
                admin_page.close()
            finally:
                browser.close()

        print(OUT / "search_acl_member_no_people.png")
        print(OUT / "search_acl_member_full_search.png")
        print(OUT / "search_acl_admin_people.png")
        return 0
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    raise SystemExit(main())
