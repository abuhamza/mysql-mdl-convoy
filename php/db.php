<?php
declare(strict_types=1);

function db_connect(): PDO {
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'mdl_demo';
    $user = getenv('DB_USER') ?: 'demo';
    $pass = getenv('DB_PASS') ?: 'demopw';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Keep the lock-wait window long enough that the demo doesn't time out mid-show.
    $pdo->exec('SET SESSION lock_wait_timeout = 120');
    return $pdo;
}

function ts(): string {
    return (new DateTime('now'))->format('H:i:s.v');
}

function logmsg(string $actor, string $msg): void {
    fwrite(STDOUT, sprintf("[%s] %-8s %s\n", ts(), $actor, $msg));
}
