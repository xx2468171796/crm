<?php
// 数据库封装,使用 PDO

// 设置时区为中国标准时间
date_default_timezone_set('Asia/Shanghai');

if (!function_exists('app_config')) {
    function app_config(): array
    {
        static $config = null;
        if ($config === null) {
            $config = require __DIR__ . '/../config.php';
        }
        return $config;
    }
}

class Db
{
    /** @var PDO|null */
    private static $pdo = null;

    /**
     * 获取 PDO 实例
     */
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $config = app_config()['db'];
            
            // 构建DSN字符串
            // 支持两种配置方式：
            // 1. 新方式：分离的 host, port, dbname, charset
            // 2. 旧方式：完整的 dsn 字符串（向后兼容）
            if (isset($config['dsn'])) {
                // 旧方式：直接使用dsn
                $dsn = $config['dsn'];
            } else {
                // 新方式：从配置项构建dsn
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $config['host'] ?? 'localhost',
                    $config['port'] ?? 3306,
                    $config['dbname'] ?? '',
                    $config['charset'] ?? 'utf8mb4'
                );
            }
            
            self::$pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
            // 确保字符集设置正确
            self::$pdo->exec("SET NAMES utf8mb4");
        }
        return self::$pdo;
    }

    /**
     * 查询多条记录
     */
    public static function query(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * 查询一条记录
     */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * 执行写操作(INSERT/UPDATE/DELETE)
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        if ($stmt === false) {
            $error = self::pdo()->errorInfo();
            throw new PDOException('SQL 准备失败: ' . ($error[2] ?? '未知错误'));
        }
        
        $result = $stmt->execute($params);
        if ($result === false) {
            $error = $stmt->errorInfo();
            throw new PDOException('SQL 执行失败: ' . ($error[2] ?? '未知错误'));
        }
        
        return $stmt->rowCount();
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::execute($sql, $params);
    }
    
    /**
     * 获取最后插入的ID
     */
    public static function lastInsertId(): string
    {
        return self::pdo()->lastInsertId();
    }
    
    /**
     * 开始事务
     */
    public static function beginTransaction(): bool
    {
        return self::pdo()->beginTransaction();
    }
    
    /**
     * 提交事务
     */
    public static function commit(): bool
    {
        return self::pdo()->commit();
    }
    
    /**
     * 回滚事务（安全版本，不会在没有活跃事务时抛出异常）
     */
    public static function rollback(): bool
    {
        if (self::pdo()->inTransaction()) {
            return self::pdo()->rollBack();
        }
        return false;
    }
    
    /**
     * 检查是否在事务中
     */
    public static function inTransaction(): bool
    {
        return self::pdo()->inTransaction();
    }
}
