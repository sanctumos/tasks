# Major workflows (E2E / Playwright)

Target: **≥ 90%** of these workflows have an automated browser path under `tools/design-smoke/`.

| ID | Workflow | Script | Required |
| -- | -------- | ------ | -------- |
| W01 | Admin login + home shell | `admin_shell.py` | yes |
| W02 | Admin walkthrough (nav surfaces) | `admin_walkthrough.py` | yes |
| W03 | Task detail view | `task_view_verify.py` | yes |
| W04 | Lists view | `lists_view_screenshots.py` | yes |
| W05 | Schedule | `schedule_verify.py` | yes |
| W06 | Project doors | `doors_verify.py` | yes |
| W07 | Documents / markdown | `docs_verify.py` | yes |
| W08 | Mobile nav toggler | `mobile_nav_toggler_verify.py` | yes |
| W09 | Ask Q composer | `ask_q_verify.py` | yes |
| W10 | Ask Q multiturn | `ask_q_multiturn_verify.py` | yes |
| W11 | Ask Q paste | `ask_q_composer_paste_verify.py` | yes |
| W12 | Ask Q reload persist | `ask_q_reload_persist_verify.py` | yes |
| W13 | Archived board ZIP (Archive downloads) | `board_export_archives_verify.py` | yes |
| W14 | Dev skin lab (dev.tasks only) | `dev_skin_lab_verify.py` | optional |
| W15 | Mermaid doc render | `doc368_mermaid_verify.py` | optional |
| W16 | Ask Q prod smoke | `ask_q_prod_verify.py` | optional |
| W17 | Navbar omnibox (Ctrl+K / live dropdown) | `omnibox_verify.py` | yes |
| W18 | Docs hub + project Docs tab search | `docs_search_verify.py` | yes |
| W19 | Home live filter (debounced fragment) | `home_live_filter_verify.py` | yes |
| W20 | Project Tasks board search + chips | `project_board_search_verify.py` | yes |
| W21 | Task detail find-in-thread + attachment filter | `find_in_thread_verify.py` | yes |
| W22 | Activity feed filter + type chips | `activity_filter_verify.py` | yes |
| W23 | Notifications + Users admin filters | `notifs_users_filter_verify.py` | yes |
| W24 | Search ACL (member People / mentions / full search) | `search_acl_verify.py` | yes |

**Required set** = rows marked `yes`. Pass rate = scripts present and runnable against the target host.

PHPUnit `tests/php/E2E/MajorWorkflowsChecklistTest.php` asserts the required set is ≥ 90% present on disk.
