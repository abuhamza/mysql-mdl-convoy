<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

/**
 * Polls performance_schema.metadata_locks once per second and prints a
 * color-coded view of the MDL queue on `sensor_data`. Designed for live demos
 * of metadata locks and the MDL convoy.
 *
 * Usage:
 *   php monitor.php [interval_seconds=1] [table=sensor_data] [--no-color]
 */

$args = $argv;
array_shift($args);
$useColor = stream_isatty(STDOUT);
foreach ($args as $i => $a) {
    if ($a === '--no-color') { $useColor = false; unset($args[$i]); }
    if ($a === '--color')    { $useColor = true;  unset($args[$i]); }
}
$args = array_values($args);

$interval = isset($args[0]) ? max(1, (int)$args[0]) : 1;
$table    = $args[1] ?? 'sensor_data';

$pdo = db_connect();

logmsg('MONITOR', "polling every {$interval}s for table `{$table}` (Ctrl-C to stop)");
logmsg('MONITOR', 'columns: STATUS = GRANTED/PENDING, LOCK_TYPE = the MDL flavor requested');

$locksSql = <<<SQL
SELECT  mdl.OBJECT_SCHEMA   AS db,
        mdl.OBJECT_NAME     AS tbl,
        mdl.LOCK_TYPE       AS lock_type,
        mdl.LOCK_DURATION   AS duration,
        mdl.LOCK_STATUS     AS status,
        mdl.OWNER_THREAD_ID AS thr,
        t.PROCESSLIST_ID    AS conn_id,
        t.PROCESSLIST_USER  AS user,
        t.PROCESSLIST_STATE AS state,
        SUBSTRING(t.PROCESSLIST_INFO, 1, 80) AS sql_preview
  FROM  performance_schema.metadata_locks mdl
  JOIN  performance_schema.threads t
        ON t.THREAD_ID = mdl.OWNER_THREAD_ID
 WHERE  mdl.OBJECT_TYPE = 'TABLE'
   AND  mdl.OBJECT_SCHEMA = DATABASE()
   AND  mdl.OBJECT_NAME = :tbl
 ORDER BY (mdl.LOCK_STATUS = 'GRANTED') DESC, mdl.OWNER_THREAD_ID
SQL;

$locks = $pdo->prepare($locksSql);

print_legend($useColor);

$tick = 0;
while (true) {
    $tick++;
    $locks->execute([':tbl' => $table]);
    $lockRows = $locks->fetchAll();
    $waitRows = derive_wait_edges($lockRows);

    print_block($tick, $lockRows, $waitRows, $useColor);
    sleep($interval);
}

/**
 * Derive a single best blocker per PENDING request from the MDL snapshot.
 *
 * Workaround: MySQL does not expose the true MDL wait-for graph. Use:
 *   1. OWNER_THREAD_ID as a proxy for arrival order (monotonic-ish).
 *   2. Documented MDL compatibility matrix.
 *   3. Barrier rule: a PENDING request that cannot be granted blocks every
 *      later arrival, even ones compatible with the current holder.
 *
 * For each PENDING waiter, pick the earliest (lowest THREAD_ID) row that:
 *   - is GRANTED and incompatible with waiter's lock_type, OR
 *   - is PENDING, arrived before waiter, and is itself incompatible with
 *     waiter's lock_type (the barrier — typically a pending EXCLUSIVE).
 */
function derive_wait_edges(array $rows): array {
    $sorted = $rows;
    usort($sorted, fn($a, $b) => (int)$a['thr'] <=> (int)$b['thr']);

    $edges = [];
    foreach ($sorted as $w) {
        if (($w['status'] ?? '') !== 'PENDING') continue;
        $waiterThr = (int)$w['thr'];
        $waiterLock = (string)$w['lock_type'];

        $best = null;
        foreach ($sorted as $b) {
            $bThr = (int)$b['thr'];
            if ($bThr === $waiterThr) continue;
            $bStatus = (string)$b['status'];
            $bLock   = (string)$b['lock_type'];
            if ($bStatus === 'GRANTED') {
                if (!mdl_compatible($bLock, $waiterLock)) {
                    $best = $b; break; // direct holder conflict — definitive
                }
            } elseif ($bStatus === 'PENDING' && $bThr < $waiterThr) {
                if (!mdl_compatible($bLock, $waiterLock)) {
                    if ($best === null) $best = $b; // barrier — keep looking for direct holder
                }
            }
        }
        if ($best !== null) {
            $edges[] = [
                'waiter_conn'  => $w['conn_id'],
                'waiter_lock'  => $waiterLock,
                'waiter_sql'   => $w['sql_preview'],
                'blocker_conn' => $best['conn_id'],
                'blocker_lock' => $best['lock_type'],
                'blocker_kind' => $best['status'] === 'GRANTED' ? 'holder' : 'barrier',
            ];
        }
    }
    return $edges;
}

/**
 * MDL compatibility (symmetric). True if both lock types can coexist.
 * Reference: sql/mdl.cc — m_granted_incompatible.
 */
function mdl_compatible(string $a, string $b): bool {
    static $incompat = [
        'EXCLUSIVE'            => ['*'],
        'SHARED_NO_READ_WRITE' => ['SHARED', 'SHARED_READ', 'SHARED_WRITE', 'SHARED_UPGRADABLE',
                                   'SHARED_NO_WRITE', 'SHARED_NO_READ_WRITE', 'INTENTION_EXCLUSIVE'],
        'SHARED_NO_WRITE'      => ['SHARED_WRITE', 'SHARED_NO_WRITE', 'SHARED_NO_READ_WRITE', 'INTENTION_EXCLUSIVE'],
        'SHARED_UPGRADABLE'    => ['SHARED_UPGRADABLE'],
    ];
    foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
        $row = $incompat[$x] ?? null;
        if ($row === null) continue;
        if ($row === ['*'] || in_array($y, $row, true)) return false;
    }
    return true;
}

// ---------- color helpers ----------

function ansi(string $code, string $s, bool $on): string {
    return $on ? "\033[{$code}m{$s}\033[0m" : $s;
}

/**
 * Map MDL lock_type -> ANSI color code.
 *  - SHARED_READ family   : cyan        (concurrent readers, compatible)
 *  - SHARED_WRITE family  : green       (DML writers, compatible w/ each other)
 *  - SHARED_UPGRADABLE    : yellow      (DDL prep, will upgrade to EXCLUSIVE)
 *  - SHARED_NO_*          : magenta     (DDL intermediates)
 *  - EXCLUSIVE            : bold red    (DDL final phase — blocks everything)
 *  - INTENTION_EXCLUSIVE  : blue        (schema-level intent)
 */
function lock_color(string $lock): string {
    return match (true) {
        $lock === 'EXCLUSIVE'                         => '1;31', // bold red
        $lock === 'SHARED_UPGRADABLE'                 => '33',   // yellow
        str_starts_with($lock, 'SHARED_NO_')          => '35',   // magenta
        $lock === 'SHARED_READ' || $lock === 'SHARED' => '36',   // cyan
        $lock === 'SHARED_WRITE'                      => '32',   // green
        $lock === 'INTENTION_EXCLUSIVE'               => '34',   // blue
        default                                       => '37',   // white
    };
}

function color_lock(string $lock, bool $on): string {
    return ansi(lock_color($lock), str_pad($lock, 20), $on);
}

function color_status(string $status, bool $on): string {
    $code = $status === 'GRANTED' ? '1;32' : '1;31';
    return ansi($code, str_pad($status, 7), $on);
}

function color_marker(string $status, bool $on): string {
    return $status === 'GRANTED'
        ? ansi('1;32', ' [HOLDS]', $on)
        : ansi('1;31', ' [WAITS]', $on);
}

function print_legend(bool $on): void {
    echo "\n" . ansi('1', 'LEGEND — MDL lock types:', $on) . "\n";
    $types = [
        'SHARED_READ'         => 'SELECT readers (compatible)',
        'SHARED_WRITE'        => 'DML writers: INSERT/UPDATE/DELETE',
        'SHARED_UPGRADABLE'   => 'DDL prep — will upgrade to EXCLUSIVE',
        'SHARED_NO_WRITE'     => 'DDL intermediate (online ALTER)',
        'EXCLUSIVE'           => 'DDL final — blocks ALL access',
        'INTENTION_EXCLUSIVE' => 'schema-level intent',
    ];
    foreach ($types as $t => $desc) {
        printf("  %s  %s\n", color_lock($t, $on), $desc);
    }
    echo "  " . ansi('1;32', 'GRANTED', $on) . " = holding the lock   "
       . ansi('1;31', 'PENDING', $on) . " = waiting in queue (convoy!)\n";
}

// ---------- main render ----------

function print_block(int $tick, array $locks, array $waits, bool $on): void {
    $bar = str_repeat('=', 110);
    echo "\n" . ansi('1;36', $bar, $on) . "\n";
    printf("%s tick #%d -- %s MDL row(s), %s wait edge(s)\n",
        ansi('1', '[' . ts() . ']', $on),
        $tick,
        ansi('1', (string)count($locks), $on),
        ansi('1;31', (string)count($waits), $on));
    echo ansi('1;36', $bar, $on) . "\n";

    if (!$locks) {
        echo ansi('2', "(no metadata locks on this table)\n", $on);
    } else {
        printf("%-6s %-6s %-20s %-12s %-7s %-8s %-22s  %s\n",
            'CONN', 'THR', 'LOCK_TYPE', 'DURATION', 'STATUS', 'USER', 'STATE', 'SQL');
        echo str_repeat('-', 110) . "\n";
        foreach ($locks as $r) {
            $status   = (string)($r['status'] ?? '?');
            $lockType = (string)($r['lock_type'] ?? '?');
            printf("%-6s %-6s %s %-12s %s %-8s %-22s  %s%s\n",
                $r['conn_id'] ?? '?',
                $r['thr'] ?? '?',
                color_lock($lockType, $on),
                $r['duration'] ?? '?',
                color_status($status, $on),
                $r['user'] ?? '?',
                substr((string)($r['state'] ?? ''), 0, 22),
                $r['sql_preview'] ?? '',
                color_marker($status, $on)
            );
        }
    }

    if ($waits) {
        echo "\n" . ansi('1;31', 'WAIT EDGES (convoy graph):', $on) . "\n";
        foreach ($waits as $w) {
            $kind = $w['blocker_kind'] === 'barrier'
                ? ansi('1;33', 'queued behind BARRIER', $on)
                : ansi('1;31', 'blocked-by HOLDER    ', $on);
            printf("  conn %s [%s]  <--%s--  conn %s [%s]\n      waiter SQL: %s\n",
                ansi('1;31', (string)($w['waiter_conn']  ?? '?'), $on),
                color_lock((string)($w['waiter_lock']  ?? '?'), $on),
                $kind,
                ansi('1;32', (string)($w['blocker_conn'] ?? '?'), $on),
                color_lock((string)($w['blocker_lock'] ?? '?'), $on),
                ansi('2', substr((string)($w['waiter_sql'] ?? ''), 0, 80), $on));
        }
    }
}
