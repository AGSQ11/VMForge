# Pack 21 — Audit & Codemods

This pack does two things:
1) **Scan** your repo for risky patterns (shell/SQL/XSS).
2) **Codemod** trivial `Shell::run("cmd $var")` into `Shell::runArgs([...])` safely.

## How to run

From the repo root:

```bash
php scripts/audit/scan.php --strict      # print findings and exit non‑zero if any
php scripts/audit/scan.php --json > audit.json

php scripts/audit/apply_fixes.php        # rewrite simple Shell::run cases; review *.bak and diffs
```

## CI gate (optional)

This pack includes `.github/workflows/audit.yml` that runs the scanner. Enable by committing it.

## Notes

- The codemod intentionally **does not** attempt complex string parsing. It only rewrites obvious cases (`virsh`, `ip`, `nft`, `zfs`). Everything else is reported so you can fix it manually by switching to `Shell::runArgs()` or `Shell::runf()`.
- After codemod, run your existing CI (php -l) and then the scanner again. Commit the remaining manual fixes.
