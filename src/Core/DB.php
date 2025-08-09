<?php
namespace VMForge\Core;
use PDO;
class DB {
    private static ?PDO $pdo = null;
    public static function pdo(): PDO {
        if (!self::$pdo) {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST','127.0.0.1'),
                Env::get('DB_PORT','3306'),
                Env::get('DB_NAME','vmforge')
            );
            self::$pdo = new PDO($dsn, Env::get('DB_USER','root'), Env::get('DB_PASS',''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
        }
        return self::$pdo;
    }
}
