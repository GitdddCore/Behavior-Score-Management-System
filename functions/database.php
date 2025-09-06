<?php
// 防止直接访问
if (!defined('INCLUDED_FROM_APP') && basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    exit();
}

/**
 * 数据库连接管理器 - 专注于MySQL和Redis连接池管理
 * 
 * 职责：
 * 1. 管理MySQL连接池
 * 2. 管理Redis连接池
 * 3. 连接健康检查和自动恢复
 * 4. 资源清理和内存管理
 * 5. 连接性能监控
 * 
 * 不负责：
 * - 数据验证
 * - 业务逻辑
 * - 数据操作
 */
class Database {
    // MySQL连接池
    private static $mysqlConnections = [];
    private static $mysqlConnectionCount = 0;
    private static $maxMysqlConnections = 25;
    
    // Redis连接池
    private static $redisConnections = [];
    private static $redisConnectionCount = 0;
    private static $maxRedisConnections = 10;
    
    // 配置
    private static $config = null;
    
    // 连接管理常量
    const CONNECTION_TIMEOUT = 5;
    const WAIT_INTERVAL = 100000; // 100ms
    const MAX_WAIT_TIME = 5; // 5秒
    const CONNECTION_IDLE_TIMEOUT = 1800; // 30分钟
    const HEALTH_CHECK_INTERVAL = 300; // 5分钟
    
    // 错误代码
    const ERROR_CONFIG_LOAD_FAILED = 1001;
    const ERROR_CONNECTION_FAILED = 1002;
    const ERROR_POOL_EXHAUSTED = 1003;
    const ERROR_HEALTH_CHECK_FAILED = 1004;
    
    /**
     * 构造函数 - 初始化配置
     */
    public function __construct() {
        $this->loadConfiguration();
        $this->initializeCleanupScheduler();
    }
    
    /**
     * 加载数据库配置
     */
    private function loadConfiguration() {
        if (self::$config === null) {
            try {
                $configPath = __DIR__ . '/../config/config.json';
                if (!file_exists($configPath)) {
                    throw new Exception("配置文件不存在: {$configPath}");
                }
                
                $configContent = file_get_contents($configPath);
                if ($configContent === false) {
                    throw new Exception("无法读取配置文件: {$configPath}");
                }
                
                $config = json_decode($configContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("配置文件JSON格式错误: " . json_last_error_msg());
                }
                
                if (!isset($config['database'])) {
                    throw new Exception("配置文件中缺少database配置");
                }
                
                self::$config = $config['database'];
                
            } catch (Exception $e) {
                throw new Exception("数据库配置加载失败", self::ERROR_CONFIG_LOAD_FAILED);
            }
        }
    }
    
    /**
     * 初始化清理调度器
     */
    private function initializeCleanupScheduler() {
        // 注册shutdown函数确保资源清理
        register_shutdown_function([$this, 'forceCleanup']);
    }
    
    // ==================== MySQL连接管理 ====================
    
    /**
     * 获取MySQL连接
     */
    public function getMysqlConnection() {
        try {
            // 尝试复用现有连接
            $connection = $this->reuseExistingMysqlConnection();
            if ($connection !== null) {
                return $connection;
            }
            
            // 创建新连接
            if (self::$mysqlConnectionCount < self::$maxMysqlConnections) {
                return $this->createNewMysqlConnection();
            }
            
            // 等待可用连接
            return $this->waitForAvailableMysqlConnection();
            
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    /**
     * 复用现有MySQL连接
     */
    private function reuseExistingMysqlConnection() {
        foreach (self::$mysqlConnections as $id => &$connectionInfo) {
            if ($connectionInfo['available'] && $this->isMysqlConnectionHealthy($connectionInfo['connection'])) {
                $connectionInfo['available'] = false;
                $connectionInfo['last_used'] = time();
                $connectionInfo['usage_count']++;
                return $connectionInfo['connection'];
            } elseif (!$this->isMysqlConnectionHealthy($connectionInfo['connection'])) {
                // 移除不健康的连接
                $this->removeMysqlConnection($id);
            }
        }
        return null;
    }
    
    /**
     * 创建新的MySQL连接
     */
    private function createNewMysqlConnection() {
        try {
            $config = self::$config['mysql'];
            $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
            
            $options = [
                PDO::ATTR_TIMEOUT => self::CONNECTION_TIMEOUT,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES'",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
            ];
            
            $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
            
            $connectionId = $this->generateConnectionId('mysql');
            self::$mysqlConnections[$connectionId] = [
                'connection' => $pdo,
                'available' => false,
                'created_at' => time(),
                'last_used' => time(),
                'usage_count' => 1,
                'health_check_count' => 0
            ];
            
            self::$mysqlConnectionCount++;
            
            return $pdo;
            
        } catch (PDOException $e) {
            throw new Exception("MySQL连接创建失败: " . $e->getMessage(), self::ERROR_CONNECTION_FAILED);
        }
    }
    
    /**
     * 等待可用的MySQL连接
     */
    private function waitForAvailableMysqlConnection() {
        $startTime = microtime(true);
        
        while ((microtime(true) - $startTime) < self::MAX_WAIT_TIME) {
            $connection = $this->reuseExistingMysqlConnection();
            if ($connection !== null) {
                return $connection;
            }
            
            // 尝试清理过期连接释放空间
            $this->cleanupIdleConnections();
            
            if (self::$mysqlConnectionCount < self::$maxMysqlConnections) {
                return $this->createNewMysqlConnection();
            }
            
            usleep(self::WAIT_INTERVAL);
        }
        
        throw new Exception("MySQL连接池已满，等待超时", self::ERROR_POOL_EXHAUSTED);
    }
    
    /**
     * 释放MySQL连接回连接池
     */
    public function releaseMysqlConnection($pdo) {
        foreach (self::$mysqlConnections as $id => &$connectionInfo) {
            if ($connectionInfo['connection'] === $pdo) {
                $connectionInfo['available'] = true;
                $connectionInfo['last_used'] = time();
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查MySQL连接健康状态
     */
    private function isMysqlConnectionHealthy($pdo) {
        try {
            $stmt = $pdo->query('SELECT 1');
            return $stmt !== false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 移除MySQL连接
     */
    private function removeMysqlConnection($connectionId) {
        if (isset(self::$mysqlConnections[$connectionId])) {
            unset(self::$mysqlConnections[$connectionId]);
            self::$mysqlConnectionCount--;
        }
    }
    
    // ==================== Redis连接管理 ====================
    
    /**
     * 获取Redis连接 - 必须指定数据库映射名称
     */
    public function getRedisConnection($databaseName) {
        if (!extension_loaded('redis')) {
            return null;
        }
        
        if (empty($databaseName)) {
            throw new Exception("Redis连接必须指定数据库映射名称", self::ERROR_CONNECTION_FAILED);
        }
        
        try {
            // 尝试复用现有连接
            $connection = $this->reuseExistingRedisConnection();
            if ($connection !== null) {
                if (!$this->selectRedisDatabase($connection, $databaseName)) {
                    return null;
                }
                return $connection;
            }
            
            // 创建新连接
            if (self::$redisConnectionCount < self::$maxRedisConnections) {
                $connection = $this->createNewRedisConnection();
                if ($connection) {
                    if (!$this->selectRedisDatabase($connection, $databaseName)) {
                        return null;
                    }
                    return $connection;
                }
                return null;
            }
            
            // 等待可用连接
            return $this->waitForAvailableRedisConnection($databaseName);
            
        } catch (Exception $e) {
            return null;
        }
    }
    

    
    /**
     * 复用现有Redis连接
     */
    private function reuseExistingRedisConnection() {
        foreach (self::$redisConnections as $id => &$connectionInfo) {
            if ($connectionInfo['available'] && $this->isRedisConnectionHealthy($connectionInfo['connection'])) {
                $connectionInfo['available'] = false;
                $connectionInfo['last_used'] = time();
                $connectionInfo['usage_count']++;
                return $connectionInfo['connection'];
            } elseif (!$this->isRedisConnectionHealthy($connectionInfo['connection'])) {
                // 移除不健康的连接
                $this->removeRedisConnection($id);
            }
        }
        return null;
    }
    
    /**
     * 创建新的Redis连接
     */
    private function createNewRedisConnection() {
        try {
            $config = self::$config['redis'];
            $redis = new Redis();
            
            $connected = $redis->connect(
                $config['host'], 
                $config['port'], 
                $config['timeout'] ?? self::CONNECTION_TIMEOUT
            );
            
            if (!$connected) {
                throw new Exception("Redis连接失败");
            }
            
            if (!empty($config['password'])) {
                if (!$redis->auth($config['password'])) {
                    throw new Exception("Redis认证失败");
                }
            }
            
            $connectionId = $this->generateConnectionId('redis');
            self::$redisConnections[$connectionId] = [
                'connection' => $redis,
                'available' => false,
                'created_at' => time(),
                'last_used' => time(),
                'usage_count' => 1,
                'health_check_count' => 0
            ];
            
            self::$redisConnectionCount++;
            
            return $redis;
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * 等待可用的Redis连接
     */
    private function waitForAvailableRedisConnection($databaseName) {
        $startTime = microtime(true);
        
        while ((microtime(true) - $startTime) < self::MAX_WAIT_TIME) {
            $connection = $this->reuseExistingRedisConnection();
            if ($connection !== null) {
                if (!$this->selectRedisDatabase($connection, $databaseName)) {
                    return null;
                }
                return $connection;
            }
            
            // 尝试清理过期连接释放空间
            $this->cleanupIdleConnections();
            
            if (self::$redisConnectionCount < self::$maxRedisConnections) {
                $connection = $this->createNewRedisConnection();
                if ($connection) {
                    if (!$this->selectRedisDatabase($connection, $databaseName)) {
                        return null;
                    }
                    return $connection;
                }
                return null;
            }
            
            usleep(self::WAIT_INTERVAL);
        }
        
        return null;
    }
    
    /**
     * 释放Redis连接回连接池
     */
    public function releaseRedisConnection($redis) {
        foreach (self::$redisConnections as $id => &$connectionInfo) {
            if ($connectionInfo['connection'] === $redis) {
                $connectionInfo['available'] = true;
                $connectionInfo['last_used'] = time();
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 选择Redis数据库
     */
    private function selectRedisDatabase($redis, $databaseName) {
        if (!isset(self::$config['redis']['databases'])) {
            return false;
        }
        
        $databases = self::$config['redis']['databases'];
        foreach ($databases as $dbNumber => $mappedName) {
            if ($mappedName === $databaseName) {
                try {
                    return $redis->select((int)$dbNumber);
                } catch (Exception $e) {
                    return false;
                }
            }
        }
        
        return false;
    }
    
    /**
     * 检查Redis连接健康状态
     */
    private function isRedisConnectionHealthy($redis) {
        try {
            return $redis->ping() === '+PONG';
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 移除Redis连接
     */
    private function removeRedisConnection($connectionId) {
        if (isset(self::$redisConnections[$connectionId])) {
            try {
                self::$redisConnections[$connectionId]['connection']->close();
            } catch (Exception $e) {
                // 忽略关闭连接时的错误
            }
            
            unset(self::$redisConnections[$connectionId]);
            self::$redisConnectionCount--;
        }
    }
    
    // ==================== 连接池管理 ====================
    
    /**
     * 清理空闲连接
     */
    public function cleanupIdleConnections() {
        $now = time();
        $cleanedCount = 0;
        
        // 清理MySQL连接
        foreach (self::$mysqlConnections as $id => $connectionInfo) {
            if ($connectionInfo['available'] && 
                ($now - $connectionInfo['last_used']) > self::CONNECTION_IDLE_TIMEOUT) {
                $this->removeMysqlConnection($id);
                $cleanedCount++;
            }
        }
        
        // 清理Redis连接
        foreach (self::$redisConnections as $id => $connectionInfo) {
            if ($connectionInfo['available'] && 
                ($now - $connectionInfo['last_used']) > self::CONNECTION_IDLE_TIMEOUT) {
                $this->removeRedisConnection($id);
                $cleanedCount++;
            }
        }
        
        return $cleanedCount;
    }
    
    /**
     * 强制清理所有连接
     */
    public function forceCleanup() {
        $totalCleaned = 0;
        
        // 清理所有MySQL连接
        foreach (array_keys(self::$mysqlConnections) as $id) {
            $this->removeMysqlConnection($id);
            $totalCleaned++;
        }
        
        // 清理所有Redis连接
        foreach (array_keys(self::$redisConnections) as $id) {
            $this->removeRedisConnection($id);
            $totalCleaned++;
        }
        
    }
    
    /**
     * 执行连接池健康检查
     */
    public function performHealthCheck() {
        $healthReport = [
            'mysql' => ['healthy' => 0, 'unhealthy' => 0],
            'redis' => ['healthy' => 0, 'unhealthy' => 0]
        ];
        
        // 检查MySQL连接
        foreach (self::$mysqlConnections as $id => &$connectionInfo) {
            $connectionInfo['health_check_count']++;
            if ($this->isMysqlConnectionHealthy($connectionInfo['connection'])) {
                $healthReport['mysql']['healthy']++;
            } else {
                $healthReport['mysql']['unhealthy']++;
                $this->removeMysqlConnection($id);
            }
        }
        
        // 检查Redis连接
        foreach (self::$redisConnections as $id => &$connectionInfo) {
            $connectionInfo['health_check_count']++;
            if ($this->isRedisConnectionHealthy($connectionInfo['connection'])) {
                $healthReport['redis']['healthy']++;
            } else {
                $healthReport['redis']['unhealthy']++;
                $this->removeRedisConnection($id);
            }
        }
        
        return $healthReport;
    }
    
    // ==================== 工具方法 ====================
    
    /**
     * 生成连接ID
     */
    private function generateConnectionId($type) {
        return $type . '_' . uniqid() . '_' . time();
    }
    
    /**
     * 清空缓存数据库 - 用于数据更新后重新读取
     */
    public function clearCacheForDataUpdate() {
        try {
            // 从配置中获取cache数据库映射名称
            $databases = self::$config['redis']['databases'] ?? [];
            $cacheDatabaseName = null;
            
            // 查找cache数据库的映射名称
            foreach ($databases as $dbNumber => $mappedName) {
                if ($mappedName === 'cache') {
                    $cacheDatabaseName = 'cache';
                    break;
                }
            }
            
            if (!$cacheDatabaseName) {
                return false;
            }
            
            // 获取Redis连接并清空cache数据库
            $redis = $this->getRedisConnection($cacheDatabaseName);
            if ($redis) {
                $result = $redis->flushDB();
                $this->releaseRedisConnection($redis);
                
                return $result;
            }
            
            return false;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 析构函数
     */
    public function __destruct() {
        $this->cleanupIdleConnections();
    }
}


?>