<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Route::get('/', function () {
    return view('welcome');
});


// លីងសម្អាត Cache
Route::get('/api/clear-route', function () {

    Artisan::call('optimize:clear');

    return response()->json([
        'success' => true,
        'message' => 'Cache Cleared!'
    ]);
});


// Backup Aiven MySQL Database - PHP Onlysdfsf
Route::get('/api/backup-database', function () {

    // Security token
    if (request('token') !== env('ADMIN_API_TOKEN')) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized'
        ], 403);
    }

    try {

        $filename = 'aiven_backup_' . date('Y-m-d_H-i-s') . '.sql';
        $backupPath = storage_path('app/' . $filename);

        $pdo = DB::connection()->getPdo();

        $sql = "-- Dyzz Store Aiven MySQL Backup\n";
        $sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Database: " . config('database.connections.mysql.database') . "\n\n";

        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        // Get tables
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {

            $table = str_replace('`', '``', $table);

            $sql .= "-- ----------------------------------------\n";
            $sql .= "-- Table: `$table`\n";
            $sql .= "-- ----------------------------------------\n\n";

            // Drop table
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";

            // Create table
            $create = $pdo->query(
                "SHOW CREATE TABLE `$table`"
            )->fetch(PDO::FETCH_ASSOC);

            $createSql = $create['Create Table'] ?? null;

            if ($createSql) {
                $sql .= $createSql . ";\n\n";
            }

            // Get rows
            $rows = $pdo->query(
                "SELECT * FROM `$table`"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {

                $columns = array_keys($row);

                $columnList = '`' . implode('`,`', array_map(
                    fn ($column) => str_replace('`', '``', $column),
                    $columns
                )) . '`';

                $values = [];

                foreach ($row as $value) {

                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = $pdo->quote($value);
                    }
                }

                $sql .= "INSERT INTO `$table` ($columnList) VALUES ";
                $sql .= "(" . implode(', ', $values) . ");\n";
            }

            $sql .= "\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        // Write backup file
        file_put_contents($backupPath, $sql);

        if (!file_exists($backupPath) || filesize($backupPath) === 0) {

            return response()->json([
                'success' => false,
                'message' => 'Backup file was not created.'
            ], 500);
        }

        // Download
        return response()
            ->download(
                $backupPath,
                $filename,
                [
                    'Content-Type' => 'application/sql'
                ]
            )
            ->deleteFileAfterSend(true);

    } catch (\Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => 'Database backup failed.',
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile()
        ], 500);
    }
});