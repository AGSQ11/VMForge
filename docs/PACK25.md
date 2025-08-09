# Pack 25 — User auth hardening & migration

Includes:
- `src/Core/Password.php` — Argon2id helpers.
- `src/Core/Auth.php` — login auto‑migrates legacy hashes to Argon2id, lockout, session rotation/fingerprint, logout.
- `src/Controllers/AuthController.php` — safe login/logout handlers with CSRF.
- `migrations/0021_user_password_hardening.sql` — columns for modern hashes + lockout bookkeeping; seeds legacy column.
- `scripts/users/create_admin.php` — CLI to seed an admin.

Apply:
```bash
unzip -o vmforge_feature_pack_25_auth_hardening.zip -d .
git checkout -b fix/pack25-auth
git add src/Core/Password.php src/Core/Auth.php src/Controllers/AuthController.php migrations/0021_user_password_hardening.sql scripts/users/create_admin.php
git commit -m "pack25: Argon2id user auth, auto-migrate legacy, lockout, session hygiene, admin CLI"
git push -u origin fix/pack25-auth

# migrate
mysql -u root -p vmforge < migrations/0021_user_password_hardening.sql

# seed admin, if needed
php scripts/users/create_admin.php admin@example.com 'Str0ngPass!'
```
Notes:
- On first successful login with an old password, the server writes `users.password_hash` (Argon2id) and clears legacy fields.
- Lockout defaults: 6 failures → 15 minutes. Tune with `AUTH_MAX_FAILURES` and `AUTH_LOCK_SECONDS` in `.env`.
- Sessions are ID-rotated on login and bound to a coarse fingerprint (UA + /16 or /48 of IP) to reduce hijack risk without breaking mobile users.
