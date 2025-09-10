<?php
// 定义常量以允许包含的文件访问
define('INCLUDED_FROM_APP', true);

/**
 * 学生个人主页 - 显示学生信息和操行分记录
 */

// 引入数据库类
require_once '../../functions/database.php';

// 初始化数据库实例
$db = new Database();

// 验证访问权限
function validateAccess() {
    if (!isset($_GET['student_id'])) {
        header('Location: ../../index.php');
        exit;
    }
    
    $studentId = $_GET['student_id'];
    
    // 验证数据库中是否存在
    $pdo = null;
    
    global $db;
    
    try {
        $pdo = $db->getMysqlConnection();
        
        $stmt = $pdo->prepare("SELECT id, status FROM students WHERE student_id = :student_id LIMIT 1");
        $stmt->execute(['student_id' => $studentId]);
        $student = $stmt->fetch();
        
        if (!$student || $student['status'] === 'inactive') {
            $db->releaseMysqlConnection($pdo);
            header('Location: ../../index.php');
            exit;
        }
        
        $studentDbId = $student['id'];
        $db->releaseMysqlConnection($pdo);
        return $studentDbId;
    } catch(PDOException $e) {
        if ($pdo) {
            $db->releaseMysqlConnection($pdo);
        }
        header('Location: ../../index.php');
        exit;
    }
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $studentId = $_POST['student_id'] ?? null;
    
    if (!$studentId) {
        echo json_encode(['success' => false, 'error' => '学生ID不能为空']);
        exit;
    }
    
    $pdo = null;
    
    global $db;
    
    try {
        $pdo = $db->getMysqlConnection();
        
        switch ($action) {
            case 'check_appeal_permission':
                $stmt = $pdo->prepare("SELECT appeal_permission, appeal_password FROM students WHERE id = :student_id");
                $stmt->execute(['student_id' => $studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student) {
                    $db->releaseMysqlConnection($pdo);
                    echo json_encode(['success' => false, 'error' => '学生不存在']);
                    exit;
                }
                
                $db->releaseMysqlConnection($pdo);
                echo json_encode([
                    'success' => true,
                    'has_permission' => (bool)$student['appeal_permission'],
                    'has_password' => !empty($student['appeal_password'])
                ]);
                exit;
                
            case 'set_appeal_password':
                $password = $_POST['password'] ?? '';
                if (empty($password)) {
                    $db->releaseMysqlConnection($pdo);
                    echo json_encode(['success' => false, 'error' => '密码不能为空']);
                    exit;
                }
                
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE students SET appeal_password = :password WHERE id = :student_id");
                $result = $stmt->execute(['password' => $hashedPassword, 'student_id' => $studentId]);
                
                $db->releaseMysqlConnection($pdo);
                echo json_encode(['success' => $result]);
                exit;
                
            case 'verify_appeal_password':
                $password = $_POST['password'] ?? '';
                if (empty($password)) {
                    $db->releaseMysqlConnection($pdo);
                    echo json_encode(['success' => false, 'error' => '密码不能为空']);
                    exit;
                }
                
                $stmt = $pdo->prepare("SELECT appeal_password FROM students WHERE id = :student_id");
                $stmt->execute(['student_id' => $studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student || !password_verify($password, $student['appeal_password'])) {
                    $db->releaseMysqlConnection($pdo);
                    echo json_encode(['success' => false, 'error' => '密码错误']);
                    exit;
                }
                
                $db->releaseMysqlConnection($pdo);
                echo json_encode(['success' => true]);
                exit;
                
            case 'submit_appeal':
                $recordId = $_POST['record_id'] ?? null;
                $reason = $_POST['appeal_reason'] ?? '';
                
                if (!$recordId || empty($reason)) {
                    $db->releaseMysqlConnection($pdo);
                    echo json_encode(['success' => false, 'error' => '申诉记录ID和理由不能为空']);
                    exit;
                }
                
                $stmt = $pdo->prepare("INSERT INTO appeals (student_id, record_id, reason, status, created_at) VALUES (:student_id, :record_id, :reason, 'pending', NOW())");
                $result = $stmt->execute([
                    'student_id' => $studentId,
                    'record_id' => $recordId,
                    'reason' => $reason
                ]);
                
                $db->releaseMysqlConnection($pdo);
                echo json_encode(['success' => $result]);
                exit;
                
            default:
                $db->releaseMysqlConnection($pdo);
                echo json_encode(['success' => false, 'error' => '未知操作']);
                exit;
        }
    } catch(PDOException $e) {
        if ($pdo) {
            $db->releaseMysqlConnection($pdo);
        }
        echo json_encode(['success' => false, 'error' => '操作失败，请稍后重试']);
        exit;
    }
}

// 执行访问验证
$validatedStudentId = validateAccess();

// 获取学生信息和操行分记录
function getStudentProfile($studentId = null) {
    global $db;
    $pdo = null;
    
    try {
        $pdo = $db->getMysqlConnection();
        
        // 获取第一个学生信息（演示用）
        if (!$studentId) {
            $stmt = $pdo->prepare("SELECT id FROM students ORDER BY id ASC LIMIT 1");
            $stmt->execute();
            $firstStudent = $stmt->fetch();
            if ($firstStudent) {
                $studentId = $firstStudent['id'];
            } else {
                return ['success' => false, 'error' => '暂无学生数据'];
            }
        }
        
        // 获取学生基本信息
        $stmt = $pdo->prepare("
            SELECT id, name, student_id, current_score
            FROM students 
            WHERE id = :student_id
        ");
        $stmt->execute(['student_id' => $studentId]);
        $student = $stmt->fetch();
        
        if (!$student) {
            return ['success' => false, 'error' => '学生信息不存在'];
        }
        
        // 获取操行分记录
        $stmt = $pdo->prepare("
            SELECT cr.id, cr.score_change, cr.score_after, cr.reason, cr.operator_name, cr.created_at,
                   a.id as appeal_id, a.status as appeal_status
            FROM conduct_records cr
            LEFT JOIN appeals a ON cr.id = a.record_id AND a.student_id = :student_id
            WHERE cr.student_id = :student_id
            ORDER BY cr.created_at DESC, cr.id DESC
            LIMIT 20
        ");
        $stmt->execute(['student_id' => $studentId]);
        $records = $stmt->fetchAll();
        
        // 获取申诉权限和密码状态
        $stmt = $pdo->prepare("SELECT appeal_permission, appeal_password FROM students WHERE id = :student_id");
        $stmt->execute(['student_id' => $studentId]);
        $studentInfo = $stmt->fetch();
        $hasAppealPermission = $studentInfo ? (bool)$studentInfo['appeal_permission'] : false;
        $hasAppealPassword = $studentInfo ? !empty($studentInfo['appeal_password']) : false;
        
        // 计算统计信息（排除已申诉通过的记录）
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_records,
                COALESCE(SUM(CASE WHEN score_change > 0 THEN 1 ELSE 0 END), 0) as positive_records,
                COALESCE(SUM(CASE WHEN score_change < 0 THEN 1 ELSE 0 END), 0) as negative_records
            FROM conduct_records cr
            LEFT JOIN appeals a ON cr.id = a.record_id
            WHERE cr.student_id = :student_id 
            AND (a.id IS NULL OR a.status != 'approved')
        ");
        $stmt->execute(['student_id' => $studentId]);
        $stats = $stmt->fetch();
        
        // 确保所有统计值都不为null
        $stats['total_records'] = (int)($stats['total_records'] ?? 0);
        $stats['positive_records'] = (int)($stats['positive_records'] ?? 0);
        $stats['negative_records'] = (int)($stats['negative_records'] ?? 0);
        
        // 计算平均分数变化（基于有效记录）
        $stmt = $pdo->prepare("
            SELECT AVG(ABS(score_change)) as avg_change
            FROM conduct_records cr
            LEFT JOIN appeals a ON cr.id = a.record_id
            WHERE cr.student_id = :student_id 
            AND (a.id IS NULL OR a.status != 'approved')
        ");
        $stmt->execute(['student_id' => $studentId]);
        $avgResult = $stmt->fetch();
        $stats['avg_change'] = ($avgResult && $avgResult['avg_change'] !== null) ? round($avgResult['avg_change'], 1) : 0;
        
        // 释放数据库连接
        if ($pdo) {
            $db->releaseMysqlConnection($pdo);
        }
        
        return [
            'success' => true,
            'student' => $student,
            'records' => $records,
            'stats' => $stats,
            'appeal_permission' => $hasAppealPermission,
            'has_appeal_password' => $hasAppealPassword
        ];
    } catch(PDOException $e) {
        // 释放数据库连接
        if ($pdo) {
            $db->releaseMysqlConnection($pdo);
        }
        return ['success' => false, 'error' => '数据获取失败: ' . $e->getMessage()];
    }
}

// 处理AJAX请求
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    $studentId = $_GET['student_id'] ?? null;
    echo json_encode(getStudentProfile($studentId));
    exit;
}



// 获取页面初始数据
$data = getStudentProfile($validatedStudentId);

if ($data['success']) {
    $student = $data['student'];
    $records = $data['records'];
    $stats = $data['stats'];
    $hasAppealPermission = $data['appeal_permission'];
    $hasAppealPassword = $data['has_appeal_password'];
} else {
    $student = null;
    $records = [];
    $stats = null;
    $hasAppealPermission = false;
    $hasAppealPassword = false;
    $error_message = $data['error'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操行分管理系统 - 个人主页</title>
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

        /* 个人信息卡片 */
        .profile-info {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            position: relative;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3498db, #2980b9);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: bold;
        }

        .profile-details h2 {
            font-size: 1.8rem;
            color: #2c3e50;
            margin-bottom: 8px;
        }

        .profile-details p {
            color: #7f8c8d;
            font-size: 1rem;
            margin-bottom: 5px;
        }

        .current-score {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 1.1rem;
            font-weight: 600;
            margin-top: 10px;
        }

        .score-excellent, .score-good, .score-average, .score-poor {
            color: white;
        }
        .score-excellent { background: linear-gradient(135deg, #27ae60, #2ecc71); }
        .score-good { background: linear-gradient(135deg, #3498db, #5dade2); }
        .score-average { background: linear-gradient(135deg, #f39c12, #f7dc6f); }
        .score-poor { background: linear-gradient(135deg, #e74c3c, #ec7063); }

        /* 申诉密码设置区域样式 */
        .appeal-password-section {
            margin-top: 15px;
        }

        .set-password-btn {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 4px rgba(23, 162, 184, 0.2);
        }

        .set-password-btn:hover {
            background: linear-gradient(135deg, #138496, #117a8b);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(23, 162, 184, 0.3);
        }

        .set-password-btn:active {
            transform: translateY(0);
        }

        .password-status {
            color: #28a745;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .password-status i {
            font-size: 16px;
        }

        /* 统计卡片网格 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border-left: 4px solid #3498db;
            text-align: center;
        }

        .stat-card.positive { border-left-color: #27ae60; }
        .stat-card.negative { border-left-color: #e74c3c; }
        .stat-card.info { border-left-color: #f39c12; }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        /* 操行分记录 */
        .records-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            max-height: 600px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 25px 25px 0 25px;
        }

        .records-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px 25px 25px 25px;
        }

        .records-container::-webkit-scrollbar {
            width: 6px;
        }

        .records-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .records-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .records-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        .record-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .record-card {
            background: white;
            border-radius: 10px;
            padding: 16px 20px;
            border-left: 5px solid #dee2e6;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .record-card:hover {
            background: #fafbfc;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .record-card.positive {
            border-left-color: #27ae60;
            background: linear-gradient(135deg, rgba(39, 174, 96, 0.08) 0%, white 100%);
        }

        .record-card.negative {
            border-left-color: #e74c3c;
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.08) 0%, white 100%);
        }

        .record-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .record-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }

        .score-change {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 700;
            color: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
            white-space: nowrap;
        }

        .score-change.positive { background: linear-gradient(135deg, #27ae60, #2ecc71); }
        .score-change.negative { background: linear-gradient(135deg, #e74c3c, #c0392b); }

        .record-reason {
            color: #2c3e50;
            font-size: 0.95rem;
            font-weight: 600;
            flex: 1;
            margin: 0 10px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .record-right {
            display: flex;
            align-items: center;
            gap: 12px;
            white-space: nowrap;
        }



        .record-operator, .record-time {
            font-size: 0.85rem;
            color: #7f8c8d;
            font-weight: 500;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 6px;
        }

        .record-operator {
            border: 1px solid #e9ecef;
        }

        .record-operator i {
            margin-right: 4px;
            color: #6c757d;
        }

        /* 申诉按钮样式 */
        .appeal-btn {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .appeal-btn:hover {
            background: linear-gradient(135deg, #e67e22, #d35400);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(243, 156, 18, 0.3);
        }

        .appeal-btn i {
            font-size: 0.75rem;
        }

        /* 申诉按钮禁用状态 */
        .appeal-btn:disabled,
        .appeal-btn.disabled {
            background: linear-gradient(135deg, #bdc3c7, #95a5a6);
            color: #7f8c8d;
            cursor: not-allowed;
            opacity: 0.6;
            transform: none;
            box-shadow: none;
        }

        .appeal-btn:disabled:hover,
        .appeal-btn.disabled:hover {
            background: linear-gradient(135deg, #bdc3c7, #95a5a6);
            transform: none;
            box-shadow: none;
        }

        /* 申诉状态提示 */
        .appeal-status {
            font-size: 0.75rem;
            color: #7f8c8d;
            font-style: italic;
            padding: 4px 8px;
            background: #f8f9fa;
            border-radius: 4px;
            border: 1px solid #e9ecef;
            white-space: nowrap;
        }

        .appeal-status.appealed {
            color: #e67e22;
            background: #fef9e7;
            border-color: #f39c12;
        }

        .appeal-status.disabled {
            color: #95a5a6;
            background: #f1f2f6;
            border-color: #bdc3c7;
        }

        .appeal-status.rejected {
            color: #e74c3c;
            background: #fdf2f2;
            border-color: #e74c3c;
        }

        .appeal-status.approved {
            color: #27ae60;
            background: #eafaf1;
            border-color: #27ae60;
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

        /* 自定义模态框样式 */
        .custom-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }

        .custom-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .custom-modal.show .modal-content {
            transform: scale(1);
        }

        .custom-modal.closing {
            animation: fadeOut 0.3s ease;
        }

        .custom-modal.closing .modal-content {
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f3f4;
        }

        .modal-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-title i {
            color: #3498db;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #95a5a6;
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            transition: all 0.3s ease;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover {
            background: #f8f9fa;
            color: #e74c3c;
        }

        .modal-body {
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 0.95rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
            outline: none;
        }

        .form-input:focus {
            border-color: #3498db;
            background: white;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
            font-family: inherit;
        }

        .form-help {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
        }

        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding-top: 15px;
            border-top: 1px solid #f1f3f4;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #2980b9, #1f5f8b);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }

        .btn-secondary {
            background: #f8f9fa;
            color: #6c757d;
            border: 1px solid #dee2e6;
        }

        .btn-secondary:hover {
            background: #e9ecef;
            color: #495057;
        }

        .btn-success {
            background: linear-gradient(135deg, #27ae60, #229954);
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #229954, #1e8449);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #e67e22, #d35400);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(243, 156, 18, 0.3);
        }

        @keyframes fadeIn {
              from { opacity: 0; }
              to { opacity: 1; }
          }

          @keyframes fadeOut {
              from { opacity: 1; }
              to { opacity: 0; }
          }

         @keyframes slideInRight {
             from {
                 transform: translateX(100%);
                 opacity: 0;
             }
             to {
                 transform: translateX(0);
                 opacity: 1;
             }
         }

         @keyframes slideOutRight {
             from {
                 transform: translateX(0);
                 opacity: 1;
             }
             to {
                 transform: translateX(100%);
                 opacity: 0;
             }
         }

        /* 移动端适配 */
        @media (max-width: 768px) {
            .main-layout {
                padding-left: 0;
            }
            
            .container {
                padding: 15px;
            }
            
            .header {
                margin-bottom: 25px;
            }
            
            .header h1 {
                font-size: 1.8rem;
                margin-bottom: 5px;
            }

            .header p {
                font-size: 1rem;
            }
            
            .profile-info {
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
                margin-bottom: 20px;
            }
            
            .profile-avatar {
                width: 60px;
                height: 60px;
                font-size: 24px;
                margin: 0 auto;
            }
            
            .profile-details h2 {
                font-size: 1.5rem;
                margin-bottom: 10px;
            }
            
            .profile-details p {
                font-size: 0.9rem;
                margin-bottom: 8px;
            }
            
            .current-score {
                font-size: 1rem;
                padding: 6px 12px;
                margin-top: 8px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
                margin-bottom: 20px;
            }
            
            .stat-card {
                padding: 12px;
                border-radius: 8px;
            }
            
            .stat-number {
                font-size: 1.3rem;
                margin-bottom: 6px;
            }
            
            .stat-label {
                font-size: 0.8rem;
            }
            
            .records-section {
                max-height: 500px;
                border-radius: 8px;
            }
            
            .section-title {
                font-size: 1.1rem;
                padding: 20px 20px 0 20px;
                margin-bottom: 15px;
            }
            
            .records-container {
                padding: 15px 20px 20px 20px;
            }
            
            .record-card {
                padding: 12px 15px;
                border-radius: 8px;
                margin-bottom: 8px;
            }
            
            .record-info {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            
            .record-left {
                justify-content: flex-start;
                gap: 8px;
                flex-wrap: wrap;
            }
            
            .score-change {
                font-size: 0.8rem;
                padding: 3px 8px;
                min-width: 50px;
                text-align: center;
            }
            
            .record-reason {
                font-size: 0.9rem;
                margin: 0;
                white-space: normal;
                word-wrap: break-word;
                line-height: 1.3;
            }
            
            .record-right {
                justify-content: space-between;
                margin-top: 8px;
                gap: 8px;
                flex-wrap: wrap;
            }
            

            
            .record-operator, .record-time {
                font-size: 0.75rem;
                padding: 3px 6px;
            }
            
            .appeal-btn {
                padding: 4px 8px;
                font-size: 0.7rem;
                border-radius: 4px;
                min-height: 28px;
            }
            
            .appeal-btn i {
                font-size: 0.7rem;
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
                margin-bottom: 4px;
            }
            
            .empty-state span {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>

<?php include '../../modules/student_sidebar.php'; ?>

<div class="main-layout">
    <div class="container">
        <div class="header">
            <h1>个人主页</h1>
            <p>操行分管理系统 | Behavior Score Management System</p>
        </div>

        <?php if (!$student): ?>
        <div class="empty-state">
            <i class="fa-solid fa-user-slash"></i>
            <p><?php echo isset($error_message) ? $error_message : '学生信息不存在'; ?></p>
            <span>请检查学生ID是否正确</span>
        </div>
        <?php else: ?>
        
        <!-- 个人信息卡片 -->
        <div class="profile-info">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php echo mb_substr($student['name'], 0, 1, 'UTF-8'); ?>
                </div>
                <div class="profile-details">
                    <h2><?php echo htmlspecialchars($student['name']); ?></h2>
                    <p><i class="fas fa-id-card"></i> 学号：<?php echo htmlspecialchars($student['student_id']); ?></p>
                    <?php 
                    $score = $student['current_score'];
                    $scoreClass = '';
                    $scoreText = '';
                    if ($score >= 90) {
                        $scoreClass = 'score-excellent';
                        $scoreText = '优秀';
                    } elseif ($score >= 70) {
                        $scoreClass = 'score-good';
                        $scoreText = '良好';
                    } elseif ($score >= 60) {
                        $scoreClass = 'score-average';
                        $scoreText = '及格';
                    } else {
                        $scoreClass = 'score-poor';
                        $scoreText = '不及格';
                    }
                    ?>
                    <div class="current-score <?php echo $scoreClass; ?>">
                        <i class="fas fa-star"></i> 当前操行分：<?php echo $score; ?>分 (<?php echo $scoreText; ?>)
                    </div>
                    <?php if ($hasAppealPermission && !$hasAppealPassword): ?>
                    <div class="appeal-password-section" id="appealPasswordSection">
                        <button class="set-password-btn" id="setPasswordBtn" onclick="handleSetPassword()">
                            <i class="fas fa-key"></i> 设置申诉密码
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 统计信息 -->
        <?php if ($stats): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_records']; ?></div>
                <div class="stat-label">总记录数</div>
            </div>
            <div class="stat-card positive">
                <div class="stat-number"><?php echo $stats['positive_records']; ?></div>
                <div class="stat-label">加分记录</div>
            </div>
            <div class="stat-card negative">
                <div class="stat-number"><?php echo $stats['negative_records']; ?></div>
                <div class="stat-label">扣分记录</div>
            </div>
            <div class="stat-card info">
                <div class="stat-number"><?php echo $stats['avg_change']; ?></div>
                <div class="stat-label">平均变动分数</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 操行分记录 -->
        <div class="records-section">
            <div class="section-title">
                <i class="fas fa-history"></i>
                操行分记录
            </div>
            
            <div class="records-container">
                <?php if (empty($records)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-clipboard-list"></i>
                    <p>暂无操行分记录</p>
                    <span>该学生还没有操行分变动记录</span>
                </div>
                <?php else: ?>
                <div class="record-list">
                    <?php foreach ($records as $record): ?>
                    <div class="record-card <?php echo $record['score_change'] > 0 ? 'positive' : 'negative'; ?><?php echo (!empty($record['appeal_status']) && $record['appeal_status'] === 'approved') ? ' appeal-approved' : ''; ?>" data-record-id="<?php echo $record['id']; ?>">
                        <div class="record-info">
                            <div class="record-left">
                                <div class="score-change <?php echo $record['score_change'] > 0 ? 'positive' : 'negative'; ?>">
                                    <?php echo $record['score_change'] > 0 ? '+' : ''; ?><?php echo $record['score_change']; ?>分
                                </div>
                                <div class="record-reason"><?php echo htmlspecialchars($record['reason']); ?></div>
                            </div>
                            <div class="record-right">
                                <div class="record-operator">
                                    <i class="fas fa-user"></i><?php echo htmlspecialchars($record['operator_name']); ?>
                                </div>
                                <div class="record-time"><?php echo date('m-d H:i', strtotime($record['created_at'])); ?></div>
                                <?php 
                                // 计算记录创建时间与当前时间的差值
                                $recordTime = strtotime($record['created_at']);
                                $currentTime = time();
                                $daysDiff = ($currentTime - $recordTime) / (24 * 60 * 60);
                                
                                // 检查申诉状态和权限
                                $isAppealed = !empty($record['appeal_id']);
                                $canAppeal = $daysDiff <= 3 && $hasAppealPermission && !$isAppealed;
                                
                                if ($daysDiff <= 3): 
                                    if ($isAppealed): 
                                        // 已申诉状态
                                        $appealStatusText = '';
                                        $appealStatusClass = 'appealed';
                                        switch($record['appeal_status']) {
                                            case 'pending':
                                                $appealStatusText = '申诉处理中';
                                                break;
                                            case 'rejected':
                                                $appealStatusText = '申诉已驳回';
                                                $appealStatusClass = 'rejected';
                                                break;
                                            case 'approved':
                                                $appealStatusText = '申诉已通过';
                                                $appealStatusClass = 'approved';
                                                break;
                                            default:
                                                $appealStatusText = '已申诉';
                                        }
                                ?>
                                <div class="appeal-status <?php echo $appealStatusClass; ?>" title="该记录已提交申诉">
                                    <?php if ($record['appeal_status'] === 'rejected'): ?>
                                        <i class="fas fa-times-circle"></i>
                                    <?php elseif ($record['appeal_status'] === 'approved'): ?>
                                        <i class="fas fa-check-circle"></i>
                                    <?php else: ?>
                                        <i class="fas fa-clock"></i>
                                    <?php endif; ?>
                                    <?php echo $appealStatusText; ?>
                                </div>
                                <?php elseif (!$hasAppealPermission): ?>
                                <div class="appeal-status disabled" title="您没有申诉权限，请联系管理员">
                                    <i class="fas fa-ban"></i> 无申诉权限
                                </div>
                                <?php else: ?>
                                <button class="appeal-btn" onclick="handleAppeal(<?php echo $record['score_change']; ?>, '<?php echo htmlspecialchars($record['reason'], ENT_QUOTES); ?>', <?php echo $record['id']; ?>)">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    申诉
                                </button>
                                <?php endif; ?>
                                <?php else: ?>
                                <div class="appeal-status disabled" title="申诉期限已过（仅限3天内）">
                                    <i class="fas fa-clock"></i> 申诉期限已过
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php endif; ?>
    </div>
</div>



<script>
$(document).ready(function() {});

// 全局变量存储当前申诉信息
let currentAppealData = {};
// 申诉权限状态
const hasAppealPermission = <?php echo $hasAppealPermission ? 'true' : 'false'; ?>;

// 通用AJAX请求函数
function makeAjaxRequest(action, data, successCallback, errorMessage) {
    $.ajax({
        url: 'profile.php',
        type: 'POST',
        data: { action: action, student_id: '<?php echo $validatedStudentId; ?>', ...data },
        dataType: 'json',
        success: successCallback,
        error: function() { notification.error(errorMessage); }
    });
}

// 处理申诉按钮点击事件
function handleAppeal(scoreChange, reason, recordId) {
    // 存储申诉信息
    currentAppealData = { scoreChange, reason, recordId };
    
    // 第一步：检查申诉权限
    checkAppealPermission(function(hasPermission, hasPassword) {
        if (!hasPermission) {
            notification.warning('您没有申诉权限，请联系管理员');
            return;
        }
        
        // 第二步：检查密码设置
        if (!hasPassword) {
            // 没有设置密码，要求设置
            showModal('setPasswordModal');
        } else {
            // 已设置密码，要求输入密码
            showModal('verifyPasswordModal');
        }
    });
}

// 检查申诉权限和密码状态
function checkAppealPermission(callback) {
    makeAjaxRequest('check_appeal_permission', {}, function(response) {
        if (response.success) {
            callback(response.has_permission, response.has_password);
        } else {
            notification.error('检查申诉权限失败：' + response.error);
        }
    }, '网络连接异常，请稍后重试');
}



// 处理设置密码按钮点击事件（独立设置）
function handleSetPassword() {
    // 检查申诉权限
    checkAppealPermission(function(hasPermission, hasPassword) {
        if (!hasPermission) {
            notification.warning('您没有申诉权限，请联系管理员');
            return;
        }
        
        if (hasPassword) {
            notification.info('您已经设置过申诉密码');
            hideAppealPasswordSection();
            return;
        }
        
        // 显示设置密码模态框
        showModal('setPasswordModal');
    });
}

// 确认设置密码
function confirmSetPassword() {
    const password = document.getElementById('setPasswordInput').value;
    if (!password) {
        notification.error('请输入密码');
        return;
    }
    if (password.length < 6 || password.length > 20) {
        notification.error('密码长度必须为6-20位');
        return;
    }
    
    // 提交设置密码请求
    makeAjaxRequest('set_appeal_password', { password: password }, function(response) {
        if (response.success) {
            closeModal('setPasswordModal');
            document.getElementById('setPasswordInput').value = '';
            notification.success('密码设置成功');
            
            // 隐藏申诉密码设置区域
            hideAppealPasswordSection();
            
            // 如果是申诉流程中的设置密码，继续申诉流程
            if (currentAppealData.recordId) {
                showModal('appealReasonModal');
            }
        } else {
            notification.error('密码设置失败：' + response.error);
        }
    }, '密码设置失败，请稍后重试');
}

// 隐藏申诉密码设置区域（设置密码成功后调用）
function hideAppealPasswordSection() {
    const appealPasswordSection = document.querySelector('.appeal-password-section');
    if (appealPasswordSection) {
        appealPasswordSection.style.display = 'none';
    }
}

// 确认验证密码
function confirmVerifyPassword() {
    const password = document.getElementById('verifyPasswordInput').value;
    if (!password) {
        notification.error('请输入密码');
        return;
    }
    
    // 验证密码
    makeAjaxRequest('verify_appeal_password', { password: password }, function(response) {
        if (response.success) {
            closeModal('verifyPasswordModal');
            document.getElementById('verifyPasswordInput').value = '';
            showModal('appealReasonModal');
        } else {
            notification.error('密码错误，请重新输入');
        }
    }, '密码验证失败，请稍后重试');
}

// 确认提交申诉
function confirmSubmitAppeal() {
    const appealReason = document.getElementById('appealReasonInput').value;
    if (!appealReason.trim()) {
        notification.error('申诉理由不能为空');
        return;
    }
    
    // 提交申诉
    makeAjaxRequest('submit_appeal', {
        record_id: currentAppealData.recordId,
        appeal_reason: appealReason.trim()
    }, function(response) {
        if (response.success) {
            closeModal('appealReasonModal');
            document.getElementById('appealReasonInput').value = '';
            notification.success('申诉提交成功，请等待处理');
            // 更新按钮状态
            const appealBtn = document.querySelector(`[data-record-id="${currentAppealData.recordId}"] .appeal-btn`);
            if (appealBtn) {
                appealBtn.outerHTML = '<span class="appeal-status appealed"><i class="fas fa-clock"></i> 申诉处理中</span>';
            }
        } else {
            notification.error('申诉提交失败：' + response.error);
        }
    }, '申诉提交失败，请稍后重试');
}

// 显示模态框
function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('show');
        modal.style.display = 'flex';
    }
}

// 关闭模态框
 function closeModal(modalId) {
     const modal = document.getElementById(modalId);
     if (modal && modal.classList.contains('show')) {
         modal.classList.add('closing');
         
         setTimeout(() => {
             modal.classList.remove('show', 'closing');
             modal.style.display = 'none';
         }, 300);
     }
 }

// 点击背景关闭模态框
 document.addEventListener('click', function(e) {
     if (e.target.classList.contains('custom-modal')) {
         closeModal(e.target.id);
     }
 });

 // 键盘事件支持
 document.addEventListener('keydown', function(e) {
     if (e.key === 'Enter') {
         if (document.getElementById('setPasswordModal').classList.contains('show')) {
             const input = document.getElementById('setPasswordInput');
             if (document.activeElement === input) {
                 confirmSetPassword();
             }
         } else if (document.getElementById('verifyPasswordModal').classList.contains('show')) {
             const input = document.getElementById('verifyPasswordInput');
             if (document.activeElement === input) {
                 confirmVerifyPassword();
             }
         } else if (document.getElementById('appealReasonModal').classList.contains('show')) {
             const textarea = document.getElementById('appealReasonInput');
             if (document.activeElement === textarea && e.ctrlKey) {
                 confirmSubmitAppeal();
             }
         }
     }
     
     // ESC关闭模态框
     if (e.key === 'Escape') {
         const modals = document.querySelectorAll('.custom-modal.show');
         modals.forEach(modal => {
             closeModal(modal.id);
         });
     }
 });

// 页面初始化
document.addEventListener('DOMContentLoaded', function() {
    // 页面初始化完成
});

// 滚动到顶部
function scrollToTop() {
    window.scrollTo(0, 0);
}
</script>

<!-- 自定义模态框 -->
<!-- 设置申诉密码模态框 -->
<div id="setPasswordModal" class="custom-modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-key"></i>
                设置申诉密码
            </div>
            <button class="modal-close" onclick="closeModal('setPasswordModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">请设置申诉密码</label>
                <input type="password" id="setPasswordInput" class="form-input" placeholder="请输入6-20位密码">
                <div class="form-help">密码长度为6-20位，用于后续申诉验证</div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('setPasswordModal')">
                <i class="fas fa-times"></i> 取消
            </button>
            <button class="btn btn-primary" onclick="confirmSetPassword()">
                <i class="fas fa-check"></i> 确认设置
            </button>
        </div>
    </div>
</div>

<!-- 验证申诉密码模态框 -->
<div id="verifyPasswordModal" class="custom-modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-unlock-alt"></i>
                验证申诉密码
            </div>
            <button class="modal-close" onclick="closeModal('verifyPasswordModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">请输入申诉密码</label>
                <input type="password" id="verifyPasswordInput" class="form-input" placeholder="请输入您的申诉密码">
                <div class="form-help">请输入您之前设置的申诉密码</div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('verifyPasswordModal')">
                <i class="fas fa-times"></i> 取消
            </button>
            <button class="btn btn-success" onclick="confirmVerifyPassword()">
                <i class="fas fa-check"></i> 验证密码
            </button>
        </div>
    </div>
</div>

<!-- 申诉理由模态框 -->
<div id="appealReasonModal" class="custom-modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-edit"></i>
                填写申诉理由
            </div>
            <button class="modal-close" onclick="closeModal('appealReasonModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">申诉理由</label>
                 <textarea id="appealReasonInput" class="form-input form-textarea" placeholder="请详细说明您的申诉理由，包括具体情况、时间、地点等信息..."></textarea>
                 <div class="form-help">请详细描述申诉的具体原因，以便管理员更好地处理您的申诉<br><small>💡 提示：按 Ctrl+Enter 快速提交</small></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('appealReasonModal')">
                <i class="fas fa-times"></i> 取消
            </button>
            <button class="btn btn-warning" onclick="confirmSubmitAppeal()">
                <i class="fas fa-paper-plane"></i> 提交申诉
            </button>
        </div>
    </div>
</div>

</body>
</html>