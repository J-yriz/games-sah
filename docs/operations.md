# Operations Guide — Virtual Chip Table

Non-monetary virtual chips. No real money anywhere in this app.

## 1. Required environment (operator-owned, never commit secrets)

| Variable | Purpose | Production value |
|---|---|---|
| `APP_ENV` | Must be `production` | `production` |
| `APP_DEBUG` | Must be `false` | `false` |
| `APP_KEY` | App encryption key (generate once, back up) | `base64:…` (secret) |
| `APP_URL` | Canonical HTTPS URL | `https://…` |
| `DB_CONNECTION` | `pgsql` in production | `pgsql` |
| `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` | PostgreSQL, reachable only from app network | secrets |
| `QUEUE_CONNECTION` | `database` (default) | `database` |
| `CACHE_STORE` | `database` or `redis` — required shared for multi-node schedule locks | `database` |
| `SESSION_DRIVER` / `SESSION_SECURE_COOKIE` | DB sessions; secure cookie `true` behind HTTPS | `database` / `true` |
| `BROADCAST_CONNECTION` | `reverb` in production | `reverb` |
| `REVERB_APP_ID/KEY/SECRET` | Reverb auth (server + Vite key) | secrets |
| `REVERB_HOST/PORT/SCHEME` | Usually `127.0.0.1:8080/http` behind TLS-terminating proxy with WSS | internal |
| `TRUSTED_PROXIES` | Set when behind a reverse proxy (`*` only inside private net) | per network |
| `CHIPS_INITIAL_GRANT/MIN/MAX_TRANSFER` | Game economy tuning | defaults in `.env.example` |

All of the above (without secret values) are documented in `.env.example`.

## 2. Processes (all required in production)

| Process | Command | Notes |
|---|---|---|
| Web (PHP) | octane/fpm/serve behind reverse proxy | force HTTPS; security headers at proxy |
| Queue worker | `php artisan queue:work --tries=3 --backoff=30,120 --timeout=120 --sleep=3` | processes broadcast events + any queued work |
| Scheduler | cron `* * * * * php artisan schedule:run` every minute (preferred) **or** `php artisan schedule:work` as a managed service | drives `poker:process-timeouts` |
| Reverb | `php artisan reverb:start --host=127.0.0.1 --port=8080` as a managed service, exposed only via WSS reverse proxy | restarts are safe: browsers Echo-reconnect and re-read state from DB |

Deploy sequence: `composer install --no-dev` → `php artisan migrate --force` (never reset)
→ `php artisan config:cache route:cache view:cache` → `php artisan queue:restart`
→ reload web + Reverb. Sample systemd units are an operator task; any process
manager (systemd/Supervisor/docker) works as long as the four rows above run.

## 3. Scheduler coordination

`poker:process-timeouts` runs every minute with `withoutOverlapping(10)` and
`onOneServer()`. Cross-node coordination is real **only** with a shared cache
(`database`, `redis`, `memcached`) — with `array`/`file` each node runs its own
sweep. That is still safe: per-hand `lockForUpdate` + deadline re-validation
means only one timeout consumes a turn; overlapping runs only waste a query.
A scheduler outage is self-healing: overdue deadlines are processed on the
next pass (covered by test).

## 4. Queue and broadcasts

- `PokerHandStateUpdated` / `RoomStateUpdated` implement `ShouldBroadcast`
  (queued) **and** `ShouldDispatchAfterCommit`: a broadcast failure fails the
  job (visible in `failed_jobs`, retry with `php artisan queue:retry`), it
  never rolls back the committed game state.
- Inspect failures: `php artisan queue:failed`. Retry: `queue:retry all`.
- Broadcast authorization is server-side (`routes/channels.php`): room members
  only for room channels; hand channels require room membership. No private
  state is derivable from IDs alone.

## 5. Reconnect behavior

Echo auto-reconnects; on `room.updated` / `poker.hand.updated` the Livewire
pages call `refreshState()`, which re-reads authoritative rows (plus a 15 s
poller as backstop). Browsers never compute balances or deadlines.

## 6. Monitoring minimum

- `GET /health` (unauthenticated, no sensitive output): 200 = web+DB up.
- Logs: timeout sweeps log a per-run summary; per-hand failures log
  `hand_id` + exception class only. Idempotency conflicts throw domain
  exceptions (no payload logging). Never log tokens, passwords, or emails.
- Watch: `failed_jobs` count, scheduler last-run time, Reverb process,
  `poker_actions` timeout rate vs manual rate, ledger conservation queries
  in `docs/backup-recovery.md`.
