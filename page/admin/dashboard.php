<?php
// 定义常量以允许包含的文件访问
define('INCLUDED_FROM_APP', true);

/**
 * 管理员仪表板页面
 * 需要管理员权限才能访问
 */
session_start();
// 引入数据库连接类
require_once '../../functions/database.php';

// 读取配置函数
function getConfig($key) {
    static $config = null;
    if ($config === null) {
        $config_file = '../../config/config.json';
        if (!file_exists($config_file)) {
            return '';
        }
        $config = json_decode(file_get_contents($config_file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return '';
        }
    }
    
    // 支持点号分隔的键路径，如 'class.name'
    $keys = explode('.', $key);
    $value = $config;
    foreach ($keys as $k) {
        if (!isset($value[$k])) {
            return '';
        }
        $value = $value[$k];
    }
    return $value;
}

// 初始化数据库连接
$db = new Database();

// 验证用户是否已登录
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    // 尝试自动登录
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        
        try {
            $redis = $db->getRedisConnection('session');
            $user_data = $redis->get("remember_token:" . $token);
            
            if ($user_data) {
                $user_info = json_decode($user_data, true);
                
                // 检查token是否过期，使用expire_time字段
                if (isset($user_info['expire_time']) && $user_info['expire_time'] > time() && $user_info['user_type'] === 'admin') {
                    // 只允许管理员自动登录到admin页面
                    $pdo = $db->getMysqlConnection();
                    $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
                    $stmt->execute([$user_info['username']]);
                    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                    $db->releaseMysqlConnection($pdo);
                    
                    if ($admin) {
                        $_SESSION['user_id'] = $admin['id'];
                        $_SESSION['username'] = $user_info['username'];
                        $_SESSION['user_type'] = 'admin';
                        $_SESSION['logged_in'] = true;
                        $_SESSION['login_time'] = time();
                    }
                } else {
                    // token过期，删除
                    $redis->del("remember_token:" . $token);
                    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
                }
            } else {
                // token不存在，删除cookie
                setcookie('remember_token', '', time() - 3600, '/', '', false, true);
            }
            $db->releaseRedisConnection($redis);
        } catch (Exception $e) {
            // Redis连接失败，忽略自动登录
            error_log("Redis连接失败: " . $e->getMessage());
        }
    }
    
    // 如果仍然没有登录，重定向到登录页面
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        header('Location: ../../login.php?message=' . urlencode('会话已失效，请重新登录'));
        exit;
    }
}

// 验证用户角色是否为管理员
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    // 如果是班级管理人角色，重定向到班级管理人仪表板
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'committee') {
        header('Location: ../committee/dashboard.php');
        exit;
    }
    
    // 如果不是管理员也不是班级管理人，清理session和cookie
    session_destroy();
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        try {
            $redis = $db->getRedisConnection('session');
            $redis->del("remember_token:" . $token);
        } catch (Exception $e) {
            error_log("清理Redis token失败: " . $e->getMessage());
        }
        setcookie('remember_token', '', time() - 3600, '/', '', false, true);
    }
    
    // 重定向到登录页面
    header('Location: ../../login.php?message=' . urlencode('您暂无访问权限'));
    exit;
}

// 获取仪表板数据函数
function getDashboardData() {
    global $db;
    try {
        $pdo = $db->getMysqlConnection();
        
        // 一次性查询所有统计数据
        $stmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM students) as total_students,
                (SELECT COUNT(*) FROM appeals WHERE status = 'pending') as pending_appeals,
                (SELECT COALESCE(ROUND(AVG(current_score), 1), 0) FROM students) as avg_score,
                (SELECT COUNT(*) FROM students WHERE current_score < 60) as failing_students,
                (SELECT COUNT(*) FROM students WHERE current_score >= 90) as excellent_students
        ");
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 查询最近活动
        $stmt = $pdo->prepare("
            SELECT s.name as student_name, cr.score_change, cr.score_after, cr.reason, cr.operator_name, cr.created_at, cr.status
            FROM conduct_records cr
            JOIN students s ON cr.student_id = s.id
            ORDER BY cr.created_at DESC, cr.id DESC LIMIT 10
        ");
        $stmt->execute();
        $stats['recent_activities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stats['success'] = true;
        
        // 释放数据库连接
        $db->releaseMysqlConnection($pdo);
        
        return $stats;
    } catch(PDOException $e) {
        // 如果有连接，确保释放
        if (isset($db) && isset($pdo)) {
            $db->releaseMysqlConnection($pdo);
        }
        return ['success' => false, 'error' => '数据获取失败'];
    }
}

// AJAX请求处理
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode(getDashboardData());
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
     <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操行分管理系统 - 管理员仪表板</title>
    <link rel="icon" type="image/x-icon" href="../../favicon.ico">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <?php include '../../modules/notification.php'; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            color: #333;
        }

        .main-layout {
            padding-left: 200px;
        }
        
        /* 移动端功能限制提示 */
        .mobile-notice {
            background: linear-gradient(135deg, #ff9a56, #ff6b35);
            color: white;
            padding: 12px 20px;
            text-align: center;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(255, 107, 53, 0.3);
            border-radius: 8px;
            margin: 20px 0;
            display: none;
            position: relative;
            z-index: 100;
        }
        
        .mobile-notice i {
            margin-right: 8px;
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

        /* 统计卡片网格 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border-left: 4px solid #3498db;
        }

        .stat-card.warning { border-left-color: #f39c12; }
        .stat-card.danger { border-left-color: #e74c3c; }
        .stat-card.success { border-left-color: #27ae60; }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 1rem;
            color: #7f8c8d;
            margin-bottom: 5px;
        }

        .stat-description {
            font-size: 0.9rem;
            color: #95a5a6;
        }

        /* 最近活动表格 */
        .recent-activities {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            max-height: 500px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .recent-activities .section-title {
            padding: 25px 25px 0 25px;
            background: white;
            position: sticky;
            top: 0;
            z-index: 10;
            margin-bottom: 0;
        }

        .activity-scroll-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px 25px 25px 25px;
        }

        .activity-scroll-container::-webkit-scrollbar {
            width: 6px;
        }

        .activity-scroll-container::-webkit-scrollbar-track,
        .activity-scroll-container::-webkit-scrollbar-thumb {
            border-radius: 3px;
        }

        .activity-scroll-container::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .activity-scroll-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
        }

        .activity-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
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

        .activity-container {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #95a5a6;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
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

        .activity-card {
            background: white;
            border-radius: 10px;
            padding: 16px 20px;
            border-left: 5px solid #dee2e6;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .activity-card:hover {
            background: #fafbfc;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .activity-card.positive {
            border-left-color: #27ae60;
            background: linear-gradient(135deg, rgba(39, 174, 96, 0.08) 0%, white 100%);
        }
        .activity-card.negative {
            border-left-color: #e74c3c;
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.08) 0%, white 100%);
        }
        .activity-card.invalid {
            border-left-color: #e74c3c;
            position: relative;
            opacity: 0.6;
        }
        .activity-card.invalid .activity-single-line {
            filter: blur(1px);
        }
        .activity-card.invalid::before {
            content: '----- 已失效 -----';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 255, 255, 0.98);
            color: #e74c3c;
            padding: 8px 16px;
            font-size: 0.9rem;
            font-weight: bold;
            z-index: 10;
            white-space: nowrap;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            filter: none;
            backdrop-filter: none;
        }

        .activity-single-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .activity-left-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }

        .student-name {
            font-weight: 700;
            color: #2c3e50;
            font-size: 1.05rem;
            white-space: nowrap;
        }

        .score-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 700;
            color: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
            white-space: nowrap;
        }

        .score-badge.positive { background: linear-gradient(135deg, #27ae60, #2ecc71); }
        .score-badge.negative { background: linear-gradient(135deg, #e74c3c, #c0392b); }
        .score-badge.invalid { 
            background: linear-gradient(135deg, #e74c3c, #c0392b); 
            opacity: 0.6;
        }

        .activity-reason {
            color: #2c3e50;
            font-size: 0.95rem;
            font-weight: 600;
            flex: 1;
            margin: 0 10px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .activity-right-info {
            display: flex;
            align-items: center;
            gap: 12px;
            white-space: nowrap;
        }

        .activity-score-info {
            color: #3498db;
            font-weight: 700;
            font-size: 0.9rem;
            background: rgba(52, 152, 219, 0.1);
            padding: 4px 8px;
            border-radius: 6px;
        }

        .activity-operator, .activity-time {
            font-size: 0.85rem;
            color: #7f8c8d;
            font-weight: 500;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 6px;
        }

        .activity-operator {
            border: 1px solid #e9ecef;
        }

        .activity-operator i {
            margin-right: 4px;
            color: #6c757d;
        }

        .activity-time {
            font-weight: 600;
        }

        /* 班级信息卡片 */
        .class-info {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            position: relative;
        }

        .refresh-btn {
            position: absolute;
            top: 25px;
            right: 25px;
            background: #f8f9fa;
            color: #6c757d;
            padding: 8px 12px;
            text-decoration: none;
            border-radius: 6px;
            font-size: 12px;
            border: 1px solid #dee2e6;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .refresh-btn:hover,
        .refresh-btn.disabled,
        .refresh-btn.disabled:hover {
            background: #e9ecef;
        }
        
        .refresh-btn.disabled {
            color: #adb5bd;
            cursor: not-allowed;
        }

        .class-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .class-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3498db, #2980b9);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
        }

        .class-details h3 {
            font-size: 1.4rem;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .class-details p {
            color: #7f8c8d;
            font-size: 0.95rem;
        }

        .class-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 20px;
        }

        .class-stat {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .class-stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .class-stat-label {
            font-size: 0.85rem;
            color: #7f8c8d;
        }
        
        /* 移动端适配 */
        @media (max-width: 768px) {
            .mobile-notice {
                display: block;
                margin: 0 0 25px 0;
                border-radius: 6px;
                font-size: 13px;
                padding: 10px 15px;
            }
            
            .main-layout {
                padding-left: 0;
                padding-top: 0;
            }
            
            .container {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 1.8rem;
                margin-bottom: 8px;
            }
            
            .header p {
                font-size: 0.95rem;
            }
            
            /* 统计卡片移动端优化 */
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
                margin-bottom: 25px;
            }
            
            .stat-card {
                padding: 20px 15px;
                text-align: center;
            }
            
            .stat-number {
                font-size: 2rem;
                margin-bottom: 6px;
            }
            
            .stat-label {
                font-size: 0.9rem;
                margin-bottom: 4px;
            }
            
            .stat-description {
                font-size: 0.8rem;
                line-height: 1.3;
            }
            
            /* 班级信息移动端优化 */
            .class-info {
                padding: 20px 15px;
                margin-bottom: 25px;
            }
            
            .refresh-btn {
                position: static;
                display: block;
                width: 100%;
                margin-bottom: 15px;
                text-align: center;
                padding: 10px;
            }
            
            .class-header {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .class-avatar {
                width: 50px;
                height: 50px;
                font-size: 20px;
            }
            
            .class-details h3 {
                font-size: 1.2rem;
                margin-bottom: 8px;
            }
            
            .class-details p {
                font-size: 0.9rem;
            }
            
            .class-stats {
                grid-template-columns: 1fr;
                gap: 10px;
                margin-top: 15px;
            }
            
            .class-stat {
                padding: 12px;
            }
            
            .class-stat-number {
                font-size: 1.3rem;
            }
            
            .class-stat-label {
                font-size: 0.8rem;
            }
            
            /* 最近活动移动端优化 */
            .recent-activities {
                max-height: 400px;
            }
            
            .section-title {
                font-size: 1.1rem;
                padding: 20px 15px 0 15px;
            }
            
            .activity-scroll-container {
                padding: 15px;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                scroll-behavior: smooth;
            }
            
            .activity-card {
                padding: 12px 15px;
                border-radius: 8px;
            }
            
            .activity-single-line {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .activity-left-info {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            
            .student-name {
                font-size: 1rem;
                text-align: center;
            }
            
            .score-badge {
                align-self: center;
                font-size: 0.9rem;
                padding: 6px 12px;
            }
            
            .activity-reason {
                text-align: center;
                white-space: normal;
                margin: 0;
                font-size: 0.9rem;
                line-height: 1.4;
            }
            
            .activity-right-info {
                flex-direction: column;
                gap: 8px;
                align-items: stretch;
            }
            
            .activity-score-info,
            .activity-operator,
            .activity-time {
                text-align: center;
                font-size: 0.8rem;
                padding: 6px 10px;
            }
            
            .empty-state {
                padding: 30px 15px;
            }
            
            .empty-state i {
                font-size: 2.5rem;
                margin-bottom: 12px;
            }
            
            .empty-state p {
                font-size: 1rem;
            }
            
            .empty-state span {
                font-size: 0.85rem;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .stat-card {
                padding: 18px 12px;
            }
            
            .stat-number {
                font-size: 1.8rem;
            }
            
            .header h1 {
                font-size: 1.5rem;
            }
            
            .class-info,
            .activity-scroll-container {
                padding: 15px 12px;
            }
            
            .activity-card {
                padding: 10px 12px;
            }
        }

    </style>
</head>
<body>

<?php if (!isset($_GET['ajax'])) { include '../../modules/admin_sidebar.php'; } ?>

<?php
// 获取页面初始数据
if (!isset($_GET['ajax'])) {
    $data = getDashboardData();
    if ($data['success']) {
        extract($data);
    } else {
        $total_students = $pending_appeals = $avg_score = $failing_students = $excellent_students = "数据获取异常";
        $recent_activities = [];
    }
}
?>

<div class="main-layout">
    <div class="container">
        <div class="header">
            <h1>管理员仪表板</h1>
            <p>操行分管理系统 | Behavior Score Management System</p>
        </div>
        
        <!-- 移动端功能限制提示 -->
        <div class="mobile-notice">
            <i class="fas fa-mobile-alt"></i>
            移动端仅支持操行分管理与申诉管理，其他功能请前往电脑端操作。
        </div>

        <!-- 班级信息 -->
        <div class="class-info">
            <a href="javascript:void(0)" class="refresh-btn"><i class="fas fa-sync-alt"></i> 刷新</a>
            <div class="class-header">
                <div class="class-avatar">计</div>
                <div class="class-details">
                    <h3><?php echo getConfig('class.name'); ?></h3>
                    <p>班主任: <?php echo getConfig('class.teacher'); ?> | 学生人数: <?php echo $total_students; ?>人</p>
                </div>
            </div>
            <div class="class-stats">
                <div class="class-stat">
                    <div class="class-stat-number"><?php echo $total_students; ?></div>
                    <div class="class-stat-label">班级总人数</div>
                </div>
                <div class="class-stat">
                    <div class="class-stat-number"><?php echo $pending_appeals; ?></div>
                    <div class="class-stat-label">申诉待处理</div>
                </div>
            </div>
        </div>

        <!-- 统计卡片 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $avg_score; ?></div>
                <div class="stat-label">班级平均分</div>
                <div class="stat-description">所有学生的平均操行分</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-number"><?php echo $pending_appeals; ?></div>
                <div class="stat-label">待审核申诉</div>
                <div class="stat-description">需要处理的操行分申请</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-number"><?php echo $failing_students; ?></div>
                <div class="stat-label">不及格学生</div>
                <div class="stat-description">操行分低于60分的学生</div>
            </div>
            <div class="stat-card success">
                <div class="stat-number"><?php echo $excellent_students; ?></div>
                <div class="stat-label">优秀学生</div>
                <div class="stat-description">操行分90分以上的学生</div>
            </div>
        </div>

        <!-- 最近活动 -->
        <div class="recent-activities">
            <div class="section-title">
                <i class="fa-solid fa-clock"></i>
                最近活动
            </div>

            <div class="activity-scroll-container">
                <div class="activity-container">
                    <?php if (empty($recent_activities)): ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-calendar-xmark"></i>
                        <p>暂无最近活动记录</p>
                        <span>系统中还没有操行分变动记录</span>
                    </div>
                    <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                        <?php 
                        $cardClass = '';
                        if ($activity['status'] === 'invalid') {
                            $cardClass = 'invalid';
                        } else {
                            $cardClass = $activity['score_change'] > 0 ? 'positive' : 'negative';
                        }
                        ?>
                        <div class="activity-card <?php echo $cardClass; ?>">
                            <div class="activity-single-line">
                                <div class="activity-left-info">
                                    <span class="student-name"><?php echo htmlspecialchars($activity['student_name']); ?></span>
                                    <span class="score-badge <?php echo $activity['score_change'] > 0 ? 'positive' : 'negative'; ?>">
                                        <?php echo $activity['score_change'] > 0 ? '+' : ''; ?><?php echo $activity['score_change']; ?>分
                                    </span>
                                    <div class="activity-reason">
                                        <?php echo htmlspecialchars($activity['reason']); ?>
                                    </div>
                                </div>
                                <div class="activity-right-info">
                                    <div class="activity-operator">
                                         <i class="fa-solid fa-user"></i>
                                         操作人：<?php echo htmlspecialchars($activity['operator_name']); ?>
                                     </div>
                                    <span class="activity-time"><?php echo date('Y年m月d日 H:i', strtotime($activity['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// 配置信息
const CONFIG = {
    classTeacher: '<?php echo getConfig("class.teacher"); ?>'
};

// 刷新仪表板数据
let isRefreshing = false;
function refreshDashboard() {
    if (isRefreshing) return;
    
    isRefreshing = true;
    const refreshBtn = $('.refresh-btn');
    const originalText = refreshBtn.html();
    let countdown = 5;
    
    refreshBtn.addClass('disabled').css('pointer-events', 'none');
    
    const updateCountdown = () => {
        refreshBtn.html(`<i class="fas fa-sync-alt fa-spin"></i> ${countdown}秒后可刷新`);
        countdown--;
        
        if (countdown >= 0) {
            setTimeout(updateCountdown, 1000);
        } else {
            refreshBtn.removeClass('disabled').css('pointer-events', 'auto').html(originalText);
            isRefreshing = false;
        }
    };
    
    updateCountdown();
    
    $.ajax({
        url: 'dashboard.php?ajax=1',
        type: 'GET',
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                // 更新统计数据
                var stats = [data.total_students, data.pending_appeals, data.avg_score, data.pending_appeals, data.failing_students, data.excellent_students];
                $('.class-stat-number, .stat-number').each(function(i) {
                    $(this).text(stats[i]);
                });
                
                // 更新班级信息中的学生人数
                $('.class-details p').html('班主任: ' + CONFIG.classTeacher + ' | 学生人数: ' + data.total_students + '人');
                
                // 更新最近活动
                updateRecentActivities(data.recent_activities);
                
                notification.success('数据刷新成功');
            } else {
                notification.error('数据获取失败，请稍后重试');
            }
        },
        error: function() {
            notification.error('网络连接异常，请检查网络后重试');
        }
    });
}

// 更新最近活动列表
function updateRecentActivities(activities) {
    const container = $('.activity-container');
    
    if (activities.length === 0) {
        container.html(`
            <div class="empty-state">
                <i class="fa-solid fa-calendar-xmark"></i>
                <p>暂无最近活动记录</p>
                <span>系统中还没有操行分变动记录</span>
            </div>`);
        return;
    }
    
    let html = '';
    activities.forEach(activity => {
        let cardClass = '';
        if (activity.status === 'invalid') {
            cardClass = 'invalid';
        } else {
            const isPositive = activity.score_change > 0;
            cardClass = isPositive ? 'positive' : 'negative';
        }
        
        const date = new Date(activity.created_at);
        const timeStr = `${date.getFullYear()}年${(date.getMonth() + 1).toString().padStart(2, '0')}月${date.getDate().toString().padStart(2, '0')}日 ${date.getHours().toString().padStart(2, '0')}:${date.getMinutes().toString().padStart(2, '0')}`;
            
            html += `
                <div class="activity-card ${cardClass}">
                    <div class="activity-single-line">
                        <div class="activity-left-info">
                            <span class="student-name">${activity.student_name}</span>
                            <span class="score-badge ${activity.status === 'invalid' ? 'invalid' : (activity.score_change > 0 ? 'positive' : 'negative')}">${activity.score_change > 0 ? '+' : ''}${activity.score_change}分</span>
                            <div class="activity-reason">${activity.reason}</div>
                        </div>
                        <div class="activity-right-info">
                            <div class="activity-operator"><i class="fa-solid fa-user"></i> 操作人：${activity.operator_name}</div>
                            <span class="activity-time">${timeStr}</span>
                        </div>
                    </div>
                </div>`;
    });
    container.html(html);
}

// 页面加载完成后的基本初始化
$(document).ready(function() {
    $('.refresh-btn').click(refreshDashboard);
    
    // 检查页面数据是否正常加载
    if ($('.class-stat-number').first().text() === '获取异常') {
        notification.warning('数据获取失败，请稍后重试');
    }
});
</script>
</body>
</html>