<?php
// 定义常量以允许包含的文件访问
define('INCLUDED_FROM_APP', true);

/**
 * 班级管理人管理页面
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

// 数据库连接实例
$database = new Database();

// 获取数据库连接
function getDatabaseConnection() {
    global $database;
    return $database->getMysqlConnection();
}

// 释放数据库连接
function releaseDatabaseConnection($pdo) {
    global $database;
    $database->releaseMysqlConnection($pdo);
}

// 获取班级管理人数据
function getCommitteeData() {
    $pdo = null;
    try {
        $pdo = getDatabaseConnection();
        $sql = "SELECT cc.*, s.name, s.student_id as student_number 
                FROM class_committee cc 
                JOIN students s ON cc.link_student_id = s.id 
                ORDER BY s.student_id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        releaseDatabaseConnection($pdo);
        return $result;
    } catch(Exception $e) {
        if ($pdo) releaseDatabaseConnection($pdo);
        return [];
    }
}

// 同步班级管理人表中的学号信息
function syncCommitteeStudentIds() {
    $pdo = null;
    try {
        $pdo = getDatabaseConnection();
        $sql = "UPDATE class_committee cc 
                JOIN students s ON cc.link_student_id = s.id 
                SET cc.student_id = s.student_id 
                WHERE cc.student_id != s.student_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->rowCount();
        releaseDatabaseConnection($pdo);
        return $result;
    } catch(Exception $e) {
        if ($pdo) releaseDatabaseConnection($pdo);
        return 0;
    }
}

// 验证学号并获取学生信息
function validateStudentId($student_id) {
    $pdo = getDatabaseConnection();
    $sql = "SELECT id, student_id FROM students WHERE student_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$student_id]);
    $result = $stmt->fetch();
    releaseDatabaseConnection($pdo);
    return $result;
}

// 通用检查函数
function checkExists($table, $field, $value, $exclude_id = null) {
    // 白名单验证，防止SQL注入
    $allowed_tables = ['class_committee', 'students'];
    $allowed_fields = ['link_student_id', 'position', 'student_id', 'name'];
    
    if (!in_array($table, $allowed_tables) || !in_array($field, $allowed_fields)) {
        return false; // 非法参数，返回false
    }
    
    $pdo = getDatabaseConnection();
    $sql = "SELECT id FROM {$table} WHERE {$field} = ?";
    $params = [$value];
    
    if ($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch() !== false;
    releaseDatabaseConnection($pdo);
    return $result;
}

// 检查学生是否已是班级管理人
function isStudentCommittee($link_student_id, $exclude_id = null) {
    return checkExists('class_committee', 'link_student_id', $link_student_id, $exclude_id);
}

// 检查职务是否已被占用
function isPositionTaken($position, $exclude_id = null) {
    return checkExists('class_committee', 'position', $position, $exclude_id);
}

// 验证ID参数
function validateId($id) {
    if (empty($id)) {
        jsonResponse(false, '参数错误');
    }
    return $id;
}

// 验证班级管理人数据
function validateCommitteeData($post_data, $exclude_id = null) {
    $student_id = $post_data['studentSelect'] ?? '';
    $position = $post_data['positionSelect'] ?? '';
    $start_date = $post_data['appointmentDate'] ?? '';
    $password = $post_data['password'] ?? '';

    if (empty($student_id) || empty($position) || empty($start_date)) {
        jsonResponse(false, '请填写所有必填字段');
    }
    
    // 验证学号
    $studentData = validateStudentId($student_id);
    if (!$studentData) {
        jsonResponse(false, '学号不存在');
    }
    $link_student_id = $studentData['id'];
    
    // 检查重复性
    if (isStudentCommittee($link_student_id, $exclude_id)) {
        jsonResponse(false, $exclude_id ? '该学生已经担任其他班级管理人职务' : '该学生已经是班级管理人');
    }
    if (isPositionTaken($position, $exclude_id)) {
        jsonResponse(false, $exclude_id ? '该职务已有其他人担任' : '该职务已有人担任');
    }
    
    return [
        'student_id' => $student_id,
        'link_student_id' => $link_student_id,
        'position' => $position,
        'start_date' => $start_date,
        'password' => $password
    ];
}

// 统一的JSON响应函数
function jsonResponse($success, $message, $data = null) {
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response);
    exit;
}

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'get_students':
                $pdo = getDatabaseConnection();
                $sql = "SELECT student_id, student_id as id, name FROM students ORDER BY name";
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                releaseDatabaseConnection($pdo);
                jsonResponse(true, '', ['students' => $students]);
                
            case 'add_committee':
                $data = validateCommitteeData($_POST);
                
                // 插入数据
                $pdo = getDatabaseConnection();
                $final_password = empty($data['password']) ? '123456' : $data['password'];
                $hashed_password = password_hash($final_password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO class_committee (student_id, link_student_id, position, start_date, password) VALUES (?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$data['student_id'], $data['link_student_id'], $data['position'], $data['start_date'], $hashed_password]);
                releaseDatabaseConnection($pdo);
                
                jsonResponse(true, '添加班级管理人成功');
                
            case 'get_committee':
                $id = validateId($_POST['id'] ?? '');
                
                $pdo = getDatabaseConnection();
                $sql = "SELECT * FROM class_committee WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id]);
                $committee = $stmt->fetch(PDO::FETCH_ASSOC);
                releaseDatabaseConnection($pdo);
                
                jsonResponse($committee ? true : false, $committee ? '' : '班级管理人信息不存在', $committee ? ['committee' => $committee] : null);
                
            case 'edit_committee':
                $id = validateId($_POST['id'] ?? '');
                $data = validateCommitteeData($_POST, $id);
                
                // 更新数据
                $pdo = getDatabaseConnection();
                $params = [$data['student_id'], $data['link_student_id'], $data['position'], $data['start_date']];
                $sql = "UPDATE class_committee SET student_id = ?, link_student_id = ?, position = ?, start_date = ?";
                
                if (!empty($data['password'])) {
                    $sql .= ", password = ?";
                    $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
                }
                
                $sql .= " WHERE id = ?";
                $params[] = $id;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                releaseDatabaseConnection($pdo);
                
                jsonResponse(true, '编辑班级管理人成功');
                
            case 'remove_committee':
                $id = validateId($_POST['id'] ?? '');
                
                $pdo = getDatabaseConnection();
                
                // 删除班委记录
                $sql = "DELETE FROM class_committee WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id]);
                releaseDatabaseConnection($pdo);
                
                jsonResponse(true, '免职成功，已清除相关登录状态');
                
            case 'get_committee_data':
                syncCommitteeStudentIds();
                $committees = getCommitteeData();
                jsonResponse(true, '', ['committees' => $committees]);
                
            case 'sync_student_ids':
                $updatedCount = syncCommitteeStudentIds();
                jsonResponse(true, "已同步{$updatedCount}条班级管理人学号信息", ['updated_count' => $updatedCount]);
                
            default:
                jsonResponse(false, '无效的操作');
        }
    } catch (Exception $e) {
        $msg = $e->getMessage();
        $errors = [
            'Duplicate entry' => [
                'student_id' => '该学号已存在班级管理人记录',
                'position' => '该职务已有人担任',
                'default' => '数据重复，请检查输入信息'
            ],
            'Data too long' => '输入数据过长，请检查输入内容',
            'Cannot add or update a child row' => '学生信息不存在，请检查学号'
        ];
        
        foreach ($errors as $key => $value) {
            if (strpos($msg, $key) !== false) {
                if (is_array($value)) {
                    foreach ($value as $subkey => $submsg) {
                        if ($subkey === 'default' || strpos($msg, $subkey) !== false) {
                            jsonResponse(false, $submsg);
                        }
                    }
                } else {
                    jsonResponse(false, $value);
                }
            }
        }
        
        jsonResponse(false, '操作失败，请稍后再试');
    }
}

// 获取班级管理人数据
$committees = getCommitteeData();
$totalCount = count($committees);
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操行分管理系统 - 班级管理人管理</title>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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

        .action-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }



        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            min-width: 80px;
            justify-content: center;
        }

        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #27ae60; color: white; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-danger { background: #6c757d; color: white; }
        .btn-edit { background: #3498db; color: white; }
        .btn-delete { background: #e74c3c; color: white; }

        .btn-primary:hover { background: #0056b3; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3); }
        .btn-success:hover { background: #229954; }
        .btn-warning:hover { background: #e67e22; }
        .btn-danger:hover { background: #545b62; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3); }
        .btn-edit:hover { background: #2980b9; }
        .btn-delete:hover { background: #c0392b; }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2c3e50;
        }

        .table-container {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .table th:last-child,
        .table td:last-child {
            text-align: center;
        }

        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        .position-badge,
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .position-monitor { background: #e8f5e8; color: #27ae60; }
        .position-deputy { background: #e3f2fd; color: #2196f3; }
        .position-study { background: #fff3e0; color: #ff9800; }
        .position-discipline { background: #fce4ec; color: #e91e63; }
        .position-life { background: #f3e5f5; color: #9c27b0; }
        .position-sports { background: #e0f2f1; color: #009688; }
        .position-culture { background: #fff8e1; color: #ffc107; }
        .position-other { background: #f5f5f5; color: #757575; }

        .status-active {
            background: #d5f4e6;
            color: #27ae60;
        }

        .status-inactive {
            background: #fadbd8;
            color: #e74c3c;
        }

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
            margin: 8% auto;
            padding: 0;
            border-radius: 16px;
            width: 90%;
            max-width: 480px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            max-height: 85vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 24px 24px 20px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafafa;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #333;
            margin: 0;
        }

        .close {
            color: #999;
            font-size: 24px;
            font-weight: normal;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
        }

        .close:hover {
            color: #666;
            background: #f0f0f0;
        }

        .modal-body {
            padding: 24px;
            flex: 1;
            overflow-y: auto;
            background: white;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            color: #333;
            background: #fff;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .form-control::placeholder {
            color: #999;
        }

        .select-wrapper {
            position: relative;
        }

        .search-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-input {
            position: relative;
            z-index: 2;
            flex: 1;
            padding-right: 40px;
        }

        .clear-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            padding: 4px;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            z-index: 3;
        }

        .clear-btn:hover {
            background: #f0f0f0;
            color: #666;
        }

        .clear-btn i {
            font-size: 12px;
        }

        .student-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .student-option {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s ease;
        }

        .student-option:hover {
            background-color: #f8f9fa;
        }

        .student-option:last-child {
            border-bottom: none;
        }

        .student-option.selected {
            background-color: #e3f2fd;
            color: #1976d2;
        }

        .student-name {
            font-weight: 500;
            color: #333;
        }

        .student-id {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }



        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #fafafa;
            flex-shrink: 0;
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #3498db;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
        }

        .action-buttons .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
        }



        /* 响应式设计 */
        @media (max-width: 768px) {
            .main-layout {
                padding-left: 0;
            }

            .action-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .table-container {
                font-size: 14px;
            }

            .table th,
            .table td {
                padding: 10px;
            }


        }
    </style>
</head>
<body>
<?php include '../../modules/admin_sidebar.php'; ?>

    <div class="main-layout">
        <div class="container">
            <div class="header">
                <h1>班级管理人管理</h1>
                <p>操行分管理系统 | Behavior Score Management System</p>
            </div>

            <div class="action-bar">
                <button class="btn btn-success" onclick="openAddModal()">
                    <i class="fa-solid fa-plus"></i> 添加班级管理人
                </button>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-title">班级管理人列表</div>
                    <div>
                        <span id="totalCount">共 <?php echo $totalCount; ?> 名班级管理人</span>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table" id="committeeTable">
                        <thead>
                            <tr>
                                <th>学生信息</th>
                                <th>职务</th>
                                <th>任职时间</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($committees)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px; color: #666;">
                                    <i class="fa-solid fa-users" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
                                    <div>暂无班级管理人数据</div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($committees as $committee): ?>
                            <?php 
                                // 获取职务对应的CSS类
                                $positionClass = 'position-other';
                                switch ($committee['position']) {
                                    case '班长': $positionClass = 'position-monitor'; break;
                                    case '副班长': $positionClass = 'position-deputy'; break;
                                    case '学习委员': $positionClass = 'position-study'; break;
                                    case '纪律委员': $positionClass = 'position-discipline'; break;
                                    case '生活委员': $positionClass = 'position-life'; break;
                                    case '体育委员': $positionClass = 'position-sports'; break;
                                    case '文艺委员': $positionClass = 'position-culture'; break;
                                }
                                // 获取学生姓名首字
                                $firstChar = mb_substr($committee['name'], 0, 1, 'UTF-8');
                            ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <div class="student-avatar"><?php echo htmlspecialchars($firstChar); ?></div>
                                        <div>
                                            <div><?php echo htmlspecialchars($committee['name']); ?></div>
                                            <div style="font-size: 12px; color: #666;"><?php echo htmlspecialchars($committee['student_number']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="position-badge <?php echo $positionClass; ?>"><?php echo htmlspecialchars($committee['position']); ?></span></td>
                                <td><?php echo htmlspecialchars($committee['start_date']); ?></td>
                                <td><span class="status-badge status-active">在职</span></td>

                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-edit" onclick="editCommittee(<?php echo $committee['id']; ?>)">
                                            <i class="fa-solid fa-edit"></i> 编辑
                                        </button>
                                        <button class="btn btn-delete" onclick="removeCommittee(<?php echo $committee['id']; ?>)">
                                            <i class="fa-solid fa-user-minus"></i> 免职
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 添加班级管理人模态框 -->
    <div id="addCommitteeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">添加班级管理人</h2>
                <span class="close" onclick="closeAddModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addCommitteeForm">
                    <div class="form-group">
                        <label class="form-label" for="addStudentSelect">选择学生</label>
                        <div class="select-wrapper">
                            <div class="search-input-wrapper">
                                <input type="text" class="form-control search-input" id="addStudentSearch" placeholder="搜索学生姓名或学号" autocomplete="off">
                                <button type="button" class="clear-btn" id="addClearBtn" onclick="clearStudentSelection('add')" style="display: none;">
                                    <i class="fa-solid fa-times"></i>
                                </button>
                            </div>
                            <select class="form-control" id="addStudentSelect" name="studentSelect" required style="display: none;">
                                <option value="">请选择学生</option>
                            </select>
                            <div class="student-dropdown" id="addStudentDropdown"></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="addPositionSelect">职务</label>
                        <select class="form-control" id="addPositionSelect" name="positionSelect" required>
                            <option value="">请选择职务</option>
                            <option value="班长">班长</option>
                            <option value="副班长">副班长</option>
                            <option value="学习委员">学习委员</option>
                            <option value="纪律委员">纪律委员</option>
                            <option value="生活委员">生活委员</option>
                            <option value="体育委员">体育委员</option>
                            <option value="文艺委员">文艺委员</option>
                            <option value="宣传委员">宣传委员</option>
                            <option value="组织委员">组织委员</option>
                            <option value="其他">其他</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="addAppointmentDate">任职时间 <span style="color: red;">*</span></label>
                        <input type="text" class="form-control" id="addAppointmentDate" name="appointmentDate" placeholder="请输入日期, 格式: YYYY-MM-DD" pattern="\d{4}-\d{2}-\d{2}" autocomplete="off" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="addPassword">登录密码</label>
                        <input type="password" class="form-control" id="addPassword" name="password" placeholder="班级管理人账户登录密码，留空则使用默认密码: 123456" autocomplete="new-password">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="saveCommittee('add')">
                    <i class="fa-solid fa-save"></i> 保存
                </button>
                <button type="button" class="btn btn-danger" onclick="closeAddModal()">
                    <i class="fa-solid fa-times"></i> 取消
                </button>
            </div>
        </div>
    </div>

    <!-- 编辑班级管理人模态框 -->
    <div id="editCommitteeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">编辑班级管理人</h2>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editCommitteeForm">
                    <div class="form-group">
                        <label class="form-label">当前学生</label>
                        <div class="form-control" id="currentStudentInfo" style="background-color: #f8f9fa; color: #666; cursor: not-allowed;">
                            <!-- 显示当前班级管理人学生信息 -->
                        </div>
                        <input type="hidden" id="editStudentSelect" name="studentSelect" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editPositionSelect">职务</label>
                        <select class="form-control" id="editPositionSelect" name="positionSelect" required>
                            <option value="">请选择职务</option>
                            <option value="班长">班长</option>
                            <option value="副班长">副班长</option>
                            <option value="学习委员">学习委员</option>
                            <option value="纪律委员">纪律委员</option>
                            <option value="生活委员">生活委员</option>
                            <option value="体育委员">体育委员</option>
                            <option value="文艺委员">文艺委员</option>
                            <option value="宣传委员">宣传委员</option>
                            <option value="组织委员">组织委员</option>
                            <option value="其他">其他</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editAppointmentDate">任职时间 <span style="color: red;">*</span></label>
                        <input type="text" class="form-control" id="editAppointmentDate" name="appointmentDate" placeholder="请输入日期，格式：yyyy-mm-dd" pattern="\d{4}-\d{2}-\d{2}" autocomplete="off" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editPassword">登录密码</label>
                        <input type="password" class="form-control" id="editPassword" name="password" placeholder="留空表示不修改密码" autocomplete="new-password">
                        <small style="color: #666; font-size: 12px; margin-top: 4px; display: block;">编辑时留空表示不修改密码</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="saveCommittee('edit')">
                    <i class="fa-solid fa-save"></i> 保存
                </button>
                <button type="button" class="btn btn-danger" onclick="closeEditModal()">
                    <i class="fa-solid fa-times"></i> 取消
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentEditId = null;
        let committees = <?php echo json_encode($committees); ?>;
        let allStudents = [];
        let selectedStudentId = '';
        
        // 格式化日期输入
        function formatDateInput(input) {
            let value = input.value.replace(/\D/g, ''); // 只保留数字
            
            if (value.length >= 4) {
                value = value.substring(0, 4) + '-' + value.substring(4);
            }
            if (value.length >= 7) {
                value = value.substring(0, 7) + '-' + value.substring(7, 9);
            }
            
            input.value = value;
        }

        // 处理退格键删除分隔符
        function handleDateKeydown(event) {
            const input = event.target;
            const cursorPos = input.selectionStart;
            const value = input.value;
            
            // 如果按下退格键且光标前面是分隔符
            if (event.key === 'Backspace' && cursorPos > 0 && value[cursorPos - 1] === '-') {
                event.preventDefault();
                // 删除分隔符前的数字
                const newValue = value.substring(0, cursorPos - 2) + value.substring(cursorPos);
                input.value = newValue;
                // 设置光标位置
                setTimeout(() => {
                    input.setSelectionRange(cursorPos - 2, cursorPos - 2);
                }, 0);
            }
        }

        // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            loadStudents();
            loadCommitteeData();
            
            // 为日期输入框绑定格式化事件
            const addAppointmentDate = document.getElementById('addAppointmentDate');
            const editAppointmentDate = document.getElementById('editAppointmentDate');
            
            if (addAppointmentDate) {
                addAppointmentDate.addEventListener('input', function() {
                    formatDateInput(this);
                });
                addAppointmentDate.addEventListener('keydown', handleDateKeydown);
            }
            
            if (editAppointmentDate) {
                editAppointmentDate.addEventListener('input', function() {
                    formatDateInput(this);
                });
                editAppointmentDate.addEventListener('keydown', handleDateKeydown);
            }
            
            // 全局点击事件：点击外部关闭下拉框
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.select-wrapper')) {
                    const dropdowns = document.querySelectorAll('[id$="StudentDropdown"]');
                    dropdowns.forEach(dropdown => dropdown.style.display = 'none');
                }
            });
        });
        
        // 加载班委数据（带防抖和加载状态管理）
        function loadCommitteeData() {
            fetchData('get_committee_data')
            .then(data => {
                if (data.success) {
                    committees = data.committees; // 更新全局班委数据
                    updateCommitteeTable(data.committees);
                    document.getElementById('totalCount').textContent = `共 ${data.committees.length} 名班级管理人`;
                } else {
                    showMessage('error', '加载班级管理人数据失败，请稍后再试');
                }
            })
            .catch(error => {
                showMessage('error', '加载班级管理人数据失败，请稍后再试');
            });
        }
        
        // 更新班委表格
        function updateCommitteeTable(committees) {
            const tbody = document.querySelector('#committeeTable tbody');
            
            if (committees.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #666;">
                            <i class="fa-solid fa-users" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
                            <div>暂无班级管理人数据</div>
                        </td>
                    </tr>
                `;
                return;
            }
            
            tbody.innerHTML = committees.map(committee => {
                const positionClass = getPositionClass(committee.position);
                const firstChar = committee.name.charAt(0);
                
                return `
                    <tr>
                        <td>
                            <div class="student-info">
                                <div class="student-avatar">${firstChar}</div>
                                <div>
                                    <div>${committee.name}</div>
                                    <div style="font-size: 12px; color: #666;">${committee.student_number}</div>
                                </div>
                            </div>
                        </td>
                        <td><span class="position-badge ${positionClass}">${committee.position}</span></td>
                        <td>${committee.start_date}</td>
                        <td><span class="status-badge status-active">在职</span></td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn btn-edit" onclick="editCommittee(${committee.id})">
                                    <i class="fa-solid fa-edit"></i> 编辑
                                </button>
                                <button class="btn btn-delete" onclick="removeCommittee(${committee.id})">
                                    <i class="fa-solid fa-user-minus"></i> 免职
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }
        
        // 获取职务对应的CSS类
         function getPositionClass(position) {
             const positionMap = {
                 '班长': 'position-monitor',
                 '副班长': 'position-deputy',
                 '学习委员': 'position-study',
                 '纪律委员': 'position-discipline',
                 '生活委员': 'position-life',
                 '体育委员': 'position-sports',
                 '文艺委员': 'position-culture'
             };
             return positionMap[position] || 'position-other';
         }
 

        // 加载学生列表（带缓存机制）
        function loadStudents() {
            fetchData('get_students')
            .then(data => {
                if (data.success) {
                    allStudents = data.students; // 保存到全局变量
                    updateStudentSelect(data.students);
                } else {
                    showMessage('error', '加载学生数据失败');
                }
            })
            .catch(error => {
                showMessage('error', '加载学生数据失败');
            });
        }

        // 更新学生选择下拉框
        function updateStudentSelect(students) {
            const availableStudents = getAvailableStudents(students);
            const optionsHtml = '<option value="">请选择学生</option>' + 
                availableStudents.map(student => 
                    `<option value="${student.student_id}">${student.name} (${student.student_id})</option>`
                ).join('');
            
            // 更新添加模态框的选择框
            const addSelect = document.getElementById('addStudentSelect');
            if (addSelect) {
                addSelect.innerHTML = optionsHtml;
            }
            
            // 更新编辑模态框的选择框
            const editSelect = document.getElementById('editStudentSelect');
            if (editSelect) {
                editSelect.innerHTML = optionsHtml;
            }
        }

        // 获取可用学生列表（排除已是班委的学生）
        function getAvailableStudents(students) {
            // 获取当前班委的学号列表
            const committeeStudentIds = committees.map(committee => committee.student_number);
            
            // 如果是编辑模式，允许当前编辑的班委学生出现在列表中
            let excludeIds = committeeStudentIds;
            if (currentEditId) {
                const currentCommittee = committees.find(c => c.id == currentEditId);
                if (currentCommittee) {
                    excludeIds = committeeStudentIds.filter(id => id !== currentCommittee.student_number);
                }
            }
            
            // 过滤掉已是班委的学生
            return students.filter(student => !excludeIds.includes(student.student_id));
        }

        // 渲染学生选项
        function renderStudentOptions(students) {
            return students.length > 0 
                ? students.map(student => 
                    `<div class="student-option" data-id="${student.student_id}">
                        <div class="student-name">${student.name}</div>
                        <div class="student-id">${student.student_id}</div>
                    </div>`
                ).join('')
                : '<div class="student-option" style="color: #999; cursor: default;">未找到可用的学生</div>';
        }

        // 初始化学生搜索功能
        function initStudentSearch(modalType) {
            const prefix = modalType === 'add' ? 'add' : 'edit';
            const searchInput = document.getElementById(`${prefix}StudentSearch`);
            const dropdown = document.getElementById(`${prefix}StudentDropdown`);
            const hiddenSelect = document.getElementById(`${prefix}StudentSelect`);
            const clearBtn = document.getElementById(`${prefix}ClearBtn`);

            // 显示下拉选项
            const showDropdown = (students) => {
                dropdown.innerHTML = renderStudentOptions(students);
                dropdown.style.display = 'block';
            };

            // 搜索输入事件
            searchInput.oninput = function() {
                const query = this.value.toLowerCase().trim();
                clearBtn.style.display = this.value.trim() ? 'flex' : 'none';
                
                if (!query) {
                    dropdown.style.display = 'none';
                    selectedStudentId = '';
                    hiddenSelect.value = '';
                    return;
                }

                const filteredStudents = getAvailableStudents(allStudents).filter(student => 
                    student.name.toLowerCase().includes(query) || 
                    student.student_id.toLowerCase().includes(query)
                );
                showDropdown(filteredStudents);
            };

            // 选择学生
            dropdown.onclick = function(e) {
                const option = e.target.closest('.student-option');
                if (option && option.dataset.id) {
                    const student = allStudents.find(s => s.student_id === option.dataset.id);
                    if (student) {
                        searchInput.value = `${student.name} (${student.student_id})`;
                        hiddenSelect.value = student.student_id;
                        selectedStudentId = student.student_id;
                        dropdown.style.display = 'none';
                        clearBtn.style.display = 'flex';
                    }
                }
            };

            // 获得焦点时显示选项
            searchInput.onfocus = function() {
                if (!this.value) showDropdown(getAvailableStudents(allStudents));
            };
        }
        

        
        // 清除学生选择
        function clearStudentSelection(mode) {
            const searchInput = document.getElementById(mode + 'StudentSearch');
            const hiddenSelect = document.getElementById(mode + 'StudentSelect');
            const dropdown = document.getElementById(mode + 'StudentDropdown');
            const clearBtn = document.getElementById(mode + 'ClearBtn');
            
            searchInput.value = '';
            hiddenSelect.value = '';
            selectedStudentId = '';
            dropdown.style.display = 'none';
            clearBtn.style.display = 'none';
            searchInput.focus();
        }
        
        // 打开添加班委模态框
        function openAddModal() {
            currentEditId = null;
            selectedStudentId = '';
            
            document.getElementById('addCommitteeForm').reset();
            document.getElementById('addPassword').value = '';
            document.getElementById('addStudentSearch').value = '';
            document.getElementById('addStudentDropdown').style.display = 'none';
            document.getElementById('addClearBtn').style.display = 'none';
            document.getElementById('addCommitteeModal').style.display = 'block';
            
            setTimeout(() => initStudentSearch('add'), 100);
        }
        
        // 编辑班级管理人
        function editCommittee(id) {
            currentEditId = id;
            
            fetchData('get_committee', {id})
            .then(data => {
                if (data.success) {
                    const committee = data.committee;
                    
                    // 重置编辑表单
                    document.getElementById('editCommitteeForm').reset();
                    document.getElementById('editPassword').value = '';
                    
                    // 设置表单值
                    document.getElementById('editStudentSelect').value = committee.student_id;
                    document.getElementById('editPositionSelect').value = committee.position;
                    document.getElementById('editAppointmentDate').value = committee.start_date;
                    
                    // 显示当前学生信息（不可编辑）
                    const student = allStudents.find(s => s.student_id === committee.student_id);
                    const currentStudentInfo = document.getElementById('currentStudentInfo');
                    if (student) {
                        currentStudentInfo.textContent = `${student.name} (${student.student_id})`;
                    } else {
                        currentStudentInfo.textContent = `学号: ${committee.student_id}`;
                    }
                    
                    document.getElementById('editCommitteeModal').style.display = 'block';
                } else {
                    showMessage('error', '获取班级管理人信息失败，请稍后再试');
                }
            })
            .catch(error => {
                showMessage('error', '获取班级管理人信息失败，请稍后再试');
            })
            };
        
        // 通用提示函数
        function showMessage(type, message) {
            if (typeof notification !== 'undefined') {
                notification[type](message);
            } else {
                alert(message);
            }
        }
        
        // 保存班级管理人
        function saveCommittee(modalType) {
            const prefix = modalType === 'add' ? 'add' : 'edit';
            const studentSelect = modalType === 'add' ? selectedStudentId : document.getElementById('editStudentSelect').value;
            const positionSelect = document.getElementById(`${prefix}PositionSelect`).value;
            const appointmentDate = document.getElementById(`${prefix}AppointmentDate`).value;
            
            // 表单验证
            if (!studentSelect) return showMessage('warning', '请选择学生');
            if (!positionSelect) return showMessage('warning', '请选择职务');
            if (!appointmentDate) return showMessage('warning', '请填写任职时间');
            if (!/^\d{4}-\d{2}-\d{2}$/.test(appointmentDate)) return showMessage('warning', '请输入正确的日期格式');
            
            const date = new Date(appointmentDate);
            if (isNaN(date.getTime()) || appointmentDate !== date.toISOString().split('T')[0]) {
                return showMessage('warning', '请输入有效的日期');
            }
            
            const formData = new FormData(document.getElementById(`${prefix}CommitteeForm`));
            const data = Object.fromEntries(formData.entries());
            data.studentSelect = studentSelect;
            if (modalType === 'edit' && currentEditId) data.id = currentEditId;
            
            const action = modalType === 'edit' ? 'edit_committee' : 'add_committee';
            fetchData(action, data)
            .then(result => {
                if (result.success) {
                    showMessage('success', '操作成功');
                    modalType === 'add' ? closeAddModal() : closeEditModal();
                    setTimeout(() => loadCommitteeData(), 1000);
                } else {
                    showMessage('error', result.message || '操作失败，请稍后再试');
                }
            })
            .catch(() => showMessage('error', '保存失败，请稍后再试'));
        }
        
        // 删除班级管理人
        function removeCommittee(id) {
            const confirmMessage = '确认免职此班级管理人吗？免职后该班级管理人的操作账户将即刻被踢下线并删除';
            
            const executeDelete = () => {
                fetchData('remove_committee', {id})
                .then(data => {
                    if (data.success) {
                        showMessage('success', '班级管理人免职成功！');
                        setTimeout(() => loadCommitteeData(), 500);
                    } else {
                        showMessage('error', '免职失败，请稍后再试');
                    }
                })
                .catch(() => showMessage('error', '操作失败，请稍后再试'));
            };
            
            if (typeof notification !== 'undefined') {
                notification.confirm(confirmMessage, '确认免职', {
                    onConfirm: executeDelete
                });
            } else if (confirm(confirmMessage)) {
                executeDelete();
            }
        }
        
        // 关闭模态框
        function closeAddModal() {
            document.getElementById('addCommitteeModal').style.display = 'none';
            document.getElementById('addClearBtn').style.display = 'none';
            document.getElementById('addStudentSearch').value = '';
            document.getElementById('addStudentDropdown').style.display = 'none';
            selectedStudentId = '';
            currentEditId = null;
        }
        
        function closeEditModal() {
            document.getElementById('editCommitteeModal').style.display = 'none';
            currentEditId = null;
        }
        
        // 显示通知
        // 通用fetch请求函数
        function fetchData(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            Object.entries(data).forEach(([key, value]) => formData.append(key, value));
            
            return fetch('committee_manager.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json());
        }
        


    </script>

<?php include '../../modules/notification.php'; ?>

</body>
</html>