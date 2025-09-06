<?php
// 定义常量以允许包含的文件访问
define('INCLUDED_FROM_APP', true);

/**
 * 班级管理人操行分管理页面
 * 需要登录权限才能访问
 */
session_start();

// 引入数据库类（用于自动登录逻辑）
require_once '../../functions/database.php';
$db = new Database();



// 统一的错误响应处理函数
function handleAuthError($message, $redirectUrl = '../../login.php') {
    // 检测是否是AJAX请求
    $isAjax = (
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) || (
        isset($_SERVER['CONTENT_TYPE']) && 
        strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false
    ) || (
        isset($_POST['action']) // 如果有action参数，通常是AJAX请求
    );
    
    if ($isAjax) {
        // AJAX请求：返回JSON响应
        header('Content-Type: application/json');
        $fullRedirectUrl = $redirectUrl . '?message=' . urlencode($message);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'redirect' => $fullRedirectUrl
        ]);
        exit;
    } else {
        // 普通页面请求：使用HTTP重定向
        $fullRedirectUrl = $redirectUrl . '?message=' . urlencode($message);
        header('Location: ' . $fullRedirectUrl);
        exit;
    }
}

// 统一的session和token清理函数
function cleanupUserSession($db) {
    global $redis;
    
    // 清理remember_token cookie和Redis中的token
    if (isset($_COOKIE['remember_token'])) {
        $remember_token = $_COOKIE['remember_token'];
        
        // 删除cookie
        setcookie('remember_token', '', time() - 3600, '/', '', false, false);
        
        // 清除Redis中的token
        try {
            $redis = $db->getRedisConnection('session');
            if ($redis) {
                $redis->del('remember_token:' . $remember_token);
                $db->releaseRedisConnection($redis);
            }
        } catch (Exception $e) {
            error_log("清理Redis token失败: " . $e->getMessage());
        }
    }
    
    // 销毁session
    session_destroy();
}



// 统一的自动登录处理函数
function handleAutoLogin($db) {
    if (!isset($_COOKIE['remember_token'])) {
        handleAuthError('会话不存在，请重新登录');
        return false;
    }
    
    $remember_token = $_COOKIE['remember_token'];
    
    try {
        $redis = $db->getRedisConnection('session');
        if (!$redis) {
            handleAuthError('系统服务暂时不可用，请稍后再试');
            return false;
        }
        
        $token_data = $redis->get('remember_token:' . $remember_token);
        if (!$token_data) {
            // Token不存在，删除cookie
            setcookie('remember_token', '', time() - 3600, '/', '', false, false);
            $db->releaseRedisConnection($redis);
            handleAuthError('会话不存在，请重新登录');
            return false;
        }
        
        $user_info = json_decode($token_data, true);
        if (!$user_info || $user_info['expire_time'] <= time()) {
            // Token过期，删除cookie和Redis中的token
            setcookie('remember_token', '', time() - 3600, '/', '', false, false);
            if ($user_info) {
                $redis->del('remember_token:' . $remember_token);
            }
            $db->releaseRedisConnection($redis);
            handleAuthError('会话已失效，请重新登录');
            return false;
        }
        
        // Token有效，设置session
        $login_time = time();
        if ($user_info['user_type'] === 'admin') {
            $_SESSION['user_id'] = $user_info['username'];
            $_SESSION['username'] = $user_info['username'];
            $_SESSION['user_type'] = 'admin';
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = $login_time;
        } elseif ($user_info['user_type'] === 'committee') {
            $_SESSION['user_id'] = $user_info['username'];
            $_SESSION['username'] = $user_info['username'];
            $_SESSION['student_id'] = $user_info['username'];
            $_SESSION['user_type'] = 'committee';
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = $login_time;
        }
        
        $db->releaseRedisConnection($redis);
        return true;
        
    } catch (Exception $e) {
        error_log("Redis连接失败: " . $e->getMessage());
        handleAuthError('系统连接异常，请稍后再试');
        return false;
    }
}

// 验证用户是否已登录
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    // 没有session，尝试自动登录
    handleAutoLogin($db);
    
    // 如果自动登录后仍然没有session，重定向到登录页
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        handleAuthError('会话已失效，请重新登录');
    }
}

// 获取数据库连接
try {
    $pdo = $db->getMysqlConnection();
} catch (Exception $e) {
    die('数据库连接失败');
}

// 权限检查函数
function checkCommitteeAccess($pdo) {
    global $db;
    
    // 检查是否是管理员访问
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
        // 如果是管理员，直接返回，不进行后续检查
        return;
    }
    
    // 如果不是管理员且有user_id，检查班委权限
    if (isset($_SESSION['user_id'])) {
        try {
            // 检查students表中的状态（必须为active）
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE student_id = ? AND status = 'active'");
            $stmt->execute([$_SESSION['user_id']]);
            $student_count = $stmt->fetchColumn();
            
            // 检查class_committee表中是否存在（不检查状态）
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM class_committee WHERE student_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $committee_count = $stmt->fetchColumn();
            
            // 如果任何一个验证不通过，踢出用户
            if ($student_count == 0 || $committee_count == 0) {
                cleanupUserSession($db);
                handleAuthError('班级管理员权限已被撤销');
            }
        } catch (Exception $e) {
            // 数据库查询失败，为安全起见踢出用户
            error_log("数据库查询失败: " . $e->getMessage());
            cleanupUserSession($db);
            handleAuthError('系统服务暂时不可用，请稍后再试');
        }
    } else {
        // 没有user_id，踢出用户
        cleanupUserSession($db);
        handleAuthError('身份验证失败，请重新登录');
    }
}

// 执行权限检查（页面加载时不是AJAX请求）
checkCommitteeAccess($pdo);

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
    // 统一的AJAX权限检查（在设置Content-Type之前）
    checkCommitteeAccess($pdo);
    
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {

            
        case 'get_students':
            try {
                $stmt = executeQuery($pdo, "SELECT id, student_id, name, current_score, status FROM students ORDER BY student_id");
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                sendJsonResponse(true, $students);
            } catch (Exception $e) {
                sendJsonResponse(false, null, $e->getMessage());
            }
            break;
            
        case 'get_conduct_rules':
            try {
                $stmt = executeQuery($pdo, "SELECT id, name, description, type, score_value FROM conduct_rules ORDER BY type, name");
                $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                sendJsonResponse(true, $rules);
            } catch (Exception $e) {
                sendJsonResponse(false, null, $e->getMessage());
            }
            break;
            
        case 'get_conduct_records':
            try {
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
                
                // 获取所有记录数据，按创建时间降序排序，时间相同时按ID降序排序
                $sql = "SELECT cr.*, s.name as student_name, s.student_id as student_number,
                              DATE_FORMAT(cr.created_at, '%Y-%m-%d %H:%i') as formatted_date
                       FROM conduct_records cr 
                       JOIN students s ON cr.student_id = s.id 
                       {$where_clause} 
                       ORDER BY cr.created_at DESC, cr.id DESC";
                
                $stmt = executeQuery($pdo, $sql, $params);
                $all_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // 前端分页：计算偏移量和截取数据
                $offset = ($page - 1) * $limit;
                $records = array_slice($all_records, $offset, $limit);
                
                sendJsonResponse(true, $records, '', ['total' => $total, 'page' => $page, 'limit' => $limit]);
            } catch (Exception $e) {
                sendJsonResponse(false, null, $e->getMessage());
            }
            break;
            
        case 'add_conduct_record':
            try {
                $student_ids = json_decode($_POST['student_ids'], true);
                $score_change = (float)$_POST['score_change'];
                $reason = trim($_POST['reason']);
                
                // 获取操作人姓名
                if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
                    $operator_name = $_SESSION['username'] ?? '管理员';
                } else {
                    // 从students表获取当前登录用户的姓名
                    $stmt = $pdo->prepare("SELECT name FROM students WHERE student_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $operator_name = $stmt->fetchColumn() ?: $_SESSION['username'];
                }
                
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
                
                // 清空缓存数据库 - 用于数据更新后重新读取
                $db->clearCacheForDataUpdate();
                
                sendJsonResponse(true, null, '操行分记录添加成功');
            } catch (Exception $e) {
                $pdo->rollBack();
                sendJsonResponse(false, null, $e->getMessage());
            }
            break;
            
        default:
            sendJsonResponse(false, null, '未知的操作类型');
            break;
    }
    exit; // 确保AJAX请求处理完成后立即退出
}

// 释放数据库连接
if (isset($pdo)) {
    $db->releaseMysqlConnection($pdo);
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
        
        .record-invalid td:nth-child(3) {
            position: relative;
        }
        
        .record-invalid td:nth-child(3)::after {
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
            padding: 8px 4px;
            color: #6c757d;
            font-size: 14px;
            display: flex;
            align-items: center;
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
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .student-item.selected {
            background: #e8f5e8;
            border-color: #27ae60;
            box-shadow: 0 2px 8px rgba(39, 174, 96, 0.2);
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
<?php include '../../modules/committee_sidebar.php'; ?>
<div class="main-layout">
    <div class="container">
        <div class="header">
            <h1>班管操行分管理页</h1>
            <p>操行分管理系统 | Behavior Score Management System</p>
        </div>
        
        <!-- 标签页容器 -->
        <div class="tab-container">
            <div class="tab-header">
                <button class="tab-btn active" onclick="switchTab('manage')">
                    <i class="fas fa-edit"></i> 修改操行分
                </button>
                <button class="tab-btn" onclick="switchTab('records')">
                    <i class="fas fa-list"></i> 操行分记录
                </button>
            </div>
            
            <!-- 操行分记录查看标签页 -->
            <div id="recordsTab" class="tab-content">
                <div class="records-view">
                    <div class="search-bar">
                        <div class="search-controls">
                            <input type="text" id="searchInput" class="search-input" placeholder="搜索学生姓名、学号或操作理由...">
                        </div>
                    </div>
                    
                    <div class="records-table">
                        <table>
                            <thead>
                                <tr>
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
            <div id="manageTab" class="tab-content active">
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
let selectedCount = 0; // 全局计数变量
let lastSearchValue = '';
let currentSearchRequest = null; // 当前搜索请求的控制器



document.addEventListener('DOMContentLoaded', function() {
    loadStudentsData();
    loadConductRules();
    try {
        loadConductRecords();
    } catch (error) {
        // 静默处理AbortError
        if (error.name !== 'AbortError') {
            console.error('初始加载失败:', error);
        }
    }
    initializeSearch();
    initSearchMonitor();
});

// 初始化搜索功能
function initializeSearch() {
    const searchInput = document.getElementById('searchInput');
    let searchTimeout;
    let lastSearchQuery = '';
    
    // 监听输入事件
    searchInput.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        
        // 清除之前的定时器
        clearTimeout(searchTimeout);
        
        // 如果搜索框为空，立即搜索
        if (query === '') {
            // 只有当之前有搜索内容时才执行搜索
            if (lastSearchQuery !== '') {
                currentSearch = '';
                currentPage = 1;
                lastSearchQuery = '';
                try {
                    loadConductRecords();
                } catch (error) {
                    // 静默处理AbortError
                    if (error.name !== 'AbortError') {
                        console.error('搜索加载失败:', error);
                    }
                }
            }
            return;
        }
        
        // 延迟搜索，避免频繁请求
        searchTimeout = setTimeout(() => {
            // 再次检查当前输入框的值，防止在延迟期间值被改变
            const currentQuery = searchInput.value.trim();
            if (currentQuery === query && query !== lastSearchQuery) {
                currentSearch = query;
                currentPage = 1;
                lastSearchQuery = query;
                try {
                    loadConductRecords();
                } catch (error) {
                    // 静默处理AbortError
                    if (error.name !== 'AbortError') {
                        console.error('搜索加载失败:', error);
                    }
                }
            }
        }, 300); // 减少延迟时间到300ms，提高响应速度
    });
    
    // 监听回车键
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const query = searchInput.value.trim();
            clearTimeout(searchTimeout);
            currentSearch = query;
            currentPage = 1;
            lastSearchQuery = query;
            try {
                loadConductRecords();
            } catch (error) {
                // 静默处理AbortError
                if (error.name !== 'AbortError') {
                    console.error('搜索加载失败:', error);
                }
            }
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
    
    // 如果切换到记录页面，重新加载数据
    if (tabName === 'records') {
        try {
            loadConductRecords();
        } catch (error) {
            // 静默处理AbortError
            if (error.name !== 'AbortError') {
                console.error('标签页切换加载失败:', error);
            }
        }
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
    // 取消之前的请求
    if (currentSearchRequest) {
        currentSearchRequest.abort();
        currentSearchRequest = null;
    }
    
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
    
    // 创建AbortController来控制请求
    const controller = new AbortController();
    currentSearchRequest = controller;
    
    makeAjaxRequestWithController('get_conduct_records', data, controller.signal, (result) => {
        // 检查请求是否被取消
        if (currentSearchRequest === controller) {
            renderRecordsTable(result.data);
            totalRecords = result.total;
            updatePaginationInfo();
            generatePaginationButtons();
            
            isLoadingRecords = false;
            isPaginationChanging = false;
            currentSearchRequest = null;
        }
    }, (error) => {
        // 检查是否是请求被取消
        if (error.name === 'AbortError') {
            // 请求被取消，重置状态但不显示错误
            if (currentSearchRequest === controller) {
                isLoadingRecords = false;
                isPaginationChanging = false;
                currentSearchRequest = null;
            }
            return;
        }
        
        if (currentSearchRequest === controller) {
            const errorMessage = error.message || error.toString() || '未知错误';
            showError('记录加载失败，' + errorMessage);
            isLoadingRecords = false;
            isPaginationChanging = false;
            currentSearchRequest = null;
        }
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
                <td colspan="7" style="text-align: center; padding: 40px; color: #6c757d;">
                    <i class="fas fa-inbox"></i> 暂无记录
                </td>
            </tr>
        `;
        return;
    }
    
    const tableHTML = records.map((record) => {
        const scoreClass = parseFloat(record.score_change) >= 0 ? 'score-positive' : 'score-negative';
        const scorePrefix = parseFloat(record.score_change) >= 0 ? '+' : '';
        const invalidClass = record.status === 'invalid' ? 'record-invalid' : '';
        
        return `
            <tr class="${invalidClass}">
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
    
    try {
        loadConductRecords();
    } catch (error) {
        // 静默处理AbortError
        if (error.name !== 'AbortError') {
            console.error('分页加载失败:', error);
        }
    }
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
    
    // 智能分页逻辑
    if (totalPages <= 7) {
        // 总页数少于等于7页，显示所有页码
        for (let i = 1; i <= totalPages; i++) {
            buttons.push(`
                <button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">
                    ${i}
                </button>
            `);
        }
    } else {
        // 总页数大于7页，使用省略号
        if (currentPage <= 4) {
            // 当前页在前部分
            for (let i = 1; i <= 5; i++) {
                buttons.push(`
                    <button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">
                        ${i}
                    </button>
                `);
            }
            buttons.push(`<span class="page-ellipsis">...</span>`);
            buttons.push(`<button class="page-btn" onclick="goToPage(${totalPages})">${totalPages}</button>`);
        } else if (currentPage >= totalPages - 3) {
            // 当前页在后部分
            buttons.push(`<button class="page-btn" onclick="goToPage(1)">1</button>`);
            buttons.push(`<span class="page-ellipsis">...</span>`);
            for (let i = totalPages - 4; i <= totalPages; i++) {
                buttons.push(`
                    <button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">
                        ${i}
                    </button>
                `);
            }
        } else {
            // 当前页在中间部分
            buttons.push(`<button class="page-btn" onclick="goToPage(1)">1</button>`);
            buttons.push(`<span class="page-ellipsis">...</span>`);
            for (let i = currentPage - 1; i <= currentPage + 1; i++) {
                buttons.push(`
                    <button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">
                        ${i}
                    </button>
                `);
            }
            buttons.push(`<span class="page-ellipsis">...</span>`);
            buttons.push(`<button class="page-btn" onclick="goToPage(${totalPages})">${totalPages}</button>`);
        }
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
    currentPage = page;
    
    try {
        loadConductRecords();
    } catch (error) {
        // 静默处理AbortError
        if (error.name !== 'AbortError') {
            console.error('分页跳转失败:', error);
        }
    }
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
        selectedCount--; // 取消选中时计数减1
    } else {
        element.classList.add('selected');
        selectedStudents.push(studentId);
        selectedCount++; // 选中时计数加1
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
        selectedCount = 0; // 取消全选时计数归零
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
    // 使用全局变量 selectedCount 而不是 selectedStudents.length
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
            try {
                loadConductRecords();
            } catch (error) {
                // 静默处理AbortError
                if (error.name !== 'AbortError') {
                    console.error('提交后刷新失败:', error);
                }
            }
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
        .then(response => {
            // 检查响应状态
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            // 检查内容类型是否为JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('服务器返回的不是JSON格式的数据');
            }
            
            return response.json();
        })
        .then(result => {
            if (result.success) {
                if (onSuccess) onSuccess(result);
            } else {
                // 检查是否是权限失效需要重定向
                if (result.redirect) {
                    // 直接跳转，不显示额外提示（登录页面会显示具体错误信息）
                    window.location.href = result.redirect;
                    return result;
                }
                
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

// 支持AbortController的AJAX请求函数
function makeAjaxRequestWithController(action, data = {}, signal = null, onSuccess = null, onError = null) {
    const formData = new FormData();
    formData.append('action', action);
    Object.keys(data).forEach(key => formData.append(key, data[key]));
    
    const fetchOptions = { method: 'POST', body: formData };
    if (signal) {
        fetchOptions.signal = signal;
    }
    
    return fetch('', fetchOptions)
        .then(response => {
            // 检查响应状态
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            // 检查内容类型是否为JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('服务器返回的不是JSON格式的数据');
            }
            
            return response.json();
        })
        .then(result => {
            if (result.success) {
                if (onSuccess) onSuccess(result);
            } else {
                // 检查是否是权限失效需要重定向
                if (result.redirect) {
                    // 直接跳转，不显示额外提示（登录页面会显示具体错误信息）
                    window.location.href = result.redirect;
                    return result;
                }
                
                const errorMsg = result.message || '操作失败，请稍后再试';
                if (onError) onError(errorMsg); else showError(errorMsg);
            }
            return result;
        })
        .catch(error => {
            console.error('Error:', error);
            if (onError) {
                onError(error);
            } else {
                if (error.name !== 'AbortError') {
                    showError('网络连接异常，请检查网络后重试');
                }
            }
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