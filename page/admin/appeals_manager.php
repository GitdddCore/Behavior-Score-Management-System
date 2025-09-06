<?php
// 定义常量以允许包含的文件访问
define('INCLUDED_FROM_APP', true);

/**
 * 申诉管理页面
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

// 获取数据库连接
function getDatabaseConnection() {
    static $db = null;
    static $pdo = null;
    if ($pdo === null) {
        $db = new Database();
        $pdo = $db->getMysqlConnection();
    }
    return $pdo;
}

// 获取申诉数据（支持分页、搜索、状态筛选）
function getAppealsData($page = 1, $limit = 10, $search = '', $status = '') {
    try {
        $pdo = getDatabaseConnection();
        
        // 构建基础查询
        $base_sql = "FROM appeals a
                     LEFT JOIN students s ON a.student_id = s.id
                     LEFT JOIN conduct_records r ON a.record_id = r.id";
        
        $where_conditions = [];
        $params = [];
        
        // 搜索条件 - 智能搜索
        if (!empty($search)) {
            $searchConditions = [];
            
            // 如果搜索内容是纯数字且长度为2，优先匹配学号后两位
            if (preg_match('/^\d{2}$/', $search)) {
                $searchConditions[] = 's.student_id LIKE :search_suffix';
                $params[':search_suffix'] = '%' . $search;
            }
            
            // 如果搜索内容是纯数字且长度大于2，匹配完整学号
            if (preg_match('/^\d{3,}$/', $search)) {
                $searchConditions[] = 's.student_id LIKE :search_full';
                $params[':search_full'] = '%' . $search . '%';
            }
            
            // 总是包含姓名和申诉内容搜索
            $searchConditions[] = 's.name LIKE :search_name';
            $params[':search_name'] = '%' . $search . '%';
            
            $searchConditions[] = 'a.reason LIKE :search_reason';
            $params[':search_reason'] = '%' . $search . '%';
            
            // 如果不是纯数字，也搜索学号（支持混合搜索）
            if (!preg_match('/^\d+$/', $search)) {
                $searchConditions[] = 's.student_id LIKE :search_mixed';
                $params[':search_mixed'] = '%' . $search . '%';
            }
            
            $where_conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
        }
        
        // 状态筛选条件
        if (!empty($status)) {
            $where_conditions[] = 'a.status = :status';
            $params[':status'] = $status;
        }
        

        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
        }
        
        // 获取总记录数
        $count_sql = "SELECT COUNT(*) as total " . $base_sql . $where_clause;
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($params);
        $total = $count_stmt->fetch()['total'];
        
        // 获取待审核数量
        $pending_where_conditions = $where_conditions;
        $pending_params = $params;
        $pending_where_conditions[] = 'a.status = :pending_status';
        $pending_params[':pending_status'] = 'pending';
        $pending_where_clause = ' WHERE ' . implode(' AND ', $pending_where_conditions);
        
        $pending_count_sql = "SELECT COUNT(*) as pending_total " . $base_sql . $pending_where_clause;
        $pending_count_stmt = $pdo->prepare($pending_count_sql);
        $pending_count_stmt->execute($pending_params);
        $pending_total = $pending_count_stmt->fetch()['pending_total'];
        
        // 计算分页
        $total_pages = ceil($total / $limit);
        $offset = ($page - 1) * $limit;
        
        // 获取分页数据
        $data_sql = "SELECT a.id, a.student_id, a.record_id, a.reason, a.status, a.created_at,
                            s.name as student_name, s.student_id as student_number,
                            r.score_change, r.reason as record_reason, r.id as conduct_record_id
                     " . $base_sql . $where_clause . "
                     ORDER BY 
                         CASE WHEN a.status = 'pending' THEN 0 ELSE 1 END,
                         CASE WHEN a.status = 'pending' THEN a.created_at END ASC,
                         CASE WHEN a.status != 'pending' THEN a.created_at END DESC
                     LIMIT :limit OFFSET :offset";
        
        $data_stmt = $pdo->prepare($data_sql);
        
        // 绑定参数
        foreach ($params as $key => $value) {
            $data_stmt->bindValue($key, $value);
        }
        $data_stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $data_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $data_stmt->execute();
        $appeals = $data_stmt->fetchAll();
        
        return [
            'success' => true,
            'appeals' => $appeals,
            'total' => $total,
            'pending_total' => $pending_total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $total_pages
        ];
        
    } catch (Exception $e) {
        
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'appeals' => [],
            'total' => 0,
            'pending_total' => 0,
            'page' => 1,
            'limit' => $limit,
            'total_pages' => 0
        ];
    }
}

// 获取申诉详情
function getAppealDetail($appeal_id) {
    try {
        $pdo = getDatabaseConnection();
        
        $sql = "SELECT a.*, s.student_id, s.name as student_name,
                       r.score_change as original_score, r.reason as original_reason,
                       r.created_at as record_date
                FROM appeals a
                LEFT JOIN students s ON a.student_id = s.id
                LEFT JOIN conduct_records r ON a.record_id = r.id
                WHERE a.id = :appeal_id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':appeal_id' => $appeal_id]);
        $appeal = $stmt->fetch();
        
        if ($appeal) {
            return [
                'success' => true,
                'appeal' => $appeal
            ];
        } else {
            return [
                'success' => false,
                'message' => '申诉记录不存在'
            ];
        }
    } catch (Exception $e) {
        
        return [
            'success' => false,
            'message' => '获取申诉详情失败'
        ];
    }
}

// 处理申诉
function processAppeal($appeal_id, $result, $comment) {
    try {
        $pdo = getDatabaseConnection();
        
        // 开始事务
        $pdo->beginTransaction();
        
        // 获取申诉信息和当前状态
        $appeal_sql = "SELECT a.record_id, a.student_id, a.status as current_status FROM appeals a WHERE a.id = :appeal_id";
        $appeal_stmt = $pdo->prepare($appeal_sql);
        $appeal_stmt->execute([':appeal_id' => $appeal_id]);
        $appeal_info = $appeal_stmt->fetch();
        
        if (!$appeal_info) {
            throw new Exception('申诉记录不存在');
        }
        
        $student_id = $appeal_info['student_id'];
        $record_id = $appeal_info['record_id'];
        $current_status = $appeal_info['current_status'];
        $new_status = ($result === 'approved') ? 'approved' : 'rejected';
        
        // 获取操行分记录信息
        $record_sql = "SELECT score_change, status as record_status FROM conduct_records WHERE id = :record_id";
        $record_stmt = $pdo->prepare($record_sql);
        $record_stmt->execute([':record_id' => $record_id]);
        $record_info = $record_stmt->fetch();
        
        if (!$record_info) {
            throw new Exception('操行分记录不存在');
        }
        
        // 获取学生当前分数
        $student_sql = "SELECT current_score FROM students WHERE id = :student_id";
        $student_stmt = $pdo->prepare($student_sql);
        $student_stmt->execute([':student_id' => $student_id]);
        $student_info = $student_stmt->fetch();
        
        if (!$student_info) {
            throw new Exception('学生记录不存在');
        }
        
        $current_score = $student_info['current_score'];
        $score_change = $record_info['score_change'];
        $record_status = $record_info['record_status'];
        
        // 处理状态变化
        if ($current_status === 'pending') {
            // 首次处理
            if ($result === 'approved') {
                // 首次通过：标记记录为无效，减去分数变化
                $update_record_sql = "UPDATE conduct_records SET status = 'invalid' WHERE id = :record_id";
                $update_record_stmt = $pdo->prepare($update_record_sql);
                $update_record_stmt->execute([':record_id' => $record_id]);
                
                $new_score = $current_score - $score_change;
            } else {
                // 首次拒绝：保持记录有效，分数不变
                $new_score = $current_score;
            }
        } else {
            // 重新处理
            if ($current_status === 'approved' && $result === 'rejected') {
                // 从通过改为拒绝：恢复记录为有效，加回分数变化
                $update_record_sql = "UPDATE conduct_records SET status = 'valid' WHERE id = :record_id";
                $update_record_stmt = $pdo->prepare($update_record_sql);
                $update_record_stmt->execute([':record_id' => $record_id]);
                
                $new_score = $current_score + $score_change;
            } elseif ($current_status === 'rejected' && $result === 'approved') {
                // 从拒绝改为通过：标记记录为无效，减去分数变化
                $update_record_sql = "UPDATE conduct_records SET status = 'invalid' WHERE id = :record_id";
                $update_record_stmt = $pdo->prepare($update_record_sql);
                $update_record_stmt->execute([':record_id' => $record_id]);
                
                $new_score = $current_score - $score_change;
            } else {
                // 状态相同，无需处理
                $new_score = $current_score;
            }
        }
        
        // 更新学生分数
        $update_sql = "UPDATE students SET current_score = :new_score WHERE id = :student_id";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([
            ':new_score' => $new_score,
            ':student_id' => $student_id
        ]);
        
        // 更新申诉状态
        $appeal_update_sql = "UPDATE appeals SET status = :status, processed_by = :processed_by, processed_at = NOW() WHERE id = :appeal_id";
        $appeal_update_stmt = $pdo->prepare($appeal_update_sql);
        $appeal_update_stmt->execute([
            ':status' => $new_status,
            ':processed_by' => $_SESSION['username'],
            ':appeal_id' => $appeal_id
        ]);
        
        $pdo->commit();
        
        // 生成消息
        if ($current_status === 'pending') {
            $message = $result === 'approved' ? '申诉已通过，相关记录已标记为无效' : '申诉已拒绝';
        } else {
            if ($current_status === 'approved' && $result === 'rejected') {
                $message = '申诉已重新处理：从通过改为拒绝，相关记录已恢复有效';
            } elseif ($current_status === 'rejected' && $result === 'approved') {
                $message = '申诉已重新处理：从拒绝改为通过，相关记录已标记为无效';
            } else {
                $message = '申诉状态未发生变化';
            }
        }
        
        return [
            'success' => true,
            'message' => $message
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        
        return [
            'success' => false,
            'message' => '处理申诉失败: ' . $e->getMessage()
        ];
    }
}

// 处理AJAX请求
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_appeal_detail':
            $appeal_id = (int)($_GET['id'] ?? 0);
            $result = $appeal_id > 0 ? getAppealDetail($appeal_id) : ['success' => false, 'message' => '无效的申诉ID'];
            break;
            
        case 'process_appeal':
            $appeal_id = (int)($_POST['id'] ?? 0);
            $result_type = $_POST['result'] ?? '';
            $comment = trim($_POST['comment'] ?? '');
            $result = ($appeal_id > 0 && in_array($result_type, ['approved', 'rejected'])) 
                ? processAppeal($appeal_id, $result_type, $comment) 
                : ['success' => false, 'message' => '参数错误'];
            break;
            
        default:
            // 默认获取申诉列表
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(10, min(100, (int)($_GET['limit'] ?? 10)));
            $search = trim($_GET['search'] ?? '');
            $status = trim($_GET['status'] ?? '');
            $result = getAppealsData($page, $limit, $search, $status);
    }
    
    echo json_encode($result);
    exit;
}

// 获取页面参数
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(10, min(100, (int)$_GET['limit'])) : 10;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';

// 获取页面初始数据
$data = getAppealsData($page, $limit, $search, $status);
if ($data['success']) {
    extract($data);
} else {
    $appeals = [];
    $total = 0;
    $pending_total = 0;
    $total_pages = 0;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操行分管理系统 - 申诉管理</title>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        /* 操作按钮区域 */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .search-box {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .search-container {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-input {
            padding: 12px 45px 12px 15px;
            border: 2px solid #e1e8ed;
            border-radius: 25px;
            font-size: 14px;
            width: 350px;
            background: #f8f9fa;
            transition: all 0.3s ease;
            outline: none;
        }

        .search-input:focus {
            border-color: #3498db;
            background: white;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .search-icon {
            position: absolute;
            right: 15px;
            color: #7f8c8d;
            font-size: 16px;
            pointer-events: none;
        }

        .filter-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .filter-select {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-select:hover {
            border-color: #3498db;
        }

        .filter-select:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        /* 申诉表格 */
        .appeals-table {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .table-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-stats {
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .student-info {
            display: flex;
            align-items: center;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3498db, #2980b9);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 12px;
        }

        .student-details {
            display: flex;
            flex-direction: column;
        }

        .student-name {
            font-weight: 500;
            color: #2c3e50;
            margin-bottom: 2px;
        }

        .student-id {
            font-size: 0.85rem;
            color: #7f8c8d;
        }

        .appeal-content {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .status-unknown {
            background: #e2e3e5;
            color: #383d41;
            border: 1px solid #d6d8db;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            color: white;
            transition: all 0.3s ease;
        }

        .btn-view { background: #3498db; }
        .btn-view:hover { background: #2980b9; }
        .btn-approve { background: #27ae60; }
        .btn-approve:hover { background: #229954; }
        .btn-reject { background: #e74c3c; }
        .btn-reject:hover { background: #c0392b; }

        /* 分页 */
        .pagination-container {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .pagination-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: #666;
        }

        .pagination-info select, #perPageSelect {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            color: #333;
            font-size: 14px;
            cursor: pointer;
            outline: none;
            transition: all 0.3s ease;
        }
        
        .pagination-info select:hover, #perPageSelect:hover {
            border-color: #3498db;
        }
        
        .pagination-info select:focus, #perPageSelect:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        select option:checked,
        select option:selected {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: #ffffff;
            font-weight: 500;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .page-btn {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: white;
            color: #333;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 40px;
            text-align: center;
        }

        .page-btn:hover {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        .page-btn.active {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        .page-btn:disabled {
            background: #f5f5f5;
            color: #ccc;
            cursor: not-allowed;
            border-color: #ddd;
        }

        .page-btn:disabled:hover {
            background: #f5f5f5;
            color: #ccc;
            border-color: #ddd;
        }

        @media (max-width: 768px) {
            .pagination-container {
                flex-direction: column;
                align-items: center;
            }
        }

        /* 模态框样式 */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #ecf0f1;
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
        }

        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: #333;
        }

        .appeal-detail {
            margin-bottom: 20px;
        }

        .detail-group {
            margin-bottom: 15px;
        }

        .detail-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .detail-content {
            color: #555;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2c3e50;
        }

        .form-input, .form-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .btn-primary {
            background: #3498db;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-success {
            background: #27ae60;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }

        .btn-success:hover {
            background: #229954;
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
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

        /* 移动端适配 */
        @media (max-width: 768px) {
            /* 基础布局 */
            .main-layout { padding-left: 0; }
            .container { padding: 15px; }
            .header h1 { font-size: 2.2rem; }
            .header p { font-size: 1.1rem; }
            
            /* 操作栏 */
            .action-bar { flex-direction: column; gap: 20px; padding: 20px; }
            .search-input, .filter-select { width: 100%; }
            .search-input { font-size: 18px; padding: 15px 45px 15px 20px; }
            .filter-select { font-size: 16px; padding: 15px; }
            
            /* 表格 */
            .appeals-table { padding: 20px; }
            table { font-size: 16px; }
            th, td { padding: 15px 8px; font-size: 15px; word-wrap: break-word; }
            th { font-size: 14px; font-weight: 600; }
            
            /* 隐藏列 */
            th:nth-child(2), td:nth-child(2),
            th:nth-child(3), td:nth-child(3),
            th:nth-child(5), td:nth-child(5) { display: none; }
            
            /* 列宽 */
            th:first-child, td:first-child { width: 45%; min-width: 140px; }
            th:nth-child(4), td:nth-child(4) { width: 25%; min-width: 80px; text-align: center; }
            th:nth-child(6), td:nth-child(6) { width: 30%; min-width: 100px; text-align: center; }
            
            /* 学生信息 */
            .student-info { align-items: center; gap: 12px; }
            .student-avatar { width: 36px; height: 36px; font-size: 14px; margin-right: 0; }
            .student-details { min-width: 0; flex: 1; }
            .student-name { font-size: 15px; margin-bottom: 4px; font-weight: 500; }
            .student-id { font-size: 13px; color: #666; }
            
            /* 状态和按钮 */
            .status-badge { font-size: 12px; padding: 6px 10px; font-weight: 500; }
            .action-buttons { flex-direction: column; gap: 8px; min-width: 90px; align-items: center; }
            .btn { padding: 8px 12px; font-size: 12px; width: 80px; max-width: 80px; font-weight: 500; }
            
            /* 分页 */
            .pagination-container { flex-direction: column; gap: 15px; align-items: center; margin-top: 20px; }
            .pagination-info { flex-direction: row; gap: 12px; text-align: center; font-size: 15px; flex-wrap: wrap; justify-content: center; }
            .pagination-info select, #perPageSelect { padding: 10px 12px; font-size: 14px; min-width: 80px; }
            .pagination { justify-content: center; flex-wrap: wrap; gap: 10px; }
            .page-btn { padding: 10px 15px; font-size: 14px; min-width: 45px; font-weight: 500; }
            
            /* 模态框 */
            .modal-content { margin: 2% auto; padding: 20px; width: 95%; max-height: 90vh; overflow-y: auto; }
            .modal-title { font-size: 1.3rem; margin-bottom: 15px; font-weight: 600; }
            .detail-label { font-size: 14px; margin-bottom: 6px; font-weight: 500; }
            .detail-content { font-size: 15px; margin-bottom: 12px; line-height: 1.4; }
            .form-input, .form-textarea { font-size: 16px; padding: 12px; }
            .form-buttons { flex-direction: row; gap: 12px; flex-wrap: wrap; }
            .btn-primary, .btn-success, .btn-danger, .btn-secondary { flex: 1; min-width: 100px; padding: 12px 16px; font-size: 14px; font-weight: 500; }
        }
    </style>
</head>
<body>
<?php include '../../modules/admin_sidebar.php'; ?>
    <?php include '../../modules/notification.php'; ?>

    <div class="main-layout">
    <div class="container">
        <div class="header">
            <h1>申诉管理</h1>
            <p>操行分管理系统 | Conduct Score System</p>
        </div>

        <!-- 操作栏 -->
        <div class="action-bar">
            <div class="search-box">
                <div class="search-container">
                    <input type="text" id="searchInput" class="search-input" placeholder="输入学生姓名、学号或申诉内容进行搜索" value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                    <i class="fa-solid fa-search search-icon"></i>
                </div>
            </div>
            <div class="filter-group">
                <select id="statusFilter" class="filter-select" onchange="applyStatusFilter()">
                    <option value="">全部状态</option>
                    <option value="pending">待处理</option>
                    <option value="approved">已通过</option>
                    <option value="rejected">已拒绝</option>
                </select>
            </div>
        </div>

        <!-- 申诉表格 -->
        <div class="appeals-table">
            <div class="table-header">
                <div class="table-title">
                    <i class="fa-solid fa-gavel"></i>
                    申诉管理
                </div>
                <div class="table-stats">
                    共 <strong id="totalCount"><?php echo $total; ?></strong> 个申诉，待审核 <strong id="pendingCount"><?php echo $pending_total; ?></strong> 个
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>学生信息</th>
                        <th>申诉内容</th>
                        <th>申诉分数</th>
                        <th>状态</th>
                        <th>提交时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="appealsTableBody">
                    <?php if (empty($appeals)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #7f8c8d;">
                                <i class="fa-solid fa-inbox" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                暂无申诉记录
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($appeals as $appeal): ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <div class="student-avatar"><?php echo mb_substr($appeal['student_name'] ?? '未知', 0, 1); ?></div>
                                        <div class="student-details">
                                            <div class="student-name"><?php echo htmlspecialchars($appeal['student_name'] ?? '未知学生'); ?></div>
                                            <div class="student-id">学号: <?php echo htmlspecialchars($appeal['student_number'] ?? '未知'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="appeal-content"><?php echo htmlspecialchars($appeal['reason'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <?php 
                                    $score = $appeal['score_change'] ?? 0;
                                    if ($score > 0) {
                                        echo '<span style="color: #27ae60; font-weight: bold;">+' . $score . '分</span>';
                                    } else {
                                        echo '<span style="color: #e74c3c; font-weight: bold; background: #fdf2f2; padding: 2px 6px; border-radius: 4px;">' . $score . '分</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    $status_text = '';
                                    $status_class = '';
                                    switch($appeal['status']) {
                                        case 'pending':
                                            $status_text = '待处理';
                                            $status_class = 'status-pending';
                                            break;
                                        case 'approved':
                                            $status_text = '已通过';
                                            $status_class = 'status-approved';
                                            break;
                                        case 'rejected':
                                            $status_text = '已拒绝';
                                            $status_class = 'status-rejected';
                                            break;
                                        default:
                                            $status_text = '未知';
                                            $status_class = 'status-unknown';
                                    }
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($appeal['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-view" onclick="viewAppeal(<?php echo $appeal['id']; ?>)">
                                            <i class="fa-solid fa-eye"></i> 查看
                                        </button>
                                        <?php if ($appeal['status'] === 'pending'): ?>
                                            <button class="btn btn-approve" onclick="approveAppeal(<?php echo $appeal['id']; ?>)">
                                                <i class="fa-solid fa-check"></i> 通过
                                            </button>
                                            <button class="btn btn-reject" onclick="rejectAppeal(<?php echo $appeal['id']; ?>)">
                                                <i class="fa-solid fa-times"></i> 拒绝
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- 分页容器 -->
            <div class="pagination-container">
                <div class="pagination-info">
                    <span>每页显示：</span>
                    <select id="perPageSelect" onchange="changePerPage()">
                        <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10条</option>
                        <option value="20" <?php echo $limit == 20 ? 'selected' : ''; ?>>20条</option>
                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50条</option>
                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100条</option>
                    </select>
                    <span id="paginationInfo">显示第 <?php echo ($total > 0) ? (($page - 1) * $limit + 1) : 0; ?>-<?php echo min($page * $limit, $total); ?> 条，共 <?php echo $total; ?> 条记录</span>
                </div>
                <div class="pagination" id="paginationButtons">
                    <!-- 分页按钮将通过JavaScript生成 -->
                </div>
            </div>
        </div>
    </div>
</div>



<!-- 申诉详情模态框 -->
<div id="appealModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">申诉详情</h2>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="appeal-detail">
                <div class="detail-group">
                    <div class="detail-label">学生信息</div>
                    <div class="detail-content" id="studentInfo"></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">申诉分数</div>
                    <div class="detail-content" id="appealScore"></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">原记录原因</div>
                    <div class="detail-content" id="originalReason"></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">申诉内容</div>
                    <div class="detail-content" id="appealContent"></div>
                </div>
                
                <div class="detail-group">
                    <div class="detail-label">提交时间</div>
                    <div class="detail-content" id="submitTime"></div>
                </div>
                
                <div class="detail-group">
                <div class="detail-label">当前状态</div>
                <div class="detail-content" id="currentStatus"></div>
            </div>
        </div>
        
        <!-- 处理表单 -->
        <div id="processForm" style="display: none;">
            <div class="detail-group">
                <div class="detail-label">处理结果</div>
                <div class="detail-content" id="processResultDisplay"></div>
            </div>
        </div>
        
        <div class="form-buttons">
            <button type="button" class="btn-secondary" onclick="closeModal()">
                <i class="fa-solid fa-times"></i> 关闭
            </button>
            <button type="button" id="processBtn" class="btn-primary">
                <i class="fa-solid fa-gavel"></i> 处理申诉
            </button>
            <button type="button" id="submitBtn" class="btn-success" style="display: none;" onclick="submitProcessForm()">
                <i class="fa-solid fa-check"></i> 确认处理
            </button>
        </div>
        </div>
    </div>
</div>

<script>
    // 分页相关变量
    let currentPage = <?php echo $page; ?>;
    let perPage = <?php echo $limit; ?>;
    let totalPages = <?php echo $total_pages; ?>;
    let totalRecords = <?php echo $total; ?>;
    
    // 搜索相关变量
    let searchTimeout;
    let currentSearch = '<?php echo addslashes($search); ?>';
    let currentStatusFilter = '<?php echo addslashes($status); ?>';
    
    // 初始化页面
    document.addEventListener('DOMContentLoaded', function() {
        updatePaginationInfo();
        generatePaginationButtons();
        initializeSearch();
        
        // 设置状态筛选器的值
        const statusFilter = document.getElementById('statusFilter');
        if (statusFilter && currentStatusFilter) {
            statusFilter.value = currentStatusFilter;
        }
        
        // 每页显示条数选择器事件
        document.getElementById('perPageSelect').addEventListener('change', function() {
            changePerPage(this.value);
        });
    });
    
    // 更新分页信息
    function updatePaginationInfo() {
        const start = totalRecords > 0 ? (currentPage - 1) * perPage + 1 : 0;
        const end = Math.min(currentPage * perPage, totalRecords);
        document.getElementById('paginationInfo').textContent = 
            `显示第 ${start}-${end} 条，共 ${totalRecords} 条记录`;
        document.getElementById('totalCount').textContent = totalRecords;
    }
    
    // 生成分页按钮
    function generatePaginationButtons() {
        const container = document.getElementById('paginationButtons');
        container.innerHTML = '';
        
        if (totalPages <= 1) return;
        
        // 上一页按钮
         const prevBtn = document.createElement('button');
         prevBtn.className = 'page-btn';
         prevBtn.innerHTML = '&laquo;';
         prevBtn.disabled = currentPage === 1;
         prevBtn.onclick = () => goToPage(currentPage - 1);
         container.appendChild(prevBtn);
        
        // 页码按钮
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            const firstBtn = document.createElement('button');
             firstBtn.className = 'page-btn';
             firstBtn.textContent = '1';
             firstBtn.onclick = () => goToPage(1);
             container.appendChild(firstBtn);
            
            if (startPage > 2) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'pagination-ellipsis';
                ellipsis.textContent = '...';
                container.appendChild(ellipsis);
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
             const pageBtn = document.createElement('button');
             pageBtn.className = 'page-btn';
             if (i === currentPage) {
                 pageBtn.classList.add('active');
             }
             pageBtn.textContent = i;
             pageBtn.onclick = () => goToPage(i);
             container.appendChild(pageBtn);
         }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'pagination-ellipsis';
                ellipsis.textContent = '...';
                container.appendChild(ellipsis);
            }
            
            const lastBtn = document.createElement('button');
             lastBtn.className = 'page-btn';
             lastBtn.textContent = totalPages;
             lastBtn.onclick = () => goToPage(totalPages);
             container.appendChild(lastBtn);
        }
        
        // 下一页按钮
         const nextBtn = document.createElement('button');
         nextBtn.className = 'page-btn';
         nextBtn.innerHTML = '&raquo;';
         nextBtn.disabled = currentPage === totalPages;
         nextBtn.onclick = () => goToPage(currentPage + 1);
         container.appendChild(nextBtn);
    }
    
    // 通用AJAX请求函数
    function makeAjaxRequest(params = {}, options = {}) {
        const urlParams = new URLSearchParams({ ajax: '1', ...params });
        const url = options.url || ('?' + urlParams.toString());
        
        const fetchOptions = {
            method: options.method || 'GET',
            ...options.fetchOptions
        };
        
        if (options.formData) {
            fetchOptions.body = options.formData;
        }
        
        return fetch(url, fetchOptions)
            .then(response => response.json())
            .catch(error => {
                console.error('请求失败:', error);
                return { success: false, error: options.errorMessage || '网络错误' };
            });
    }
    
    // 显示搜索加载指示器
    function showSearchLoading() {
        const tbody = document.querySelector('.appeals-table tbody');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="loading-state">
                        <i class="fa-solid fa-spinner"></i>
                        <p>搜索中...</p>
                    </td>
                </tr>
            `;
        }
    }

    // 隐藏搜索加载指示器（实际上通过更新表格内容来隐藏）
    function hideSearchLoading() {
        // 加载指示器会在updateAppealsTable函数中被新内容替换，所以这里不需要特别处理
    }

    // 获取申诉数据
    function fetchAppealsData(page = 1, limit = perPage, search = '', status = '') {
        const params = { page, limit };
        if (search) params.search = search;
        if (status) params.status = status;
        
        // 显示加载指示器
        showSearchLoading();
        
        return makeAjaxRequest(params, { errorMessage: '获取数据失败' })
            .then(data => {
                hideSearchLoading();
                return data;
            })
            .catch(error => {
                hideSearchLoading();
                console.error('获取申诉数据失败:', error);
                const tbody = document.querySelector('.appeals-table tbody');
                if (tbody) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="6" class="error-state">
                                <i class="fa-solid fa-exclamation-triangle"></i>
                                <p>网络错误，请检查连接</p>
                            </td>
                        </tr>
                    `;
                }
                return { success: false, error: '网络错误' };
            });
    }
    
    // 更新申诉表格
    function updateAppealsTable(appeals) {
        const tbody = document.querySelector('.appeals-table tbody');
        if (!tbody) return;
        
        if (appeals.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px; color: #7f8c8d;">
                        <i class="fa-solid fa-inbox" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                        暂无申诉记录
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = appeals.map(appeal => {
            const statusClass = {
                'pending': 'status-pending',
                'approved': 'status-approved', 
                'rejected': 'status-rejected'
            }[appeal.status] || 'status-unknown';
            
            const statusText = {
                'pending': '待处理',
                'approved': '已通过',
                'rejected': '已拒绝'
            }[appeal.status] || '未知';
            
            const studentInitial = appeal.student_name ? appeal.student_name.charAt(0) : '?';
            
            // 格式化分数显示
            const score = appeal.score_change || 0;
            const scoreDisplay = score > 0 ? 
                `<span style="color: #27ae60; font-weight: bold;">+${score}分</span>` :
                `<span style="color: #e74c3c; font-weight: bold; background: #fdf2f2; padding: 2px 6px; border-radius: 4px;">${score}分</span>`;
            
            // 格式化日期显示
            const dateDisplay = appeal.created_at ? new Date(appeal.created_at).toLocaleString('zh-CN', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            }).replace(/\//g, '-') : '未知';
            
            return `
                <tr>
                    <td>
                        <div class="student-info">
                            <div class="student-avatar">${studentInitial}</div>
                            <div class="student-details">
                                <div class="student-name">${appeal.student_name || '未知学生'}</div>
                                <div class="student-id">学号: ${appeal.student_number || '未知'}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="appeal-content">${appeal.reason || ''}</div>
                    </td>
                    <td>${scoreDisplay}</td>
                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                    <td>${dateDisplay}</td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-view" onclick="viewAppeal(${appeal.id})">
                                <i class="fa-solid fa-eye"></i> 查看
                            </button>
                            ${appeal.status === 'pending' ? `
                                <button class="btn btn-approve" onclick="approveAppeal(${appeal.id})">
                                    <i class="fa-solid fa-check"></i> 通过
                                </button>
                                <button class="btn btn-reject" onclick="rejectAppeal(${appeal.id})">
                                    <i class="fa-solid fa-times"></i> 拒绝
                                </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }
    
    // 更新分页数据
    function updatePaginationData(data) {
        currentPage = data.page;
        totalPages = data.total_pages;
        
        // 更新右上角统计信息
        const statsElement = document.querySelector('.table-stats');
        if (statsElement) {
            const totalCountElement = document.getElementById('totalCount');
            const pendingCountElement = document.getElementById('pendingCount');
            if (totalCountElement) {
                totalCountElement.textContent = data.total;
            }
            if (pendingCountElement) {
                pendingCountElement.textContent = data.pending_total || 0;
            }
        }
        
        // 更新左下角分页信息
        const paginationInfo = document.getElementById('paginationInfo');
        if (paginationInfo) {
            const start = (data.page - 1) * data.limit + 1;
            const end = Math.min(data.page * data.limit, data.total);
            paginationInfo.textContent = `显示第 ${start}-${end} 条，共 ${data.total} 条记录`;
        }
        
        // 更新分页按钮
        updatePaginationButtons();
        
        // 更新浏览器URL但不显示查询参数
        const cleanUrl = window.location.pathname;
        history.replaceState(null, '', cleanUrl);
    }
    
    // 通用数据刷新函数
    function refreshData(page = currentPage, limit = perPage, search = currentSearch, status = currentStatusFilter) {
        return fetchAppealsData(page, limit, search, status)
            .then(data => {
                if (data.success) {
                    updateAppealsTable(data.appeals);
                    updatePaginationData(data);
                } else {
                    notification.error('数据获取失败: ' + (data.error || '未知错误'));
                }
                return data;
            });
    }
    
    // 跳转到指定页面
    function goToPage(page) {
        if (page < 1 || page > totalPages || page === currentPage) return;
        
        // 显示加载指示器
        showSearchLoading();
        
        refreshData(page);
    }

    // 改变每页显示条数
    function changePerPage(newPerPage) {
        perPage = newPerPage;
        
        // 显示加载指示器
        showSearchLoading();
        
        refreshData(1, newPerPage);
    }
    
    // 初始化搜索功能
    function initializeSearch() {
        const searchInput = document.getElementById('searchInput');
        
        // 智能搜索 - 防抖处理
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchTerm = this.value.trim();
            
            // 如果搜索词为空或长度小于2，延迟更长时间
            const delay = searchTerm.length < 2 ? 800 : 300;
            
            searchTimeout = setTimeout(() => {
                performSearch(searchTerm);
            }, delay);
        });
        
        // 回车键立即搜索
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                clearTimeout(searchTimeout);
                performSearch(this.value.trim());
            }
        });
        
        // 失去焦点时搜索
        searchInput.addEventListener('blur', function() {
            clearTimeout(searchTimeout);
            performSearch(this.value.trim());
        });
    }
    
    // 执行搜索
    function performSearch(searchTerm) {
        if (searchTerm === currentSearch) return;
        currentSearch = searchTerm;
        
        // 显示加载指示器
        showSearchLoading();
        
        refreshData(1, perPage, searchTerm, currentStatusFilter);
    }
    
    // 状态筛选功能
    function applyStatusFilter() {
        const statusFilter = document.getElementById('statusFilter');
        currentStatusFilter = statusFilter.value;
        
        // 显示加载指示器
        showSearchLoading();
        
        refreshData(1, perPage, currentSearch, currentStatusFilter);
    }
    
    // 查看申诉详情
    function viewAppeal(appealId) {
        makeAjaxRequest({ action: 'get_appeal_detail', id: appealId }, { errorMessage: '获取申诉详情失败' })
            .then(data => {
                if (data.success) {
                    showAppealModal(data.appeal);
                } else {
                    notification.error('申诉详情获取失败: ' + data.message);
                }
            });
    }
    
    // 显示申诉模态框
    function showAppealModal(appeal) {
        document.getElementById('studentInfo').textContent = 
            `${appeal.student_name} (学号: ${appeal.student_id})`;
        document.getElementById('appealScore').innerHTML = 
            appeal.original_score > 0 ? 
            `<span style="color: #27ae60; font-weight: bold;">+${appeal.original_score}分</span>` :
            `<span style="color: #e74c3c; font-weight: bold;">${appeal.original_score}分</span>`;
        document.getElementById('originalReason').textContent = appeal.original_reason || '无';
        document.getElementById('appealContent').textContent = appeal.reason;
        document.getElementById('submitTime').textContent = 
            new Date(appeal.created_at).toLocaleString('zh-CN');
        
        // 设置状态显示
        const statusElement = document.getElementById('currentStatus');
        let statusClass = '';
        let statusText = '';
        switch(appeal.status) {
            case 'pending':
                statusClass = 'status-pending';
                statusText = '待处理';
                break;
            case 'approved':
                statusClass = 'status-approved';
                statusText = '已通过';
                break;
            case 'rejected':
                statusClass = 'status-rejected';
                statusText = '已拒绝';
                break;
            default:
                statusClass = 'status-unknown';
                statusText = '未知';
        }
        statusElement.innerHTML = `<span class="status-badge ${statusClass}">${statusText}</span>`;
        
        // 显示处理按钮（允许重新处理已处理的申诉）
        const processBtn = document.getElementById('processBtn');
        processBtn.style.display = 'inline-block';
        processBtn.setAttribute('data-appeal-id', appeal.id);
        processBtn.setAttribute('data-current-status', appeal.status);
        
        // 根据申诉状态设置按钮文本和点击事件
        if (appeal.status !== 'pending') {
            processBtn.innerHTML = '<i class="fa-solid fa-edit"></i> 重新处理';
            processBtn.onclick = () => showReProcessDialog(appeal.id, appeal.status);
        } else {
            processBtn.innerHTML = '<i class="fa-solid fa-gavel"></i> 处理申诉';
            processBtn.onclick = () => showProcessForm();
        }
        
        // 显示模态框
        document.getElementById('appealModal').style.display = 'block';
    }
    
    // 关闭模态框
    function closeModal() {
        document.getElementById('appealModal').style.display = 'none';
        
        // 重置表单状态
        const processForm = document.getElementById('processForm');
        const processBtn = document.getElementById('processBtn');
        const submitBtn = document.getElementById('submitBtn');
        
        processForm.style.display = 'none';
        processBtn.style.display = 'inline-block';
        submitBtn.style.display = 'none';
        
        // 清除数据属性
        processBtn.removeAttribute('data-new-status');
    }
    
    // 显示处理表单
    function showProcessForm() {
        const processForm = document.getElementById('processForm');
        const processBtn = document.getElementById('processBtn');
        const submitBtn = document.getElementById('submitBtn');
        const processResultDisplay = document.getElementById('processResultDisplay');
        
        // 获取当前申诉ID
        const appealId = processBtn.getAttribute('data-appeal-id');
        
        // 获取当前申诉状态（从按钮的data属性或全局变量中获取）
        let currentStatus = processBtn.getAttribute('data-current-status') || 'pending';
        
        // 根据当前状态设置处理选项
        let newStatus, newStatusText, newStatusClass;
        if (currentStatus === 'pending') {
            // 待处理状态，提供通过和拒绝两个选项
            // 默认显示通过选项
            newStatus = 'approved';
            newStatusText = '通过申诉';
            newStatusClass = 'status-approved';
        } else {
            // 已处理状态，提供相反的选项
            if (currentStatus === 'approved') {
                newStatus = 'rejected';
                newStatusText = '改为拒绝';
                newStatusClass = 'status-rejected';
            } else {
                newStatus = 'approved';
                newStatusText = '改为通过';
                newStatusClass = 'status-approved';
            }
        }
        
        // 显示处理结果选项
        if (currentStatus === 'pending') {
            processResultDisplay.innerHTML = `
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button type="button" class="btn-success" onclick="setProcessResult('approved')" id="approveOption">
                        <i class="fa-solid fa-check"></i> 通过申诉
                    </button>
                    <button type="button" class="btn-danger" onclick="setProcessResult('rejected')" id="rejectOption">
                        <i class="fa-solid fa-times"></i> 拒绝申诉
                    </button>
                </div>
            `;
        } else {
            processResultDisplay.innerHTML = `<span class="status-badge ${newStatusClass}">${newStatusText}</span>`;
            processBtn.setAttribute('data-new-status', newStatus);
        }
        
        processForm.style.display = 'block';
        processBtn.style.display = 'none';
        
        if (currentStatus !== 'pending') {
            submitBtn.style.display = 'inline-block';
        }
    }
    
    // 设置处理结果
    function setProcessResult(result) {
        const processBtn = document.getElementById('processBtn');
        const submitBtn = document.getElementById('submitBtn');
        const processResultDisplay = document.getElementById('processResultDisplay');
        
        processBtn.setAttribute('data-new-status', result);
        
        const statusClass = result === 'approved' ? 'status-approved' : 'status-rejected';
        const statusText = result === 'approved' ? '通过申诉' : '拒绝申诉';
        
        processResultDisplay.innerHTML = `<span class="status-badge ${statusClass}">${statusText}</span>`;
        submitBtn.style.display = 'inline-block';
    }
    
    // 显示重新处理对话框
    function showReProcessDialog(appealId, currentStatus) {
        let newStatus, confirmMessage;
        
        if (currentStatus === 'approved') {
            newStatus = 'rejected';
            confirmMessage = '确定要将申诉状态从"已通过"改为"已拒绝"吗？<br><br>此操作将恢复对应的操行分记录为有效状态。';
        } else if (currentStatus === 'rejected') {
            newStatus = 'approved';
            confirmMessage = '确定要将申诉状态从"已拒绝"改为"已通过"吗？<br><br>此操作将标记对应的操行分记录为无效状态。';
        } else {
            return;
        }
        
        notification.confirm(confirmMessage, '确认重新处理', {
            type: 'warning',
            onConfirm: () => processAppeal(appealId, newStatus, true)
        });
    }
    
    // 通用申诉处理函数
    function processAppeal(appealId, result, shouldCloseModal = false) {
        const formData = new FormData();
        formData.append('id', appealId);
        formData.append('result', result);
        formData.append('comment', '');
        
        makeAjaxRequest({ action: 'process_appeal' }, { 
            method: 'POST', 
            formData, 
            errorMessage: '操作失败' 
        })
        .then(data => {
            if (data.success) {
                notification.success(data.message);
                if (shouldCloseModal) {
                    closeModal();
                } else {
                    // 重置表单状态但不关闭模态框
                    const processForm = document.getElementById('processForm');
                    const processBtn = document.getElementById('processBtn');
                    const submitBtn = document.getElementById('submitBtn');
                    
                    processForm.style.display = 'none';
                    processBtn.style.display = 'inline-block';
                    submitBtn.style.display = 'none';
                    processBtn.removeAttribute('data-new-status');
                }
                refreshCurrentData();
            } else {
                notification.error('操作失败: ' + data.message);
            }
        });
    }
    
    // 提交处理表单
    function submitProcessForm() {
        const processBtn = document.getElementById('processBtn');
        const appealId = processBtn.getAttribute('data-appeal-id');
        const newStatus = processBtn.getAttribute('data-new-status');
        
        if (!appealId || !newStatus) {
            notification.error('处理参数错误');
            return;
        }
        
        // 确认提示
        const confirmMessage = newStatus === 'approved' ? 
            '确定要通过这个申诉吗？通过后将标记对应的操行分记录为无效。' :
            '确定要拒绝这个申诉吗？拒绝后将保持对应的操行分记录为有效。';
            
        notification.confirm(confirmMessage, '确认处理', {
            type: 'warning',
            onConfirm: () => {
                processAppeal(appealId, newStatus, true);
            }
        });
    }
    
    // 刷新当前页面数据
    function refreshCurrentData() {
        refreshData();
    }
    
    // 快速通过申诉
    function approveAppeal(appealId) {
        notification.confirm('确定要通过这个申诉吗？通过后将标记对应的操行分记录为无效。确认后无法撤销。', '确认通过申诉', {
            type: 'warning',
            onConfirm: () => processAppeal(appealId, 'approved')
        });
    }
    
    // 快速拒绝申诉
    function rejectAppeal(appealId) {
        notification.confirm('确定要拒绝这个申诉吗？确认后无法撤销。', '确认拒绝申诉', {
            type: 'warning',
            onConfirm: () => processAppeal(appealId, 'rejected')
        });
    }
    

    

    
    // 绑定submitBtn点击事件
    document.addEventListener('DOMContentLoaded', function() {
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            submitBtn.addEventListener('click', submitProcessForm);
        }
    });
    
    // 点击模态框外部关闭
    window.onclick = function(event) {
        const modal = document.getElementById('appealModal');
        if (event.target === modal) {
            closeModal();
        }
    }

</script>

</body>
</html>