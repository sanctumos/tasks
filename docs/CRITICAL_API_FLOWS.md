# Critical API flows (Integration)

Target: **≥ 90%** of these flows have an automated PHPUnit Integration (HTTP) test.

| ID | Flow | Covered by |
| -- | ---- | ---------- |
| A01 | Health unauthorized + authorized | `ApiHealthAndTaskFlowTest` |
| A02 | Create directory project + list todo lists | `ApiHealthAndTaskFlowTest` |
| A03 | Create + list tasks | `ApiHealthAndTaskFlowTest` |
| A04 | Shared document asset HTTP | `SharedDocumentAssetHttpTest` |
| A05 | Q-bridge security HTTP | `QBridgeSecurityHttpTest` |
| A06 | Board export request (deny active) | `BoardExportHttpTest` |
| A07 | Board export request after archive | `BoardExportHttpTest` |
| A08 | Board export list jobs | `BoardExportHttpTest` |
| A09 | Board export download ZIP | `BoardExportHttpTest` |
| A10 | Board export unchanged reuse | `BoardExportHttpTest` |

PHPUnit `tests/php/Integration/CriticalApiFlowsChecklistTest.php` asserts ≥ 90% of this table is wired.
