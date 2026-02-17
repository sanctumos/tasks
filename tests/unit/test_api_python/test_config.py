"""Unit tests for api_python.config."""
import pytest


def test_get_bootstrap_admin_username_default(monkeypatch):
    monkeypatch.delenv("TASKS_BOOTSTRAP_ADMIN_USERNAME", raising=False)
    from api_python import config
    assert config.get_bootstrap_admin_username() == "admin"


def test_get_bootstrap_admin_username_from_env(monkeypatch):
    monkeypatch.setenv("TASKS_BOOTSTRAP_ADMIN_USERNAME", "superadmin")
    from api_python import config
    assert config.get_bootstrap_admin_username() == "superadmin"


def test_get_bootstrap_admin_password_from_env(monkeypatch):
    monkeypatch.setenv("TASKS_BOOTSTRAP_ADMIN_PASSWORD", "Secret123!")
    from api_python import config
    assert config.get_bootstrap_admin_password() == "Secret123!"


def test_get_bootstrap_admin_password_from_file(monkeypatch, tmp_path):
    db_dir = tmp_path / "db"
    db_dir.mkdir()
    secret_file = db_dir / "bootstrap_admin_password.txt"
    secret_file.write_text("file-secret-xyz")
    monkeypatch.setenv("TASKS_DB_PATH", str(db_dir / "tasks.db"))
    monkeypatch.delenv("TASKS_BOOTSTRAP_ADMIN_PASSWORD", raising=False)
    from api_python import config
    monkeypatch.setattr(config, "DB_PATH", str(db_dir / "tasks.db"))
    assert config.get_bootstrap_admin_password() == "file-secret-xyz"


def test_get_bootstrap_api_key_from_env(monkeypatch):
    monkeypatch.setenv("TASKS_BOOTSTRAP_API_KEY", "key123")
    from api_python import config
    assert config.get_bootstrap_api_key() == "key123"


def test_get_bootstrap_api_key_from_file(monkeypatch, tmp_path):
    db_dir = tmp_path / "db"
    db_dir.mkdir()
    (db_dir / "api_key.txt").write_text("file-api-key")
    monkeypatch.setenv("TASKS_DB_PATH", str(db_dir / "tasks.db"))
    monkeypatch.delenv("TASKS_BOOTSTRAP_API_KEY", raising=False)
    from api_python import config
    monkeypatch.setattr(config, "DB_PATH", str(db_dir / "tasks.db"))
    assert config.get_bootstrap_api_key() == "file-api-key"


def test_get_app_base_url_empty_returns_none(monkeypatch):
    monkeypatch.delenv("TASKS_APP_BASE_URL", raising=False)
    from api_python import config
    assert config.get_app_base_url() is None or config.get_app_base_url() == ""


def test_get_app_base_url_from_env(monkeypatch):
    monkeypatch.setenv("TASKS_APP_BASE_URL", "https://tasks.example.com")
    from api_python import config
    assert config.get_app_base_url() == "https://tasks.example.com"


def test_get_bootstrap_admin_password_creates_file_when_missing(monkeypatch, tmp_path):
    db_dir = tmp_path / "db"
    db_dir.mkdir()
    monkeypatch.setenv("TASKS_DB_PATH", str(db_dir / "tasks.db"))
    monkeypatch.delenv("TASKS_BOOTSTRAP_ADMIN_PASSWORD", raising=False)
    from api_python import config
    monkeypatch.setattr(config, "DB_PATH", str(db_dir / "tasks.db"))
    pw = config.get_bootstrap_admin_password()
    assert len(pw) >= 32
    assert (db_dir / "bootstrap_admin_password.txt").read_text() == pw


def test_get_bootstrap_api_key_creates_file_when_missing(monkeypatch, tmp_path):
    db_dir = tmp_path / "db"
    db_dir.mkdir()
    monkeypatch.setenv("TASKS_DB_PATH", str(db_dir / "tasks.db"))
    monkeypatch.delenv("TASKS_BOOTSTRAP_API_KEY", raising=False)
    from api_python import config
    monkeypatch.setattr(config, "DB_PATH", str(db_dir / "tasks.db"))
    key = config.get_bootstrap_api_key()
    assert len(key) >= 32
    assert (db_dir / "api_key.txt").read_text() == key
