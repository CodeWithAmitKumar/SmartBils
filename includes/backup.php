<?php
// Pure-PHP mini mysqldump: streams a schema + data snapshot of the current DB.
declare(strict_types=1);

/** Stream a full backup of the configured DB directly to the response. */
function backup_stream_sql(string $filename = ''): void {
    $pdo = db();
    $dbName = DB_NAME;
    $filename = $filename ?: "smartbilling-{$dbName}-" . date('Ymd-His') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    echo "-- SmartBils DB backup\n";
    echo "-- Database: {$dbName}\n";
    echo "-- Taken: " . date('c') . "\n\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $t) {
        echo "-- ----------------------------\n-- Table `{$t}`\n-- ----------------------------\n";
        echo "DROP TABLE IF EXISTS `{$t}`;\n";
        $create = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch();
        echo rtrim($create['Create Table']) . ";\n\n";

        // Data
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        if ($count === 0) continue;

        $cols = $pdo->query("SELECT * FROM `{$t}` LIMIT 0")->getColumnMeta(0) ? null : null;
        // Fetch rows in batches so memory stays flat on large tables.
        $offset = 0; $batch = 500;
        while (true) {
            $rows = $pdo->query("SELECT * FROM `{$t}` LIMIT {$batch} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;
            $colNames = array_map(fn($c) => "`{$c}`", array_keys($rows[0]));
            foreach ($rows as $r) {
                $vals = array_map(function ($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    if (is_int($v) || is_float($v)) return (string)$v;
                    return $pdo->quote((string)$v);
                }, array_values($r));
                echo "INSERT INTO `{$t}` (" . implode(',', $colNames) . ") VALUES (" . implode(',', $vals) . ");\n";
            }
            $offset += $batch;
            if (ob_get_level()) { ob_flush(); flush(); }
        }
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
}

/** Save a backup to a file. Returns [ok, path|error]. */
function backup_save_to_file(string $dir): array {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return [false, 'Cannot create backup directory.'];
    }
    $file = $dir . '/smartbils-' . date('Ymd-His') . '.sql';
    $fh = @fopen($file, 'wb');
    if (!$fh) return [false, 'Cannot write backup file.'];

    ob_start(function ($buffer) use ($fh) {
        fwrite($fh, $buffer);
        return '';
    });
    try { backup_stream_sql(); }
    finally { ob_end_clean(); fclose($fh); }
    return [true, $file];
}

/** Restore from an uploaded .sql file. Executes statements one by one. Throws on error. */
function backup_restore_sql_file(string $path): int {
    if (!is_file($path)) throw new RuntimeException('SQL file not found.');
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Cannot read SQL file.');

    // Strip -- line comments and /* ... */ block comments
    $sql = preg_replace('!/\*.*?\*/!s', '', $sql);
    $sql = preg_replace('/^--.*$/m', '', $sql);

    // Split on semicolons at end of line (naive, works for our dump).
    $stmts = array_filter(array_map('trim', explode(";\n", $sql)));
    $pdo = db();
    $count = 0;
    foreach ($stmts as $s) {
        if ($s === '') continue;
        $pdo->exec($s);
        $count++;
    }
    return $count;
}
