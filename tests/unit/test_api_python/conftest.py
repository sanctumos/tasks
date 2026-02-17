# Ensure repo root is on path when collecting unit tests under this directory
import sys
from pathlib import Path

_REPO_ROOT = Path(__file__).resolve().parents[3]  # .../tests/unit/test_api_python -> repo root
if str(_REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(_REPO_ROOT))
