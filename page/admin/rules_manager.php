<?php
// 定义常量以允许包含的文件访问
define('INCLUDED_FROM_APP', true);

/**
 * 规则管理页面
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

// 检测移动端访问，如果是移动端则跳转到仪表板
function isMobileDevice() {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $mobile_agents = [
        'Mobile', 'Android', 'iPhone', 'iPad', 'iPod', 'BlackBerry', 
        'Windows Phone', 'Opera Mini', 'IEMobile', 'Mobile Safari'
    ];
    
    foreach ($mobile_agents as $agent) {
        if (stripos($user_agent, $agent) !== false) {
            return true;
        }
    }
    
    // 检测屏幕宽度（通过CSS媒体查询无法在服务端检测，这里主要依赖User-Agent）
    return false;
}

// 如果是移动端访问，跳转到仪表板
if (isMobileDevice()) {
    header('Location: dashboard.php');
    exit;
}

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

// 通用数据库操作函数
function executeDbQuery($sql, $params = [], $fetchMode = 'all') {
    $pdo = null;
    try {
        $pdo = getDatabaseConnection();
        $stmt = $pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        
        $stmt->execute();
        
        switch ($fetchMode) {
            case 'one':
                $result = $stmt->fetch();
                break;
            case 'column':
                $result = $stmt->fetchColumn();
                break;
            case 'count':
                $result = $stmt->rowCount();
                break;
            default:
                $result = $stmt->fetchAll();
                break;
        }
        
        releaseDatabaseConnection($pdo);
        return $result;
        
    } catch (PDOException $e) {
        if ($pdo) {
            releaseDatabaseConnection($pdo);
        }
        throw $e;
    }
}

// 验证分数与类型的匹配性
function validateScoreType($score_change, $rule_type) {
    if ($score_change == 0) return ['valid' => false, 'error' => '分数不能为0'];
    if ($rule_type === 'reward' && $score_change <= 0) return ['valid' => false, 'error' => '奖励类型必须设置正数分值'];
    if ($rule_type === 'penalty' && $score_change >= 0) return ['valid' => false, 'error' => '惩罚类型必须设置负数分值'];
    
    return ['valid' => true];
}

// 生成批量操作结果消息
function generateBatchMessage($operation, $successCount, $failCount) {
    if ($failCount > 0) {
        return "批量{$operation}完成: 成功{$successCount}条 | 失败{$failCount}条";
    }
    return "批量{$operation}完成: 共{$operation}{$successCount}条规则";
}

// 获取规则数据函数
function getRulesData($page = 1, $limit = 10, $search = '') {
    try {
        // 构建搜索条件
        $where = '';
        $params = [];
        if (!empty($search)) {
            $where = 'WHERE name LIKE :search';
            $params[':search'] = '%' . $search . '%';
        }
        
        // 获取总数
        $total = executeDbQuery("SELECT COUNT(*) FROM conduct_rules $where", $params, 'column');
        
        // 获取规则列表
        $offset = ($page - 1) * $limit;
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $rules = executeDbQuery("SELECT id, name as rule_name, description, score_value as score_change, type as rule_type, created_at FROM conduct_rules $where ORDER BY type ASC, score_value DESC LIMIT :limit OFFSET :offset", $params);
        
        return [
            'success' => true,
            'rules' => $rules,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ];
    } catch(PDOException $e) {
        return ['success' => false, 'error' => '数据获取失败，请稍后再试'];
    }
}

// 获取单个规则信息
function getRuleById($id) {
    try {
        $rule = executeDbQuery("SELECT id, name as rule_name, description, score_value as score_change, type as rule_type FROM conduct_rules WHERE id = :id", [':id' => $id], 'one');
        
        if ($rule) {
            return ['success' => true, 'rule' => $rule];
        } else {
            return ['success' => false, 'error' => '规则不存在'];
        }
    } catch(PDOException $e) {
        return ['success' => false, 'error' => '数据获取失败，请稍后再试'];
    }
}

// 添加规则
function addRule($rule_name, $description, $score_change, $rule_type) {
    try {
        $validation = validateScoreType($score_change, $rule_type);
        if (!$validation['valid']) return ['success' => false, 'error' => $validation['error']];
        
        $count = executeDbQuery("SELECT COUNT(*) FROM conduct_rules WHERE name = :rule_name", [':rule_name' => $rule_name], 'column');
        if ($count > 0) return ['success' => false, 'error' => '规则名称已存在'];
        
        executeDbQuery("INSERT INTO conduct_rules (name, description, score_value, type, created_at) VALUES (:rule_name, :description, :score_change, :rule_type, NOW())", [
            ':rule_name' => $rule_name, ':description' => $description, ':score_change' => $score_change, ':rule_type' => $rule_type
        ]);
        
        return ['success' => true, 'message' => '规则添加成功'];
    } catch(PDOException $e) {
        return ['success' => false, 'error' => '添加失败，请稍后再试'];
    }
}

// 更新规则
function updateRule($id, $rule_name, $description, $score_change, $rule_type) {
    try {
        // 验证分数与类型的匹配性
        $validation = validateScoreType($score_change, $rule_type);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }
        
        // 检查规则名称是否已存在（排除当前规则）
        $count = executeDbQuery("SELECT COUNT(*) FROM conduct_rules WHERE name = :rule_name AND id != :id", [
            ':rule_name' => $rule_name,
            ':id' => $id
        ], 'column');
        
        if ($count > 0) {
            return ['success' => false, 'error' => '规则名称已被其他规则使用'];
        }
        
        executeDbQuery("UPDATE conduct_rules SET name = :rule_name, description = :description, score_value = :score_change, type = :rule_type WHERE id = :id", [
            ':id' => $id,
            ':rule_name' => $rule_name,
            ':description' => $description,
            ':score_change' => $score_change,
            ':rule_type' => $rule_type
        ]);
        
        return ['success' => true, 'message' => '规则更新成功'];
    } catch(PDOException $e) {
        return ['success' => false, 'error' => '更新失败，请稍后再试'];
    }
}

// 删除规则
function deleteRule($id) {
    try {
        $rowCount = executeDbQuery("DELETE FROM conduct_rules WHERE id = :id", [':id' => $id], 'count');
        
        if ($rowCount > 0) {
            return ['success' => true, 'message' => '规则删除成功'];
        } else {
            return ['success' => false, 'error' => '规则不存在'];
        }
    } catch(PDOException $e) {
        return ['success' => false, 'error' => '删除失败，请稍后再试'];
    }
}

// 批量删除规则
function batchDeleteRules($ids) {
    $pdo = null;
    try {
        $pdo = getDatabaseConnection();
        $pdo->beginTransaction();
        
        $successCount = 0;
        $errors = [];
        
        foreach ($ids as $id) {
            $stmt = $pdo->prepare("DELETE FROM conduct_rules WHERE id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $rowCount = $stmt->rowCount();
            
            if ($rowCount > 0) {
                $successCount++;
            } else {
                $errors[] = "规则不存在";
            }
        }
        
        $pdo->commit();
        releaseDatabaseConnection($pdo);
        
        $failCount = count($errors);
        $message = generateBatchMessage('删除', $successCount, $failCount);
        
        return ['success' => true, 'message' => $message, 'successCount' => $successCount, 'errors' => $errors];
        
    } catch(PDOException $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($pdo) {
            releaseDatabaseConnection($pdo);
        }
        return ['success' => false, 'error' => '批量删除失败，请稍后再试'];
    }
}

// 批量添加规则
function batchAddRules($rulesData) {
    $pdo = null;
    try {
        $pdo = getDatabaseConnection();
        
        $pdo->beginTransaction();
        
        $successCount = 0;
        $errors = [];
        
        foreach ($rulesData as $index => $ruleData) {
            $lineNumber = $index + 1;
            
            // 验证数据格式
            if (count($ruleData) < 4) {
                $errors[] = "第{$lineNumber}行 数据格式不正确";
                continue;
            }
            
            $rule_name = trim($ruleData[0]);
            $description = trim($ruleData[1]);
            $score_change = floatval($ruleData[2]);
            $rule_type = trim($ruleData[3]);
            
            // 验证数据有效性
            if (empty($rule_name)) {
                $errors[] = "第{$lineNumber}行 规则名称不能为空";
                continue;
            }
            
            if (empty($description)) {
                $errors[] = "第{$lineNumber}行 规则描述不能为空";
                continue;
            }
            
            if (!in_array($rule_type, ['reward', 'penalty'])) {
                $errors[] = "第{$lineNumber}行 规则类型不正确";
                continue;
            }
            
            // 验证分数与类型的匹配性
            $validation = validateScoreType($score_change, $rule_type);
            if (!$validation['valid']) {
                $errors[] = "第{$lineNumber}行 {$validation['error']}";
                continue;
            }
            
            // 检查规则名称是否已存在
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM conduct_rules WHERE name = :rule_name");
            $stmt->bindValue(':rule_name', $rule_name);
            $stmt->execute();
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $errors[] = "第{$lineNumber}行 规则名称{$rule_name}已存在";
                continue;
            }
            
            // 插入规则数据
            $stmt = $pdo->prepare("INSERT INTO conduct_rules (name, description, score_value, type, created_at) VALUES (:rule_name, :description, :score_change, :rule_type, NOW())");
            $stmt->bindValue(':rule_name', $rule_name);
            $stmt->bindValue(':description', $description);
            $stmt->bindValue(':score_change', $score_change);
            $stmt->bindValue(':rule_type', $rule_type);
            $stmt->execute();
            $successCount++;
        }
        
        $pdo->commit();
        releaseDatabaseConnection($pdo);
        
        $failCount = count($errors);
        $message = generateBatchMessage('添加', $successCount, $failCount);
        
        return ['success' => true, 'message' => $message, 'successCount' => $successCount, 'errors' => $errors];
        
    } catch(PDOException $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($pdo) {
            releaseDatabaseConnection($pdo);
        }
        return ['success' => false, 'error' => '批量添加失败，请稍后再试'];
    }
}

// AJAX请求处理
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    // 获取单个规则信息
    if (isset($_GET['action']) && $_GET['action'] == 'get_rule' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        echo json_encode(getRuleById($id));
        exit;
    }
    
    // 获取规则列表
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    echo json_encode(getRulesData($page, $limit, $search));
    exit;
}

// POST请求处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'add_rule':
        case 'update_rule':
            $params = [
                trim($_POST['rule_name']),
                trim($_POST['description']),
                (float)$_POST['score_change'],
                $_POST['rule_type']
            ];
            
            if ($_POST['action'] === 'add_rule') {
                echo json_encode(addRule(...$params));
            } else {
                echo json_encode(updateRule((int)$_POST['id'], ...$params));
            }
            break;
            
        case 'delete_rule':
            $id = (int)$_POST['id'];
            echo json_encode(deleteRule($id));
            break;
            
        case 'batch_delete_rules':
            $ids = isset($_POST['ids']) ? $_POST['ids'] : [];
            if (empty($ids)) {
                echo json_encode(['success' => false, 'error' => '请选择要删除的规则']);
                break;
            }
            
            // 确保所有ID都是整数
            $ids = array_map('intval', $ids);
            echo json_encode(batchDeleteRules($ids));
            break;
            
        case 'batch_add_rules':
            $rulesText = trim($_POST['rules_data']);
            if (empty($rulesText)) {
                echo json_encode(['success' => false, 'error' => '请输入规则数据']);
                break;
            }
            
            // 解析文本数据（空格分隔格式）
            $lines = array_filter(array_map('trim', explode("\n", $rulesText)));
            $rulesData = array_filter(array_map(function($line) {
                $parts = preg_split('/\s+/', $line);
                return count($parts) >= 4 ? $parts : null;
            }, $lines));
            
            if (empty($rulesData)) {
                echo json_encode(['success' => false, 'error' => '没有有效的规则数据']);
                break;
            }
            
            echo json_encode(batchAddRules($rulesData));
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => '无效的操作']);
    }
    exit;
}

// 初始化搜索变量用于HTML显示
$search = isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操行分管理系统 - 规则管理</title>
    <link rel="icon" type="image/x-icon" href="../../favicon.ico">
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
            display: inline-block;
        }

        .search-input {
            padding: 10px 40px 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            width: 300px;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
            pointer-events: none;
        }

        .btn-base {
            padding: 12px 24px;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .add-btn { background: #27ae60; }
        .batch-add-btn { background: #8e44ad; margin-left: 10px; }
        .batch-delete-btn { background: #e74c3c; margin-left: 10px; }
        
        .add-btn:hover { background: #229954; }
        .batch-add-btn:hover { background: #7d3c98; }
        .batch-delete-btn:hover { 
            background: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
        }

        .button-group {
            display: flex;
            gap: 0;
        }

        .button-group .add-btn {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            margin-left: 0;
        }

        .button-group .batch-add-btn {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            margin-left: 0;
        }

        /* 规则表格 */
        .rules-table {
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

        .rule-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 12px;
            font-size: 16px;
        }

        .rule-positive {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
        }

        .rule-negative {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }

        .rule-info {
            display: flex;
            align-items: center;
        }

        .rule-details {
            display: flex;
            flex-direction: column;
        }

        .rule-name {
            font-weight: 500;
            color: #2c3e50;
            margin-bottom: 2px;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .rule-description {
            font-size: 0.85rem;
            color: #7f8c8d;
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .score-badge, .type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .score-positive, .type-reward { color: #27ae60; }
        .score-negative, .type-penalty { color: #e74c3c; }
        .score-positive { background: #d5f4e6; }
        .score-negative { background: #fadbd8; }
        .type-reward { background: #e8f5e8; }
        .type-penalty { background: #ffeaea; }

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
            transition: all 0.3s ease;
        }

        .btn-edit {
            background: #3498db;
            color: white;
        }

        .btn-edit:hover {
            background: #2980b9;
        }

        .btn-delete {
            background: #e74c3c;
            color: white;
        }

        .btn-delete:hover {
            background: #c0392b;
        }

        /* 加载状态样式 */
        .loading-state {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }

        .loading-state i {
            font-size: 2rem;
            margin-bottom: 10px;
            display: block;
            animation: spin 1s linear infinite;
        }

        .loading-state p {
            margin: 0;
            font-size: 1rem;
            font-weight: 500;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .error-state {
            text-align: center;
            padding: 40px;
            color: #e74c3c;
        }

        .error-state i {
            font-size: 2rem;
            margin-bottom: 10px;
            display: block;
        }

        .error-state p {
            margin: 0;
            font-size: 1rem;
            font-weight: 500;
        }

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

        #perPageSelect {
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: #ffffff;
            color: #333;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            outline: none;
            width: auto;
            min-width: 60px;
            max-width: 80px;
            height: 32px;
            box-sizing: border-box;
            line-height: 1.4;
            text-align: center;
            vertical-align: middle;
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
        
        .page-ellipsis {
            padding: 8px 12px;
            color: #666;
            font-size: 14px;
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
            max-width: 500px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
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

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2c3e50;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .form-input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-select { background: white; }
        .form-textarea { resize: vertical; min-height: 80px; }

        .form-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-primary, .btn-secondary {
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }

        .btn-primary { background: #3498db; }
        .btn-secondary { background: #95a5a6; }
        .btn-primary:hover { background: #2980b9; }
        .btn-secondary:hover { background: #7f8c8d; }

        /* 批量添加模态框样式 */
        .batch-tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .tab-btn {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #666;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .tab-btn.active {
            color: #3498db;
            border-bottom-color: #3498db;
        }

        .tab-btn:hover {
            color: #3498db;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .batch-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-family: monospace;
            font-size: 14px;
            resize: vertical;
            min-height: 150px;
        }

        .form-hint {
            font-size: 12px;
            color: #666;
            margin-bottom: 8px;
        }
        .file-format-example {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 12px;
            margin: 10px 0;
            font-size: 13px;
        }

        .file-format-example code {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 8px;
            display: block;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
            color: #495057;
            margin-top: 8px;
        }

        .file-upload-area {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #fafafa;
        }

        .file-upload-area:hover {
            border-color: #3498db;
            background: #f0f8ff;
        }

        .file-upload-area i {
            font-size: 2rem;
            color: #3498db;
            margin-bottom: 10px;
        }

        .file-upload-area p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        .file-info {
            margin-top: 10px;
            padding: 10px 15px;
            border-radius: 6px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .file-info i {
            font-size: 16px;
        }

        .file-info.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        /* 响应式设计 */
        @media (max-width: 768px) {
            .main-layout {
                padding-left: 0;
            }

            .action-bar {
                flex-direction: column;
                gap: 15px;
            }

            .search-input {
                width: 100%;
            }

            table {
                font-size: 14px;
            }

            th, td {
                padding: 10px;
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
            background-color: #fff;
            margin: 5% auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .modal-title {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .close {
            color: #999;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }

        .close:hover {
            color: #333;
        }

        .form-group {
            margin-bottom: 20px;
            padding: 0 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
        }

        .form-hint {
            display: block;
            margin-top: 5px;
            font-size: 12px;
            color: #6c757d;
            line-height: 1.4;
        }

        .form-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 20px;
            border-top: 1px solid #e9ecef;
        }

        .btn-secondary {
            padding: 8px 16px;
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        .btn-primary {
            padding: 8px 16px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-primary:hover {
            background-color: #0056b3;
        }

        .btn-primary:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }

        /* 批量添加模态框样式 */
        .batch-tabs {
            display: flex;
            border-bottom: 1px solid #e9ecef;
        }

        .tab-btn {
            flex: 1;
            padding: 15px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            color: #666;
            border-bottom: 2px solid transparent;
        }

        .tab-btn.active {
            color: #007bff;
            border-bottom-color: #007bff;
        }

        .tab-content {
            display: none;
            padding: 20px;
        }

        .tab-content.active {
            display: block;
        }

        .form-hint {
            font-size: 12px;
            color: #666;
            margin-bottom: 8px;
        }

        .batch-textarea {
            min-height: 150px;
            font-family: monospace;
        }

        .file-upload-area {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.3s;
        }

        .file-upload-area:hover {
            border-color: #007bff;
        }

        .file-upload-area i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 10px;
        }

        .file-upload-area p {
            margin: 0;
            color: #666;
        }

        .file-info {
            margin-top: 10px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
            font-size: 14px;
        }
    </style>
</head>
<body>
<?php include '../../modules/admin_sidebar.php'; ?>
<?php include '../../modules/notification.php'; ?>
<div class="main-layout">
    <div class="container">
        <div class="header">
            <h1>规则管理</h1>
            <p>操行分管理系统 | Behavior Score Management System</p>
        </div>

        <!-- 操作栏 -->
        <div class="action-bar">
            <div class="search-box">
                <div class="search-container">
                    <input type="text" id="searchInput" class="search-input" placeholder="输入规则名称进行搜索" value="<?php echo $search; ?>" autocomplete="off">
                    <i class="fa-solid fa-search search-icon"></i>
                </div>
            </div>
            <div class="button-group">
                <button class="btn-base add-btn" onclick="openAddModal()">
                    <i class="fa-solid fa-plus"></i> 添加规则
                </button>
                <button class="btn-base batch-add-btn" onclick="openBatchAddModal()">
                    <i class="fa-solid fa-upload"></i> 批量添加
                </button>
                <button class="btn-base batch-delete-btn" id="batchDeleteBtn" onclick="batchDeleteRules()" style="display: none;">
                    <i class="fa-solid fa-trash"></i> 批量删除
                </button>
            </div>
        </div>

        <!-- 规则表格 -->
        <div class="rules-table">
            <div class="table-header">
                <div class="table-title">
                    <i class="fa-solid fa-gavel"></i>
                    规则列表
                </div>
                <div class="table-stats" id="tableStats">
                    共 <strong>0</strong> 条规则
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th width="50">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                        </th>
                        <th>规则信息</th>
                        <th>分值</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="rulesTableBody">
                    <!-- 规则数据将通过 JavaScript 动态加载 -->
                </tbody>
            </table>
            
            <!-- 分页 -->
            <div class="pagination-container">
                <div class="pagination-info">
                    <span>每页显示：</span>
                    <select id="perPageSelect" onchange="changePerPage()">
                        <option value="10" selected>10条</option>
                        <option value="20">20条</option>
                        <option value="50">50条</option>
                        <option value="100">100条</option>
                    </select>
                    <span id="paginationInfo">显示第 1-10 条，共 0 条记录</span>
                </div>
                <div class="pagination" id="paginationButtons">
                    <!-- 分页按钮将通过JavaScript动态生成 -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 添加/编辑规则模态框 -->
<div id="ruleModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">添加规则</h2>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <form id="ruleForm">
            <div class="form-group">
                <label class="form-label" for="ruleName">规则名称</label>
                <input type="text" class="form-input" id="ruleName" name="ruleName" placeholder="请输入规则名称">
            </div>
            <div class="form-group">
                <label class="form-label" for="ruleDescription">规则描述</label>
                <textarea class="form-textarea" id="ruleDescription" name="ruleDescription" placeholder="请详细描述该规则的适用情况..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label" for="ruleType">规则类型</label>
                <select class="form-select" id="ruleType" name="ruleType">
                    <option value="">请选择规则类型</option>
                    <option value="reward">奖励</option>
                    <option value="penalty">扣分</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="ruleScore">分值</label>
                    <input type="number" class="form-input" id="ruleScore" name="ruleScore" step="0.1" placeholder="请先选择规则类型">
                <small class="form-hint">奖励类型请输入正数，惩罚类型请输入负数，不允许为0</small>
            </div>
            <div class="form-buttons">
                <button type="button" class="btn-secondary" onclick="closeModal()">取消</button>
                <button type="submit" class="btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 批量添加规则模态框 -->
<div id="batchRuleModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 class="modal-title">批量添加规则</h2>
            <span class="close" onclick="closeBatchModal()">&times;</span>
        </div>
        
        <div class="batch-tabs">
            <button type="button" class="tab-btn active" onclick="switchTab('text')">文本输入</button>
            <button type="button" class="tab-btn" onclick="switchTab('file')">文件上传</button>
        </div>
        
        <!-- 文本输入标签页 -->
        <div id="textTab" class="tab-content active">
            <div class="form-group">
                <label class="form-label">规则数据</label>
                <div class="form-hint">格式: 规则名称 规则描述 分值 类型(reward/penalty), 每行一条规则, 用空格分隔</div>
                <div class="file-format-example">
                    <strong>输入格式示例：</strong><br>
                    <code>按时完成作业 每次按时提交作业可获得奖励分 5 reward<br>
                    迟到早退 上课迟到或提前离开教室 -3 penalty<br>
                    积极发言 课堂积极回答问题 2 reward</code>
                </div>
                <textarea id="batchRuleText" class="batch-textarea" placeholder="请输入规则数据，每行一条规则"></textarea>
            </div>
            <div class="form-buttons">
                <button type="button" class="btn-secondary" onclick="closeBatchModal()">取消</button>
                <button type="button" class="btn-primary" onclick="processBatchAdd(false)">批量添加</button>
            </div>
        </div>
        
        <!-- 文件上传标签页 -->
        <div id="fileTab" class="tab-content">
            <div class="form-group">
                <label class="form-label">选择文件</label>
                <div class="form-hint">支持 .txt 文件，每行一条规则，格式: 规则名称 规则描述 分值 类型(reward/penalty)</div>
                <div class="file-format-example">
                    <strong>文件内容示例：</strong><br>
                    <code>按时完成作业 每次按时提交作业可获得奖励分 5 reward<br>
                    迟到早退 上课迟到或提前离开教室 -3 penalty<br>
                    积极发言 课堂积极回答问题 2 reward</code>
                </div>
                <div class="file-upload-area" onclick="document.getElementById('batchRuleFile').click()">
                    <i class="fa-solid fa-cloud-upload-alt"></i>
                    <p>点击选择文件或拖拽文件到此处</p>
                    <input type="file" id="batchRuleFile" accept=".txt" style="display: none;" onchange="handleFileSelect(this)">
                </div>
                <div id="fileInfo" class="file-info" style="display: none;"></div>
            </div>
            <div class="form-buttons">
                <button type="button" class="btn-secondary" onclick="closeBatchModal()">取消</button>
                <button type="button" id="uploadBtn" class="btn-primary" onclick="processBatchAdd(true)" disabled>上传并添加</button>
            </div>
        </div>
    </div>
</div>

<script>
// 全局变量
let currentPage = 1;
let totalPages = 1;
let currentSearch = '';
let isEditing = false;
let editingId = null;
let currentLimit = 10;
let totalRecords = 0;

// 选中保留功能
let selectedRules = new Set();

// 从sessionStorage加载已保存的选中状态（仅分页切换时记忆）
function loadSelectedRulesFromStorage() {
    try {
        const saved = sessionStorage.getItem('selectedRules');
        if (saved) {
            const savedArray = JSON.parse(saved);
            selectedRules = new Set(savedArray);
        }
    } catch (e) {
        console.warn('加载选中状态失败:', e);
        selectedRules = new Set();
    }
}

// 保存选中状态到sessionStorage（仅分页切换时记忆）
function saveSelectedRulesToStorage() {
    try {
        const selectedArray = Array.from(selectedRules);
        sessionStorage.setItem('selectedRules', JSON.stringify(selectedArray));
    } catch (e) {
        console.warn('保存选中状态失败:', e);
    }
}

// 清空选中状态记忆（批量操作成功后调用）
function clearSelectedRulesStorage() {
    try {
        sessionStorage.removeItem('selectedRules');
        selectedRules.clear();
    } catch (e) {
        console.warn('清空选中状态失败:', e);
    }
}

// 搜索相关变量
let searchTimeout;

// 通用请求处理函数
function makeRequest(formData, successCallback, errorMessage = '操作失败，请稍后再试') {
    return fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            successCallback(data);
        } else {
            notification.error(data.error || errorMessage);
        }
    })
    .catch(error => {
        notification.error('网络连接异常，请检查网络后重试');
    });
}

// 通用获取数据函数
function fetchData(url, successCallback, errorMessage = '获取数据失败') {
    return fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                successCallback(data);
            } else {
                notification.error(data.error || errorMessage);
            }
        })
        .catch(error => {
            notification.error('网络连接异常，请检查网络后重试');
        });
}

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    // 页面刷新时清除选中状态记忆
    clearSelectedRulesStorage();
    
    loadRulesData();
    initDragAndDrop();
    initializeSearch();
    initScoreValidation();
    
    // 表单提交
    document.getElementById('ruleForm').addEventListener('submit', function(e) {
        e.preventDefault();
        saveRule();
    });
});

// 初始化分数验证功能
function initScoreValidation() {
    const ruleTypeSelect = document.getElementById('ruleType');
    const ruleScoreInput = document.getElementById('ruleScore');
    
    // 更新分数输入框的提示文本
    function updateScorePlaceholder() {
        const ruleType = ruleTypeSelect.value;
        if (ruleType === 'reward') {
            ruleScoreInput.placeholder = '请输入正数分值（如：5, 10, 2.5）';
            ruleScoreInput.style.borderColor = '';
        } else if (ruleType === 'penalty') {
            ruleScoreInput.placeholder = '请输入负数分值（如：-5, -10, -2.5）';
            ruleScoreInput.style.borderColor = '';
        } else {
            ruleScoreInput.placeholder = '请先选择规则类型';
        }
    }
    
    // 验证分数输入
    function validateScoreInput() {
        const ruleType = ruleTypeSelect.value;
        const scoreValue = parseFloat(ruleScoreInput.value);
        
        if (!ruleType || isNaN(scoreValue)) {
            ruleScoreInput.style.borderColor = '';
            return;
        }
        
        const validation = validateScoreType(scoreValue, ruleType);
        if (!validation.valid) {
            ruleScoreInput.style.borderColor = '#e74c3c';
            ruleScoreInput.title = validation.error;
        } else {
            ruleScoreInput.style.borderColor = '#27ae60';
            ruleScoreInput.title = '';
        }
    }
    
    // 监听规则类型变化
    ruleTypeSelect.addEventListener('change', function() {
        updateScorePlaceholder();
        validateScoreInput();
    });
    
    // 监听分数输入变化
    ruleScoreInput.addEventListener('input', validateScoreInput);
    ruleScoreInput.addEventListener('blur', validateScoreInput);
}

// 初始化搜索功能
function initializeSearch() {
    const searchInput = document.getElementById('searchInput');
    
    // 输入事件监听
    searchInput.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        
        // 清除之前的定时器
        clearTimeout(searchTimeout);
        
        if (query.length === 0) {
            // 如果搜索框为空，重新加载所有数据
            currentSearch = '';
            currentPage = 1;
            loadRulesData();
            return;
        }
        
        // 防抖处理，200ms后执行搜索
        searchTimeout = setTimeout(() => {
            if (query.length >= 1) {
                currentSearch = query;
                currentPage = 1;
                loadRulesData();
            }
        }, 200);
    });
    
    // 键盘事件监听
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(searchTimeout);
            const query = searchInput.value.trim();
            currentSearch = query;
            currentPage = 1;
            loadRulesData();
        }
    });
    
    // 获得焦点时如果有内容则执行搜索
    searchInput.addEventListener('focus', function() {
        const query = this.value.trim();
        if (query.length >= 1) {
            currentSearch = query;
            currentPage = 1;
            loadRulesData();
        }
    });
}

// 加载规则数据
function loadRulesData() {
    const url = `?ajax=1&page=${currentPage}&limit=${currentLimit}&search=${encodeURIComponent(currentSearch)}`;
    
    // 显示加载指示器
    showLoading();
    
    // 设置最大时间上限，防止无限转圈
    const timeoutId = setTimeout(function() {
        hideLoading();
        showError('加载超时，请重试');
    }, 8000); // 8秒最大时间上限
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            clearTimeout(timeoutId);
            hideLoading();
            
            if (data.success) {
                // 检查当前页面是否为空且不是第一页
                if (data.rules.length === 0 && currentPage > 1 && data.total > 0) {
                    // 当前页面为空，自动跳转到第一页
                    currentPage = 1;
                    loadRulesData();
                    return;
                }
                
                renderRulesTable(data.rules);
                updateTableStats(data.total);
                updatePaginationData(data);
                updatePaginationInfo();
                generatePaginationButtons();
                totalPages = data.total_pages;
                totalRecords = data.total;
            } else {
                showError('数据加载失败，' + (data.error || '请稍后再试'));
            }
        })
        .catch(error => {
            clearTimeout(timeoutId);
            hideLoading();
            showError('网络连接异常，请检查网络后重试');
        });
}

// 显示加载指示器
function showLoading() {
    const tbody = document.getElementById('rulesTableBody');
    tbody.innerHTML = `
        <tr>
            <td colspan="4" class="loading-state">
                <i class="fas fa-spinner fa-spin"></i>
                <p>加载中...</p>
            </td>
        </tr>
    `;
}

// 隐藏加载指示器
function hideLoading() {
    // 加载指示器会在renderRulesTable中被替换，这里不需要特别处理
}

// 显示错误状态
function showError(message) {
    const tbody = document.getElementById('rulesTableBody');
    tbody.innerHTML = `
        <tr>
            <td colspan="4" class="error-state">
                <i class="fas fa-exclamation-triangle"></i>
                <p>${message}</p>
            </td>
        </tr>
    `;
}

// 渲染规则表格
function renderRulesTable(rules) {
    // 不在这里保存状态，因为页面内容即将改变
    
    const tbody = document.getElementById('rulesTableBody');
    
    if (rules.length === 0) {
        const emptyMessage = currentSearch ? 
            `<i class="fa-solid fa-search" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
             未找到匹配的规则` :
            `<i class="fa-solid fa-inbox" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
             暂无规则数据`;
        
        tbody.innerHTML = `
            <tr>
                <td colspan="4" style="text-align: center; padding: 40px; color: #999;">
                    ${emptyMessage}
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = rules.map(rule => {
        const isPositive = rule.score_change > 0;
        const scoreClass = isPositive ? 'score-positive' : 'score-negative';
        const typeClass = rule.rule_type === 'reward' ? 'type-reward' : 'type-penalty';
        const iconClass = isPositive ? 'rule-positive' : 'rule-negative';
        const icon = isPositive ? '+' : '-';
        const typeText = rule.rule_type === 'reward' ? '奖励' : '扣分';
        
        return `
            <tr>
                <td>
                    <input type="checkbox" class="rule-checkbox" value="${rule.id}" onchange="handleRuleCheckboxChange(this)">
                </td>
                <td>
                    <div class="rule-info">
                        <div class="rule-icon ${iconClass}">${icon}</div>
                        <div class="rule-details">
                            <div class="rule-name">${escapeHtml(rule.rule_name)}</div>
                            <div class="rule-description">${escapeHtml(rule.description || '')}</div>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="score-badge ${scoreClass}">
                        ${rule.score_change > 0 ? '+' : ''}${rule.score_change}
                    </span>
                </td>
                <td>
                    <div class="action-buttons">
                        <button class="btn btn-edit" onclick="editRule(${rule.id})">
                            <i class="fa-solid fa-edit"></i> 编辑
                        </button>
                        <button class="btn btn-delete" onclick="deleteRule(${rule.id})">
                            <i class="fa-solid fa-trash"></i> 删除
                        </button>
                    </div>
                </td>
            </tr>
        `;    }).join('');
    
    // 恢复选中状态（确保DOM更新后执行）
    setTimeout(() => {
        restoreSelectedState();
    }, 0);
}

// 更新表格统计信息
function updateTableStats(total) {
    document.getElementById('tableStats').innerHTML = `共 <strong>${total}</strong> 条规则`;
}

// 渲染分页

// 打开添加模态框
function openAddModal() {
    isEditing = false;
    editingId = null;
    document.getElementById('modalTitle').textContent = '添加规则';
    document.getElementById('ruleForm').reset();
    document.getElementById('ruleModal').style.display = 'block';
}

// 编辑规则
function editRule(id) {
    isEditing = true;
    editingId = id;
    document.getElementById('modalTitle').textContent = '编辑规则';
    
    fetchData(`?ajax=1&action=get_rule&id=${id}`, (data) => {
        const rule = data.rule;
        document.getElementById('ruleName').value = rule.rule_name;
        document.getElementById('ruleDescription').value = rule.description;
        document.getElementById('ruleType').value = rule.rule_type;
        document.getElementById('ruleScore').value = rule.score_change;
        document.getElementById('ruleModal').style.display = 'block';
    }, '获取规则信息失败');
}

// 保存规则
// 验证分数与类型的匹配性
function validateScoreType(score, ruleType) {
    if (score == 0) return { valid: false, error: '分数不能为0' };
    if (ruleType === 'reward' && score <= 0) return { valid: false, error: '奖励类型必须设置正数分值' };
    if (ruleType === 'penalty' && score >= 0) return { valid: false, error: '惩罚类型必须设置负数分值' };
    return { valid: true };
}

function saveRule() {
    const ruleName = document.getElementById('ruleName').value.trim();
    const description = document.getElementById('ruleDescription').value.trim();
    const ruleType = document.getElementById('ruleType').value;
    const scoreValue = parseFloat(document.getElementById('ruleScore').value);
    
    // 前端验证
    if (!ruleName) return notification.warning('请输入规则名称');
    if (!ruleType) return notification.warning('请选择规则类型');
    if (isNaN(scoreValue)) return notification.warning('请输入有效的分值');
    
    // 验证分数与类型的匹配性
    const validation = validateScoreType(scoreValue, ruleType);
    if (!validation.valid) return notification.warning(validation.error);
    
    const formData = new FormData();
    formData.append('action', isEditing ? 'update_rule' : 'add_rule');
    formData.append('rule_name', ruleName);
    formData.append('description', description);
    formData.append('rule_type', ruleType);
    formData.append('score_change', scoreValue);
    
    if (isEditing) {
        formData.append('id', editingId);
    }
    
    makeRequest(formData, (data) => {
        notification.success(data.message);
        closeModal();
        loadRulesData();
    });
}

// 删除规则
function deleteRule(id) {
    notification.confirm('确定要删除这条规则吗？删除后无法恢复。', '确认删除', {
        onConfirm: () => {
            const formData = new FormData();
            formData.append('action', 'delete_rule');
            formData.append('id', id);
            
            makeRequest(formData, (data) => {
                notification.success(data.message);
                // 从选中集合中移除已删除的规则ID
                selectedRules.delete(id.toString());
                // 取消对应复选框的选中状态
                const checkbox = document.querySelector(`.rule-checkbox[value="${id}"]`);
                if (checkbox) {
                    checkbox.checked = false;
                }
                // 更新批量删除按钮状态
                updateBatchDeleteButton();
                // 保存更新后的选中状态
                saveSelectedRulesToStorage();
                loadRulesData();
            }, '删除操作失败');
        }
    });
}

// 模态框操作
function closeModal() { document.getElementById('ruleModal').style.display = 'none'; }
function openBatchAddModal() { 
    document.getElementById('batchRuleModal').style.display = 'block'; 
    switchTab('text'); 
}
function closeBatchModal() {
    const modal = document.getElementById('batchRuleModal');
    modal.style.display = 'none';
    document.getElementById('batchRuleText').value = '';
    document.getElementById('batchRuleFile').value = '';
    document.getElementById('fileInfo').style.display = 'none';
    document.getElementById('uploadBtn').disabled = true;
}

// 切换批量添加标签页
function switchTab(tab) {
    // 更新标签按钮状态
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`[onclick="switchTab('${tab}')"]`).classList.add('active');
    
    // 显示对应内容
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    document.getElementById(tab + 'Tab').classList.add('active');
}

// 处理批量添加（文本或文件）
function processBatchAdd(isFile = false) {
    let rulesData;
    
    if (isFile) {
        const file = document.getElementById('batchRuleFile').files[0];
        if (!file) return notification.warning('请选择要上传的文件');
        
        const allowedExtensions = ['.txt'];
        const maxFileSize = 50 * 1024 * 1024; // 50MB in bytes
        
        if (!allowedExtensions.some(ext => file.name.toLowerCase().endsWith(ext))) {
            return notification.warning('仅支持 .txt 格式的文件');
        }
        
        if (file.size > maxFileSize) {
            return notification.warning('文件大小超过限制，请选择小于50MB的文件');
        }
        
        const reader = new FileReader();
        reader.onload = e => submitBatchData(e.target.result);
        reader.onerror = () => notification.error('文件读取失败，请检查文件格式');
        reader.readAsText(file, 'UTF-8');
        return;
    }
    
    rulesData = document.getElementById('batchRuleText').value.trim();
    if (!rulesData) return notification.warning('请输入规则数据');
    submitBatchData(rulesData);
}

// 提交批量数据
function submitBatchData(rulesData) {
    const formData = new FormData();
    formData.append('action', 'batch_add_rules');
    formData.append('rules_data', rulesData);
    
    makeRequest(formData, (data) => {
        notification[data.errors?.length > 0 ? 'warning' : 'success'](data.message);
        closeBatchModal();
        loadRulesData();
    });
}

// 处理文件选择和显示信息
function handleFileSelect(input) {
    const file = input.files[0];
    if (!file) return;
    
    const fileInfo = document.getElementById('fileInfo');
    const allowedExtensions = ['.txt'];
    const maxFileSize = 50 * 1024 * 1024; // 50MB in bytes
    
    // 验证文件格式
    const isValidFile = allowedExtensions.some(ext => file.name.toLowerCase().endsWith(ext));
    if (!isValidFile) {
        notification.warning('文件格式不支持，请选择 .txt 文件');
        fileInfo.style.display = 'none';
        document.getElementById('uploadBtn').disabled = true;
        input.value = ''; // 清空文件选择
        return;
    }
    
    // 验证文件大小
    if (file.size > maxFileSize) {
        notification.warning('文件大小超过限制，请选择小于50MB的文件');
        fileInfo.style.display = 'none';
        document.getElementById('uploadBtn').disabled = true;
        input.value = ''; // 清空文件选择
        return;
    }
    
    // 文件验证通过，显示文件信息
    fileInfo.className = 'file-info';
    fileInfo.style.display = 'block';
    fileInfo.innerHTML = `<i class="fa-solid fa-file-text"></i> 已选择文件：${file.name} (${(file.size / 1024).toFixed(2)} KB)`;
    document.getElementById('uploadBtn').disabled = false;
}

// 初始化拖拽上传功能
function initDragAndDrop() {
    const uploadArea = document.querySelector('.file-upload-area');
    
    if (!uploadArea) return;
    
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        uploadArea.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    ['dragenter', 'dragover'].forEach(eventName => {
        uploadArea.addEventListener(eventName, highlight, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        uploadArea.addEventListener(eventName, unhighlight, false);
    });
    
    function highlight() {
        uploadArea.style.borderColor = '#007bff';
        uploadArea.style.backgroundColor = '#f8f9ff';
    }
    
    function unhighlight() {
        uploadArea.style.borderColor = '#ddd';
        uploadArea.style.backgroundColor = 'transparent';
    }
    
    uploadArea.addEventListener('drop', handleDrop, false);
    
    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        
        if (files.length > 0) {
            const fileInput = document.getElementById('batchRuleFile');
            fileInput.files = files;
            handleFileSelect(fileInput);
        }
    }
}

// HTML转义函数
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 全选/取消全选
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.rule-checkbox');
    
    if (selectAll.checked) {
        // 全选：只添加当前页面的规则ID
        checkboxes.forEach(checkbox => {
            checkbox.checked = true;
            selectedRules.add(checkbox.value);
        });
    } else {
        // 取消全选：只移除当前页面的规则ID
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
            selectedRules.delete(checkbox.value);
        });
    }
    
    updateBatchDeleteButton();
    saveSelectedRulesToStorage(); // 保存选中状态
}

// 处理单个规则复选框变化
function handleRuleCheckboxChange(checkbox) {
    if (checkbox.checked) {
        selectedRules.add(checkbox.value);
    } else {
        selectedRules.delete(checkbox.value);
    }
    updateBatchDeleteButton();
    saveSelectedRulesToStorage(); // 保存选中状态
}

// 保存选中状态
// saveSelectedState函数已删除，现在使用新的选中状态管理逻辑

// 恢复选中状态
function restoreSelectedState() {
    // 从sessionStorage加载选中状态
    loadSelectedRulesFromStorage();
    
    // 恢复复选框状态
    document.querySelectorAll('.rule-checkbox').forEach(checkbox => {
        checkbox.checked = selectedRules.has(checkbox.value);
    });
    updateBatchDeleteButton();
}

// 更新批量删除按钮显示状态
function updateBatchDeleteButton() {
    const batchDeleteBtn = document.getElementById('batchDeleteBtn');
    const selectAll = document.getElementById('selectAll');
    
    // 基于selectedRules Set显示批量删除按钮和计数
    if (selectedRules.size > 0) {
        batchDeleteBtn.style.display = 'flex';
        batchDeleteBtn.innerHTML = `<i class="fa-solid fa-trash"></i> 批量删除 (${selectedRules.size})`;
    } else {
        batchDeleteBtn.style.display = 'none';
    }
    
    // 更新全选复选框状态（仅基于当前页面）
    const allCheckboxes = document.querySelectorAll('.rule-checkbox');
    const checkedBoxes = document.querySelectorAll('.rule-checkbox:checked');
    if (allCheckboxes.length > 0) {
        selectAll.checked = checkedBoxes.length === allCheckboxes.length;
        selectAll.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < allCheckboxes.length;
    }
}

// 批量删除规则
function batchDeleteRules() {
    if (selectedRules.size === 0) return notification.warning('请先选择要删除的规则');
    
    const ruleIds = Array.from(selectedRules);
    
    notification.confirm(`确定要删除选中的${ruleIds.length}条规则吗？此操作无法撤销！`, '确认批量删除', {
        onConfirm: () => {
            const batchDeleteBtn = document.getElementById('batchDeleteBtn');
            const originalText = batchDeleteBtn.innerHTML;
            batchDeleteBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 删除中...';
            batchDeleteBtn.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'batch_delete_rules');
            ruleIds.forEach(id => formData.append('ids[]', id));
            
            makeRequest(formData, (data) => {
                notification.success(data.message);
                // 清空选中状态记忆（批量删除成功后记忆失效）
                clearSelectedRulesStorage();
                // 取消所有复选框的选中状态
                document.querySelectorAll('.rule-checkbox').forEach(checkbox => {
                    checkbox.checked = false;
                });
                // 取消全选复选框的选中状态
                const selectAllCheckbox = document.getElementById('selectAll');
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = false;
                }
                // 更新批量删除按钮状态
                updateBatchDeleteButton();
                loadRulesData();
            })
            .finally(() => {
                batchDeleteBtn.innerHTML = originalText;
                batchDeleteBtn.disabled = false;
            });
        }
    });
}

// 点击模态框外部关闭
window.onclick = function(event) {
    const ruleModal = document.getElementById('ruleModal');
    const batchModal = document.getElementById('batchRuleModal');
    
    if (event.target === ruleModal) {
        closeModal();
    }
    if (event.target === batchModal) {
        closeBatchModal();
    }
}

// 改变每页显示条数
function changePerPage() {
    currentLimit = parseInt(document.getElementById('perPageSelect').value);
    currentPage = 1;
    loadRulesData();
}

// 更新分页数据
function updatePaginationData(data) {
    currentPage = data.page;
    totalPages = data.total_pages;
    totalRecords = data.total;
}

// 更新分页信息显示
function updatePaginationInfo() {
    const start = (currentPage - 1) * currentLimit + 1;
    const end = Math.min(currentPage * currentLimit, totalRecords);
    document.getElementById('paginationInfo').textContent = `显示第 ${start}-${end} 条，共 ${totalRecords} 条记录`;
}

// 生成分页按钮
function generatePaginationButtons() {
    const container = document.getElementById('paginationButtons');
    if (!container) return;
    
    let html = '';
    
    // 上一页按钮
    if (currentPage > 1) {
        html += `<button class="page-btn" onclick="goToPage(${currentPage - 1})">上一页</button>`;
    } else {
        html += `<button class="page-btn" disabled>上一页</button>`;
    }
    
    // 页码按钮
    const startPage = Math.max(1, currentPage - 2);
    const endPage = Math.min(totalPages, currentPage + 2);
    
    if (startPage > 1) {
        html += `<button class="page-btn" onclick="goToPage(1)">1</button>`;
        if (startPage > 2) {
            html += `<span class="page-ellipsis">...</span>`;
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        if (i === currentPage) {
            html += `<button class="page-btn active">${i}</button>`;
        } else {
            html += `<button class="page-btn" onclick="goToPage(${i})">${i}</button>`;
        }
    }
    
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            html += `<span class="page-ellipsis">...</span>`;
        }
        html += `<button class="page-btn" onclick="goToPage(${totalPages})">${totalPages}</button>`;
    }
    
    // 下一页按钮
    if (currentPage < totalPages) {
        html += `<button class="page-btn" onclick="goToPage(${currentPage + 1})">下一页</button>`;
    } else {
        html += `<button class="page-btn" disabled>下一页</button>`;
    }
    
    container.innerHTML = html;
}

// 跳转到指定页面
function goToPage(page) {
    if (page >= 1 && page <= totalPages && page !== currentPage) {
        saveSelectedRulesToStorage(); // 分页切换时保存选中状态
        currentPage = page;
        loadRulesData();
    }
}
</script>

</body>
</html>