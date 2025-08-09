# Pack 24 — Token hashing & rotation, CSRF middleware, API rate limiting

**What’s included** (drop-in files):

- `src/Services/AgentToken.php` — Argon2id hashing, verify, rotate, legacy migration helper.
- `src/Core/Middleware/CsrfMiddleware.php` — consistent CSRF enforcement for non-exempt POSTs.
- `src/Core/RateLimiter.php` — Redis-backed throttle with SQL fallback.
- `src/Controllers/AgentController.php` — hardened agent endpoints using hashed tokens.
- `migrations/0019_token_hashing.sql` — adds `token_hash`, `token_old_hash`, `token_rotated_at` to `nodes` (+ index on legacy `token`).
- `migrations/0020_ratelimiter.sql` — creates `rate_limits` table for DB fallback.

## Apply

```bash
unzip -o vmforge_feature_pack_24_security_tokens_rate_limit.zip -d .
git checkout -b fix/pack24-security
git add src/Services/AgentToken.php src/Core/Middleware/CsrfMiddleware.php src/Core/RateLimiter.php src/Controllers/AgentController.php migrations/0019_token_hashing.sql migrations/0020_ratelimiter.sql
git commit -m "pack24: token hashing+rotation, CSRF middleware, API rate limiting, hardened AgentController"
git push -u origin fix/pack24-security

# migrate
mysql -u root -p vmforge < migrations/0019_token_hashing.sql
mysql -u root -p vmforge < migrations/0020_ratelimiter.sql
```

No route changes are required. `public/index.php` already references `CsrfMiddleware::validate()` and `RateLimiter::throttle(...)` for `/api/*`. If not, wire them as shown in Pack 20.

## Behavior

- Agents can continue using their existing plaintext token. On first successful auth, the server stores an Argon2id hash and nulls the legacy plaintext column for that node. Future checks use hashes only.
- `AgentToken::rotate($nodeId)` rotates the secret and keeps `token_old_hash` valid for 1 hour (configurable via `expireOldIfNeeded()`), so connected agents can survive a rolling restart.
- Rate limiter: if `REDIS_HOST` is set, uses Redis. Otherwise, it falls back to a single-row-per-key SQL counter.
