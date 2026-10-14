# Backup & Disaster Recovery — PostgreSQL

Status: procedures below are documented for the operator. No backup
infrastructure is provisioned by this repository — do not assume automatic
backups exist until the operator configures them.

## 1. Backup strategy (operator to configure)

- Nightly logical backup: `pg_dump -Fc gamesah > gamesah-$(date +%F).dump`
  via cron on the DB host, copied off-host. Retain 7 daily + 4 weekly copies.
- If using managed PostgreSQL, enable point-in-time recovery (WAL archiving)
  per the provider's docs and record the RPO here.
- Always back up before `migrate --force` on production.

## 2. Restore to an isolated environment (never production)

1. Create an empty database and role with no production grants.
2. `pg_restore -d <isolated_db> <dump>` (or `psql < <sql dump>`).
3. Point a staging app copy at the isolated DB (`DB_DATABASE`, credentials).
4. Run integrity verification (section 3) before any other use.

## 3. Post-restore integrity verification

```sql
-- No negative money anywhere.
SELECT count(*) FROM wallets WHERE balance < 0;
SELECT count(*) FROM table_stacks WHERE balance < 0;
SELECT count(*) FROM chip_transactions WHERE amount <= 0;

-- Ledger completeness per poker hand: in-escrow equals paid-out.
SELECT h.id,
       COALESCE(i.in_amt,0) - COALESCE(o.out_amt,0) AS open_escrow
FROM poker_hands h
LEFT JOIN (SELECT to_poker_hand_id AS hid, sum(amount) AS in_amt
           FROM chip_transactions GROUP BY 1) i ON i.hid = h.id
LEFT JOIN (SELECT from_poker_hand_id AS hid, sum(amount) AS out_amt
           FROM chip_transactions GROUP BY 1) o ON o.hid = h.id
WHERE h.status = 'settled' AND COALESCE(i.in_amt,0) <> COALESCE(o.out_amt,0);
-- Must return zero rows for settled hands. Open hands hold the difference
-- as live escrow (expected: equals their total_pot).

-- Orphan references must not exist.
SELECT count(*) FROM chip_transactions t
LEFT JOIN wallets w ON w.id IN (t.from_wallet_id, t.to_wallet_id)
WHERE (t.from_wallet_id IS NOT NULL OR t.to_wallet_id IS NOT NULL)
  AND w.id IS NULL;
```

Then: `php artisan test` (staging), `queue:failed` empty-or-triaged,
`/health` 200.

## 4. Failure handling

| Failure | Response |
|---|---|
| DB unreachable | App 503s on `/health`; queued jobs retry with backoff; scheduler passes do nothing (no due rows readable). Restore connectivity, then verify section 3. |
| Queue down/backlog | Gameplay still commits (broadcasts queue separately and fail independently). Clear backlog, `queue:retry all`, watch `failed_jobs`. |
| Reverb restart | Clients reconnect automatically and re-read DB state; no action needed. Missed events are covered by the 15 s poller. |
| Scheduler down | Overdue poker deadlines are caught up on the next pass (no deadlines are lost; they are timestamps, not in-memory timers). |
| Suspected ledger anomaly | Freeze writes (maintenance mode), run section 3, reconcile per-hand against `poker_actions`; never edit `chip_transactions` by hand — fix forward with compensating logic. |
