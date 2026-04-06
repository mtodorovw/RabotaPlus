<?php
// config/db.php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'fl');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            // Sync MySQL timezone with PHP so timestamps match
            $tz = date('P'); // e.g. +02:00 or +03:00
            $pdo->exec("SET time_zone = '$tz'");
            // Ensure emoji and multi-byte Unicode work correctly (4-byte utf8mb4)
            // Set charset for emoji/unicode support
            $pdo->exec("SET NAMES utf8mb4");
        } catch (PDOException $e) {
            die('<div style="padding:20px;font-family:sans-serif;color:red;">Грешка при свързване с базата данни: ' . htmlspecialchars($e->getMessage()) . '</div>');
        }
    }
    return $pdo;
}
