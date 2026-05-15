<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

/**
 * Polls performance_schema.metadata_locks and sys.schema_table_lock_waits once per second
 * and prints a digestible view of the MDL queue on `sensor_data`.
 *
 * Usage:
 *   php monitor.php [interval_seconds=1] [table=sensor_data]
 */

$interval = isset($argv[1]) ? max(1, (int)$argv[1]) : 1;
$table    = $argv[2] ?? 'sensor_data';

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

$waitsSql = <<<SQL
SELECT  waiting_pid       AS waiter_conn,
        waiting_query     AS waiter_sql,
        blocking_pid      AS blocker_conn,
        sql_kill_blocking_query AS kill_hint
  FROM  sys.schema_table_lock_waits
 WHERE  object_schema = DATABASE()
   AND  object_name   = :tbl
SQL;

$locks = $pdo->prepare($locksSql);
$waits = $pdo->prepare($waitsSql);

$tick = 0;
while (true) {
    $tick++;
    $locks->execute([':tbl' => $table]);
    $lockRows = $locks->fetchAll();

    $waits->execute([':tbl' => $table]);
    $waitRows = $waits->fetchAll();

    print_block($tick, $lockRows, $waitRows);
    sleep($interval);
}

function print_block(int $tick, array $locks, array $waits): void {
    $bar = str_repeat('=', 96);
    echo "\n{$bar}\n";
    printf("[%s] tick #%d -- %d MDL row(s), %d wait edge(s)\n", ts(), $tick, count($locks), count($waits));
    echo $bar . "\n";

    if (!$locks) {
        echo "(no metadata locks on this table)\n";
    } else {
        printf("%-8s %-6s %-22s %-12s %-9s %-7s %-22s  %s\n",
            'CONN', 'THR', 'LOCK_TYPE', 'DURATION', 'STATUS', 'USER', 'STATE', 'SQL');
        echo str_repeat('-', 96) . "\n";
        foreach ($locks as $r) {
            $marker = $r['status'] === 'GRANTED' ? ' [HOLDS]' : ' [WAITS]';
            printf("%-8s %-6s %-22s %-12s %-9s %-7s %-22s  %s%s\n",
                $r['conn_id'] ?? '?',
                $r['thr'] ?? '?',
                $r['lock_type'] ?? '?',
                $r['duration'] ?? '?',
                $r['status'] ?? '?',
                $r['user'] ?? '?',
                substr((string)($r['state'] ?? ''), 0, 22),
                $r['sql_preview'] ?? '',
                $marker
            );
        }
    }

    if ($waits) {
        echo "\nWAIT EDGES (sys.schema_table_lock_waits):\n";
        foreach ($waits as $w) {
            printf("  conn %s  <--blocked-by--  conn %s   (waiter: %s)\n",
                $w['waiter_conn'] ?? '?',
                $w['blocker_conn'] ?? '?',
                substr((string)($w['waiter_sql'] ?? ''), 0, 70));
        }
    }
}
