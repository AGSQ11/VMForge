# VMForge Security Hardening (Pack 20)

This pack focuses on **injection** risks and session/header hardening without breaking routes.

## What changed
- `src/Core/Shell.php`: added `runArgs()` and `runf()` to execute commands with proper escaping; `run()` now refuses obvious metacharacters in raw strings.
- `src/Core/Headers.php`: sends sane security headers and enables strict session config + session fingerprinting.
- `public/index.php`: wires the headers/session init **before** routing.
- `src/Controllers/APIController.php`: validates JSON payload, uses prepared statements everywhere.
- `src/Controllers/ISOController.php`: sanitizes filenames, whitelists extensions, stores ISO **outside** `public/`.
- `agent/FW_SYNC.txt`: validates CIDR/ports more strictly before emitting nft rules.
- `migrations/0018_indexes.sql`: indexes to speed up common queries.

## Action items
1. Replace agent FW function with the **hardened** version from `agent/FW_SYNC.txt` and restart the agent.
2. Migrate DB indexes:
   ```sql
   mysql -u root -p vmforge < migrations/0018_indexes.sql
   ```
3. Review any custom shell calls in your local edits and convert to `Shell::runArgs([ 'virsh', 'cmd', $arg ])`.

## Notes on Claude’s enterprise migrations
Do **not** apply the whole `enterprise-migrations.sql` blindly. It duplicates tables like `migrations`, `alerts`, and adds large partitioned tables you may not need yet. We’ll cherry-pick what’s relevant after first successful installs.