"""
Integration tests for the Documents feature (long-form markdown reference
material attached to a directory project, with its own discussion thread).

Drives the public PHP HTTP surface — same fixtures as the task tests.
"""

import uuid

import pytest
import requests


pytestmark = pytest.mark.integration


def _url(base: str, path: str) -> str:
    return f"{base}{path}"


def _h(api_key: str) -> dict:
    return {"X-API-Key": api_key, "Content-Type": "application/json"}


def _create_project(base_url: str, api_key: str, name: str) -> int:
    r = requests.post(
        _url(base_url, "/api/create-directory-project.php"),
        headers=_h(api_key),
        json={"name": name, "all_access": True},
        timeout=5,
    )
    assert r.status_code == 201, r.text
    j = r.json()
    return int(((j.get("data") or {}).get("project") or j.get("project"))["id"])


def test_document_full_lifecycle_with_comments(php_server):
    base = php_server.base_url
    api_key = php_server.api_key
    headers = _h(api_key)

    tag = uuid.uuid4().hex[:8]
    project_id = _create_project(base, api_key, f"DocsProj-{tag}")

    # Create a doc with markdown body.
    create = requests.post(
        _url(base, "/api/create-document.php"),
        headers=headers,
        json={
            "project_id": project_id,
            "title": f"Onboarding spec {tag}",
            "body": "# Onboarding\n\n* step 1\n* step 2\n\nLink: https://example.com",
        },
        timeout=5,
    )
    assert create.status_code == 201, create.text
    doc = create.json()["document"]
    doc_id = int(doc["id"])
    assert doc["project_id"] == project_id
    assert doc["title"] == f"Onboarding spec {tag}"
    assert "step 1" in doc["body"]
    assert doc["comment_count"] == 0

    # Read it back.
    fetched = requests.get(
        _url(base, f"/api/get-document.php?id={doc_id}"),
        headers=headers,
        timeout=5,
    )
    assert fetched.status_code == 200
    fdoc = fetched.json()["document"]
    assert int(fdoc["id"]) == doc_id
    assert fdoc["comments"] == []

    # Update title + body.
    upd = requests.post(
        _url(base, "/api/update-document.php"),
        headers=headers,
        json={"id": doc_id, "title": f"Onboarding spec {tag} (rev 2)", "body": "## Revised\n\nbody"},
        timeout=5,
    )
    assert upd.status_code == 200, upd.text
    assert upd.json()["document"]["title"].endswith("(rev 2)")

    # Empty title rejected.
    bad = requests.post(
        _url(base, "/api/update-document.php"),
        headers=headers,
        json={"id": doc_id, "title": "  "},
        timeout=5,
    )
    assert bad.status_code == 400

    # Add comments.
    for body in ["First reply", "Second reply with **markdown**"]:
        c = requests.post(
            _url(base, "/api/create-document-comment.php"),
            headers=headers,
            json={"document_id": doc_id, "comment": body},
            timeout=5,
        )
        assert c.status_code == 201, c.text
        assert c.json()["comment"]["comment"] == body

    # Empty comment rejected.
    bad_comment = requests.post(
        _url(base, "/api/create-document-comment.php"),
        headers=headers,
        json={"document_id": doc_id, "comment": "   "},
        timeout=5,
    )
    assert bad_comment.status_code == 400

    # List comments.
    listc = requests.get(
        _url(base, f"/api/list-document-comments.php?document_id={doc_id}"),
        headers=headers,
        timeout=5,
    )
    assert listc.status_code == 200
    payload = listc.json()
    assert payload["count"] == 2
    assert [c["comment"] for c in payload["comments"]] == ["First reply", "Second reply with **markdown**"]

    # comment_count surfaces on the doc.
    after = requests.get(
        _url(base, f"/api/get-document.php?id={doc_id}"),
        headers=headers,
        timeout=5,
    )
    assert int(after.json()["document"]["comment_count"]) == 2

    # List filters by project_id.
    listed = requests.get(
        _url(base, f"/api/list-documents.php?project_id={project_id}"),
        headers=headers,
        timeout=5,
    )
    assert listed.status_code == 200
    ids = [int(d["id"]) for d in listed.json()["documents"]]
    assert doc_id in ids

    # Other-project filter excludes our doc.
    other_pid = _create_project(base, api_key, f"OtherDocsProj-{tag}")
    other_listed = requests.get(
        _url(base, f"/api/list-documents.php?project_id={other_pid}"),
        headers=headers,
        timeout=5,
    )
    assert other_listed.status_code == 200
    other_ids = [int(d["id"]) for d in other_listed.json()["documents"]]
    assert doc_id not in other_ids

    # Delete cascades the comments.
    deleted = requests.post(
        _url(base, "/api/delete-document.php"),
        headers=headers,
        json={"id": doc_id},
        timeout=5,
    )
    assert deleted.status_code == 200
    after_delete = requests.get(
        _url(base, f"/api/get-document.php?id={doc_id}"),
        headers=headers,
        timeout=5,
    )
    assert after_delete.status_code == 404


def test_document_endpoints_reject_unknown_ids(php_server):
    base = php_server.base_url
    headers = _h(php_server.api_key)
    bogus = 9_999_999

    miss = requests.get(_url(base, f"/api/get-document.php?id={bogus}"), headers=headers, timeout=5)
    assert miss.status_code == 404

    upd = requests.post(
        _url(base, "/api/update-document.php"),
        headers=headers,
        json={"id": bogus, "title": "x"},
        timeout=5,
    )
    assert upd.status_code == 404

    cmt = requests.post(
        _url(base, "/api/create-document-comment.php"),
        headers=headers,
        json={"document_id": bogus, "comment": "ping"},
        timeout=5,
    )
    assert cmt.status_code == 404


def test_admin_docs_pages_render(php_server):
    """Sanity check the admin Docs HTML pages render under a session."""
    base = php_server.base_url

    landing = requests.get(_url(base, "/admin/docs.php"), timeout=5, allow_redirects=False)
    # Unauthenticated -> redirect to login.
    assert landing.status_code in (301, 302)
    assert "login.php" in landing.headers.get("Location", "")
