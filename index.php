<?php
// 定义应用程序常量，允许包含的文件正常访问
define('INCLUDED_FROM_APP', true);

// 学生排名首页 - 显示前三名排名和搜索功能

// 引入数据库类
require_once 'functions/database.php';

// 初始化数据库连接
$db = new Database();

// 获取学生数据（带Redis缓存）
function getStudents($limit = null, $search = null) {
    global $db;
    
    try {
        // 生成缓存键
        $cacheKey = $search ? "Search_" . md5($search) : "TopRank" . ($limit ?: 'all');
        $cacheDb = 'cache'; // 统一使用cache数据库
        
        // 优先查询Redis缓存
        try {
            $cacheType = $search ? 'Search' : 'TopRank';
            $cachedData = getCache($cacheKey, $cacheDb, $cacheType);
            if ($cachedData !== null && !empty($cachedData)) {
                return $cachedData;
            }
        } catch (Exception $e) {
            // Redis缓存不可用时继续执行SQL查询
        }
        
        // 输入验证
        if ($search) {
            $search = trim($search);
            if (strlen($search) < 1 || strlen($search) > 50) {
                return [];
            }
            // 过滤特殊字符
            $search = preg_replace('/[^\p{L}\p{N}\s]/u', '', $search);
        }
        
        $sql = "SELECT id, name, student_id, current_score FROM students";
        $params = [];
        
        if ($search) {
            $sql .= " WHERE name LIKE :search OR student_id LIKE :search";
            $params['search'] = '%' . $search . '%';
            // 搜索结果按ID排序
            $sql .= " ORDER BY id ASC";
        } else {
            // 前三名按操行分降序排序
            $sql .= " ORDER BY current_score DESC";
        }
        
        if ($limit) $sql .= " LIMIT $limit";
        
        $pdo = $db->getMysqlConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll();
        $db->releaseMysqlConnection($pdo);
        
        // 直接返回学生数据数组（兼容前端期望的格式）
        $result = $students;
        // 只有当搜索出结果时才进行缓存，利用智能失效机制延长缓存时间
        if (!empty($result)) {
            try {
                $cacheTime = 600; // 缓存时间为10分钟，配合智能失效机制
                $cacheType = $search ? 'Search' : 'TopRank';
                setCache($cacheKey, $result, $cacheTime, 'cache', $cacheType);
            } catch (Exception $e) {
                // Redis不可用时忽略缓存设置
            }
        }
        
        return $result;
    } catch(PDOException $e) {
        // 不在浏览器中暴露错误信息
        error_log("Database error in getStudents: " . $e->getMessage());
        return [];
    }
}

// ==================== 缓存管理方法 ====================

/**
 * 生成带版本的缓存键
 */
function getVersionedCacheKey($key, $cacheType = 'Search') {
    if ($cacheType === 'TopRank') {
        // 前三名使用固定格式，每次更新时清理旧缓存
        return 'Rank3';
    } else {
        // 搜索使用固定格式，基于搜索内容的哈希值
        return 'Search_' . md5($key);
    }
}

/**
 * Redis缓存设置（带版本控制）
 */
function setCache($key, $value, $expire = 600, $dbName = 'cache', $cacheType = 'Search') {
    global $db;
    try {
        $redis = $db->getRedisConnection($dbName);
        if ($redis) {
            $versionedKey = getVersionedCacheKey($key, $cacheType);
            
            // 对于搜索缓存，先检查是否已存在，避免重复设置
            if ($cacheType === 'Search') {
                $existing = $redis->get($versionedKey);
                if ($existing !== false) {
                    // 缓存已存在，直接返回成功
                    $db->releaseRedisConnection($redis);
                    return true;
                }
            }
            
            $result = $redis->setex($versionedKey, $expire, json_encode($value));
            $db->releaseRedisConnection($redis);
            return $result;
        }
    } catch (Exception $e) {
        error_log("Redis设置缓存失败: " . $e->getMessage());
    }
    return false;
}

/**
 * Redis缓存获取（带版本控制）
 */
function getCache($key, $dbName = 'cache', $cacheType = 'Search') {
    global $db;
    try {
        $redis = $db->getRedisConnection($dbName);
        if ($redis) {
            $versionedKey = getVersionedCacheKey($key, $cacheType);
            $data = $redis->get($versionedKey);
            $db->releaseRedisConnection($redis);
            return $data ? json_decode($data, true) : null;
        }
    } catch (Exception $e) {
        error_log("Redis获取缓存失败: " . $e->getMessage());
    }
    return null;
}

// AJAX处理
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    // 简单的访问频率限制
    session_start();
    if (!isset($_SESSION['last_request'])) $_SESSION['last_request'] = 0;
    if (time() - $_SESSION['last_request'] < 0.2) {
        echo json_encode(['success' => false, 'error' => '请求过于频繁，请稍后再试']);
        exit;
    }
    $_SESSION['last_request'] = time();
    
    $action = $_GET['action'] ?? '';
    if ($action === 'search') {
        $searchTerm = $_GET['search'] ?? '';
        if (empty(trim($searchTerm))) {
            echo json_encode(['success' => false, 'error' => '搜索内容不能为空']);
            exit;
        }
        echo json_encode(getStudents(null, $searchTerm));
    } else {
        echo json_encode(getStudents(3));
    }
    exit;
}

// 初始化数据
$topStudents = getStudents(3);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>操行分管理系统 - 首页</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <?php include 'modules/notification.php'; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        input[type="text"], input[type="search"], textarea {
            font-size: 16px !important;
            appearance: none;
        }
        html {
            overflow-y: scroll;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            color: #333;
            overflow-x: hidden;
        }
        .main-layout {
            padding-left: 200px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
            color: #2c3e50;
        }
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            font-weight: 600;
        }
        .header p {
            font-size: 1.1rem;
            opacity: 0.7;
        }
        .search-container {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }
        .search-box {
            position: relative;
            max-width: 500px;
            margin: 0 auto;
        }

        .search-input {
            width: 100%;
            padding: 15px 40px 15px 20px;
            border: 2px solid #e9ecef;
            border-radius: 25px;
            font-size: 16px;
            outline: none;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
            transform: none;
        }

        .search-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            pointer-events: none;
        }

        /* 前三名排名样式 */
        .top-three-container {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .podium {
            display: flex;
            justify-content: center;
            align-items: end;
            gap: 20px;
            margin-bottom: 30px;
        }

        .podium-item {
            text-align: center;
            position: relative;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .podium-item:hover {
            transform: translateY(-2px);
        }

        .podium-item.first {
            order: 2;
        }

        .podium-item.second {
            order: 1;
        }

        .podium-item.third {
            order: 3;
        }

        .podium-base {
            width: 120px;
            background: linear-gradient(135deg, #f39c12, #e67e22);
            border-radius: 8px 8px 0 0;
            color: white;
            padding: 20px 10px;
            margin-bottom: 10px;
            position: relative;
        }

        .podium-item.first .podium-base {
            height: 100px;
            background: linear-gradient(135deg, #f1c40f, #f39c12);
        }

        .podium-item.second .podium-base {
            height: 80px;
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }

        .podium-item.third .podium-base {
            height: 60px;
            background: linear-gradient(135deg, #e67e22, #d35400);
        }

        .rank-number {
            position: absolute;
            top: -15px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            color: #2c3e50;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .student-info {
            margin-top: 15px;
        }

        .student-name, .mobile-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .student-id, .mobile-detail-label {
            font-size: 0.9rem;
            color: #7f8c8d;
            margin-bottom: 8px;
        }

        .score-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            color: white;
        }

        .score-excellent { background: linear-gradient(135deg, #27ae60, #2ecc71); }
        .score-good { background: linear-gradient(135deg, #3498db, #5dade2); }
        .score-average { background: linear-gradient(135deg, #f39c12, #f7dc6f); }
        .score-poor { background: linear-gradient(135deg, #e74c3c, #ec7063); }

        /* 查看全部排名按钮 */
        .view-all-btn {
            display: block;
            width: 200px;
            margin: 0 auto;
            padding: 12px 24px;
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            text-decoration: none;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 600;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
            user-select: none;
        }

        .view-all-btn:hover {
            background: linear-gradient(135deg, #2980b9, #21618c);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(52, 152, 219, 0.4);
        }

        .view-all-btn:active, .view-all-btn.touching {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3);
        }

        /* 搜索结果样式 */
        .search-results {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            display: none;
            max-height: 600px;
            overflow: hidden;
        }
        
        .search-results-header {
            padding: 25px 25px 15px 25px;
            border-bottom: 2px solid #f8f9fa;
            background: white;
            position: sticky;
            top: 0;
            z-index: 10;
            border-radius: 12px 12px 0 0;
        }
        
        .search-results-content {
            max-height: 450px;
            overflow-y: auto;
            padding: 0 25px 25px 25px;
        }
        
        .search-results-content::-webkit-scrollbar {
            width: 8px;
        }
        
        .search-results-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .search-results-content::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        .search-results-content::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        .result-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            border-bottom: 1px solid #f8f9fa;
            transition: all 0.3s ease;
            cursor: pointer;
            border-radius: 8px;
            margin-bottom: 5px;
        }

        .result-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .result-item:hover {
            background: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .result-item:active {
            background: #e9ecef;
            transform: translateY(0);
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
        }

        .result-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .result-rank {
            width: 30px;
            height: 30px;
            background: #3498db;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }

        .result-info h4 {
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .result-info p {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .result-right {
            display: flex;
            align-items: center;
        }

        /* 状态样式 */
        .empty-state, .loading-state, .error-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }
        
        .empty-state i, .loading-state i, .error-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .loading-state {
            color: #ccc;
        }
        
        .loading-state i {
            color: #ccc;
            animation: spin 1s linear infinite;
        }
        
        .error-state {
            color: #ccc;
        }
        
        .error-state i {
            color: #ccc;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .empty-state p {
            font-size: 1.1rem;
            font-weight: 500;
            margin-bottom: 5px;
            color: #7f8c8d;
        }

        .empty-state span {
            font-size: 0.9rem;
            opacity: 0.7;
        }

        /* 移动端卡片样式 */
        .mobile-top-three {
            display: none;
        }

        .mobile-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 15px;
            padding: 18px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.08);
            border-left: 4px solid #3498db;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .mobile-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(52, 152, 219, 0.02), rgba(52, 152, 219, 0.05));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .mobile-card:hover::before {
            opacity: 1;
        }

        .mobile-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
        }

        .mobile-card:active {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .mobile-card.rank-1 {
            border-left-color: #f1c40f;
        }

        .mobile-card.rank-2 {
            border-left-color: #95a5a6;
        }

        .mobile-card.rank-3 {
            border-left-color: #e67e22;
        }

        .mobile-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .mobile-rank {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2c3e50;
        }
        .mobile-rank.rank-1 { color: #f39c12; }
        .mobile-rank.rank-2 { color: #95a5a6; }
        .mobile-rank.rank-3 { color: #cd7f32; }

        .mobile-name {
            font-size: 1.2rem;
        }

        .mobile-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }

        .mobile-detail-item {
            display: flex;
            flex-direction: column;
        }

        .mobile-detail-label {
            font-size: 0.8rem;
            margin-bottom: 2px;
        }

        .mobile-detail-value {
            font-size: 0.9rem;
            font-weight: 500;
            color: #2c3e50;
        }

        /* 平板适配 */
        @media (max-width: 1024px) and (min-width: 769px) {
            .container {
                padding: 20px;
            }
            
            .podium {
                transform: scale(0.9);
            }
            
            .search-input {
                font-size: 16px;
                padding: 14px 20px;
            }
        }
        
        /* 移动端适配 */
        @media (max-width: 768px) {
            .main-layout {
                padding-left: 0;
            }

            .container {
                padding: 15px;
                max-width: 100%;
            }

            .header {
                margin-bottom: 25px;
                padding: 0 5px;
            }

            .header h1 {
                font-size: 1.8rem;
                line-height: 1.2;
            }

            .header p {
                font-size: 1rem;
                margin-top: 8px;
            }
            
            .search-container {
                margin-bottom: 20px;
                padding: 20px;
                border-radius: 15px;
            }
            
            .search-input {
                padding: 14px 36px 14px 18px;
                font-size: 16px;
                border-radius: 25px;
            }
            
            .search-icon {
                right: 18px;
                font-size: 14px;
            }

            .search-input:focus {
                background: white;
                border-color: #3498db;
                box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.15);
            }

            /* 隐藏桌面端的前三名排行榜 */
            .podium {
                display: none;
            }

            /* 显示移动端卡片 */
            .mobile-top-three {
                display: block;
            }

            .top-three-container {
                padding: 20px;
                border-radius: 15px;
                margin-bottom: 20px;
            }

            .search-results {
                padding: 20px;
                border-radius: 15px;
                margin-bottom: 20px;
            }

            .result-item {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                padding: 16px;
                border-radius: 12px;
                margin-bottom: 8px;
                background: #fafbfc;
                border: 1px solid #f1f3f4;
            }

            .result-item:hover {
                background: #f8f9fa;
                border-color: #e9ecef;
            }

            .result-left {
                justify-content: flex-start;
                gap: 12px;
            }

            .result-right {
                justify-content: flex-start;
                margin-top: 0;
            }

            .result-rank {
                width: 28px;
                height: 28px;
                font-size: 13px;
                flex-shrink: 0;
            }

            .result-info h4 {
                font-size: 1.05rem;
                margin-bottom: 4px;
                line-height: 1.3;
            }

            .result-info p {
                font-size: 0.9rem;
                line-height: 1.4;
            }
            
            /* 优化按钮触摸区域 */
            .view-all-btn {
                min-height: 48px;
                padding: 14px 28px;
                font-size: 1.05rem;
                margin: 20px auto;
            }

            .search-input {
                user-select: text;
            }
        }
        
        /* 小屏手机适配 */
        @media (max-width: 480px) {
            .container {
                padding: 12px;
            }

            .header {
                margin-bottom: 20px;
            }

            .header h1 {
                font-size: 1.6rem;
            }

            .search-container, .top-three-container, .search-results {
                padding: 16px;
                margin-bottom: 16px;
            }
            
            .mobile-card {
                margin-bottom: 10px;
                padding: 16px;
                border-radius: 12px;
                user-select: none;
                transition: all 0.2s ease;
            }

            .mobile-card:active, .mobile-card.touching {
                background: #f0f0f0;
                transform: scale(0.98);
            }

            .podium-item.touching {
                transform: scale(0.98);
                opacity: 0.8;
            }
            
            .mobile-rank {
                font-size: 1.25rem;
            }
            
            .mobile-name {
                font-size: 1.05rem;
                line-height: 1.3;
            }
            
            .mobile-details {
                gap: 10px;
                margin-top: 12px;
            }

            .mobile-detail-label {
                font-size: 0.75rem;
            }

            .mobile-detail-value {
                font-size: 0.85rem;
            }

            .result-item {
                padding: 14px;
            }

            .section-title {
                font-size: 1.15rem;
                margin-bottom: 16px;
            }

            .view-all-btn {
                width: 100%;
                max-width: 280px;
            }
        }

        /* 超小屏适配 */
        @media (max-width: 360px) {
            .container {
                padding: 10px;
            }

            .search-container, .top-three-container, .search-results {
                padding: 14px;
            }

            .mobile-card {
                padding: 14px;
            }

            .mobile-card-header {
                margin-bottom: 8px;
            }

            .mobile-details {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .result-item {
                padding: 12px;
            }
        }
    </style>
</head>
<body>

<?php include 'modules/student_sidebar.php'; ?>

<div class="main-layout">
    <div class="container">
        <div class="header">
            <h1>首页</h1>
            <p>操行分管理系统 | Behavior Score Management System</p>
        </div>

        <div class="search-container">
            <div class="search-box">
                <input type="text" class="search-input" placeholder="搜索学号或姓名" id="searchInput">
                <div class="search-icon">
                    <i class="fas fa-search"></i>
                </div>
            </div>
        </div>

        <div class="search-results" id="searchResults">
            <div class="search-results-header">
                <div class="section-title">
                    <i class="fas fa-search"></i>
                    搜索结果
                </div>
            </div>
            <div class="search-results-content">
                <div id="searchResultsList"></div>
            </div>
        </div>

        <div class="top-three-container" id="topThreeContainer">
            <div class="section-title">
                <i class="fas fa-trophy"></i>
                前三名排行榜
            </div>

            <?php if (empty($topStudents)): ?>
            <div class="empty-state">
                <i class="fas fa-users-slash"></i>
                <p>暂无学生数据</p>
                <span>系统中还没有学生信息</span>
            </div>
            <?php else: ?>
            <div class="podium">
                <?php foreach ($topStudents as $index => $student): ?>
                <div class="podium-item <?php echo $index == 0 ? 'first' : ($index == 1 ? 'second' : 'third'); ?>" data-student-id="<?php echo htmlspecialchars($student['student_id']); ?>">
                    <div class="podium-base">
                        <div class="rank-number"><?php echo $index + 1; ?></div>
                    </div>
                    <div class="student-info">
                        <div class="student-name"><?php echo htmlspecialchars($student['name']); ?></div>
                        <div class="student-id"><?php echo htmlspecialchars($student['student_id']); ?></div>
                        <?php 
                        $score = $student['current_score'];
                        if ($score >= 90) {
                            echo '<span class="score-badge score-excellent">' . $score . '分</span>';
                        } elseif ($score >= 70) {
                            echo '<span class="score-badge score-good">' . $score . '分</span>';
                        } elseif ($score >= 60) {
                            echo '<span class="score-badge score-average">' . $score . '分</span>';
                        } else {
                            echo '<span class="score-badge score-poor">' . $score . '分</span>';
                        }
                        ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="mobile-top-three">
                <?php foreach ($topStudents as $index => $student): ?>
                <div class="mobile-card rank-<?php echo $index + 1; ?>" data-student-id="<?php echo htmlspecialchars($student['student_id']); ?>">
                    <div class="mobile-card-header">
                        <div class="mobile-rank rank-<?php echo $index + 1; ?>">
                            <?php if ($index < 3): ?>
                                <i class="fa-solid fa-medal"></i>
                            <?php endif; ?>
                            #<?php echo $index + 1; ?>
                        </div>
                        <div class="mobile-name"><?php echo htmlspecialchars($student['name']); ?></div>
                    </div>
                    <div class="mobile-details">
                        <div class="mobile-detail-item">
                            <div class="mobile-detail-label">学号</div>
                            <div class="mobile-detail-value"><?php echo htmlspecialchars($student['student_id']); ?></div>
                        </div>
                        <div class="mobile-detail-item">
                            <div class="mobile-detail-label">操行分</div>
                            <div class="mobile-detail-value"><?php echo $student['current_score']; ?>分</div>
                        </div>
                        <div class="mobile-detail-item">
                            <div class="mobile-detail-label">等级</div>
                            <div class="mobile-detail-value">
                                <?php 
                                $score = $student['current_score'];
                                if ($score >= 90) {
                                    echo '<span class="score-badge score-excellent">优秀</span>';
                                } elseif ($score >= 70) {
                                    echo '<span class="score-badge score-good">良好</span>';
                                } elseif ($score >= 60) {
                                    echo '<span class="score-badge score-average">及格</span>';
                                } else {
                                    echo '<span class="score-badge score-poor">不及格</span>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <a href="page/student/rank.php" class="view-all-btn">
                <i class="fas fa-list"></i>
                查看全部排名
            </a>
        </div>
    </div>
</div>

<script>
$(function() {
    let searchTimeout;
    let isSearching = false;
    const $searchInput = $('#searchInput');
    const $searchResults = $('#searchResults');
    const $topThreeContainer = $('#topThreeContainer');
    const $resultsList = $('#searchResultsList');
    
    function getScoreClass(score) {
        return score >= 90 ? 'score-excellent' : score >= 70 ? 'score-good' : score >= 60 ? 'score-average' : 'score-poor';
    }
    
    function showLoading() {
        $resultsList.html('<div class="loading-state"><i class="fas fa-spinner fa-spin"></i><p>搜索中...</p></div>');
        $searchResults.show();
        $topThreeContainer.hide();
    }
    
    function hideLoading() {
        isSearching = false;
    }
    
    function performSearch() {
        const searchTerm = $searchInput.val().trim();
        if (!searchTerm) {
            $searchResults.hide();
            $topThreeContainer.show();
            hideLoading();
            return;
        }
        
        if (isSearching) return;
        isSearching = true;
        showLoading();
        
        // 设置最大时间上限，防止无限转圈
        const timeoutId = setTimeout(function() {
            isSearching = false;
            hideLoading();
            $resultsList.html('<div class="error-state"><i class="fas fa-exclamation-triangle"></i><p>搜索失败，请重试</p></div>');
        }, 8000); // 8秒最大时间上限
        
        $.get('index.php', { ajax: 1, action: 'search', search: searchTerm })
            .done(function(response) {
                clearTimeout(timeoutId);
                hideLoading();
                if (Array.isArray(response)) {
                    displaySearchResults(response);
                    $topThreeContainer.hide();
                    $searchResults.show();
                } else if (response && typeof response === 'object' && response.success === false) {
                    // 显示后端返回的具体错误信息
                    $resultsList.html(`<div class="error-state"><i class="fas fa-exclamation-triangle"></i><p>${response.error}</p></div>`);
                } else {
                    $resultsList.html('<div class="error-state"><i class="fas fa-exclamation-triangle"></i><p>搜索失败，请稍后再试</p></div>');
                }
            })
            .fail(function() {
                clearTimeout(timeoutId);
                hideLoading();
                $resultsList.html('<div class="error-state"><i class="fas fa-exclamation-triangle"></i><p>网络错误，请检查连接</p></div>');
            });
    }
    
    function displaySearchResults(students) {
        if (!students.length) {
            $resultsList.html('<div class="empty-state"><i class="fas fa-search"></i><p>未找到匹配的学生</p></div>');
            return;
        }
        
        const html = students.map((student, index) => `
            <div class="result-item" data-student-id="${student.student_id}">
                <div class="result-left">
                    <div class="result-rank">${index + 1}</div>
                    <div class="result-info">
                        <h4>${student.name}</h4>
                        <p>学号: ${student.student_id}</p>
                    </div>
                </div>
                <div class="result-right">
                    <span class="score-badge ${getScoreClass(student.current_score)}">${student.current_score}分</span>
                </div>
            </div>
        `).join('');
        $resultsList.html(html);
    }
    
    $searchInput.on({
        input: function() { 
            clearTimeout(searchTimeout);
            const searchTerm = $(this).val().trim();
            if (searchTerm.length === 0) {
                $searchResults.hide();
                $topThreeContainer.show();
                hideLoading();
                return;
            }
            if (searchTerm.length < 2) return; // 至少2个字符才开始搜索
            searchTimeout = setTimeout(performSearch, 200); // 减少防抖延迟
        },
        keypress: function(e) { 
            if (e.which === 13) {
                clearTimeout(searchTimeout);
                performSearch();
                isMobile && $(this).blur();
            }
        },
        focus: function() {
            isMobile && setTimeout(() => {
                this.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 300);
        },
        blur: function() { 
            if (!$searchInput.val().trim()) {
                setTimeout(function() { 
                    $searchResults.hide(); 
                    $topThreeContainer.show();
                    hideLoading();
                }, 200);
            }
        }
    });
    
    // 防止iOS键盘弹出时页面缩放
    /iPhone|iPad|iPod/i.test(navigator.userAgent) && $(window).on({
        focusin: () => $('meta[name=viewport]').attr('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no'),
        focusout: () => $('meta[name=viewport]').attr('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover')
    });
    
    // 点击事件处理
    function navigateToProfile(element) {
        const studentId = $(element).data('student-id');
        
        if (studentId) {
            const targetUrl = `page/student/profile.php?student_id=${studentId}`;
            window.location.href = targetUrl;
        }
    }
    
    // 点击事件
    $(document).on('click', '.result-item, .podium-item, .mobile-card', function(e) {
        navigateToProfile(this);
    });
});
</script>
</body>
</html>