<?php
// 定义常量以允许包含的文件访问
define('INCLUDED_FROM_APP', true);

/**
 * 操行分记录管理页面
 * 需要管理员权限才能访问
 */
session_start();
// 引入数据库连接类
require_once '../../functions/database.php';

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
            $db->releaseRedisConnection($redis);
        } catch (Exception $e) {
            error_log("清理Redis token失败: " . $e->getMessage());
        }
        setcookie('remember_token', '', time() - 3600, '/', '', false, true);
    }
    
    // 重定向到登录页面
    header('Location: ../login.php?message=' . urlencode('您暂无访问权限'));
    exit;
}

// 数据库连接
// 获取数据库连接
function getDatabaseConnection() {
    global $db;
    return $db->getMysqlConnection();
}

// 释放数据库连接
function releaseDatabaseConnection($pdo) {
    global $db;
    $db->releaseMysqlConnection($pdo);
}

// 数据库连接将在每个操作中独立获取和释放

// 统一的JSON响应函数
function sendJsonResponse($success, $data = null, $message = '', $extra = []) {
    $response = [
        'success' => $success,
        'data' => $data,
        'message' => $message
    ];
    
    // 合并额外参数
    if (!empty($extra)) {
        $response = array_merge($response, $extra);
    }
    
    echo json_encode($response);
    exit;
}

// 统一的数据库查询函数
function executeQuery($pdo, $sql, $params = [], $fetchMode = PDO::FETCH_ASSOC) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (Exception $e) {
        throw new Exception('数据库操作失败: ' . $e->getMessage());
    }
}

// 获取学生当前分数
function getStudentScore($pdo, $student_id) {
    $stmt = executeQuery($pdo, "SELECT current_score FROM students WHERE id = ?", [$student_id]);
    $score = $stmt->fetchColumn();
    if ($score === false) {
        throw new Exception('学生不存在');
    }
    return $score;
}

// 更新学生分数
function updateStudentScore($pdo, $student_id, $new_score) {
    executeQuery($pdo, "UPDATE students SET current_score = ? WHERE id = ?", [$new_score, $student_id]);
}

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {

            
        case 'get_students':
            $pdo = null;
            try {
                $pdo = getDatabaseConnection();
                $stmt = executeQuery($pdo, "SELECT id, student_id, name, current_score, status FROM students ORDER BY student_id");
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                releaseDatabaseConnection($pdo);
                sendJsonResponse(true, $students);
            } catch (Exception $e) {
                if ($pdo) releaseDatabaseConnection($pdo);
                sendJsonResponse(false, null, $e->getMessage());
            }
            break;
            
        case 'get_conduct_rules':
            $pdo = null;
            try {
                $pdo = getDatabaseConnection();
                $stmt = executeQuery($pdo, "SELECT id, name, description, type, score_value FROM conduct_rules ORDER BY type, name");
                $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                releaseDatabaseConnection($pdo);
                sendJsonResponse(true, $rules);
            } catch (Exception $e) {
                if ($pdo) releaseDatabaseConnection($pdo);
                sendJsonResponse(false, null, $e->getMessage());
            }
            break;
            
        case 'get_conduct_records':
            $pdo = null;
            try {
                $pdo = getDatabaseConnection();
                $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
                $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 10;
                $search = isset($_POST['search']) ? trim($_POST['search']) : '';
                
                $where_clause = "WHERE 1=1";
                $params = [];
                
                if (!empty($search)) {
                    $where_clause .= " AND (s.name LIKE ? OR s.student_id LIKE ? OR cr.reason LIKE ?)";
                    $search_param = "%{$search}%";
                    $params = [$search_param, $search_param, $search_param];
                }
                
                // 获取总记录数
                $count_sql = "SELECT COUNT(*) FROM conduct_records cr 
                             JOIN students s ON cr.student_id = s.id 
                             {$where_clause}";
                $count_stmt = executeQuery($pdo, $count_sql, $params);
                $total = $count_stmt->fetchColumn();
                
                // 数据库层面分页：使用 LIMIT 和 OFFSET
                $offset = ($page - 1) * $limit;
                $sql = "SELECT cr.*, s.name as student_name, s.student_id as student_number,
                              DATE_FORMAT(cr.created_at, '%Y-%m-%d %H:%i') as formatted_date,
                              cr.status as record_status
                       FROM conduct_records cr 
                       JOIN students s ON cr.student_id = s.id 
                       {$where_clause} 
                       ORDER BY cr.created_at DESC, cr.id DESC
                       LIMIT {$limit} OFFSET {$offset}";
                
                // LIMIT和OFFSET直接拼接到SQL中，不使用参数绑定
                
                $stmt = executeQuery($pdo, $sql, $params);
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                releaseDatabaseConnection($pdo);
                sendJsonResponse(true, $records, '', ['total' => $total, 'page' => $page, 'limit' => $limit]);
            } catch (Exception $e) {
                if ($pdo) releaseDatabaseConnection($pdo);
                sendJsonResponse(false, null, $e->getMessage());
            }
            break;
            
        case 'add_conduct_record':
            $pdo = null;
            try {
                $pdo = getDatabaseConnection();
                $student_ids = json_decode($_POST['student_ids'], true);
                $score_change = (float)$_POST['score_change'];
                $reason = trim($_POST['reason']);
                $operator_name = $_SESSION['username'] ?? '管理员';
                
                if (empty($student_ids) || empty($reason)) {
                    throw new Exception('参数不完整');
                }
                
                $pdo->beginTransaction();
                
                foreach ($student_ids as $student_id) {
                    $current_score = getStudentScore($pdo, $student_id);
                    $new_score = $current_score + $score_change;
                    
                    updateStudentScore($pdo, $student_id, $new_score);
                    
                    // 插入记录
                    executeQuery($pdo, "
                        INSERT INTO conduct_records (student_id, reason, score_change, score_after, operator_name) 
                        VALUES (?, ?, ?, ?, ?)
                    ", [$student_id, $reason, $score_change, $new_score, $operator_name]);
                }
                
                $pdo->commit();
                
                // 清空Redis缓存数据库
                try {
                    $db = new Database();
                    $db->clearCacheForDataUpdate();
                } catch (Exception $cacheError) {
                    error_log('清空Redis缓存失败: ' . $cacheError->getMessage());
                    // 缓存清理失败不影响主要操作，只记录日志
                }
                
                releaseDatabaseConnection($pdo);
                sendJsonResponse(true, null, '操行分记录添加成功');
            } catch (Exception $e) {
                if ($pdo && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($pdo) releaseDatabaseConnection($pdo);
                sendJsonResponse(false, null, $e->getMessage());
            }
            break;
            
        case 'delete_conduct_records':
            $pdo = null;
            try {
                $pdo = getDatabaseConnection();
                $record_ids = json_decode($_POST['record_ids'], true);
                
                if (empty($record_ids) || !is_array($record_ids)) {
                    throw new Exception('参数不完整');
                }
                
                $pdo->beginTransaction();
                $deleted_count = 0;
                
                foreach ($record_ids as $record_id) {
                    // 获取记录信息
                    $stmt = executeQuery($pdo, "SELECT student_id, score_change FROM conduct_records WHERE id = ?", [$record_id]);
                    $record = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$record) {
                        continue; // 跳过不存在的记录，而不是抛出异常
                    }
                    
                    $current_score = getStudentScore($pdo, $record['student_id']);
                    
                    // 恢复分数（减去之前的变化）
                    $new_score = $current_score - $record['score_change'];
                    
                    updateStudentScore($pdo, $record['student_id'], $new_score);
                    
                    // 删除记录
                    $delete_stmt = executeQuery($pdo, "DELETE FROM conduct_records WHERE id = ?", [$record_id]);
                    if ($delete_stmt->rowCount() > 0) {
                        $deleted_count++;
                    }
                }
                
                $pdo->commit();
                
                // 清空Redis缓存数据库
                try {
                    $db = new Database();
                    $db->clearCacheForDataUpdate();
                } catch (Exception $cacheError) {
                    error_log('清空Redis缓存失败: ' . $cacheError->getMessage());
                    // 缓存清理失败不影响主要操作，只记录日志
                }
                
                releaseDatabaseConnection($pdo);
                sendJsonResponse(true, null, '记录删除成功，共删除 ' . $deleted_count . ' 条记录');
            } catch (Exception $e) {
                if ($pdo && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($pdo) releaseDatabaseConnection($pdo);
                sendJsonResponse(false, null, $e->getMessage());
            }
            break;
            
        case 'clear_cache':
            try {
                $db = new Database();
                $db->clearCacheForDataUpdate();
                sendJsonResponse(true, null, 'Redis缓存清空成功');
            } catch (Exception $e) {
                sendJsonResponse(false, null, 'Redis缓存清空失败: ' . $e->getMessage());
            }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操行分管理系统 - 操行分管理</title>
    <link rel="icon" type="image/x-icon" href="../../favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
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

        /* 标签页样式 */
        .tab-container {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }
        
        .tab-header {
            display: flex;
            border-bottom: 1px solid #e9ecef;
        }
        
        .tab-btn {
            flex: 1;
            padding: 15px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            color: #6c757d;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .tab-btn:hover {
            background: #f8f9fa;
            color: #495057;
        }
        
        .tab-btn.active {
            color: #007bff;
            background: #f8f9fa;
        }
        
        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: #007bff;
        }
        
        .tab-content {
            display: none;
            padding: 25px;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* 记录查看页面样式 */
        .records-view {
            min-height: 600px;
        }
        
        .search-bar {
            margin-bottom: 20px;
        }
        
        .search-controls {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        

        
        .search-input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .search-btn {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .search-btn:hover {
            background: #0056b3;
            transform: translateY(-1px);
        }
        
        .records-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .records-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .records-table th,
        .records-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        .records-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .records-table tr:hover {
            background: #f8f9fa;
        }
        
        .score-positive {
            color: #28a745;
            font-weight: 600;
        }
        
        .score-negative {
            color: #dc3545;
            font-weight: 600;
        }
        
        /* 无效记录样式 - 简洁的背景标识方式 */
        .record-invalid {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%) !important;
            opacity: 0.8;
        }
        
        .record-invalid td {
            color: #6c757d !important;
            text-decoration: line-through;
            position: relative;
        }
        
        .record-invalid td:nth-child(4) {
            position: relative;
        }
        
        .record-invalid td:nth-child(4)::after {
            content: ' (记录已失效)';
            color: #dc3545;
            font-weight: bold;
            font-size: 12px;
            text-decoration: none;
        }
        
        .record-invalid .score-positive,
        .record-invalid .score-negative {
            color: #6c757d !important;
            text-decoration: line-through;
        }
        
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
        }
        
        .pagination-info {
            font-size: 14px;
            color: #6c757d;
        }
        
        /* 通用按钮样式 */
        .table-actions {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .table-actions .btn {
            flex: 1;
            width: 100%;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700;
            text-align: center;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 140px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #6c757d !important;
            transform: none !important;
        }
        
        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
        }
        
        .btn:active:not(:disabled) {
            transform: translateY(0);
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover:not(:disabled) {
            background: #c82333;
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }
        
        .btn-danger:active:not(:disabled) {
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
        }
        
        /* 复选框样式 */
        .record-checkbox,
        #selectAllRecords {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: #007bff;
        }
        
        .records-table th:first-child,
        .records-table td:first-child {
            text-align: center;
            width: 40px;
        }
        
        .pagination-buttons {
            display: flex;
            gap: 5px;
        }
        
        .page-btn {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: white;
            color: #495057;
            cursor: pointer;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .page-btn:hover {
            background: #e9ecef;
        }
        
        .page-btn.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .page-ellipsis {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            color: #6c757d;
            font-size: 14px;
            font-weight: bold;
            user-select: none;
        }
        
        #perPageSelect {
            padding: 5px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            margin-left: 10px;
        }
        
        /* 修改操行分页面样式 */
        .main-content {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 20px;
            min-height: 600px;
            max-width: 100%;
            overflow: hidden;
        }

        .students-panel, .management-panel {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            min-width: 0;
            overflow: hidden;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #ecf0f1;
        }

        .panel-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .select-all-btn {
            background: #f8f9fa;
            color: #2c3e50;
            border: 1px solid #e9ecef;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .select-all-btn:hover {
            background: #3498db;
            color: white;
        }

        .students-list {
            max-height: 500px;
            overflow-y: auto;
            overflow-x: hidden;
            width: 100%;
        }

        /* 通用列表项样式 */
        .student-item, .rule-item {
            padding: 15px;
            margin-bottom: 8px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .student-item {
            display: flex;
            align-items: center;
        }

        .student-item:hover {
            background: #e3f2fd;
            border-color: #2196f3;
        }

        .student-item.selected {
            background: #e8f5e8;
            border-color: #27ae60;
        }

        .student-info {
            flex: 1;
            min-width: 0;
            overflow: hidden;
        }

        .student-name {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .student-details {
            font-size: 12px;
            color: #7f8c8d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }



        .rules-list {
            max-height: 500px;
            overflow-y: auto;
        }

        .rule-item:hover {
            background: #fff3cd;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .rule-item.selected {
            background: #fff3cd;
            border-color: #f39c12;
            box-shadow: 0 2px 8px rgba(243, 156, 18, 0.2);
        }

        .rule-name {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 4px;
        }

        /* 通用标签样式 */
        .rule-score, .student-score, .rule-type {
            display: inline-block;
            color: white;
            border-radius: 12px;
            font-weight: bold;
        }

        .rule-score {
            background: #e74c3c;
            padding: 2px 8px;
            font-size: 12px;
            margin-right: 8px;
        }

        .rule-score.positive {
            background: #27ae60;
        }

        .student-score {
            background: #3498db;
            padding: 4px 8px;
            font-size: 12px;
        }

        .rule-type {
            background: #95a5a6;
            padding: 2px 8px;
            font-size: 10px;
        }
        
        /* 学生状态标签样式 */
        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 8px;
        }
        
        .status-badge.active {
            background: #27ae60;
            color: white;
        }
        
        .status-badge.inactive {
            background: #e74c3c;
            color: white;
        }
        
        /* 停用学生样式 */
        .student-item.inactive-student {
            opacity: 0.7;
            background: #f8f9fa;
            border-color: #dee2e6;
        }
        
        .student-item.inactive-student:hover {
            background: #e9ecef;
            opacity: 0.8;
        }
        
        .student-item.inactive-student .student-name {
            color: #6c757d;
        }
        
        .student-item.inactive-student .student-details {
            color: #adb5bd;
        }

        .selected-rule-info {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
        }

        .rule-info-card {
            text-align: center;
        }

        .rule-info-header {
            margin-bottom: 8px;
        }

        .rule-info-header .rule-name {
            font-weight: bold;
            color: #2c3e50;
            margin-right: 10px;
        }

        .rule-info-header .rule-score {
            display: inline-block;
            background: #e74c3c;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        .rule-info-header .rule-score.positive {
            background: #27ae60;
        }

        .rule-info-type .rule-type {
            background: #3498db;
            font-size: 11px;
            padding: 3px 10px;
        }

        .custom-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .form-input {
            padding: 10px;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #3498db;
        }
        
        .form-input.input-error {
            border-color: #e74c3c;
            background-color: #fdf2f2;
            box-shadow: 0 0 0 2px rgba(231, 76, 60, 0.2);
        }
        
        .form-input.input-error:focus {
            border-color: #e74c3c;
            box-shadow: 0 0 0 2px rgba(231, 76, 60, 0.3);
        }

        .score-input {
            width: 100%;
        }

        .reason-input {
            min-height: 80px;
            resize: vertical;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .action-buttons .btn {
            padding: 15px 20px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            flex: 1;
        }

        .btn-primary {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        .btn-primary:hover {
            background: #2980b9;
            border-color: #2980b9;
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }

        .btn-secondary {
            background: #f8f9fa;
            color: #2c3e50;
            border-color: #e9ecef;
        }

        .btn-secondary:hover {
            background: #e9ecef;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .selected-count {
            background: #e8f5e8;
            color: #27ae60;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 15px;
            text-align: center;
            min-height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 1;
            transition: all 0.3s ease;
        }

        .no-selection {
            background: #f8f9fa;
            color: #7f8c8d;
            padding: 25px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            text-align: center;
            font-size: 14px;
        }

        .students-panel {
            position: relative;
        }

        /* 响应式设计 */
        @media (max-width: 768px) {
            .main-layout {
                padding-left: 0;
            }
            
            .main-content {
                grid-template-columns: 1fr;
                gap: 20px;
                max-width: 100vw;
                overflow-x: hidden;
            }
            
            .container {
                padding: 15px;
                max-width: 100vw;
                overflow-x: hidden;
            }
            
            .selected-count {
                margin-bottom: 15px;
            }
        }

        /* 移动端适配 */
        @media (max-width: 768px) {
            /* 基础布局 */
            .main-layout { padding-left: 0; }
            .container { padding: 10px; }
            .tab-content { padding: 15px; }
            .main-content { grid-template-columns: 1fr; gap: 15px; }
            
            /* 文字大小统一 */
            .header h1 { font-size: 1.8rem; }
            .header p { font-size: 0.9rem; }
            .panel-title { font-size: 16px; }
            .form-label { font-size: 13px; }
            .student-name { font-size: 14px; }
            .student-details { font-size: 11px; }
            .no-selection { font-size: 13px; }
            
            /* 按钮统一样式 */
            .btn, .tab-btn, .select-all-btn {
                width: 100%;
                padding: 10px 16px;
                font-size: 14px;
                min-width: auto;
            }
            .page-btn { padding: 6px 10px; font-size: 12px; }
            
            /* 表格响应式 */
            .records-table {
                overflow-x: auto;
            }
            
            .records-table table {
                min-width: auto;
                width: 100%;
            }
            
            .records-table th,
            .records-table td {
                padding: 6px 4px;
                font-size: 11px;
                word-wrap: break-word;
                max-width: 120px;
            }
            
            /* 隐藏列：学号、操作后分数、操作人、记录日期 */
            .records-table th:nth-child(3),
            .records-table td:nth-child(3),
            .records-table th:nth-child(6),
            .records-table td:nth-child(6),
            .records-table th:nth-child(7),
            .records-table td:nth-child(7),
            .records-table th:nth-child(8),
            .records-table td:nth-child(8) {
                display: none !important;
            }
            
            /* 列宽优化 */
            .records-table th:first-child,
            .records-table td:first-child {
                width: 40px;
                min-width: 40px;
            }
            
            .records-table th:nth-child(2),
            .records-table td:nth-child(2) {
                width: 25%;
                min-width: 80px;
            }
            
            .records-table th:nth-child(4),
            .records-table td:nth-child(4) {
                width: 45%;
                min-width: 100px;
            }
            
            .records-table th:nth-child(5),
            .records-table td:nth-child(5) {
                width: 20%;
                min-width: 60px;
                text-align: center;
            }
            
            /* 布局调整 */
            .search-controls, .table-actions, .pagination-container, .panel-header {
                flex-direction: column;
                gap: 10px;
            }
            .panel-header { align-items: flex-start; }
            .pagination-container { text-align: center; }
            .pagination-buttons { justify-content: center; flex-wrap: wrap; }
            
            /* 输入框优化 */
            .search-input { width: 100%; }
            .search-container { padding: 8px 10px !important; }
            .search-input-wrapper input {
                font-size: 16px !important;
                padding: 10px 60px 10px 12px !important;
            }
            .form-input {
                font-size: 16px;
                padding: 12px 10px;
            }
            .reason-input { min-height: 60px; }
            
            /* 学生列表 */
            .students-list { max-height: 250px; }
            .student-item { padding: 12px 10px; margin-bottom: 6px; }
            .selected-count { padding: 6px 10px; font-size: 12px; margin-bottom: 10px; }
            
            /* 小元素样式 */
            .student-score { font-size: 10px; padding: 2px 6px; }
            .status-badge { font-size: 9px; padding: 1px 4px; }
            .rule-info-header .rule-score { font-size: 11px; padding: 3px 8px; }
            .rule-info-type .rule-type { font-size: 10px; padding: 2px 6px; }
            
            /* 面板和表单 */
            .management-panel { padding: 10px; }
            .form-group { gap: 8px; }
            .selected-rule-info { padding: 10px; margin-top: 10px; }
            .rule-info-header .rule-name { font-size: 13px; display: block; margin-bottom: 5px; }
            .action-buttons { margin-top: 15px; gap: 8px; }
            .no-selection { padding: 20px 15px; }
        }
    </style>
</head>
<body>
<?php include '../../modules/admin_sidebar.php'; ?>
<div class="main-layout">
    <div class="container">
        <div class="header">
            <h1>操行分管理</h1>
            <p>操行分管理系统 | Behavior Score Management System</p>
        </div>
        
        <!-- 标签页容器 -->
        <div class="tab-container">
            <div class="tab-header">
                <button class="tab-btn active" onclick="switchTab('records')">
                    <i class="fas fa-list"></i> 操行分记录
                </button>
                <button class="tab-btn" onclick="switchTab('manage')">
                    <i class="fas fa-edit"></i> 修改操行分
                </button>
            </div>
            
            <!-- 操行分记录查看标签页 -->
            <div id="recordsTab" class="tab-content active">
                <div class="records-view">
                    <div class="search-bar">
                        <div class="search-controls">
                            <input type="text" id="searchInput" class="search-input" placeholder="搜索学生姓名、学号或操作理由...">
                        </div>
                    </div>
                    
                    <div class="records-table">
                        <div class="table-actions">
                            <button id="deleteSelectedBtn" class="btn btn-danger" onclick="deleteSelectedRecords()" disabled>
                                <i class="fas fa-trash"></i> 删除选中记录
                            </button>
                        </div>
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="selectAllRecords">
                                    </th>
                                    <th>学生姓名</th>
                                    <th>学号</th>

                                    <th>操作理由</th>
                                    <th>分数变化</th>
                                    <th>操作后分数</th>
                                    <th>操作人</th>
                                    <th>记录日期</th>
                                </tr>
                            </thead>
                            <tbody id="recordsTableBody">
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 40px; color: #6c757d;">
                                        <i class="fas fa-spinner fa-spin"></i> 正在加载数据...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="pagination-container">
                            <div class="pagination-info">
                                显示第 <span id="currentStart">0</span> - <span id="currentEnd">0</span> 条，共 <span id="totalRecords">0</span> 条记录
                                <select id="perPageSelect" onchange="changePerPage()">
                                    <option value="10">每页10条</option>
                                    <option value="20">每页20条</option>
                                    <option value="50">每页50条</option>
                                    <option value="100">每页100条</option>
                                </select>
                            </div>
                            <div class="pagination-buttons" id="paginationButtons">
                                <!-- 分页按钮将通过JavaScript生成 -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 修改操行分标签页 -->
            <div id="manageTab" class="tab-content">
                <div class="main-content">
            <!-- 学生列表面板 -->
            <div class="students-panel">
                <div class="panel-header">
                    <div class="panel-title">👥 学生列表</div>
                    <button class="select-all-btn" onclick="toggleSelectAll()">全选/取消</button>
                </div>
                
                <!-- 学生搜索框 -->
                <div class="search-container" style="padding: 10px 15px; border-bottom: 1px solid #e0e0e0; max-width: 100%; overflow: hidden;">
                    <div class="search-input-wrapper" style="position: relative; max-width: 100%; overflow: hidden;">
                        <input type="text" id="studentSearchInput" placeholder="搜索学生姓名或学号..." 
                               style="width: 100%; max-width: 100%; padding: 8px 60px 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; outline: none; transition: all 0.3s; background-color: #fafafa; box-sizing: border-box;" 
                               oninput="searchStudents()" 
                               onkeyup="searchStudents()" 
                               onchange="searchStudents()" 
                               onpaste="setTimeout(searchStudents, 10)" 
                               oncut="setTimeout(searchStudents, 10)" 
                               onkeydown="if(event.key === 'Delete' || event.key === 'Backspace') setTimeout(searchStudents, 10)" 
                               onfocus="this.style.borderColor='#007bff'; this.style.backgroundColor='#fff'; this.style.boxShadow='0 0 0 2px rgba(0,123,255,0.25)';" 
                               onblur="this.style.borderColor='#ddd'; this.style.backgroundColor='#fafafa'; this.style.boxShadow='none';">
                        <button id="clearSearchBtn" onclick="clearSearch()" style="position: absolute; right: 35px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #999; cursor: pointer; font-size: 14px; display: none;">
                            <i class="fas fa-times"></i>
                        </button>
                        <i class="fas fa-search" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #999; font-size: 14px;"></i>
                    </div>
                </div>
                
                <div id="selectedCount" class="selected-count">
                    已选择 <span id="countNumber">0</span> 名学生
                </div>
                
                <div class="students-list">
                    <!-- 学生数据将通过JavaScript动态加载 -->
                    <div style="text-align: center; padding: 40px; color: #6c757d;">
                        <i class="fas fa-spinner fa-spin"></i> 正在加载学生数据...
                    </div>
                </div>
            </div>
            
            <!-- 操行分管理面板 -->
            <div class="management-panel">
                <div class="panel-header">
                    <div class="panel-title">⚙️ 操行分管理</div>
                </div>
                
                <div id="noSelectionMsg" class="no-selection">
                    请先选择学生，然后选择规则或自定义分数
                </div>
                
                <div id="managementForm" class="custom-form" style="display: none;">
                    <!-- 规则选择区域 -->
                    <div class="form-group">
                        <label class="form-label">📋 选择操行规则</label>
                        <select id="ruleSelect" class="form-input" onchange="selectRuleFromDropdown()">
                            <option value="">正在加载规则...</option>
                        </select>
                    </div>
                    
                    <div id="selectedRuleInfo" class="selected-rule-info" style="display: none;">
                        <div class="rule-info-card">
                            <div class="rule-info-header">
                                <span id="selectedRuleName" class="rule-name"></span>
                                <span id="selectedRuleScore" class="rule-score"></span>
                            </div>
                            <div class="rule-info-type">
                                <span id="selectedRuleType" class="rule-type"></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 分隔线 -->
                    <div style="text-align: center; margin: 20px 0; color: #7f8c8d; font-size: 14px;">
                        —————— OR ——————
                    </div>
                    
                    <!-- 自定义区域 -->
                    <div class="form-group">
                        <label class="form-label">🎯 自定义分数调整</label>
                        <input type="number" id="scoreInput" class="form-input score-input" placeholder="输入分数" min="-100" max="100" step="0.1" oninput="onCustomInputChange()">
                        <small style="color: #7f8c8d; margin-top: 5px;">正数为加分，负数为扣分（填写此项将清除规则选择）</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">操作理由</label>
                        <textarea id="reasonInput" class="form-input reason-input" placeholder="请输入操作理由..." oninput="onCustomInputChange()"></textarea>
                    </div>
                    
                    <div class="action-buttons">
                        <button class="btn btn-primary" onclick="applyScore()">应用分数</button>
                    </div>
                </div>
            </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// 全局变量
let currentPage = 1, currentLimit = 10, totalRecords = 0, currentSearch = '';
let isPaginationChanging = false, isLoadingRecords = false;
let selectedStudents = [], studentsData = [], filteredStudentsData = [], rulesData = [];
let selectedRecords = new Set(), lastSearchValue = '';

// 从sessionStorage加载已保存的选中状态（仅分页切换时记忆）
function loadSelectedRecordsFromStorage() {
    try {
        const saved = sessionStorage.getItem('selectedRecords');
        if (saved) {
            const savedArray = JSON.parse(saved);
            selectedRecords = new Set(savedArray.map(id => parseInt(id)));
        }
    } catch (e) {
        console.warn('加载选中状态失败:', e);
        selectedRecords = new Set();
    }
}

// 保存选中状态到sessionStorage（仅分页切换时记忆）
function saveSelectedRecordsToStorage() {
    try {
        const selectedArray = Array.from(selectedRecords).map(id => parseInt(id));
        sessionStorage.setItem('selectedRecords', JSON.stringify(selectedArray));
    } catch (e) {
        console.warn('保存选中状态失败:', e);
    }
}

// 清空选中状态记忆（批量操作成功后调用）
function clearSelectedRecordsStorage() {
    try {
        sessionStorage.removeItem('selectedRecords');
        selectedRecords.clear();
    } catch (e) {
        console.warn('清空选中状态失败:', e);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    clearSelectedRecordsStorage();
    
    loadStudentsData();
    loadConductRules();
    loadConductRecords();
    initializeSearch();
    initSearchMonitor();
    
    // 使用事件委托处理复选框点击事件
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('record-checkbox')) {
            toggleRecord(e.target);
        } else if (e.target.id === 'selectAllRecords') {
            toggleSelectAllRecords();
        }
    });
});

// 初始化搜索功能
function initializeSearch() {
    const searchInput = document.getElementById('searchInput');
    let searchTimeout;
    
    // 监听输入事件
    searchInput.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        
        // 清除之前的定时器
        clearTimeout(searchTimeout);
        
        // 如果搜索框为空，立即搜索
        if (query === '') {
            currentSearch = '';
            currentPage = 1;
            loadConductRecords();
            return;
        }
        
        // 延迟搜索，避免频繁请求
        searchTimeout = setTimeout(() => {
            currentSearch = query;
            currentPage = 1;
            loadConductRecords();
        }, 500);
    });
    
    // 监听回车键
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const query = searchInput.value.trim();
            clearTimeout(searchTimeout);
            currentSearch = query;
            currentPage = 1;
            loadConductRecords();
        }
    });
}

// 标签页切换
function switchTab(tabName) {
    // 移除所有活动状态
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    
    // 激活选中的标签页
    event.target.classList.add('active');
    document.getElementById(tabName + 'Tab').classList.add('active');
    
    // 如果切换到记录页面，清除选择记忆并重新加载数据
    if (tabName === 'records') {
        // 切换到记录页面时清除选择记忆（相当于刷新）
        clearSelectedRecordsStorage();
        loadConductRecords();
    } else if (tabName === 'manage') {
        loadStudentsData();
        // 只清空搜索框，保持学生选择状态
        const searchInput = document.getElementById('studentSearchInput');
        if (searchInput) {
            searchInput.value = '';
            // 重置过滤数据为全部学生
            filteredStudentsData = [...studentsData];
        }
        
        // 清空表单
        document.getElementById('ruleSelect').value = '';
        document.getElementById('scoreInput').value = '';
        document.getElementById('reasonInput').value = '';
        document.getElementById('selectedRuleInfo').style.display = 'none';
        
        updateSelectionUI();
    }
}



// 加载操行分记录
function loadConductRecords() {
    // 防止重复请求
    if (isLoadingRecords) {
        return;
    }
    
    isLoadingRecords = true;
    
    const data = {
        page: currentPage,
        limit: currentLimit,
        search: currentSearch
    };
    
    makeAjaxRequest('get_conduct_records', data, (result) => {
        renderRecordsTable(result.data);
        totalRecords = result.total;
        updatePaginationInfo();
        generatePaginationButtons();
        
        // 渲染完成后恢复选中状态（分页切换和搜索时都保持选择记忆）
        restoreSelectedState();
        
        isLoadingRecords = false;
        isPaginationChanging = false;
    }, (error) => {
        showError('记录加载失败，' + error);
        isLoadingRecords = false;
        isPaginationChanging = false;
    });
}

// 渲染记录表格
function renderRecordsTable(records) {
    const tbody = document.getElementById('recordsTableBody');
    
    if (!tbody) {
        return;
    }
    
    if (!records || records.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; padding: 40px; color: #6c757d;">
                    <i class="fas fa-inbox"></i> 暂无记录
                </td>
            </tr>
        `;
        return;
    }
    

    
    const tableHTML = records.map((record) => {
        const scoreClass = parseFloat(record.score_change) >= 0 ? 'score-positive' : 'score-negative';
        const scorePrefix = parseFloat(record.score_change) >= 0 ? '+' : '';
        const invalidClass = record.record_status === 'invalid' ? 'record-invalid' : '';
        
        return `
            <tr class="${invalidClass}">
                <td>
                    <input type="checkbox" class="record-checkbox" value="${record.id}">
                </td>
                <td>${record.student_name}</td>
                <td>${record.student_number}</td>
                <td>${record.reason}</td>
                <td class="${scoreClass}">${scorePrefix}${record.score_change}</td>
                <td>${record.score_after}</td>
                <td>${record.operator_name}</td>
                <td>${record.formatted_date}</td>
            </tr>
        `;
    }).join('');
    
    tbody.innerHTML = tableHTML;
}

// 改变每页显示数量
function changePerPage() {
    currentLimit = parseInt(document.getElementById('perPageSelect').value);
    currentPage = 1;
    
    loadConductRecords();
}

// 更新分页信息
function updatePaginationInfo() {
    const start = (currentPage - 1) * currentLimit + 1;
    const end = Math.min(currentPage * currentLimit, totalRecords);
    
    document.getElementById('currentStart').textContent = totalRecords > 0 ? start : 0;
    document.getElementById('currentEnd').textContent = end;
    document.getElementById('totalRecords').textContent = totalRecords;
}

// 生成分页按钮
function generatePaginationButtons() {
    const container = document.getElementById('paginationButtons');
    const totalPages = Math.ceil(totalRecords / currentLimit);
    
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    let buttons = [];
    
    // 上一页按钮
    buttons.push(`
        <button class="page-btn" ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(${currentPage - 1})">
            <i class="fas fa-chevron-left"></i>
        </button>
    `);
    
    // 页码按钮
    const startPage = Math.max(1, currentPage - 2);
    const endPage = Math.min(totalPages, currentPage + 2);
    
    if (startPage > 1) {
        buttons.push(`<button class="page-btn" onclick="goToPage(1)">1</button>`);
        if (startPage > 2) {
            buttons.push(`<span class="page-ellipsis">...</span>`);
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        buttons.push(`
            <button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">
                ${i}
            </button>
        `);
    }
    
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            buttons.push(`<span class="page-ellipsis">...</span>`);
        }
        buttons.push(`<button class="page-btn" onclick="goToPage(${totalPages})">${totalPages}</button>`);
    }
    
    // 下一页按钮
    buttons.push(`
        <button class="page-btn" ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${currentPage + 1})">
            <i class="fas fa-chevron-right"></i>
        </button>
    `);
    
    container.innerHTML = buttons.join('');
}

// 跳转到指定页面
function goToPage(page) {
    if (page < 1 || page > Math.ceil(totalRecords / currentLimit) || page === currentPage) {
        return;
    }
    
    // 防止快速分页切换
    if (isPaginationChanging || isLoadingRecords) {
        return;
    }
    

    
    isPaginationChanging = true;
    
    // 分页切换时保存当前页面的选中状态
    const currentPageRecordIds = [];
    document.querySelectorAll('.record-checkbox').forEach(checkbox => {
        const recordId = parseInt(checkbox.value);
        if (!isNaN(recordId)) {
            currentPageRecordIds.push(recordId);
            // 如果复选框被选中，添加到selectedRecords中
            if (checkbox.checked) {
                selectedRecords.add(recordId);
            } else {
                // 如果复选框未选中，从selectedRecords中移除
                selectedRecords.delete(recordId);
            }
        }
    });
    
    // 保存更新后的选中状态
    saveSelectedRecordsToStorage();
    

    
    currentPage = page;
    
    loadConductRecords();
}

// 加载学生数据
function loadStudentsData() {
    makeAjaxRequest('get_students', {}, (result) => {
        studentsData = result.data;
        filteredStudentsData = [...studentsData];
        renderStudentsList();
    });
}



// 学生搜索功能
function searchStudents() {
    const searchInput = document.getElementById('studentSearchInput');
    const clearBtn = document.getElementById('clearSearchBtn');
    const query = searchInput.value.trim().toLowerCase();
    
    // 检测搜索框是否从有内容变为空
    if (lastSearchValue !== '' && query === '') {
        clearSearch();
        lastSearchValue = query;
        return;
    }
    
    // 更新上一次搜索值
    lastSearchValue = query;
    
    // 显示/隐藏清空按钮并过滤数据
    clearBtn.style.display = query === '' ? 'none' : 'block';
    filteredStudentsData = query === '' ? [...studentsData] : 
        studentsData.filter(student => {
            const name = student.name.toLowerCase();
            const studentId = student.student_id.toLowerCase();
            return name.includes(query) || studentId.includes(query);
        });
    
    // 重新渲染学生列表（会自动恢复选中状态）
    renderStudentsList();
    
    updateSelectionUI();
}

// 清空搜索
function clearSearch() {
    const searchInput = document.getElementById('studentSearchInput');
    const clearBtn = document.getElementById('clearSearchBtn');
    
    // 清空搜索框
    searchInput.value = '';
    clearBtn.style.display = 'none';
    
    // 重置为显示所有学生
    filteredStudentsData = [...studentsData];
    renderStudentsList();
    
    updateSelectionUI();
}

// 清空学生选择
function clearStudentSelection() {
    selectedStudents = [];
    document.querySelectorAll('.student-item').forEach(item => {
        item.classList.remove('selected');
    });
    updateSelectionUI();
}



// 渲染学生列表
function renderStudentsList() {
    const container = document.querySelector('.students-list');
    const searchQuery = document.getElementById('studentSearchInput').value.trim();
    const dataToRender = searchQuery === '' ? studentsData : filteredStudentsData;
    
    if (dataToRender.length === 0) {
        container.innerHTML = `<div style="text-align: center; padding: 40px 20px; color: #999;">
            <i class="fas fa-search" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
            <div style="font-size: 16px;">未找到匹配的学生</div>
            <div style="font-size: 14px; margin-top: 5px;">请尝试其他关键词</div>
        </div>`;
        return;
    }
    
    container.innerHTML = dataToRender.map(student => {
        const isInactive = student.status === 'inactive';
        const statusClass = isInactive ? ' inactive-student' : '';
        const statusBadge = isInactive ? '<span class="status-badge inactive">停用</span>' : '<span class="status-badge active">正常</span>';
        
        return `
        <div class="student-item${statusClass}" data-student-id="${student.id}" onclick="toggleStudent(this)">
            <div class="student-info">
                <div class="student-name">${student.name} ${statusBadge}</div>
                <div class="student-details">学号: ${student.student_id}</div>
            </div>
            <div class="student-score">${student.current_score}分</div>
        </div>
        `;
    }).join('');
    
    // 恢复选中状态
    document.querySelectorAll('.student-item').forEach(item => {
        const studentId = parseInt(item.dataset.studentId);
        if (selectedStudents.includes(studentId)) {
            item.classList.add('selected');
        }
    });
    
    updateSelectionUI();
}

// 加载操行分规则
function loadConductRules() {
    makeAjaxRequest('get_conduct_rules', {}, (result) => {
        rulesData = result.data;
        renderRulesSelect();
    });
}

// 渲染规则选择框
function renderRulesSelect() {
    const select = document.getElementById('ruleSelect');
    
    select.innerHTML = '<option value="">请选择操行规则...</option>' + 
        rulesData.map(rule => {
            const prefix = rule.score_value >= 0 ? '+' : '';
            const typeText = rule.type === 'reward' ? '奖励' : '惩罚';
            return `<option value="${rule.id}|${rule.score_value}|${rule.name}">${rule.name} (${prefix}${rule.score_value}分) - ${typeText}</option>`;
        }).join('');
}

// 选中学生计数变量
let selectedCount = 0;

// 切换学生选择状态
function toggleStudent(element) {
    // 检查学生是否为停用状态
    if (element.classList.contains('inactive-student')) {
        showError('停用状态的学生无法被选择');
        return;
    }
    
    const studentId = parseInt(element.dataset.studentId);
    
    if (element.classList.contains('selected')) {
        element.classList.remove('selected');
        selectedStudents = selectedStudents.filter(id => id !== studentId);
        selectedCount--; // 取消选中时计数-1
    } else {
        element.classList.add('selected');
        selectedStudents.push(studentId);
        selectedCount++; // 选中时计数+1
    }
    
    updateSelectionUI();
}

// 全选/取消全选
function toggleSelectAll() {
    const studentItems = document.querySelectorAll('.student-item');
    
    // 获取当前显示的学生数据和ID，排除停用状态的学生
    const currentDisplayData = document.getElementById('studentSearchInput').value.trim() !== '' ? filteredStudentsData : studentsData;
    const activeStudentIds = currentDisplayData.filter(student => student.status !== 'inactive').map(student => student.id);
    const allCurrentSelected = activeStudentIds.every(id => selectedStudents.includes(id));
    
    if (allCurrentSelected && activeStudentIds.length > 0) {
        // 取消全选：清空所有选择
        studentItems.forEach(item => item.classList.remove('selected'));
        selectedStudents = [];
        selectedCount = 0; // 取消全选时计数设为0
    } else {
        // 全选当前显示的正常状态学生
        studentItems.forEach(item => {
            if (!item.classList.contains('inactive-student')) {
                item.classList.add('selected');
            }
        });
        selectedStudents = [...activeStudentIds];
        selectedCount = activeStudentIds.length; // 全选时计数设为当前显示的学生数量
    }
    
    // 立即更新UI显示
    updateSelectionUI();
}

// 更新选中状态和表单显示
function updateSelectionUI() {
    // 使用全局的 selectedCount 变量
    const countElement = document.getElementById('countNumber');
    const noSelectionMsg = document.getElementById('noSelectionMsg');
    const managementForm = document.getElementById('managementForm');
    
    if (countElement) countElement.textContent = selectedCount;
    
    if (selectedCount > 0) {
        if (noSelectionMsg) noSelectionMsg.style.display = 'none';
        if (managementForm) managementForm.style.display = 'block';
    } else {
        if (noSelectionMsg) noSelectionMsg.style.display = 'block';
        if (managementForm) managementForm.style.display = 'none';
    }
}



// 从下拉框选择规则
function selectRuleFromDropdown() {
    const select = document.getElementById('ruleSelect');
    const value = select.value;
    
    if (value) {
        const [ruleId, scoreValue, ruleName] = value.split('|');
        
        // 清空自定义输入
        document.getElementById('scoreInput').value = '';
        document.getElementById('reasonInput').value = '';
        
        // 显示选中的规则信息
        document.getElementById('selectedRuleName').textContent = ruleName;
        document.getElementById('selectedRuleScore').textContent = (parseFloat(scoreValue) >= 0 ? '+' : '') + scoreValue + '分';
        document.getElementById('selectedRuleScore').className = 'rule-score ' + (parseFloat(scoreValue) >= 0 ? 'positive' : '');
        document.getElementById('selectedRuleType').textContent = parseFloat(scoreValue) >= 0 ? '奖励' : '惩罚';
        document.getElementById('selectedRuleInfo').style.display = 'block';
    } else {
        document.getElementById('selectedRuleInfo').style.display = 'none';
    }
}

// 自定义输入变化
function onCustomInputChange() {
    const scoreInput = document.getElementById('scoreInput');
    const reasonInput = document.getElementById('reasonInput');
    
    // 如果有自定义输入，清空规则选择
    if (scoreInput.value.trim() || reasonInput.value.trim()) {
        document.getElementById('ruleSelect').value = '';
        document.getElementById('selectedRuleInfo').style.display = 'none';
    }
    
        // 实时验证分数输入
    const scoreValue = parseFloat(scoreInput.value);
    scoreInput.classList.remove('input-error');
    
    if (scoreInput.value.trim()) {
        if (scoreValue === 0) {
            scoreInput.classList.add('input-error');
            scoreInput.title = '分数不能为0，请输入正数(加分)或负数(扣分)';
            } else if (scoreValue < -100 || scoreValue > 100) {
                scoreInput.classList.add('input-error');
                scoreInput.title = '分数变化范围应在-100到100之间';
            } else {
                scoreInput.title = '';
            }
        } else {
            scoreInput.title = '';
        }
}

// 应用分数
function applyScore() {
    if (selectedStudents.length === 0) return showError('请先选择学生');
    
    const ruleSelect = document.getElementById('ruleSelect');
    const scoreInput = document.getElementById('scoreInput');
    const reasonInput = document.getElementById('reasonInput');
    
    let scoreChange = 0;
    let reason = '';
    
    if (ruleSelect.value) {
        // 使用规则
        const [ruleId, scoreValue, ruleName] = ruleSelect.value.split('|');
        scoreChange = parseFloat(scoreValue);
        reason = ruleName;
    } else if (scoreInput.value && reasonInput.value) {
        // 使用自定义
        scoreChange = parseFloat(scoreInput.value);
        reason = reasonInput.value.trim();
        
        // 验证自定义分数
        if (scoreChange === 0) {
            return showError('自定义分数不能为0，请输入正数(加分)或负数(扣分)');
        }
        if (scoreChange < -100 || scoreChange > 100) {
            return showError('分数变化范围应在-100到100之间');
        }
    } else {
        return showError('请选择规则或填写自定义分数和理由');
    }
    
    if (!reason) return showError('操作理由不能为空');
    
    // 确认对话框
    const studentNames = selectedStudents.map(id => {
        const student = studentsData.find(s => parseInt(s.id) === parseInt(id));
        return student ? student.name : '未知学生';
    }).filter(name => name).join('、');
    
    // 确保学生名称不为空
    const displayNames = studentNames || '未知学生';
    
    const confirmMsg = `确认为以下学生${scoreChange >= 0 ? '加' : '扣'}分？<br><br><strong>学生：</strong>${displayNames}<br><strong>分数变化：</strong>${scoreChange >= 0 ? '+' : ''}${scoreChange}分<br><strong>理由：</strong>${reason}`;
    
    notification.confirm(confirmMsg, '确认操作', {
        onConfirm: () => submitRecord(scoreChange, reason)
    });
}

function submitRecord(scoreChange, reason) {
    const data = {
        student_ids: JSON.stringify(selectedStudents),
        score_change: scoreChange,
        reason: reason
    };
    
    makeAjaxRequest('add_conduct_record', data, (result) => {
        showSuccess(result.message);
        // 清空选择和表单
        clearFormAndSelection();
        loadStudentsData();
        if (document.getElementById('recordsTab').classList.contains('active')) {
            loadConductRecords();
        }
    });
}

// 清空表单和选择状态的辅助函数
function clearFormAndSelection() {
    document.querySelectorAll('.student-item').forEach(item => item.classList.remove('selected'));
    selectedStudents = [];
    document.getElementById('ruleSelect').value = '';
    document.getElementById('scoreInput').value = '';
    document.getElementById('reasonInput').value = '';
    document.getElementById('selectedRuleInfo').style.display = 'none';
    updateSelectionUI();
}



// 全选/取消全选记录
function toggleSelectAllRecords() {
    const selectAllCheckbox = document.getElementById('selectAllRecords');
    const recordCheckboxes = document.querySelectorAll('.record-checkbox');
    
    recordCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    
    // 更新selectedRecords Set
    if (selectAllCheckbox.checked) {
        // 添加当前页面的记录到选中集合，确保ID为数字类型
        const currentPageRecordIds = Array.from(recordCheckboxes)
            .map(checkbox => parseInt(checkbox.value))
            .filter(id => !isNaN(id)); // 过滤掉无效的ID
        currentPageRecordIds.forEach(id => selectedRecords.add(id));
    } else {
        // 从selectedRecords中移除当前页面的记录ID，确保ID为数字类型
        const currentPageRecordIds = Array.from(recordCheckboxes)
            .map(checkbox => parseInt(checkbox.value))
            .filter(id => !isNaN(id)); // 过滤掉无效的ID
        currentPageRecordIds.forEach(id => selectedRecords.delete(id));
    }
    
    saveSelectedRecordsToStorage(); // 保存选中状态
    updateDeleteButton();
}

// 恢复选中状态
function restoreSelectedState() {
    document.querySelectorAll('.record-checkbox').forEach(checkbox => {
        const recordId = parseInt(checkbox.value);
        if (!isNaN(recordId) && selectedRecords.has(recordId)) {
            checkbox.checked = true;
        } else {
            checkbox.checked = false;
        }
    });
    
    const selectAllCheckbox = document.getElementById('selectAllRecords');
    const recordCheckboxes = document.querySelectorAll('.record-checkbox');
    const checkedCount = Array.from(recordCheckboxes).filter(cb => cb.checked).length;
    
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = recordCheckboxes.length > 0 && checkedCount === recordCheckboxes.length;
        selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < recordCheckboxes.length;
    }
    
    updateDeleteButton();
}

// 切换单个记录的选中状态
function toggleRecord(checkbox) {
    const recordId = parseInt(checkbox.value);
    
    // 检查ID是否有效
    if (isNaN(recordId)) {
        console.warn('无效的记录ID:', checkbox.value);
        return;
    }
    
    if (checkbox.checked) {
        selectedRecords.add(recordId);
    } else {
        selectedRecords.delete(recordId);
    }
    
    saveSelectedRecordsToStorage();
    updateDeleteButton();
}

// 更新删除按钮状态
function updateDeleteButton() {
    const deleteBtn = document.getElementById('deleteSelectedBtn');
    const selectAllCheckbox = document.getElementById('selectAllRecords');
    const allCheckboxes = document.querySelectorAll('.record-checkbox');
    
    deleteBtn.disabled = selectedRecords.size === 0;
    
    // 计算当前页面中应该选中的记录数量（基于selectedRecords Set）
    const currentPageRecordIds = Array.from(allCheckboxes)
        .map(checkbox => parseInt(checkbox.value))
        .filter(id => !isNaN(id));
    
    const currentPageSelectedCount = currentPageRecordIds.filter(id => selectedRecords.has(id)).length;
    const totalCount = currentPageRecordIds.length;
    
    selectAllCheckbox.indeterminate = currentPageSelectedCount > 0 && currentPageSelectedCount < totalCount;
    selectAllCheckbox.checked = currentPageSelectedCount === totalCount && totalCount > 0;
}

// 删除选中记录
function deleteSelectedRecords() {
    if (selectedRecords.size === 0) return showError('请选择要删除的记录');
    
    // 确保所有ID都是有效的数字，然后转换为字符串
    const recordIds = Array.from(selectedRecords)
        .filter(id => !isNaN(parseInt(id))) // 过滤掉无效ID
        .map(id => parseInt(id).toString()); // 确保为数字后转换为字符串
    
    if (recordIds.length === 0) {
        return showError('没有有效的记录可删除');
    }
    
    notification.confirm(
        `确定要删除选中的 ${recordIds.length} 条记录吗？<br><br><span style="color: #e74c3c;">此操作无法撤销！</span>`,
        '确认删除',
        { onConfirm: () => performDeleteRecords(recordIds) }
    );
}

function performDeleteRecords(recordIds) {
    const data = { record_ids: JSON.stringify(recordIds) };
    
    makeAjaxRequest('delete_conduct_records', data, (result) => {
        showSuccess(`已成功删除 ${recordIds.length} 条记录`);
        selectedRecords.clear();
        clearSelectedRecordsStorage();
        loadConductRecords();
    }, (error) => {
        showError('删除失败: ' + error);
    });
}

// 统一的消息显示函数
const showMessage = (type, message) => notification[type](message);

const showSuccess = (message) => showMessage('success', message);
const showError = (message) => showMessage('error', message);

// 统一的AJAX请求处理
function makeAjaxRequest(action, data = {}, onSuccess = null, onError = null) {
    const formData = new FormData();
    formData.append('action', action);
    Object.keys(data).forEach(key => formData.append(key, data[key]));
    
    return fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                if (onSuccess) onSuccess(result);
            } else {
                const errorMsg = result.message || '操作失败，请稍后再试';
                if (onError) onError(errorMsg); else showError(errorMsg);
            }
            return result;
        })
        .catch(error => {
            console.error('Error:', error);
            const errorMsg = '网络连接异常，请检查网络后重试';
            if (onError) onError(errorMsg); else showError(errorMsg);
        });
}





// 初始化搜索框监听
function initSearchMonitor() {
    const searchInput = document.getElementById('studentSearchInput');
    if (!searchInput) return;
    
    searchInput.addEventListener('input', function() {
        if (searchInput.value === '' && lastSearchValue !== '') {
            clearSearch();
            lastSearchValue = '';
        }
    });
}


</script>

</body>
</html>