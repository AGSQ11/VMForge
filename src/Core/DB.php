<?php
namespace VMForge\Core;

use PDO;
use PDOException;

class DB {
    private static ?PDO $instance = null;
    private static array $config = [];
    
    /**
     * Initialize database configuration
     */
    public static function init(array $config = []): void {
        self::$config = array_merge([
            'host' => Env::get('DB_HOST', '127.0.0.1'),
            'port' => (int)Env::get('DB_PORT', '3306'),
            'name' => Env::get('DB_NAME', 'vmforge'),
            'user' => Env::get('DB_USER', 'vmforge'),
            'pass' => Env::get('DB_PASS', ''),
            'charset' => 'utf8mb4',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]
        ], $config);
    }
    
    /**
     * Get PDO instance with connection pooling and retry logic
     */
    public static function pdo(): PDO {
        if (self::$instance === null) {
            self::connect();
        }
        
        // Test connection and reconnect if needed
        try {
            self::$instance->query('SELECT 1');
        } catch (PDOException $e) {
            self::connect();
        }
        
        return self::$instance;
    }
    
    /**
     * Establish database connection with retry logic
     */
    private static function connect(int $retries = 3): void {
        if (empty(self::$config)) {
            self::init();
        }
        
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $retries) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    self::$config['host'],
                    self::$config['port'],
                    self::$config['name'],
                    self::$config['charset']
                );
                
                self::$instance = new PDO(
                    $dsn,
                    self::$config['user'],
                    self::$config['pass'],
                    self::$config['options']
                );
                
                // Set connection attributes
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                
                return;
            } catch (PDOException $e) {
                $lastException = $e;
                $attempt++;
                if ($attempt < $retries) {
                    sleep(1); // Wait before retry
                }
            }
        }
        
        throw new \RuntimeException(
            'Database connection failed after ' . $retries . ' attempts: ' . $lastException->getMessage(),
            0,
            $lastException
        );
    }
    
    /**
     * Execute a transaction with automatic rollback on failure
     */
    public static function transaction(callable $callback) {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Prepared statement helper with named parameters
     */
    public static function execute(string $sql, array $params = []): \PDOStatement {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Fetch single row
     */
    public static function fetchOne(string $sql, array $params = []): ?array {
        return self::execute($sql, $params)->fetch() ?: null;
    }
    
    /**
     * Fetch all rows
     */
    public static function fetchAll(string $sql, array $params = []): array {
        return self::execute($sql, $params)->fetchAll();
    }
    
    /**
     * Get last insert ID
     */
    public static function lastInsertId(): int {
        return (int)self::pdo()->lastInsertId();
    }
}
