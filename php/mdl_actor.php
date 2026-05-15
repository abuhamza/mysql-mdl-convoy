<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

/**
 * Orchestration script for the three actors in the MDL contention demo.
 *
 * Usage:
 *   php mdl_actor.php holder   [hold_seconds=60]   Actor A: open txn, INSERT into p1, sleep.
 *   php mdl_actor.php blocker  [partition=p2]      Actor B: ALTER TABLE ... TRUNCATE PARTITION.
 *   php mdl_actor.php victim                       Actor C: plain INSERT (will hang).
 */

$mode = $argv[1] ?? '';
$arg  = $argv[2] ?? null;

switch ($mode) {
    case 'holder':
        run_holder((int)($arg ?? 60));
        break;
    case 'blocker':
        run_blocker((string)($arg ?? 'p2'));
        break;
    case 'victim':
        run_victim();
        break;
    default:
        fwrite(STDERR, "Usage: php mdl_actor.php {holder|blocker|victim} [arg]\n");
        exit(2);
}

function run_holder(int $holdSeconds): void {
    $pdo = db_connect();
    logmsg('ACTOR-A', "connected (connection_id=" . conn_id($pdo) . ")");

    $pdo->beginTransaction();
    logmsg('ACTOR-A', 'BEGIN');

    // INSERT into partition p1 (anything before 2026-04-01).
    $stmt = $pdo->prepare(
        "INSERT INTO sensor_data (sensor_id, ts, payload) VALUES (?, ?, ?)"
    );
    $stmt->execute([42, '2026-03-20 09:00:00', 'A-holds-p1']);
    logmsg('ACTOR-A', 'INSERT into p1 done -> now holding SHARED_WRITE MDL on sensor_data');

    logmsg('ACTOR-A', "sleeping {$holdSeconds}s WITHOUT committing (door is wedged open)...");
    sleep($holdSeconds);

    $pdo->commit();
    logmsg('ACTOR-A', 'COMMIT -- MDL released');
}

function run_blocker(string $partition): void {
    $pdo = db_connect();
    logmsg('ACTOR-B', "connected (connection_id=" . conn_id($pdo) . ")");

    $sql = "ALTER TABLE sensor_data TRUNCATE PARTITION {$partition}";
    logmsg('ACTOR-B', "issuing: {$sql}");
    logmsg('ACTOR-B', "this requests an EXCLUSIVE MDL and will WAIT behind Actor A's open transaction");

    $t0 = microtime(true);
    try {
        $pdo->exec($sql);
        $elapsed = round(microtime(true) - $t0, 2);
        logmsg('ACTOR-B', "TRUNCATE PARTITION {$partition} completed in {$elapsed}s");
    } catch (PDOException $e) {
        $elapsed = round(microtime(true) - $t0, 2);
        logmsg('ACTOR-B', "FAILED after {$elapsed}s: " . $e->getMessage());
        exit(1);
    }
}

function run_victim(): void {
    $pdo = db_connect();
    logmsg('ACTOR-C', "connected (connection_id=" . conn_id($pdo) . ")");

    logmsg('ACTOR-C', 'issuing plain INSERT into p3 (a partition nobody else is touching)');
    logmsg('ACTOR-C', "this SHOULD be instant, but will hang behind Actor B's pending EXCLUSIVE MDL");

    $t0 = microtime(true);
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO sensor_data (sensor_id, ts, payload) VALUES (?, ?, ?)"
        );
        $stmt->execute([99, '2026-05-20 09:00:00', 'C-victim-p3']);
        $elapsed = round(microtime(true) - $t0, 2);
        logmsg('ACTOR-C', "INSERT finally completed after {$elapsed}s (was queued behind the convoy)");
    } catch (PDOException $e) {
        $elapsed = round(microtime(true) - $t0, 2);
        logmsg('ACTOR-C', "FAILED after {$elapsed}s: " . $e->getMessage());
        exit(1);
    }
}

function conn_id(PDO $pdo): string {
    $row = $pdo->query('SELECT CONNECTION_ID() AS id')->fetch();
    return (string)$row['id'];
}
