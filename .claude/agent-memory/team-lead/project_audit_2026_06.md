---
name: project-audit-2026-06
description: Senior audit snapshot of Krayin CRM repo, June 2026 - key risks and debt
metadata:
  type: project
---

Audit snapshot taken 2026-06-18 on branch `kohlberg`.

**Why:** baseline for prioritizing remediation work. Excluded dashboard files (reviewed in parallel).

**How to apply:** consult before recommending architecture changes or estimating risk for new features.

Key findings:
- Laravel 10 (EOL window approaching); PHP `^8.1`.
- 0 FormRequest classes in `packages/Webkul/*/Http/Requests/` - all validation inline.
- Tests: only a handful under `tests/` vs 100+ controllers in `packages/Webkul/*/Http/Controllers`.
- No `.github/workflows` CI pipeline detected. Pint available locally but not enforced.
- Raw queries (`DB::raw`, `whereRaw`, `selectRaw`) used across modules - audit for input concatenation.
- Bouncer.php modified locally (uncommitted) - ACL surface in flux.
- Docker compose only provides MySQL+phpMyAdmin (dev). Production Dockerfile added recently (commits 7d0ec77, 15c96e8).
- TODOs/FIXMEs present in module code; many controllers exceed 200 LOC with embedded business logic violating repository pattern stated in CLAUDE.md.
