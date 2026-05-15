# MySQL MDL Convoy — Reproduction Sandbox

A Dockerized environment that reproduces the classic MySQL **Metadata Lock (MDL) convoy**:
a long-running transaction holds a `SHARED_WRITE` MDL, a subsequent
`ALTER TABLE ... TRUNCATE PARTITION` queues up for `EXCLUSIVE`, and every
later DML — *including writes against completely different partitions* — gets
stuck behind that pending exclusive lock.

This is the "gold-standard" proof that MDLs are **table-level**, not
partition-level.

## What's in the box

| Path                 | Purpose                                                           |
|----------------------|-------------------------------------------------------------------|
| `docker-compose.yml` | `mysql:8.0` + `php:8.2-cli` on a shared bridge network            |
| `init/01-schema.sql` | Enables the `mdl` instrument, creates a 5-partition `sensor_data` |
| `php/mdl_actor.php`  | Three actor modes: `holder`, `blocker`, `victim`                  |
| `php/monitor.php`    | Polls `performance_schema.metadata_locks` every second            |
| `php/db.php`         | Shared PDO connection + log helpers                               |

`sensor_data` is partitioned by `RANGE (UNIX_TIMESTAMP(ts))` into
`p1 .. p4 + pmax`. Each partition is pre-seeded so `TRUNCATE PARTITION`
actually has work to do.

## One-time setup

```bash
docker compose up -d
```

That brings up MySQL (with `performance_schema=ON` and the MDL instrument
pre-armed via `command:` flags **and** the `init/01-schema.sql` script) and a
PHP CLI container with `pdo_mysql` installed and the `/app` volume mounted
read-only.

Wait for MySQL to be healthy:

```bash
docker compose ps
```

## Running the demo — four terminals

You'll want four terminals open. Every command below runs inside the
already-running `mdl-php` container via `docker compose exec`.

### Terminal 1 — Monitor (start first)

```bash
docker compose exec php php /app/monitor.php
```

At rest you'll see `(no metadata locks on this table)`.

### Terminal 2 — Actor A: the holder

```bash
docker compose exec php php /app/mdl_actor.php holder 60
```

`Actor A` opens a transaction, inserts one row into partition **p1**, and
sleeps for 60 seconds without committing.

The monitor will now show a row like:

```
CONN  THR   LOCK_TYPE            DURATION    STATUS    ...  [HOLDS]
12    73    SHARED_WRITE         TRANSACTION GRANTED
```

### Terminal 3 — Actor B: the blocker

```bash
docker compose exec php php /app/mdl_actor.php blocker p2
```

`Actor B` issues `ALTER TABLE sensor_data TRUNCATE PARTITION p2`. It needs an
**EXCLUSIVE** MDL on `sensor_data`, but Actor A is holding `SHARED_WRITE`, so
Actor B's request goes `PENDING`. The monitor:

```
CONN  THR   LOCK_TYPE            DURATION    STATUS    ...
12    73    SHARED_WRITE         TRANSACTION GRANTED   [HOLDS]
13    74    EXCLUSIVE            TRANSACTION PENDING   [WAITS]
```

### Terminal 4 — Actor C: the victim

```bash
docker compose exec php php /app/mdl_actor.php victim
```

`Actor C` is a plain `INSERT` into **p3** — a partition nobody else is
touching. On a partition-aware locking model this would be instant. In reality
it hangs. The monitor now shows the convoy:

```
CONN  THR   LOCK_TYPE            DURATION    STATUS    ...
12    73    SHARED_WRITE         TRANSACTION GRANTED   [HOLDS]
13    74    EXCLUSIVE            TRANSACTION PENDING   [WAITS]
14    75    SHARED_WRITE         TRANSACTION PENDING   [WAITS]

WAIT EDGES (derived from performance_schema.metadata_locks):
  conn 13 [EXCLUSIVE]    <--blocked-by--  conn 12 [SHARED_WRITE]   (waiter: ALTER TABLE sensor_data TRUNCATE PARTITION p2)
  conn 14 [SHARED_WRITE] <--blocked-by--  conn 13 [EXCLUSIVE]      (waiter: INSERT INTO sensor_data ...)
  conn 14 [SHARED_WRITE] <--blocked-by--  conn 12 [SHARED_WRITE]   (waiter: INSERT INTO sensor_data ...)
```

That third row is the punchline: **Actor C is blocked by Actor B, not by
Actor A**. The pending `EXCLUSIVE` request acts as a barrier in the lock
acquisition queue — every later writer queues behind it, even when their data
never touches the same partition.

### Watch the queue drain

After ~60s, Actor A commits. The monitor will show:

1. Actor B's `EXCLUSIVE` flips to `GRANTED` and the `TRUNCATE` completes.
2. Actor C's `SHARED_WRITE` flips to `GRANTED` and the `INSERT` completes.

In Terminals 3 and 4 the actor logs print their total wall-clock time — you
should see Actor B took ~60s and Actor C took ~60s as well, even though both
"should" have been instant.

## What the schema does (and why)

`init/01-schema.sql` does two non-obvious things:

1. Enables the `wait/lock/metadata/sql/mdl` instrument in
   `performance_schema.setup_instruments`. **Without this, the
   `metadata_locks` table is empty** even when locks are clearly being taken.
   The `command:` block in `docker-compose.yml` also passes
   `--performance-schema-instrument='wait/lock/metadata/sql/mdl=ON'` so the
   instrument is armed at server start, before any session connects.
2. Grants the `demo` user `SELECT` on `performance_schema` and `PROCESS`
   globally so `monitor.php` can see threads from other connections.

The monitor deliberately does **not** use `sys.schema_table_lock_waits` —
that view's definer/invoker checks fail with `ERROR 1356` for non-root users
in a stock MySQL 8.0 image. Instead the wait edges are derived directly from
`performance_schema.metadata_locks` by joining each `PENDING` row to every
`GRANTED` row on the same object.

## Tearing it down

```bash
docker compose down -v
```

The `-v` drops the MySQL data volume so the next `up -d` re-runs the init
script from scratch.

## Notes / gotchas

- `lock_wait_timeout` is set to 120s server-side and per-session, which gives
  you breathing room to watch the convoy form. Bump the `holder`'s sleep
  argument if you want a longer window: `php mdl_actor.php holder 180`.
- If Actor B times out before Actor A commits, you'll see
  `ERROR 1205 (HY000): Lock wait timeout exceeded`. The monitor prints the
  connection ids of the holder and the waiter, so you can `KILL <conn_id>`
  the holder by hand if you'd rather not wait for the timeout.
- The `php` container runs `tail -f /dev/null` after installing `pdo_mysql`;
  all PHP commands go through `docker compose exec php ...`.
