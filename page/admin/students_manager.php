<?php
// 定义常量以允许包含的文件访问
define('INCLUDED_FROM_APP', true);

/**
 * 学生管理页面
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
$database = new Database();

// 验证用户是否已登录
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    // 尝试自动登录
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        
        try {
            $redis = $database->getRedisConnection('session');
            $user_data = $redis->get("remember_token:" . $token);
            
            if ($user_data) {
                $user_info = json_decode($user_data, true);
                
                // 检查token是否过期，使用expire_time字段
                if (isset($user_info['expire_time']) && $user_info['expire_time'] > time() && $user_info['user_type'] === 'admin') {
                    // 只允许管理员自动登录到admin页面
                    $pdo = $database->getMysqlConnection();
                    $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
                    $stmt->execute([$user_info['username']]);
                    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                    $database->releaseMysqlConnection($pdo);
                    
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
            $database->releaseRedisConnection($redis);
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
            $redis = $database->getRedisConnection('session');
            $redis->del("remember_token:" . $token);
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
    global $database;
    return $database->getMysqlConnection();
}

// 释放数据库连接
function releaseDatabaseConnection($pdo) {
    global $database;
    $database->releaseMysqlConnection($pdo);
}

// 验证学号格式
function validateStudentId($student_id) {
    return preg_match('/^[0-9]+$/', $student_id) && strlen($student_id) > 0;
}

// 获取学生数据函数
function getStudentsData($page = 1, $limit = 10, $search = '') {
    $pdo = null;
    try {
        $pdo = getDatabaseConnection();
        
        // 构建搜索条件
        $where = '';
        $params = [];
        if (!empty($search)) {
            // 智能搜索逻辑
            $searchConditions = [];
            
            // 如果搜索内容是纯数字且长度为2，优先匹配学号后两位
            if (preg_match('/^\d{2}$/', $search)) {
                $searchConditions[] = 'student_id LIKE :search_suffix';
                $params['search_suffix'] = '%' . $search;
            }
            
            // 如果搜索内容是纯数字且长度大于2，匹配完整学号
            if (preg_match('/^\d{3,}$/', $search)) {
                $searchConditions[] = 'student_id LIKE :search_full';
                $params['search_full'] = '%' . $search . '%';
            }
            
            // 总是包含姓名搜索
            $searchConditions[] = 'name LIKE :search_name';
            $params['search_name'] = '%' . $search . '%';
            
            // 如果不是纯数字，也搜索学号（支持混合搜索）
            if (!preg_match('/^\d+$/', $search)) {
                $searchConditions[] = 'student_id LIKE :search_mixed';
                $params['search_mixed'] = '%' . $search . '%';
            }
            
            $where = 'WHERE (' . implode(' OR ', $searchConditions) . ')';
        }
        
        // 获取总数
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students $where");
        $stmt->execute($params);
        $total = $stmt->fetch()['total'];
        
        // 获取学生列表
        $offset = ($page - 1) * $limit;
        $stmt = $pdo->prepare("SELECT id, student_id, name, current_score, status, appeal_permission, created_at FROM students $where ORDER BY student_id ASC LIMIT :limit OFFSET :offset");
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        $students = $stmt->fetchAll();
        
        $result = [
            'success' => true,
            'students' => $students,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ];
        
        releaseDatabaseConnection($pdo);
        return $result;
    } catch(PDOException $e) {
        if ($pdo) releaseDatabaseConnection($pdo);
        return ['success' => false, 'error' => '数据获取失败'];
    }
}

// 获取单个学生信息
function getStudentById($id) {
    $pdo = null;
    try {
        $pdo = getDatabaseConnection();
        
        $stmt = $pdo->prepare("SELECT id, student_id, name, current_score, status, appeal_permission FROM students WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $student = $stmt->fetch();
        
        $result = $student ? ['success' => true, 'student' => $student] : ['success' => false, 'error' => '学生不存在'];
        
        releaseDatabaseConnection($pdo);
        return $result;
    } catch(PDOException $e) {
        if ($pdo) releaseDatabaseConnection($pdo);
        return ['success' => false, 'error' => '数据获取失败'];
    }
}

// 添加学生信息
function addStudent($name, $student_id, $score, $status, $appeal_permission) {
    $pdo = null;
    try {
        // 验证学号格式（必须为数字）
        if (!validateStudentId($student_id)) {
            return ['success' => false, 'error' => '学号必须为数字'];
        }
        
        $pdo = getDatabaseConnection();
        
        // 检查学号是否已存在
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE student_id = :student_id");
        $stmt->bindValue(':student_id', $student_id);
        $stmt->execute();
        
        if ($stmt->fetchColumn() > 0) {
            releaseDatabaseConnection($pdo);
            return ['success' => false, 'error' => '学号已存在'];
        }
        
        // 插入新学生
        $stmt = $pdo->prepare("INSERT INTO students (name, student_id, current_score, status, appeal_permission, created_at) VALUES (:name, :student_id, :score, :status, :appeal_permission, NOW())");
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':student_id', $student_id);
        $stmt->bindValue(':score', $score);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':appeal_permission', $appeal_permission, PDO::PARAM_INT);
        
        $stmt->execute();
        
        // 清空缓存数据库 - 用于数据更新后重新读取
        try {
            $db = new Database();
            $db->clearCacheForDataUpdate();
        } catch (Exception $cacheError) {
            error_log('清空Redis缓存失败: ' . $cacheError->getMessage());
            // 缓存清理失败不影响主要操作，只记录日志
        }
        
        releaseDatabaseConnection($pdo);
        return ['success' => true, 'message' => '学生添加成功'];
    } catch(PDOException $e) {
        if ($pdo) releaseDatabaseConnection($pdo);
        return ['success' => false, 'error' => '学生添加失败'];
    }
}

// 更新学生信息
function updateStudent($id, $name, $student_id, $score, $status, $appeal_permission) {
    $pdo = null;
    try {
        // 验证学号格式（必须为数字）
        if (!validateStudentId($student_id)) {
            return ['success' => false, 'error' => '学号必须为数字'];
        }
        
        $pdo = getDatabaseConnection();
        
        // 开始事务
        $pdo->beginTransaction();
        
        // 检查学号是否被其他学生使用
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE student_id = :student_id AND id != :id");
        $stmt->bindValue(':student_id', $student_id);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->fetchColumn() > 0) {
            $pdo->rollBack();
            releaseDatabaseConnection($pdo);
            return ['success' => false, 'error' => '学号已被其他学生使用'];
        }
        
        // 获取原来的学号，用于更新 class_committee 表
        $stmt = $pdo->prepare("SELECT student_id FROM students WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $old_student_id = $stmt->fetchColumn();
        
        // 更新学生信息
        $stmt = $pdo->prepare("UPDATE students SET name = :name, student_id = :student_id, current_score = :score, status = :status, appeal_permission = :appeal_permission WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':student_id', $student_id);
        $stmt->bindValue(':score', $score);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':appeal_permission', $appeal_permission, PDO::PARAM_INT);
        
        $stmt->execute();
        
        // 如果学号发生了变化，同步更新 class_committee 表中的 student_id
        if ($old_student_id !== $student_id) {
            $stmt = $pdo->prepare("UPDATE class_committee SET student_id = :new_student_id WHERE student_id = :old_student_id");
            $stmt->bindValue(':new_student_id', $student_id);
            $stmt->bindValue(':old_student_id', $old_student_id);
            $stmt->execute();
        }
        
        // 提交事务
        $pdo->commit();
        
        // 清空缓存数据库 - 用于数据更新后重新读取
        try {
            $db = new Database();
            $db->clearCacheForDataUpdate();
        } catch (Exception $cacheError) {
            error_log('清空Redis缓存失败: ' . $cacheError->getMessage());
            // 缓存清理失败不影响主要操作，只记录日志
        }
        
        releaseDatabaseConnection($pdo);
        return ['success' => true, 'message' => '学生信息更新成功'];
    } catch(PDOException $e) {
        // 回滚事务
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($pdo) releaseDatabaseConnection($pdo);
        return ['success' => false, 'error' => '学生信息更新失败'];
    }
}

// 批量添加学生
function batchAddStudents($studentsData) {
    try {
        $pdo = getDatabaseConnection();
        
        $pdo->beginTransaction();
        
        $successCount = 0;
        $errors = [];
        
        foreach ($studentsData as $index => $studentData) {
            $lineNumber = $index + 1;
            
            // 验证数据格式
            if (count($studentData) < 3) {
                $errors[] = "第{$lineNumber}行 数据格式不正确";
                continue;
            }
            
            $name = trim($studentData[0]);
            $student_id = trim($studentData[1]);
            $score = floatval($studentData[2]);
            
            // 处理可选字段：状态和申诉权限
            $status = (count($studentData) >= 4 && trim($studentData[3]) === '0') ? 'inactive' : 'active';
            $appeal_permission = (count($studentData) >= 5 && trim($studentData[4]) === '0') ? 0 : 1;
            
            // 验证数据有效性
            if (empty($name)) {
                $errors[] = "第{$lineNumber}行 姓名不能为空";
                continue;
            }
            
            if (empty($student_id)) {
                $errors[] = "第{$lineNumber}行 学号不能为空";
                continue;
            }
            
            // 验证学号格式（必须为数字）
            if (!validateStudentId($student_id)) {
                $errors[] = "第{$lineNumber}行 学号必须为数字";
                continue;
            }
            
            if ($score < 0 || $score > 100) {
                $errors[] = "第{$lineNumber}行 操行分必须在0-100之间";
                continue;
            }
            
            // 检查学号是否已存在
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE student_id = :student_id");
            $stmt->bindValue(':student_id', $student_id);
            $stmt->execute();
            
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "第{$lineNumber}行 学号{$student_id}已存在";
                continue;
            }
            
            // 插入学生数据
            $stmt = $pdo->prepare("INSERT INTO students (name, student_id, current_score, status, appeal_permission, created_at) VALUES (:name, :student_id, :score, :status, :appeal_permission, NOW())");
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':student_id', $student_id);
            $stmt->bindValue(':score', $score);
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':appeal_permission', $appeal_permission, PDO::PARAM_INT);
            
            $stmt->execute();
            $successCount++;
        }
        
        $pdo->commit();
        
        $failCount = count($errors);
        $totalCount = $successCount + $failCount;
        
        if ($failCount > 0) {
            $message = "批量添加成功: 成功{$successCount}个 | 失败{$failCount}个 | 共处理{$totalCount}名学生";
        } else {
            $message = "批量添加成功: 共添加{$successCount}名学生";
        }
        
        return ['success' => true, 'message' => $message, 'successCount' => $successCount, 'errors' => $errors];
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => '批量添加失败'];
    }
}

// 删除学生
function deleteStudent($id) {
    $pdo = null;
    try {
        $pdo = getDatabaseConnection();
        
        // 开始事务
        $pdo->beginTransaction();
        
        // 首先检查学生是否存在
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->fetchColumn() == 0) {
            $pdo->rollBack();
            releaseDatabaseConnection($pdo);
            return ['success' => false, 'error' => '学生不存在'];
        }
        
        // 删除conduct_records表中的相关记录
        $stmt = $pdo->prepare("DELETE FROM conduct_records WHERE student_id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        // 删除appeals表中的相关记录（申诉记录）
        $stmt = $pdo->prepare("DELETE FROM appeals WHERE student_id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        // 删除class_committee表中的相关记录（如果存在）
        $stmt = $pdo->prepare("DELETE FROM class_committee WHERE link_student_id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        // 最后删除学生记录
        $stmt = $pdo->prepare("DELETE FROM students WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        // 提交事务
        $pdo->commit();
        
        // 清空缓存数据库 - 用于数据更新后重新读取
        try {
            $db = new Database();
            $db->clearCacheForDataUpdate();
        } catch (Exception $cacheError) {
            error_log('清空Redis缓存失败: ' . $cacheError->getMessage());
            // 缓存清理失败不影响主要操作，只记录日志
        }
        
        releaseDatabaseConnection($pdo);
        return ['success' => true, 'message' => '学生已删除成功'];
        
    } catch(PDOException $e) {
        // 回滚事务
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($pdo) releaseDatabaseConnection($pdo);
        return ['success' => false, 'error' => '学生删除失败，请稍后再试'];
    }
}

// 批量编辑学生
function batchEditStudents($studentIds, $status = null, $appealPermission = null) {
    $pdo = null;
    try {
        $pdo = getDatabaseConnection();
        
        $pdo->beginTransaction();
        
        $successCount = 0;
        $errors = [];
        
        foreach ($studentIds as $id) {
            $id = (int)$id;
            
            // 检查学生是否存在
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->fetchColumn() == 0) {
                $errors[] = "学生不存在";
                continue;
            }
            
            // 构建更新SQL
            $updateFields = [];
            $params = ['id' => $id];
            
            if ($status !== null && $status !== '') {
                $updateFields[] = 'status = :status';
                $params['status'] = $status;
            }
            
            if ($appealPermission !== null && $appealPermission !== '') {
                $updateFields[] = 'appeal_permission = :appeal_permission';
                $params['appeal_permission'] = (int)$appealPermission;
            }
            
            if (empty($updateFields)) {
                continue; // 没有需要更新的字段
            }
            
            $sql = "UPDATE students SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            $stmt->execute();
            $successCount++;
        }
        
        $pdo->commit();
        
        // 清空缓存数据库 - 用于数据更新后重新读取
        try {
            $db = new Database();
            $db->clearCacheForDataUpdate();
        } catch (Exception $cacheError) {
            error_log('清空Redis缓存失败: ' . $cacheError->getMessage());
            // 缓存清理失败不影响主要操作，只记录日志
        }
        
        $failCount = count($errors);
        $totalCount = $successCount + $failCount;
        
        if ($failCount > 0) {
            $message = "批量编辑完成: 成功{$successCount}个 | 失败{$failCount}个 | 共处理{$totalCount}名学生";
        } else {
            $message = "批量编辑成功";
        }
        
        releaseDatabaseConnection($pdo);
        return ['success' => true, 'message' => $message, 'successCount' => $successCount, 'errors' => $errors];
        
    } catch(PDOException $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($pdo) releaseDatabaseConnection($pdo);
        return ['success' => false, 'error' => '批量编辑失败'];
    }
}

// 一键重置所有学生操行分
function resetAllStudentScores() {
    $pdo = null;
    try {
        $pdo = getDatabaseConnection();
        
        // 开始事务
        $pdo->beginTransaction();
        
        // 获取当前学生总数
        $stmt = $pdo->query("SELECT COUNT(*) FROM students");
        $totalStudents = $stmt->fetchColumn();
        
        if ($totalStudents == 0) {
            $pdo->rollBack();
            releaseDatabaseConnection($pdo);
            return ['success' => false, 'error' => '没有学生数据可重置'];
        }
        
        // 清空操行分记录表
        $stmt = $pdo->prepare("DELETE FROM conduct_records");
        $stmt->execute();
        $deletedRecords = $stmt->rowCount();
        
        // 清空申诉记录表
        $stmt = $pdo->prepare("DELETE FROM appeals");
        $stmt->execute();
        $deletedAppeals = $stmt->rowCount();
        
        // 将所有学生的操行分重置为配置文件中的初始分数
        $initial_score = getConfig('conduct_score.initial_score');
        $stmt = $pdo->prepare("UPDATE students SET current_score = :score, updated_at = NOW()");
        $stmt->bindValue(':score', $initial_score);
        $stmt->execute();
        
        $affectedRows = $stmt->rowCount();
        
        // 提交事务
        $pdo->commit();
        
        // 清空缓存数据库 - 用于数据更新后重新读取
        try {
            $db = new Database();
            $db->clearCacheForDataUpdate();
        } catch (Exception $cacheError) {
            error_log('清空Redis缓存失败: ' . $cacheError->getMessage());
            // 缓存清理失败不影响主要操作，只记录日志
        }
        
        releaseDatabaseConnection($pdo);
        return [
            'success' => true, 
            'message' => "成功重置{$affectedRows}名学生，清空{$deletedRecords}条操行分记录，清空{$deletedAppeals}条申诉记录",
            'affectedRows' => $affectedRows,
            'deletedRecords' => $deletedRecords,
            'deletedAppeals' => $deletedAppeals
        ];
        
    } catch(PDOException $e) {
        // 回滚事务
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($pdo) releaseDatabaseConnection($pdo);
        return ['success' => false, 'error' => '重置失败，请稍后再试'];
    }
}

// AJAX请求处理
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    // 获取单个学生信息
    if (isset($_GET['action']) && $_GET['action'] == 'get_student' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        echo json_encode(getStudentById($id));
        exit;
    }
    
    // 获取学生列表
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    echo json_encode(getStudentsData($page, $limit, $search));
    exit;
}

// POST请求处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'add_student':
        case 'update_student':
            $name = trim($_POST['name']);
            $student_id = trim($_POST['student_id']);
            $score = (float)$_POST['score'];
            $status = $_POST['status'];
            $appeal_permission = (int)$_POST['appeal_permission'];
            
            if ($_POST['action'] === 'add_student') {
                echo json_encode(addStudent($name, $student_id, $score, $status, $appeal_permission));
            } else {
                $id = (int)$_POST['id'];
                echo json_encode(updateStudent($id, $name, $student_id, $score, $status, $appeal_permission));
            }
            break;
            
        case 'delete_student':
            $id = (int)$_POST['id'];
            echo json_encode(deleteStudent($id));
            break;
            
        case 'batch_add_students':
            $studentsText = trim($_POST['students_data']);
            if (empty($studentsText)) {
                echo json_encode(['success' => false, 'error' => '请输入学生数据']);
                break;
            }
            
            // 解析文本数据（空格分隔格式）
            $lines = explode("\n", $studentsText);
            $studentsData = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // 使用空格分隔，支持多个连续空格
                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 3) {
                    $studentsData[] = $parts;
                }
            }
            
            if (empty($studentsData)) {
                echo json_encode(['success' => false, 'error' => '没有有效的学生数据']);
                break;
            }
            
            echo json_encode(batchAddStudents($studentsData));
            break;
            
        case 'batch_edit_students':
            $studentIds = isset($_POST['student_ids']) ? json_decode($_POST['student_ids'], true) : [];
            $status = isset($_POST['status']) && $_POST['status'] !== '' ? $_POST['status'] : null;
            $appealPermission = isset($_POST['appeal_permission']) && $_POST['appeal_permission'] !== '' ? $_POST['appeal_permission'] : null;
            
            if (empty($studentIds) || !is_array($studentIds)) {
                echo json_encode(['success' => false, 'error' => '请选择要编辑的学生']);
                break;
            }
            
            if ($status === null && $appealPermission === null) {
                echo json_encode(['success' => false, 'error' => '请至少选择一个要修改的字段']);
                break;
            }
            
            echo json_encode(batchEditStudents($studentIds, $status, $appealPermission));
            break;
            
        case 'reset_all_scores':
            echo json_encode(resetAllStudentScores());
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => '无效的操作']);
    }
    exit;
}

// 获取页面参数
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(10, min(100, (int)$_GET['limit'])) : 10;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// 获取页面初始数据
$data = getStudentsData($page, $limit, $search);
if ($data['success']) {
    extract($data);
} else {
    $students = [];
    $total = 0;
    $total_pages = 0;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操行分管理系统 - 学生管理</title>
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
            transition: all 0.3s ease;
        }

        /* 搜索加载状态样式 */
        .loading-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }
        
        .loading-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #3498db;
            animation: spin 1s linear infinite;
        }
        
        .loading-state p {
            font-size: 1.1rem;
            margin: 0;
        }
        
        /* 错误状态样式 */
        .error-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }
        
        .error-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #e74c3c;
            /* 错误状态不添加旋转动画 */
        }
        
        .error-state p {
            font-size: 1.1rem;
            margin: 0;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .add-btn {
            padding: 12px 24px;
            background: #27ae60;
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

        .add-btn:hover {
            background: #229954;
        }

        .batch-add-btn {
            padding: 12px 24px;
            background: #8e44ad;
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
            margin-left: 10px;
        }

        .batch-add-btn:hover {
            background: #7d3c98;
        }

        .batch-delete-btn {
            padding: 12px 24px;
            background: #e74c3c;
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
            margin-left: 10px;
        }

        .batch-delete-btn:hover {
            background: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
        }

        .batch-edit-btn {
            padding: 12px 24px;
            background: #f39c12;
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
            margin-left: 10px;
        }

        .batch-edit-btn:hover {
            background: #e67e22;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
        }

        .reset-btn {
            padding: 12px 24px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: 10px;
            box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
        }

        .reset-btn:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
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

        /* 学生表格 */
        .students-table {
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

        /* 居中对齐特定列 */
        th:nth-child(1), td:nth-child(1) { text-align: center; } /* 复选框 */
        th:nth-child(3), td:nth-child(3) { text-align: center; } /* 当前操行分 */
        th:nth-child(4), td:nth-child(4) { text-align: center; } /* 状态 */
        th:nth-child(5), td:nth-child(5) { text-align: center; } /* 申诉权限 */
        th:nth-child(6), td:nth-child(6) { text-align: center; } /* 操作 */

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

        .student-info {
            display: flex;
            align-items: center;
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

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .status-active {
            background: #d5f4e6;
            color: #27ae60;
        }

        .status-inactive {
            background: #fadbd8;
            color: #e74c3c;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
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

        .pagination-info select {
            padding: 12px 16px;
            border: 2px solid #e1e8ed;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            color: #2c3e50;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
            appearance: none;
            background-image: 
                linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%),
                url('data:image/svg+xml;charset=US-ASCII,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%234a90e2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6,9 12,15 18,9"></polyline></svg>');
            background-repeat: no-repeat, no-repeat;
            background-position: 0 0, right 12px center;
            background-size: 100% 100%, 16px 16px;
            padding-right: 40px;
            min-width: 100px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            position: relative;
        }

        .pagination-info select:hover {
            border-color: #4a90e2;
            background: linear-gradient(135deg, #ffffff 0%, #f0f7ff 100%);
            box-shadow: 0 4px 16px rgba(74, 144, 226, 0.15);
            transform: translateY(-1px);
        }

        .pagination-info select:focus {
            border-color: #4a90e2;
            background: linear-gradient(135deg, #ffffff 0%, #f0f7ff 100%);
            box-shadow: 0 0 0 4px rgba(74, 144, 226, 0.15), 0 4px 16px rgba(74, 144, 226, 0.2);
            transform: translateY(-1px);
        }

        .pagination-info select:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(74, 144, 226, 0.2);
        }

        /* 每页显示下拉框优化样式 */
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
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            color: #333;
        }

        .close {
            color: #999;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            padding: 5px;
        }

        .close:hover {
            color: #333;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-input:focus {
            outline: none;
            border-color: #3498db;
        }

        .input-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 14px;
        }

        .input-group .form-input {
            padding-left: 35px;
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
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        /* 状态按钮样式 */
        .status-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .status-btn {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            background: #f8f9fa;
            color: #333;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-align: center;
        }

        .status-btn:hover {
            background: #e9ecef;
        }

        .status-btn.active {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        /* 操行分样式 */
        .score-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            text-align: center;
            display: inline-block;
        }

        .score-excellent {
            background: #d5f4e6;
            color: #27ae60;
        }

        .score-good {
            background: #dbeafe;
            color: #3498db;
        }

        .score-warning {
            background: #fef3cd;
            color: #f39c12;
        }

        .score-danger {
            background: #fadbd8;
            color: #e74c3c;
        }

        /* 批量添加模态框样式 */
        .batch-tabs {
            display: flex;
            margin-bottom: 15px;
            border-bottom: 1px solid #ddd;
        }

        .tab-btn {
            flex: 1;
            padding: 10px 15px;
            border: none;
            background: #f5f5f5;
            color: #666;
            cursor: pointer;
            border-radius: 4px 4px 0 0;
            margin-right: 2px;
            font-weight: normal;
            transition: background-color 0.2s;
        }

        .tab-btn:hover {
            background: #e9e9e9;
            color: #333;
        }

        .tab-btn.active {
            background: #007bff;
            color: white;
        }

        .tab-btn.active:hover {
            background: #0056b3;
        }

        .tab-content {
            display: none;
            padding: 15px 0;
        }

        .tab-content.active {
            display: block;
        }

        .batch-textarea {
            width: 100%;
            min-height: 150px;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 14px;
            line-height: 1.5;
            resize: vertical;
            transition: all 0.3s ease;
            background: #fafbfc;
        }
        
        .batch-textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }
        
        .form-hint {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 8px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #17a2b8;
        }
        
        .file-upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            position: relative;
            overflow: hidden;
        }
        
        .file-upload-area:hover {
            border-color: #667eea;
            background: linear-gradient(135deg, #f0f4ff 0%, #ffffff 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, );
        }
        
        .file-upload-area i {
            font-size: 48px;
            color: #dee2e6;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .file-upload-area:hover i {
            color: #667eea;
            transform: scale(1.1);
        }
        
        .file-upload-area p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }
        
        .file-info {
            margin-top: 15px;
            padding: 12px;
            background: #e8f5e8;
            border: 1px solid #c3e6c3;
            border-radius: 6px;
            font-size: 14px;
            color: #155724;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .file-info i {
            font-size: 16px;
        }

        /* 批量编辑模态框样式 */
        .selected-students-list {
            max-height: 150px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            background: #f8f9fa;
            margin-bottom: 15px;
        }

        .selected-student-item {
            display: flex;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }

        .selected-student-item:last-child {
            border-bottom: none;
        }

        .selected-student-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #3498db;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 10px;
            font-size: 12px;
        }

        .selected-student-info {
            flex: 1;
        }

        .selected-student-name {
            font-weight: bold;
            color: #2c3e50;
        }

        .selected-student-id {
            font-size: 12px;
            color: #7f8c8d;
        }

        .loading-text {
            text-align: center;
            padding: 20px;
            color: #3498db;
            font-style: italic;
        }

        .error-text {
            text-align: center;
            padding: 20px;
            color: #e74c3c;
            font-style: italic;
        }

    </style>
</head>
<body>

<?php include '../../modules/admin_sidebar.php'; ?>

<div class="main-layout">
    <div class="container">
        <div class="header">
            <h1>学生管理</h1>
            <p>操行分管理系统 | Behavior Score Management System</p>
        </div>

        <!-- 操作栏 -->
        <div class="action-bar">
            <div class="search-box">
                <div class="search-container">
                    <input type="text" id="searchInput" class="search-input" placeholder="输入姓名或学号进行搜索" value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                    <i class="fa-solid fa-search search-icon"></i>
                </div>
            </div>
            <div class="button-group">
                <button class="add-btn" onclick="openAddModal()">
                    <i class="fa-solid fa-plus"></i> 添加学生
                </button>
                <button class="batch-add-btn" onclick="openBatchAddModal()">
                    <i class="fa-solid fa-upload"></i> 批量添加
                </button>
                <button class="batch-delete-btn" id="batchDeleteBtn" onclick="batchDeleteStudents()" style="display: none;">
                    <i class="fa-solid fa-trash"></i> 批量删除
                </button>
                <button class="batch-edit-btn" id="batchEditBtn" onclick="openBatchEditModal()" style="display: none;">
                    <i class="fa-solid fa-edit"></i> 批量编辑
                </button>
                <button class="reset-btn" onclick="resetAllData()" title="重置所有学生操行分为<?php echo getConfig('conduct_score.initial_score'); ?>分">
                    <i class="fa-solid fa-refresh"></i> 学期重置
                </button>
            </div>
        </div>

        <!-- 学生表格 -->
        <div class="students-table">
            <div class="table-header">
                <div class="table-title">
                    <i class="fa-solid fa-users"></i>
                    学生列表
                </div>
                <div class="table-stats">
                    共 <strong><?php echo $total; ?></strong> 名学生
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll()" style="transform: scale(1.2);">
                        </th>
                        <th>学生信息</th>
                        <th>当前操行分</th>
                        <th>状态</th>
                        <th>申诉权限</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #666;">
                            <i class="fa-solid fa-users" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                            暂无学生数据
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($students as $student): ?>
                        <tr>
                            <td style="text-align: center;">
                                <input type="checkbox" class="student-checkbox" value="<?php echo $student['id']; ?>" onchange="handleStudentCheckboxChange(this)" style="transform: scale(1.2);">
                            </td>
                            <td>
                                <div class="student-info">
                                    <div class="student-avatar">
                                        <?php echo mb_substr($student['name'], 0, 1); ?>
                                    </div>
                                    <div class="student-details">
                                        <div class="student-name"><?php echo htmlspecialchars($student['name']); ?></div>
                                        <div class="student-id"><?php echo htmlspecialchars($student['student_id']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php 
                                $score = $student['current_score'];
                                $scoreClass = '';
                                if ($score >= 90) $scoreClass = 'score-excellent';
                                elseif ($score >= 70 && $score < 90) $scoreClass = 'score-good';
                                elseif ($score >= 60 && $score < 70) $scoreClass = 'score-warning';
                                else $scoreClass = 'score-danger';
                                // 格式化操行分：如果是整数则去除小数点
                                $formattedScore = (floor($score) == $score) ? intval($score) : $score;
                                ?>
                                <span class="score-badge <?php echo $scoreClass; ?>"><?php echo $formattedScore; ?>分</span>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $student['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $student['status'] === 'active' ? '启用' : '停用'; ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $student['appeal_permission'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $student['appeal_permission'] ? '允许' : '禁止'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-edit" onclick="editStudent(<?php echo $student['id']; ?>)">
                                        <i class="fa-solid fa-edit"></i> 编辑
                                    </button>
                                    <button class="btn btn-delete" onclick="deleteStudent(<?php echo $student['id']; ?>)">
                                        <i class="fa-solid fa-trash"></i> 删除
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
                    <span id="paginationInfo">显示第 1-<?php echo min(10, $total); ?> 条，共 <?php echo $total; ?> 条记录</span>
                </div>
                <div class="pagination" id="paginationButtons">
                    <!-- 分页按钮将通过JavaScript动态生成 -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 添加/编辑学生模态框 -->
<div id="studentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">添加学生</h2>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="studentForm" novalidate>
                <input type="hidden" id="studentIdHidden" value="">
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label required">姓名</label>
                        <div class="input-group">
                            <i class="input-icon fa-solid fa-user"></i>
                            <input type="text" class="form-input" id="studentName" placeholder="请输入姓名" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">学号</label>
                        <div class="input-group">
                            <i class="input-icon fa-solid fa-id-card"></i>
                            <input type="text" class="form-input" id="studentId" placeholder="请输入学号" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label required">操行分</label>
                    <div class="input-group">
                        <i class="input-icon fa-solid fa-star"></i>
                        <input type="number" class="form-input" id="studentScore" value="<?php echo getConfig('conduct_score.initial_score'); ?>" min="0" max="100" step="0.1" placeholder="仅限0~100分" required>
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">学生状态</label>
                    <div class="status-buttons">
                        <button type="button" class="status-btn active" data-status="active" onclick="selectStatus('active')">
                            <i class="fa-solid fa-check-circle" style="margin-right: 6px;"></i>启用
                        </button>
                        <button type="button" class="status-btn" data-status="inactive" onclick="selectStatus('inactive')">
                            <i class="fa-solid fa-times-circle" style="margin-right: 6px;"></i>停用
                        </button>
                    </div>
                    <input type="hidden" id="studentStatus" value="active">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">申诉权限</label>
                    <div class="status-buttons">
                        <button type="button" class="status-btn active" data-appeal="1" onclick="selectAppealPermission(1)">
                            <i class="fa-solid fa-unlock" style="margin-right: 6px;"></i>允许
                        </button>
                        <button type="button" class="status-btn" data-appeal="0" onclick="selectAppealPermission(0)">
                            <i class="fa-solid fa-lock" style="margin-right: 6px;"></i>禁止
                        </button>
                    </div>
                    <input type="hidden" id="appealPermission" value="1">
                </div>
                
                <div class="form-buttons">
                    <button type="button" class="btn-secondary" onclick="closeModal()">
                        <i class="fa-solid fa-times"></i> 取消
                    </button>
                    <button type="submit" class="btn-primary">
                        <i class="fa-solid fa-save"></i> 保存
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 批量添加学生模态框 -->
<div id="batchAddModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">批量添加学生</h2>
            <span class="close" onclick="closeBatchModal()">&times;</span>
        </div>
        
        <div class="batch-tabs">
            <button type="button" class="tab-btn active" onclick="switchTab('text')">文本输入</button>
            <button type="button" class="tab-btn" onclick="switchTab('file')">文件上传</button>
        </div>
        
        <div id="textTab" class="tab-content active">
            <div class="form-group">
                <label class="form-label">批量添加学生 - 格式输入</label>
                <div class="form-hint">格式: 姓名 学号 操行分 学生状态 申诉权限</div>
                <div class="form-hint">示例: 张三 01 100 1 1</div>
                <textarea class="batch-textarea" id="batchTextInput" placeholder="请在此输入学生信息&#10;每行填写一位学生信息&#10;1. 学号仅允许数字&#10;2. 操行分仅允许0~100分&#10;3. 学生状态与申诉权限为可选项, 可留空&#10;1表示启用, 0表示禁用"></textarea>
            </div>
            <div class="form-buttons">
                <button type="button" class="btn-secondary" onclick="closeBatchModal()">取消</button>
                <button type="button" class="btn-primary" onclick="processBatchText()">批量添加</button>
            </div>
        </div>
        
        <div id="fileTab" class="tab-content">
            <div class="form-group">
                <label class="form-label">批量添加学生 - 文件上传</label>
                <div class="form-hint">支持TXT文件 | 格式: 姓名 学号 操行分 学生状态 申诉权限</div>
                <div class="form-hint">示例: 张三 01 100 1 1</div>
                <div class="file-upload-area" onclick="document.getElementById('batchFile').click()">
                    <i class="fa-solid fa-cloud-upload"></i>
                    <p>点击选择文件或拖拽文件到此处</p>
                    <p style="font-size: 12px; margin-top: 5px;">支持.txt格式</p>
                </div>
                <input type="file" id="batchFile" accept=".txt" style="display: none;" onchange="handleFileSelect(this)">
                <div id="fileInfo" class="file-info" style="display: none;"></div>
            </div>
            <div class="form-buttons">
                <button type="button" class="btn-secondary" onclick="closeBatchModal()">取消</button>
                <button type="button" class="btn-primary" id="fileUploadBtn" onclick="processBatchFile()" disabled>批量添加</button>
            </div>
        </div>
    </div>
</div>

<!-- 批量编辑学生模态框 -->
<div id="batchEditModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">批量编辑学生</h2>
            <span class="close" onclick="closeBatchEditModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">已选择学生</label>
                <div id="selectedStudentsList" class="selected-students-list">
                    <!-- 选中的学生列表将在这里显示 -->
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">学生状态</label>
                <div class="status-buttons">
                    <button type="button" class="status-btn" data-batch-status="active" onclick="selectBatchStatus('active')">
                        <i class="fa-solid fa-check-circle" style="margin-right: 6px;"></i>启用
                    </button>
                    <button type="button" class="status-btn" data-batch-status="inactive" onclick="selectBatchStatus('inactive')">
                        <i class="fa-solid fa-times-circle" style="margin-right: 6px;"></i>停用
                    </button>
                </div>
                <input type="hidden" id="batchStatus" value="">
            </div>
            
            <div class="form-group">
                <label class="form-label">申诉权限</label>
                <div class="status-buttons">
                    <button type="button" class="status-btn" data-batch-appeal="1" onclick="selectBatchAppealPermission(1)">
                        <i class="fa-solid fa-unlock" style="margin-right: 6px;"></i>允许
                    </button>
                    <button type="button" class="status-btn" data-batch-appeal="0" onclick="selectBatchAppealPermission(0)">
                        <i class="fa-solid fa-lock" style="margin-right: 6px;"></i>禁止
                    </button>
                </div>
                <input type="hidden" id="batchAppealPermission" value="">
            </div>
            
            <div class="form-buttons">
                <button type="button" class="btn-secondary" onclick="closeBatchEditModal()">取消</button>
                <button type="button" class="btn-primary" onclick="batchEditStudents()">批量更新</button>
            </div>
        </div>
    </div>
</div>

<script>
// 配置常量
const CONFIG = {
    conductScore: {
        initialScore: <?php echo getConfig('conduct_score.initial_score'); ?>
    }
};

// 错误消息常量
const ERROR_MESSAGES = {
    OPERATION_FAILED: '操作失败，请稍后再试',
    DELETE_FAILED: '删除失败，请稍后再试',
    IMPORT_FAILED: '文件导入失败，请稍后再试',
    BATCH_ADD_FAILED: '批量添加失败，请稍后再试',
    BATCH_EDIT_FAILED: '批量编辑失败，请稍后再试',
    EDIT_FAILED: '编辑失败，请稍后再试',
    RESET_FAILED: '重置失败，请稍后再试'
};

// 常用DOM元素缓存
const searchInput = document.getElementById('searchInput');
const selectAllCheckbox = document.getElementById('selectAll');
const batchDeleteBtn = document.getElementById('batchDeleteBtn');
const batchEditBtn = document.getElementById('batchEditBtn');
const studentModal = document.getElementById('studentModal');
const batchModal = document.getElementById('batchAddModal');
const perPageSelect = document.getElementById('perPageSelect');
const paginationInfo = document.getElementById('paginationInfo');
const paginationButtons = document.getElementById('paginationButtons');
const tableStatsElement = document.querySelector('.table-stats');
const studentsTableBody = document.querySelector('.students-table tbody');

// 获取学生复选框的函数（因为这些元素是动态生成的）
function getStudentCheckboxes() {
    return document.querySelectorAll('.student-checkbox');
}

function getCheckedStudentCheckboxes() {
    return document.querySelectorAll('.student-checkbox:checked');
}

// 搜索学生
function searchStudents() {
    const searchTerm = searchInput.value;
    // 这里可以添加AJAX搜索功能
}

// 打开添加学生模态框
function openAddModal() {
    document.getElementById('modalTitle').textContent = '添加学生';
    document.getElementById('studentForm').reset();
    document.getElementById('studentIdHidden').value = '';
    document.getElementById('studentScore').value = '';
    
    // 重置状态按钮
    selectStatus('active');
    selectAppealPermission(1);
    
    showModal('studentModal');
}

// 编辑学生
function editStudent(id) {
    document.getElementById('modalTitle').textContent = '编辑学生';
    
    // 通过AJAX获取学生信息
    fetch(`?ajax=1&action=get_student&id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const student = data.student;
                document.getElementById('studentIdHidden').value = student.id;
                document.getElementById('studentName').value = student.name;
                document.getElementById('studentId').value = student.student_id;
                document.getElementById('studentScore').value = student.current_score;
                
                // 设置状态
                selectStatus(student.status);
                
                // 设置申诉权限
                selectAppealPermission(student.appeal_permission);
                
                // 存储学生ID用于更新
                document.getElementById('studentForm').dataset.studentId = id;
                
                showModal('studentModal');
            } else {
                notification.error('获取学生信息失败');
            }
        })
        .catch(error => {
            notification.error('获取学生信息失败');
        });
}

// 删除学生
function deleteStudent(id) {
    notification.confirm('确定要删除这个学生吗？', '确认删除', {
        onConfirm: function() {
            const formData = new FormData();
        formData.append('action', 'delete_student');
        formData.append('id', id);
        
        sendRequest(formData, (data) => {
             notification.success('学生删除成功');
             // 从选中状态中移除已删除的学生（确保ID类型匹配）
             selectedStudents.delete(String(id));
             // 刷新当前页面数据
             refreshCurrentPageData();
         }, ERROR_MESSAGES.DELETE_FAILED);
        }
    });
}

// 关闭模态框
function closeModal() {
    hideModal('studentModal');
    // 清空表单
    document.getElementById('studentForm').reset();
    document.getElementById('studentForm').removeAttribute('data-student-id');
    // 重置状态按钮
    selectStatus('active');
    selectAppealPermission(1);
}

// 通用选择器函数
function selectOption(selector, value, inputId) {
    document.querySelectorAll(selector).forEach(btn => btn.classList.remove('active'));
    document.querySelector(`${selector}="${value}"]`).classList.add('active');
    document.getElementById(inputId).value = value;
}

// 选择状态
function selectStatus(status) {
    selectOption('[data-status', status, 'studentStatus');
}

// 选择申诉权限
function selectAppealPermission(permission) {
    selectOption('[data-appeal', permission, 'appealPermission');
}

// 通用模态框管理
function showModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function hideModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function showElement(elementId) {
    document.getElementById(elementId).style.display = 'block';
}

function hideElement(elementId) {
    document.getElementById(elementId).style.display = 'none';
}

// 模态框管理
function openBatchAddModal() {
    showModal('batchAddModal');
}

function closeBatchModal() {
    hideModal('batchAddModal');
    document.getElementById('batchTextInput').value = '';
    document.getElementById('batchFile').value = '';
    hideElement('fileInfo');
    document.getElementById('fileUploadBtn').disabled = true;
}

// 切换标签页
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    
    event.target.classList.add('active');
    document.getElementById(tab + 'Tab').classList.add('active');
}

// 处理批量文本输入
function processBatchText() {
    const text = document.getElementById('batchTextInput').value;
    if (!text.trim()) {
        notification.warning('请输入学生信息');
        document.getElementById('batchTextInput').focus();
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'batch_add_students');
    formData.append('students_data', text);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // 根据是否有错误显示不同的提示信息
            if (data.errors && data.errors.length > 0) {
                notification.warning(data.message || '部分学生信息添加失败，请检查数据格式');
            } else {
                notification.success(data.message || '学生批量添加成功');
            }
            closeBatchModal();
            // 重新获取当前页面数据，无需刷新页面
            refreshCurrentPageData();
        } else {
            notification.error(data.error || ERROR_MESSAGES.IMPORT_FAILED);
        }
    })
    .catch(error => {
        notification.error(ERROR_MESSAGES.BATCH_ADD_FAILED);
    });
}

// 处理文件选择
function handleFileSelect(input) {
    const file = input.files[0];
    const fileInfo = document.getElementById('fileInfo');
    
    if (!file) {
        fileInfo.style.display = 'none';
        document.getElementById('fileUploadBtn').disabled = true;
        return;
    }
    
    // 文件大小限制 (50MB)
    const maxSize = 50 * 1024 * 1024; // 50MB in bytes
    if (file.size > maxSize) {
        notification.error('文件过大，请选择小于50MB的文件');
        fileInfo.style.display = 'none';
        document.getElementById('fileUploadBtn').disabled = true;
        input.value = ''; // 清空文件选择
        return;
    }
    
    // 文件类型验证
    const allowedTypes = ['.txt'];
    const fileName = file.name.toLowerCase();
    const isValidType = allowedTypes.some(type => fileName.endsWith(type));
    
    if (!isValidType) {
        notification.warning('文件格式不支持，请选择.txt文件');
        fileInfo.style.display = 'none';
        document.getElementById('fileUploadBtn').disabled = true;
        input.value = ''; // 清空文件选择
        return;
    }
    
    // 文件名安全检查
    const dangerousChars = /[<>:"/\|?*\x00-\x1f]/;
    if (dangerousChars.test(file.name)) {
        notification.error('文件名包含非法字符，请重命名后重试');
        fileInfo.style.display = 'none';
        document.getElementById('fileUploadBtn').disabled = true;
        input.value = ''; // 清空文件选择
        return;
    }
    
    // 显示文件信息
    const fileSizeKB = (file.size / 1024).toFixed(2);
    const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
    const sizeDisplay = file.size > 1024 * 1024 ? `${fileSizeMB} MB` : `${fileSizeKB} KB`;
    
    fileInfo.innerHTML = `<i class="fa-solid fa-file-text"></i> 已选择文件：${file.name} (${sizeDisplay})`;
    fileInfo.className = 'file-info';
    fileInfo.style.display = 'block';
    document.getElementById('fileUploadBtn').disabled = false;
}

// 处理批量文件上传
function processBatchFile() {
    const fileInput = document.getElementById('batchFile');
    const file = fileInput.files[0];
    
    if (!file) {
        notification.warning('请选择要上传的文件');
        return;
    }
    
    // 文件大小验证 (50MB)
    const maxSize = 50 * 1024 * 1024; // 50MB in bytes
    if (file.size > maxSize) {
        notification.error('文件过大，请选择小于50MB的文件');
        return;
    }
    
    // 检查文件类型
    const allowedTypes = ['.txt'];
    const fileName = file.name.toLowerCase();
    const isValidType = allowedTypes.some(type => fileName.endsWith(type));
    
    if (!isValidType) {
        notification.warning('仅支持 .txt 格式的文件');
        return;
    }
    
    // 文件名安全检查
    const dangerousChars = /[<>:"/\|?*\x00-\x1f]/;
    if (dangerousChars.test(file.name)) {
        notification.error('文件名包含非法字符，请重命名后重试');
        return;
    }
    
    // 检查文件是否为空
    if (file.size === 0) {
        notification.warning('文件为空，请选择有效的文件');
        return;
    }
    
    // 读取文件内容
    const reader = new FileReader();
    reader.onload = function(e) {
        const content = e.target.result;
        
        // 处理TXT文件
        const studentsData = content.trim();
        
        if (!studentsData) {
            notification.warning('文件内容为空，请检查文件');
            return;
        }
        
        // 发送到服务器处理
        const formData = new FormData();
        formData.append('action', 'batch_add_students');
        formData.append('students_data', studentsData);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 根据是否有错误显示不同的提示信息
                if (data.errors && data.errors.length > 0) {
                    notification.warning(data.message || '部分学生信息导入失败，请检查数据格式');
                } else {
                    notification.success(data.message || '文件导入成功');
                }
                closeBatchModal();
                // 重新获取当前页面数据，无需刷新页面
                refreshCurrentPageData();
            } else {
                notification.error(data.error || ERROR_MESSAGES.BATCH_ADD_FAILED);
            }
        })
        .catch(error => {
            notification.error(ERROR_MESSAGES.IMPORT_FAILED);
        });
    };
    
    reader.onerror = function() {
        notification.error('文件读取失败，请检查文件格式');
    };
    
    reader.readAsText(file, 'UTF-8');
}

// 表单提交处理
document.getElementById('studentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData();
    const studentId = this.dataset.studentId;
    
    // 获取表单数据
    const name = document.getElementById('studentName').value.trim();
    const student_id = document.getElementById('studentId').value.trim();
    const score = document.getElementById('studentScore').value;
    const status = document.getElementById('studentStatus').value;
    const appealPermission = document.getElementById('appealPermission').value;
    
    // 表单验证
    const validations = [
        { condition: !name, message: '请填写姓名', field: 'studentName' },
        { condition: !student_id, message: '请填写学号', field: 'studentId' },
        { condition: !/^[0-9]+$/.test(student_id), message: '学号仅限数字', field: 'studentId' },
        { condition: isNaN(parseFloat(score)) || parseFloat(score) < 0 || parseFloat(score) > 100, message: '操行分必须在0-100之间', field: 'studentScore' }
    ];
    
    for (const validation of validations) {
        if (validation.condition) {
            notification.warning(validation.message);
            document.getElementById(validation.field).focus();
            return;
        }
    }
    
    if (studentId) {
        // 编辑模式
        formData.append('action', 'update_student');
        formData.append('id', studentId);
    } else {
        // 添加模式
        formData.append('action', 'add_student');
    }
    
    formData.append('name', name);
    formData.append('student_id', student_id);
    formData.append('score', score);
    formData.append('status', status);
    formData.append('appeal_permission', appealPermission);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            notification.success(data.message || (studentId ? '学生信息更新成功' : '学生添加成功'));
            closeModal();
            // 重新获取当前页面数据，无需刷新页面
            refreshCurrentPageData();
        } else {
            notification.error(data.error || ERROR_MESSAGES.OPERATION_FAILED);
        }
    })
    .catch(error => {
        notification.error(ERROR_MESSAGES.OPERATION_FAILED);
    });
});

// 分页相关变量
let currentPage = <?php echo isset($_GET['page']) ? (int)$_GET['page'] : 1; ?>;
let perPage = <?php echo isset($_GET['limit']) ? (int)$_GET['limit'] : 10; ?>;
let totalPages = <?php echo $total_pages; ?>;
let totalRecords = <?php echo $total; ?>;

// 页面加载完成后初始化分页和搜索
document.addEventListener('DOMContentLoaded', function() {
    updatePaginationInfo();
    generatePaginationButtons();
    
    // 设置每页显示数量的选中状态
    // perPageSelect already cached
    perPageSelect.value = perPage;
    
    // 初始化搜索功能
    initializeSearch();
});

// 更新分页信息
function updatePaginationInfo() {
    const start = (currentPage - 1) * perPage + 1;
    const end = Math.min(currentPage * perPage, totalRecords);
    // paginationInfo already cached
    paginationInfo.textContent = `显示第 ${start}-${end} 条，共 ${totalRecords} 条记录`;
}

// 生成分页按钮
function generatePaginationButtons() {
    // paginationButtons already cached
    paginationButtons.innerHTML = '';
    
    if (totalPages <= 1) {
        return;
    }
    
    // 上一页按钮
    const prevBtn = document.createElement('button');
    prevBtn.className = 'page-btn';
    prevBtn.innerHTML = '&laquo;';
    prevBtn.disabled = currentPage === 1;
    prevBtn.onclick = () => goToPage(currentPage - 1);
    paginationButtons.appendChild(prevBtn);
    
    // 页码按钮
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, currentPage + 2);
    
    // 确保显示5个页码（如果可能）
    if (endPage - startPage < 4) {
        if (startPage === 1) {
            endPage = Math.min(totalPages, startPage + 4);
        } else {
            startPage = Math.max(1, endPage - 4);
        }
    }
    
    // 第一页
    if (startPage > 1) {
        const firstBtn = document.createElement('button');
        firstBtn.className = 'page-btn';
        firstBtn.textContent = '1';
        firstBtn.onclick = () => goToPage(1);
        paginationButtons.appendChild(firstBtn);
        
        if (startPage > 2) {
            const ellipsis = document.createElement('span');
            ellipsis.textContent = '...';
            ellipsis.style.padding = '8px 4px';
            paginationButtons.appendChild(ellipsis);
        }
    }
    
    // 页码按钮
    for (let i = startPage; i <= endPage; i++) {
        const pageBtn = document.createElement('button');
        pageBtn.className = 'page-btn';
        if (i === currentPage) {
            pageBtn.classList.add('active');
        }
        pageBtn.textContent = i;
        pageBtn.onclick = () => goToPage(i);
        paginationButtons.appendChild(pageBtn);
    }
    
    // 最后一页
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            const ellipsis = document.createElement('span');
            ellipsis.textContent = '...';
            ellipsis.style.padding = '8px 4px';
            paginationButtons.appendChild(ellipsis);
        }
        
        const lastBtn = document.createElement('button');
        lastBtn.className = 'page-btn';
        lastBtn.textContent = totalPages;
        lastBtn.onclick = () => goToPage(totalPages);
        paginationButtons.appendChild(lastBtn);
    }
    
    // 下一页按钮
    const nextBtn = document.createElement('button');
    nextBtn.className = 'page-btn';
    nextBtn.innerHTML = '&raquo;';
    nextBtn.disabled = currentPage === totalPages;
    nextBtn.onclick = () => goToPage(currentPage + 1);
    paginationButtons.appendChild(nextBtn);
}

// 跳转到指定页面
function goToPage(page, restoreSelection = true) {
    if (page < 1 || page > totalPages || page === currentPage) {
        return;
    }
    
    // 保存当前页面的选中状态（仅在需要时）
    if (restoreSelection) {
        saveSelectedState();
    }
    
    // 使用AJAX获取数据，不显示URL参数
    const searchQuery = searchInput.value.trim();
    const url = `?ajax=1&page=${page}&limit=${perPage}${searchQuery ? '&search=' + encodeURIComponent(searchQuery) : ''}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateStudentTable(data.students, restoreSelection);
                updatePaginationData(data);
                updatePaginationInfo();
                generatePaginationButtons();
                updateBatchDeleteButton();
                
                // 更新浏览器URL但不显示查询参数
                const cleanUrl = window.location.pathname;
                history.replaceState(null, '', cleanUrl);
            }
        })
        .catch(error => {
            return null
        });
}

// 改变每页显示数量
function changePerPage() {
    const select = document.getElementById('perPageSelect');
    const newPerPage = parseInt(select.value);
    
    if (newPerPage !== perPage) {
        // 保存当前页面的选中状态
        saveSelectedState();
        
        perPage = newPerPage;
        
        // 使用AJAX获取数据，不显示URL参数
        const searchQuery = searchInput.value.trim();
        const url = `?ajax=1&page=1&limit=${newPerPage}${searchQuery ? '&search=' + encodeURIComponent(searchQuery) : ''}`;
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateStudentTable(data.students);
                    updatePaginationData(data);
                    updatePaginationInfo();
                    generatePaginationButtons();
                    updateBatchDeleteButton();
                    
                    // 更新浏览器URL但不显示查询参数
                    const cleanUrl = window.location.pathname;
                    history.replaceState(null, '', cleanUrl);
                }
            })
            .catch(error => {
                return null
            });
    }
}

// 搜索相关变量
let searchTimeout;

// 选中保留功能变量
let selectedStudents = new Set(); // 存储选中的学生ID

// 初始化搜索功能
function initializeSearch() {
    // searchInput already cached
    
    // 输入事件监听
    searchInput.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        
        // 清除之前的定时器
        clearTimeout(searchTimeout);
        
        if (query.length === 0) {
            // 如果搜索框为空，重新加载所有数据
            performSearch('');
            return;
        }
        
        // 防抖处理，300ms后执行搜索
        searchTimeout = setTimeout(() => {
            if (query.length >= 1) {
                fetchSearchResults(query);
            }
        }, 300);
    });
    
    // 键盘事件监听
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const query = searchInput.value.trim();
            if (query) {
                fetchSearchResults(query);
            } else {
                performSearch('');
            }
        }
    });

    // 获得焦点时如果有内容则执行搜索
    searchInput.addEventListener('focus', function() {
        const query = this.value.trim();
        if (query.length >= 1) {
            fetchSearchResults(query);
        }
    });
}

// 获取搜索结果并更新表格
function fetchSearchResults(query) {
    // 显示加载指示器
    showSearchLoading();
    
    // 设置最大时间上限，防止无限转圈
    const timeoutId = setTimeout(function() {
        const tableBody = document.querySelector('.students-table tbody');
        if (tableBody) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="6" class="error-state">
                        <i class="fa-solid fa-exclamation-triangle"></i>
                        <p>搜索超时，请重试</p>
                    </td>
                </tr>
            `;
        }
    }, 8000); // 8秒最大时间上限
    
    fetch(`?ajax=1&search=${encodeURIComponent(query)}&page=1&limit=${perPage}`)
        .then(response => response.json())
        .then(data => {
            clearTimeout(timeoutId);
            if (data.success) {
                updateStudentTable(data.students);
                updatePaginationData(data);
                updatePaginationInfo();
                generatePaginationButtons();
                
                // 更新浏览器URL但不显示查询参数
                const cleanUrl = window.location.pathname;
                history.replaceState(null, '', cleanUrl);
            }
        })
        .catch(error => {
            clearTimeout(timeoutId);
            console.error('搜索请求失败:', error);
            const tableBody = document.querySelector('.students-table tbody');
            if (tableBody) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="6" class="error-state">
                            <i class="fa-solid fa-exclamation-triangle"></i>
                            <p>网络错误，请检查连接</p>
                        </td>
                    </tr>
                `;
            }
        })
        .finally(() => {
            // 隐藏加载指示器（实际上已经被新内容替换）
            hideSearchLoading();
        });
}

// 显示搜索加载指示器
function showSearchLoading() {
    const tableBody = document.querySelector('.students-table tbody');
    if (tableBody) {
        tableBody.innerHTML = `
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
    // 加载指示器会在updateStudentTable函数中被新内容替换，所以这里不需要特别处理
}

// 保存当前选中状态
function saveSelectedState() {
    getCheckedStudentCheckboxes().forEach(checkbox => {
        selectedStudents.add(checkbox.value);
    });
}

// 恢复选中状态
function restoreSelectedState() {
    const checkboxes = getStudentCheckboxes();
    
    // 恢复当前页面存在的学生的选中状态
    checkboxes.forEach(checkbox => {
        if (selectedStudents.has(checkbox.value)) {
            checkbox.checked = true;
        }
    });
    
    updateSelectAllState();
    updateBatchDeleteButton();
}

// 更新学生表格
function updateStudentTable(students, restoreSelection = true) {
    // 保存当前选中状态（仅在需要时）
    if (restoreSelection) {
        saveSelectedState();
    }
    
    // studentsTableBody already cached
     studentsTableBody.innerHTML = '';
    
    if (students.length === 0) {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="6" style="text-align: center; padding: 40px; color: #666;"><i class="fa-solid fa-users" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>暂无学生数据</td>';
        studentsTableBody.appendChild(row);
        return;
    }
    
    students.forEach(student => {
        const row = document.createElement('tr');
        
        // 复选框列
        const checkboxCell = document.createElement('td');
        checkboxCell.style.textAlign = 'center';
        checkboxCell.innerHTML = `<input type="checkbox" class="student-checkbox" value="${student.id}" onchange="handleStudentCheckboxChange(this)" style="transform: scale(1.2);">`;
        
        // 学生信息列
        const studentInfoCell = document.createElement('td');
        studentInfoCell.innerHTML = `
            <div class="student-info">
                <div class="student-avatar">${student.name.charAt(0)}</div>
                <div class="student-details">
                    <div class="student-name">${student.name}</div>
                    <div class="student-id">${student.student_id}</div>
                </div>
            </div>
        `;
        
        // 操行分列
        const scoreCell = document.createElement('td');
        let scoreClass = '';
        if (student.current_score >= 90) scoreClass = 'score-excellent';
        else if (student.current_score >= 70 && student.current_score < 90) scoreClass = 'score-good';
        else if (student.current_score >= 60 && student.current_score < 70) scoreClass = 'score-warning';
        else scoreClass = 'score-danger';
        // 格式化操行分：如果是整数则去除小数点
        const scoreValue = parseFloat(student.current_score);
        const formattedScore = (scoreValue % 1 === 0) ? parseInt(scoreValue) : scoreValue;
        scoreCell.innerHTML = `<span class="score-badge ${scoreClass}">${formattedScore}分</span>`;
        
        // 状态列
        const statusCell = document.createElement('td');
        statusCell.innerHTML = `<span class="status-badge ${getStatusClass(student.status)}">${getStatusText(student.status)}</span>`;
        
        // 申诉权限列
        const appealCell = document.createElement('td');
        appealCell.innerHTML = `<span class="status-badge ${getAppealClass(student.appeal_permission)}">${getAppealText(student.appeal_permission)}</span>`;
        
        // 操作列
        const actionCell = document.createElement('td');
        actionCell.innerHTML = `
            <div class="action-buttons">
                <button class="btn btn-edit" onclick="editStudent(${student.id})">
                    <i class="fa-solid fa-edit"></i> 编辑
                </button>
                <button class="btn btn-delete" onclick="deleteStudent(${student.id})">
                    <i class="fa-solid fa-trash"></i> 删除
                </button>
            </div>
        `;
        
        row.appendChild(checkboxCell);
        row.appendChild(studentInfoCell);
        row.appendChild(scoreCell);
        row.appendChild(statusCell);
        row.appendChild(appealCell);
        row.appendChild(actionCell);
        
        studentsTableBody.appendChild(row);
    });
    
    // 恢复选中状态（仅在需要时）
    if (restoreSelection) {
        setTimeout(() => {
            restoreSelectedState();
        }, 10);
    } else {
        // 不恢复选中状态时，仍需更新按钮状态
        updateBatchDeleteButton();
    }
}

// 更新分页数据
function updatePaginationData(data) {
    currentPage = data.page;
    totalPages = data.total_pages;
    totalRecords = data.total;
    
    // 更新学生总数显示
    // tableStatsElement already cached
    if (tableStatsElement) {
        tableStatsElement.innerHTML = `共 <strong>${data.total}</strong> 名学生`;
    }
}

// 检查并刷新页面数据（删除后可能需要回到第一页）
function checkAndRefreshPage() {
    const searchQuery = searchInput.value.trim();
    const url = `?ajax=1&page=${currentPage}&limit=${perPage}${searchQuery ? '&search=' + encodeURIComponent(searchQuery) : ''}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 如果当前页面没有学生且不是第一页，则跳转到第一页
                if (data.students.length === 0 && currentPage > 1) {
                    currentPage = 1;
                    refreshCurrentPageData();
                } else {
                    updateStudentTable(data.students, false);
                    updatePaginationData(data);
                    updatePaginationInfo();
                    generatePaginationButtons();
                    
                    // 更新全选复选框和批量操作按钮状态
                    updateBatchDeleteButton();
                    
                    // 更新浏览器URL但不显示查询参数
                    const cleanUrl = window.location.pathname;
                    history.replaceState(null, '', cleanUrl);
                }
            }
        })
        .catch(error => {
            return null
        });
}

// 刷新当前页面数据
function refreshCurrentPageData() {
    const searchQuery = searchInput.value.trim();
    const url = `?ajax=1&page=${currentPage}&limit=${perPage}${searchQuery ? '&search=' + encodeURIComponent(searchQuery) : ''}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateStudentTable(data.students, false);
                updatePaginationData(data);
                updatePaginationInfo();
                generatePaginationButtons();
                
                // 更新全选复选框和批量操作按钮状态
                updateBatchDeleteButton();
                
                // 更新浏览器URL但不显示查询参数
                const cleanUrl = window.location.pathname;
                history.replaceState(null, '', cleanUrl);
            }
        })
        .catch(error => {
            return null
        });
}

// 执行搜索
function performSearch(query) {
    // 保存当前页面的选中状态
    saveSelectedState();
    
    // 使用AJAX获取数据，不显示URL参数
    const searchQuery = query && query.trim() ? query.trim() : '';
    const url = `?ajax=1&page=1&limit=${perPage}${searchQuery ? '&search=' + encodeURIComponent(searchQuery) : ''}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateStudentTable(data.students);
                updatePaginationData(data);
                updatePaginationInfo();
                generatePaginationButtons();
                
                // 更新浏览器URL但不显示查询参数
                const cleanUrl = window.location.pathname;
                history.replaceState(null, '', cleanUrl);
            }
        })
        .catch(error => {
            return null
        });
}

// 点击模态框外部关闭
window.onclick = function(event) {
    const studentModal = document.getElementById('studentModal');
    const batchModal = document.getElementById('batchAddModal');
    
    if (event.target === studentModal) {
        studentModal.style.display = 'none';
    }
    if (event.target === batchModal) {
        batchModal.style.display = 'none';
    }
};

// 学号输入框实时验证
document.getElementById('studentId').addEventListener('input', function(e) {
    // 只允许输入数字
    this.value = this.value.replace(/[^0-9]/g, '');
});

// 学号输入框失去焦点时验证
document.getElementById('studentId').addEventListener('blur', function(e) {
    const value = this.value.trim();
    if (value.length > 0 && !/^[0-9]+$/.test(value)) {
        // 只在输入框有内容但不是纯数字时提示，避免与表单提交验证重复
    }
});

// 批量选择功能
// 全选/取消全选
function toggleSelectAll() {
    // selectAllCheckbox already cached
    const studentCheckboxes = getStudentCheckboxes();
    
    if (selectAllCheckbox.checked) {
        // 全选：只添加当前页面的学生ID
        studentCheckboxes.forEach(checkbox => {
            checkbox.checked = true;
            selectedStudents.add(checkbox.value);
        });
    } else {
        // 取消全选：只移除当前页面的学生ID
        studentCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
            selectedStudents.delete(checkbox.value);
        });
    }
    
    // 更新批量删除按钮显示状态
    updateBatchDeleteButton();
}

// 处理单个学生复选框变化
function handleStudentCheckboxChange(checkbox) {
    if (checkbox.checked) {
        selectedStudents.add(checkbox.value);
    } else {
        selectedStudents.delete(checkbox.value);
    }
    updateSelectAllState();
}

// 保存选中状态

// 更新全选状态
function updateSelectAllState() {
    // 更新批量删除按钮显示状态（包含全选状态更新）
    updateBatchDeleteButton();
}

// 更新批量删除按钮显示状态
function updateBatchDeleteButton() {
    // batchDeleteBtn, batchEditBtn, selectAllCheckbox already cached
    const studentCheckboxes = getStudentCheckboxes();
    const checkedBoxes = getCheckedStudentCheckboxes();

    // 基于 selectedStudents Set 显示批量操作按钮
    if (selectedStudents.size > 0) {
        batchDeleteBtn.style.display = 'flex';
        batchDeleteBtn.innerHTML = `<i class="fa-solid fa-trash"></i> 批量删除 (${selectedStudents.size})`;
        batchEditBtn.style.display = 'flex';
        batchEditBtn.innerHTML = `<i class="fa-solid fa-edit"></i> 批量编辑 (${selectedStudents.size})`;
    } else {
        batchDeleteBtn.style.display = 'none';
        batchEditBtn.style.display = 'none';
    }
    
    // 更新全选复选框状态（仅基于当前页面）
    if (checkedBoxes.length === 0) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    } else if (checkedBoxes.length === studentCheckboxes.length) {
        selectAllCheckbox.checked = true;
        selectAllCheckbox.indeterminate = false;
    } else {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = true;
    }
}

// 批量删除学生
function batchDeleteStudents() {
    if (selectedStudents.size === 0) {
        notification.warning('请先选择要删除的学生');
        return;
    }
    
    const studentIds = Array.from(selectedStudents);
    const studentCount = studentIds.length;
    
    notification.confirm(
        `确定要删除选中的${studentCount}名学生吗？`,
        '批量删除确认',
        {
            type: 'error',
            confirmText: '确认删除',
            cancelText: '取消',
            onConfirm: () => {
                // 显示加载状态
                // batchDeleteBtn already cached
                const originalText = batchDeleteBtn.innerHTML;
                batchDeleteBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 删除中...';
                batchDeleteBtn.disabled = true;
                // 批量删除请求
                const promises = studentIds.map(id => {
                    const formData = new FormData();
                    formData.append('action', 'delete_student');
                    formData.append('id', id);
                    
                    return fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    }).then(response => response.json());
                });
                
                Promise.all(promises)
                    .then(results => {
                        const successCount = results.filter(result => result.success).length;
                        const failCount = results.length - successCount;
                        
                        // 只清理删除成功的学生ID，保持选中记忆功能
                        results.forEach((result, index) => {
                            if (result.success) {
                                selectedStudents.delete(studentIds[index]);
                            }
                        });
                        
                        if (failCount === 0) {
                            notification.success(`已成功删除${successCount}名学生`);
                        } else {
                            notification.warning(`批量删除成功: 成功${successCount}个 | 失败${failCount}个`);
                        }
                        
                        // 检查当前页面是否还有学生，如果没有则回到第一页
                        checkAndRefreshPage();
                    })
                    .catch(error => {
                        notification.error('批量删除操作失败，请重试');
                    })
                    .finally(() => {
                        // 恢复按钮状态
                        batchDeleteBtn.innerHTML = originalText;
                        batchDeleteBtn.disabled = false;
                    });
            }
        }
    );
}

// 打开批量编辑模态框
function openBatchEditModal() {
    // 使用 selectedStudents Set 来检查是否有选中的学生（跨分页）
    if (selectedStudents.size === 0) {
        notification.warning('请先选择要编辑的学生');
        return;
    }
    
    // 显示加载状态
    const selectedList = document.getElementById('selectedStudentsList');
    selectedList.innerHTML = '<div class="loading-text">正在加载选中的学生信息...</div>';
    
    // 显示模态框
    showModal('batchEditModal');
    
    // 获取所有选中学生的详细信息
    const studentIds = Array.from(selectedStudents);
    const promises = studentIds.map(id => {
        return fetch(`?ajax=1&action=get_student&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    return {
                        id: data.student.id,
                        name: data.student.name,
                        studentId: data.student.student_id,
                        avatar: data.student.name.charAt(0)
                    };
                }
                return null;
            })
            .catch(() => null);
    });
    
    Promise.all(promises)
        .then(students => {
            // 过滤掉获取失败的学生
            const validStudents = students.filter(student => student !== null);
            
            // 填充已选择学生列表
            selectedList.innerHTML = '';
            
            if (validStudents.length === 0) {
                selectedList.innerHTML = '<div class="error-text">无法获取学生信息</div>';
                return;
            }
            
            validStudents.forEach(student => {
                const studentItem = document.createElement('div');
                studentItem.className = 'selected-student-item';
                studentItem.innerHTML = `
                    <div class="selected-student-avatar">${student.avatar}</div>
                    <div class="selected-student-info">
                        <div class="selected-student-name">${student.name}</div>
                        <div class="selected-student-id">${student.studentId}</div>
                    </div>
                `;
                selectedList.appendChild(studentItem);
            });
        })
        .catch(error => {
            selectedList.innerHTML = '<div class="error-text">获取学生信息失败</div>';
        });
    
    // 重置表单
    resetBatchForm();
}

// 关闭批量编辑模态框
function closeBatchEditModal() {
    hideModal('batchEditModal');
    // 重置表单状态
    resetBatchForm();
    document.getElementById('selectedStudentsList').innerHTML = '';
}

// 通用批量选择函数
function selectBatchOption(type, value) {
    const selector = `[data-batch-${type}]`;
    const inputId = type === 'status' ? 'batchStatus' : 'batchAppealPermission';
    const targetBtn = document.querySelector(`[data-batch-${type}="${value}"]`);
    const isCurrentlyActive = targetBtn.classList.contains('active');
    
    // 清除所有按钮的active状态
    document.querySelectorAll(selector).forEach(btn => btn.classList.remove('active'));
    
    if (isCurrentlyActive) {
        // 如果当前按钮已经是激活状态，则取消选择
        document.getElementById(inputId).value = '';
    } else {
        // 否则激活当前按钮
        targetBtn.classList.add('active');
        document.getElementById(inputId).value = value;
    }
}

// 选择批量编辑状态
function selectBatchStatus(status) {
    selectBatchOption('status', status);
}

// 选择批量编辑申诉权限
function selectBatchAppealPermission(permission) {
    selectBatchOption('appeal', permission);
}

// 重置批量编辑表单
function resetBatchForm() {
    document.querySelectorAll('[data-batch-status]').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('[data-batch-appeal]').forEach(btn => btn.classList.remove('active'));
    document.getElementById('batchStatus').value = '';
    document.getElementById('batchAppealPermission').value = '';
}

// 状态文本转换工具函数
function getStatusText(status) {
    return status === 'active' ? '启用' : '停用';
}

function getStatusClass(status) {
    return status === 'active' ? 'status-active' : 'status-inactive';
}

function getAppealText(permission) {
    return permission == 1 ? '允许' : '禁止';
}

function getAppealClass(permission) {
    return permission == 1 ? 'status-active' : 'status-inactive';
}

// 通用请求处理函数
function sendRequest(formData, successCallback, errorMessage = ERROR_MESSAGES.OPERATION_FAILED) {
    return fetch(window.location.href, {
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
        return data;
    })
    .catch(error => {
        notification.error(errorMessage);
        throw error;
    });
}

// 批量编辑学生
function batchEditStudents() {
    // 使用 selectedStudents Set 来获取所有选中的学生ID（跨分页）
    if (selectedStudents.size === 0) {
        notification.warning('请先选择要编辑的学生');
        return;
    }
    
    const studentIds = Array.from(selectedStudents);
    
    // 获取选中的状态和申诉权限
    const status = document.getElementById('batchStatus').value;
    const appealPermission = document.getElementById('batchAppealPermission').value;
    
    if (!status && !appealPermission) {
        notification.warning('请至少选择一个要修改的字段');
        return;
    }
    
    // 显示加载状态
    const batchUpdateBtn = document.querySelector('#batchEditModal .btn-primary');
    const originalText = batchUpdateBtn.innerHTML;
    batchUpdateBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 更新中...';
    batchUpdateBtn.disabled = true;
    
    // 构建请求参数
    let params = `action=batch_edit_students&student_ids=${JSON.stringify(studentIds)}`;
    if (status) {
        params += `&status=${encodeURIComponent(status)}`;
    }
    if (appealPermission) {
        params += `&appeal_permission=${encodeURIComponent(appealPermission)}`;
    }
    
    fetch('students_manager.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: params
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            notification.success(data.message);
            closeBatchEditModal();
            // 清除所有选中状态
            selectedStudents.clear();
            getStudentCheckboxes().forEach(checkbox => {
                checkbox.checked = false;
            });
            updateBatchDeleteButton();
            // 刷新页面数据
            refreshCurrentPageData();
        } else {
            notification.error(data.error || ERROR_MESSAGES.EDIT_FAILED);
        }
    })
    .catch(error => {
        notification.error(ERROR_MESSAGES.BATCH_EDIT_FAILED);
    })
    .finally(() => {
        // 恢复按钮状态
        batchUpdateBtn.innerHTML = originalText;
        batchUpdateBtn.disabled = false;
    });
}

// 学号输入框样式验证
document.getElementById('studentId').addEventListener('blur', function(e) {
    const value = this.value.trim();
    if (value.length > 0 && !/^[0-9]+$/.test(value)) {
        this.style.borderColor = '#e74c3c';
    } else if (value.length > 0 && /^[0-9]+$/.test(value)) {
        this.style.borderColor = '#27ae60';
    } else {
        this.style.borderColor = '';
    }
});

// 学号输入框粘贴事件处理
document.getElementById('studentId').addEventListener('paste', function(e) {
    e.preventDefault();
    const paste = (e.clipboardData || window.clipboardData).getData('text');
    const numericOnly = paste.replace(/[^0-9]/g, '').slice(0, 7);
    this.value = numericOnly;
    
    // 触发input事件进行验证
    this.dispatchEvent(new Event('input'));
});

// 一键重置所有学生操行分
function resetAllData() {
    // 显示确认对话框
    notification.confirm(`确定进行学期重置操作吗？<br>此操作将会进行如下工作:<br>清除所有操行分记录<br>清除所有申诉记录<br>恢复操行分为${CONFIG.conductScore.initialScore}分`, '危险操作确认', {
        onConfirm: () => {
            executeReset();
        }
    });
}

// 执行重置操作
function executeReset() {
    
    // 显示加载状态
    const resetBtn = document.querySelector('.reset-btn');
    const originalText = resetBtn.innerHTML;
    resetBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 重置中...';
    resetBtn.disabled = true;
    
    // 发送重置请求
    fetch('students_manager.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=reset_all_scores'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            notification.success('学生已重置成功');
            // 刷新页面数据
            refreshCurrentPageData();
        } else {
            notification.error(data.error || ERROR_MESSAGES.RESET_FAILED);
        }
    })
    .catch(error => {
        notification.error(ERROR_MESSAGES.RESET_FAILED);
    })
    .finally(() => {
        // 恢复按钮状态
        resetBtn.innerHTML = originalText;
        resetBtn.disabled = false;
    });
}
</script>

</body>
</html>