<?php
// 定义常量以允许包含的文件访问
define('INCLUDED_FROM_APP', true);

/**
 * 数据导出页面
 * 需要管理员权限才能访问
 */
session_start();
// 引入数据库连接类
require_once '../../functions/database.php';

// 初始化数据库连接
$db = new Database();

// 引入PhpSpreadsheet相关类
// 尝试多个可能的vendor路径
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
} elseif (file_exists('../../vendor/autoload.php')) {
    require_once '../../vendor/autoload.php';
} elseif (file_exists('../../../vendor/autoload.php')) {
    require_once '../../../vendor/autoload.php';
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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



// 通用时间过滤函数
function buildTimeFilter($timeColumnAlias, $timeFilter = null, $startDate = null, $endDate = null) {
    if ($timeFilter === null) $timeFilter = $_GET['time_filter'] ?? '';
    if ($startDate === null) $startDate = $_GET['start_date'] ?? '';
    if ($endDate === null) $endDate = $_GET['end_date'] ?? '';
    
    $whereConditions = [];
    $params = [];
    
    if ($timeFilter) {
        switch ($timeFilter) {
            case 'today':
                $whereConditions[] = "DATE({$timeColumnAlias}) = CURDATE()";
                break;
            case 'week':
                $whereConditions[] = "YEARWEEK({$timeColumnAlias}, 1) = YEARWEEK(CURDATE(), 1)";
                break;
            case 'month':
                $whereConditions[] = "YEAR({$timeColumnAlias}) = YEAR(CURDATE()) AND MONTH({$timeColumnAlias}) = MONTH(CURDATE())";
                break;
            case 'semester':
                if ($timeColumnAlias === 'cr.created_at') {
                    $whereConditions[] = 'cr.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)';
                } else {
                    $whereConditions[] = "{$timeColumnAlias} >= (SELECT start_date FROM semesters WHERE is_current = 1 LIMIT 1)";
                }
                break;
        }
    }
    
    if ($startDate && $endDate) {
        $whereConditions[] = "DATE({$timeColumnAlias}) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }
    
    return [$whereConditions, $params];
}

// 构建操行记录查询条件
function buildConductRecordsQuery() {
    list($timeConditions, $params) = buildTimeFilter('cr.created_at');
    
    $whereClause = $timeConditions ? 'WHERE ' . implode(' AND ', $timeConditions) : '';
    
    $sql = "SELECT 
                cr.id,
                s.student_id,
                s.name as student_name,
                cr.reason,
                cr.score_change,
                cr.score_after,
                cr.operator_name,
                DATE_FORMAT(cr.created_at, '%Y-%m-%d %H:%i') as record_datetime,
                cr.created_at,
                CASE 
                    WHEN EXISTS (
                        SELECT 1 FROM appeals a 
                        WHERE a.record_id = cr.id AND a.status = 'approved'
                    ) THEN '无效'
                    ELSE '有效'
                END as record_status
            FROM conduct_records cr
            JOIN students s ON cr.student_id = s.id
            {$whereClause}
            ORDER BY cr.created_at DESC";
    
    return [$sql, $params];
}

// 构建学生分数查询条件
function buildStudentScoresQuery() {
    $scoreFilter = $_GET['score_filter'] ?? '';
    
    $whereConditions = [];
    $params = [];
    
    if ($scoreFilter) {
        switch ($scoreFilter) {
            case 'excellent':
                $whereConditions[] = 's.current_score >= 90';
                break;
            case 'good':
                $whereConditions[] = 's.current_score >= 70 AND s.current_score < 90';
                break;
            case 'warning':
                $whereConditions[] = 's.current_score >= 60 AND s.current_score < 70';
                break;
            case 'danger':
                $whereConditions[] = 's.current_score < 60';
                break;
        }
    }
    
    $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $sql = "SELECT 
                s.student_id,
                s.name,
                s.current_score,
                s.updated_at,
                COUNT(CASE WHEN cr.score_change < 0 THEN 1 END) as deduction_count,
                COALESCE(SUM(CASE WHEN cr.score_change < 0 THEN ABS(cr.score_change) ELSE 0 END), 0) as total_deduction
            FROM students s
            LEFT JOIN conduct_records cr ON s.id = cr.student_id
            {$whereClause}
            GROUP BY s.id, s.student_id, s.name, s.current_score, s.updated_at
            ORDER BY s.current_score DESC, s.student_id";
    
    return [$sql, $params];
}

// 构建申诉信息查询条件
function buildAppealsQuery() {
    $statusFilter = $_GET['status_filter'] ?? '';
    
    $whereConditions = [];
    $params = [];
    
    if ($statusFilter) {
        $whereConditions[] = 'a.status = ?';
        $params[] = $statusFilter;
    }
    
    // 使用通用时间过滤函数
    list($timeConditions, $timeParams) = buildTimeFilter('a.created_at');
    $whereConditions = array_merge($whereConditions, $timeConditions);
    $params = array_merge($params, $timeParams);
    
    $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    
    $sql = "SELECT 
                a.id,
                s.student_id,
                s.name as student_name,
                cr.reason as record_reason,
                cr.score_change as original_score,
                a.reason as appeal_reason,
                a.status,
                a.processed_by,
                a.created_at,
                a.processed_at
            FROM appeals a
            JOIN students s ON a.student_id = s.id
            JOIN conduct_records cr ON a.record_id = cr.id
            {$whereClause}
            ORDER BY 
                CASE WHEN a.status = 'pending' THEN 0 ELSE 1 END,
                CASE WHEN a.status = 'pending' THEN a.created_at END ASC,
                CASE WHEN a.status != 'pending' THEN a.created_at END DESC";

    
    return [$sql, $params];
}

// 获取操行记录表头和数据行
function getConductRecordsHeaders($fields) {
    // 如果没有指定字段，默认导出所有字段
    if (empty($fields)) {
        $fields = ['student_info', 'reason', 'score', 'time', 'operator', 'status'];
    }
    
    $headers = [];
    if (in_array('student_info', $fields)) {
        $headers[] = '学号';
        $headers[] = '姓名';
    }
    if (in_array('reason', $fields)) {
        $headers[] = '操行事由';
    }
    if (in_array('score', $fields)) {
        $headers[] = '分值变动';
        $headers[] = '操作后分数';
    }
    if (in_array('time', $fields)) {
        $headers[] = '记录时间';
    }
    if (in_array('operator', $fields)) {
        $headers[] = '操作人员';
    }
    if (in_array('status', $fields)) {
        $headers[] = '记录状态';
    }
    return $headers;
}

// 获取学生分数表头
function getStudentScoresHeaders($fields) {
    // 如果没有指定字段，默认导出所有字段
    if (empty($fields)) {
        $fields = ['student_info', 'current_score', 'deduction_count', 'total_deduction', 'last_update'];
    }
    
    $headers = [];
    if (in_array('student_info', $fields)) {
        $headers[] = '学号';
        $headers[] = '姓名';
    }
    if (in_array('current_score', $fields)) {
        $headers[] = '当前分数';
    }
    if (in_array('deduction_count', $fields)) {
        $headers[] = '扣分次数';
    }
    if (in_array('total_deduction', $fields)) {
        $headers[] = '累计扣分';
    }
    if (in_array('last_update', $fields)) {
        $headers[] = '最后更新';
    }
    return $headers;
}

// 格式化操行记录数据行
function formatConductRecordRow($record, $fields) {
    // 如果没有指定字段，默认导出所有字段
    if (empty($fields)) {
        $fields = ['student_info', 'reason', 'score', 'time', 'operator', 'status'];
    }
    
    $row = [];
    if (in_array('student_info', $fields)) {
        $row[] = $record['student_id'];
        $row[] = $record['student_name'];
    }
    if (in_array('reason', $fields)) {
        $row[] = $record['reason'];
    }
    if (in_array('score', $fields)) {
        $row[] = $record['score_change'];
        $row[] = $record['score_after'];
    }
    if (in_array('time', $fields)) {
        $row[] = $record['record_datetime'];
    }
    if (in_array('operator', $fields)) {
        $row[] = $record['operator_name'];
    }
    if (in_array('status', $fields)) {
        $row[] = $record['record_status'];
    }
    return $row;
}

// 格式化学生分数数据行
function formatStudentScoreRow($student, $fields) {
    // 如果没有指定字段，默认导出所有字段
    if (empty($fields)) {
        $fields = ['student_info', 'current_score', 'deduction_count', 'total_deduction', 'last_update'];
    }
    
    $row = [];
    if (in_array('student_info', $fields)) {
        $row[] = $student['student_id'];
        $row[] = $student['name'];
    }
    if (in_array('current_score', $fields)) {
        $row[] = $student['current_score'];
    }
    if (in_array('deduction_count', $fields)) {
        $row[] = $student['deduction_count'];
    }
    if (in_array('total_deduction', $fields)) {
        $row[] = $student['total_deduction'];
    }
    if (in_array('last_update', $fields)) {
        $row[] = date('Y-m-d H:i:s', strtotime($student['updated_at']));
    }
    return $row;
}

// 获取申诉信息表头
function getAppealsHeaders($fields) {
    // 如果没有指定字段，默认导出所有字段
    if (empty($fields)) {
        $fields = ['student_info', 'record_info', 'appeal_info', 'process_info', 'time_info'];
    }
    
    $headers = [];
    if (in_array('student_info', $fields)) {
        $headers[] = '学号';
        $headers[] = '学生姓名';
    }
    if (in_array('record_info', $fields)) {
        $headers[] = '原操行事由';
        $headers[] = '原分值变动';
    }
    if (in_array('appeal_info', $fields)) {
        $headers[] = '申诉理由';
        $headers[] = '申诉状态';
    }
    if (in_array('process_info', $fields)) {
        $headers[] = '处理人员';
    }
    if (in_array('time_info', $fields)) {
        $headers[] = '申诉时间';
        $headers[] = '处理时间';
    }
    return $headers;
}

// 格式化申诉信息数据行
function formatAppealRow($appeal, $fields) {
    // 如果没有指定字段，默认导出所有字段
    if (empty($fields)) {
        $fields = ['student_info', 'record_info', 'appeal_info', 'process_info', 'time_info'];
    }
    
    $row = [];
    if (in_array('student_info', $fields)) {
        $row[] = $appeal['student_id'];
        $row[] = $appeal['student_name'];
    }
    if (in_array('record_info', $fields)) {
        $row[] = $appeal['record_reason'];
        $row[] = $appeal['original_score'];
    }
    if (in_array('appeal_info', $fields)) {
        $row[] = $appeal['appeal_reason'];
        $statusMap = [
            'pending' => '待处理',
            'approved' => '已通过',
            'rejected' => '已拒绝'
        ];
        $row[] = $statusMap[$appeal['status']] ?? $appeal['status'];
    }
    if (in_array('process_info', $fields)) {
        $row[] = $appeal['processed_by'] ?? '未处理';
    }
    if (in_array('time_info', $fields)) {
        $row[] = date('Y-m-d H:i:s', strtotime($appeal['created_at']));
        $row[] = $appeal['processed_at'] ? date('Y-m-d H:i:s', strtotime($appeal['processed_at'])) : '未处理';
    }
    return $row;
}

// 设置Excel表头样式
function setExcelHeaderStyle($sheet, $headerRange) {
    if (class_exists('PhpOffice\PhpSpreadsheet\Style\Fill')) {
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E6E6FA']
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
            ],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
        ]);
    }
}

// 设置Excel数据区域边框
function setExcelDataBorders($sheet, $dataRange) {
    if (class_exists('PhpOffice\PhpSpreadsheet\Style\Border')) {
        $sheet->getStyle($dataRange)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
            ]
        ]);
    }
}



// 通用导出请求处理函数
function handleExportRequest() {
    $db = null;
    $pdo = null;
    try {
        $exportType = $_GET['export'] ?? '';
        $type = $_GET['type'] ?? '';
        $db = new Database();
        $pdo = $db->getMysqlConnection();
        
        if ($exportType === 'excel') {
            if ($type === 'conduct_records') {
                exportConductRecordsExcel($pdo);
            } elseif ($type === 'student_scores') {
                exportStudentScoresExcel($pdo);
            } elseif ($type === 'appeals') {
                exportAppealsExcel($pdo);
            } elseif ($type === 'combined') {
                exportCombinedExcel($pdo);
            } else {
                throw new Exception('无效的导出类型');
            }
        } else {
            throw new Exception('无效的导出格式');
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } finally {
        // 释放数据库连接回连接池
        if ($db && $pdo) {
            $db->releaseMysqlConnection($pdo);
        }
    }
    exit;
}

// 处理导出请求
if (isset($_GET['export'])) {
    handleExportRequest();
}





// 导出申诉信息Excel
// 通用Excel导出函数
function exportExcel($pdo, $type, $title, $filename) {
    try {
        // 检查是否有PhpSpreadsheet库
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            // 回退到HTML导出
            switch ($type) {
                case 'conduct_records':
                    exportConductRecordsHTMLExcel($pdo);
                    break;
                case 'student_scores':
                    exportStudentScoresHTMLExcel($pdo);
                    break;
                case 'appeals':
                    exportAppealsHTMLExcel($pdo);
                    break;
            }
            return;
        }
        
        $fields = $_GET['fields'] ?? [];
        
        // 根据类型获取查询和数据
        switch ($type) {
            case 'conduct_records':
                list($sql, $params) = buildConductRecordsQuery();
                $getHeaders = 'getConductRecordsHeaders';
                $formatRow = 'formatConductRecordRow';
                break;
            case 'student_scores':
                list($sql, $params) = buildStudentScoresQuery();
                $getHeaders = 'getStudentScoresHeaders';
                $formatRow = 'formatStudentScoreRow';
                break;
            case 'appeals':
                list($sql, $params) = buildAppealsQuery();
                $getHeaders = 'getAppealsHeaders';
                $formatRow = 'formatAppealRow';
                break;
            default:
                throw new Exception('无效的导出类型');
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        // 检查是否有数据
        if (empty($data)) {
            throw new Exception('没有找到符合条件的数据');
        }
        
        // 创建Excel文件
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($title);
        
        // 写入表头
        $headers = $getHeaders($fields);
        $col = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($col++, 1, $header);
        }
        
        // 设置表头样式
        $headerRange = 'A1:' . chr(64 + count($headers)) . '1';
        if (function_exists('setExcelHeaderStyle')) {
            setExcelHeaderStyle($sheet, $headerRange);
        } else {
            $sheet->getStyle($headerRange)->getFont()->setBold(true);
            $sheet->getStyle($headerRange)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E3F2FD');
        }
        
        // 写入数据
        $row = 2;
        $statusColumnIndex = null;
        
        // 找到记录状态列的位置（仅对操行记录类型）
        if ($type === 'conduct_records' && in_array('status', $fields)) {
            $statusColumnIndex = 1;
            foreach ($headers as $index => $header) {
                if ($header === '记录状态') {
                    $statusColumnIndex = $index + 1;
                    break;
                }
            }
        }
        
        foreach ($data as $item) {
            $rowData = $formatRow($item, $fields);
            $col = 1;
            foreach ($rowData as $value) {
                $sheet->setCellValueByColumnAndRow($col, $row, $value);
                
                // 为记录状态列设置颜色
                if ($statusColumnIndex && $col === $statusColumnIndex && $type === 'conduct_records') {
                    $cellCoordinate = chr(64 + $col) . $row;
                    if ($value === '有效') {
                        // 绿色背景
                        $sheet->getStyle($cellCoordinate)->applyFromArray([
                            'fill' => [
                                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                                'startColor' => ['rgb' => 'C8E6C9'] // 浅绿色
                            ],
                            'font' => [
                                'color' => ['rgb' => '2E7D32'] // 深绿色字体
                            ]
                        ]);
                    } elseif ($value === '无效') {
                        // 红色背景
                        $sheet->getStyle($cellCoordinate)->applyFromArray([
                            'fill' => [
                                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                                'startColor' => ['rgb' => 'FFCDD2'] // 浅红色
                            ],
                            'font' => [
                                'color' => ['rgb' => 'C62828'] // 深红色字体
                            ]
                        ]);
                    }
                }
                $col++;
            }
            $row++;
        }
        
        // 设置数据区域边框和自动列宽
        if ($row > 2) {
            $dataRange = 'A1:' . chr(64 + count($headers)) . ($row - 1);
            if (function_exists('setExcelDataBorders')) {
                setExcelDataBorders($sheet, $dataRange);
            }
        }
        
        foreach (range(1, count($headers)) as $columnIndex) {
            $sheet->getColumnDimensionByColumn($columnIndex)->setAutoSize(true);
        }
        
        // 输出Excel文件
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function exportAppealsExcel($pdo) {
    exportExcel($pdo, 'appeals', '申诉信息', '操行分数据-' . date('Y年m月d日H时i分') . '导出.xlsx');
}

// 创建工作表的通用函数
function createWorksheet($pdo, $spreadsheet, $sheetIndex, $type) {
    $typeConfig = [
        'conduct_records' => [
            'title' => '操行记录',
            'name' => '操行记录',
            'fields' => ['student_info', 'reason', 'score', 'time', 'operator', 'status'],
            'query' => 'buildConductRecordsQuery',
            'headers' => 'getConductRecordsHeaders',
            'format' => 'formatConductRecordRow'
        ],
        'student_scores' => [
            'title' => '学生分数',
            'name' => '学生分数',
            'fields' => ['student_info', 'current_score', 'deduction_count', 'total_deduction', 'last_update'],
            'query' => 'buildStudentScoresQuery',
            'headers' => 'getStudentScoresHeaders',
            'format' => 'formatStudentScoreRow'
        ],
        'appeals' => [
            'title' => '申诉信息',
            'name' => '申诉信息',
            'fields' => ['student_info', 'record_info', 'appeal_info', 'process_info', 'time_info'],
            'query' => 'buildAppealsQuery',
            'headers' => 'getAppealsHeaders',
            'format' => 'formatAppealRow'
        ]
    ];
    
    if (!isset($typeConfig[$type])) {
        throw new Exception('无效的工作表类型');
    }
    
    $config = $typeConfig[$type];
    
    // 创建工作表
    if ($sheetIndex === 0) {
        $sheet = $spreadsheet->getActiveSheet();
    } else {
        $sheet = $spreadsheet->createSheet();
    }
    $sheet->setTitle($config['title']);
    
    // 获取数据
    list($sql, $params) = $config['query']();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    
    // 写入表头
    $headers = $config['headers']($config['fields']);
    $col = 1;
    foreach ($headers as $header) {
        $sheet->setCellValueByColumnAndRow($col++, 1, $header);
    }
    setExcelHeaderStyle($sheet, 'A1:' . chr(64 + count($headers)) . '1');
    
    // 写入数据
    $row = 2;
    $statusColumnIndex = null;
    
    // 找到记录状态列的位置（仅对操行记录类型）
    if ($type === 'conduct_records' && in_array('status', $config['fields'])) {
        $statusColumnIndex = 1;
        foreach ($headers as $index => $header) {
            if ($header === '记录状态') {
                $statusColumnIndex = $index + 1;
                break;
            }
        }
    }
    
    foreach ($data as $item) {
        $rowData = $config['format']($item, $config['fields']);
        $col = 1;
        foreach ($rowData as $value) {
            $sheet->setCellValueByColumnAndRow($col, $row, $value);
            
            // 为记录状态列设置颜色
            if ($statusColumnIndex && $col === $statusColumnIndex && $type === 'conduct_records') {
                $cellCoordinate = chr(64 + $col) . $row;
                if ($value === '有效') {
                    // 绿色背景
                    $sheet->getStyle($cellCoordinate)->applyFromArray([
                        'fill' => [
                            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'C8E6C9'] // 浅绿色
                        ],
                        'font' => [
                            'color' => ['rgb' => '2E7D32'] // 深绿色字体
                        ]
                    ]);
                } elseif ($value === '无效') {
                    // 红色背景
                    $sheet->getStyle($cellCoordinate)->applyFromArray([
                        'fill' => [
                            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'FFCDD2'] // 浅红色
                        ],
                        'font' => [
                            'color' => ['rgb' => 'C62828'] // 深红色字体
                        ]
                    ]);
                }
            }
            $col++;
        }
        $row++;
    }
    
    // 设置自动列宽
    foreach (range(1, count($headers)) as $columnIndex) {
        $sheet->getColumnDimensionByColumn($columnIndex)->setAutoSize(true);
    }
    
    return $config['name'];
}

// 合并导出Excel
function exportCombinedExcel($pdo) {
    try {
        // 检查是否有PhpSpreadsheet库
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            throw new Exception('PhpSpreadsheet库未安装');
        }
        
        // 获取要导出的表格类型
        $selectedTables = isset($_GET['tables']) ? explode(',', $_GET['tables']) : ['conduct_records', 'student_scores', 'appeals'];
        
        // 创建Excel文件
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $tableNames = [];
        
        // 根据选择的表格类型创建工作表
        foreach ($selectedTables as $index => $tableType) {
            $tableName = createWorksheet($pdo, $spreadsheet, $index, $tableType);
            $tableNames[] = $tableName;
        }
        
        // 激活第一个工作表
        $spreadsheet->setActiveSheetIndex(0);
        
        // 生成文件名
        $tableNamesStr = implode('_', $tableNames);
        $filename = '操行分数据-' . date('Y年m月d日H时i分') . '导出.xlsx';
        
        // 输出文件
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 导出操行记录Excel
function exportConductRecordsExcel($pdo) {
    exportExcel($pdo, 'conduct_records', '操行记录', '操行分数据-' . date('Y年m月d日H时i分') . '导出.xlsx');
}

// 导出学生操行分Excel
function exportStudentScoresExcel($pdo) {
    exportExcel($pdo, 'student_scores', '学生分数', '操行分数据-' . date('Y年m月d日H时i分') . '导出.xlsx');
}

// 使用HTML表格模拟Excel导出操行记录
// 通用HTML表格导出函数
function exportHTMLExcel($pdo, $type, $filename) {
    $fields = $_GET['fields'] ?? [];
    
    // 根据类型获取查询和数据
    switch ($type) {
        case 'conduct_records':
            list($sql, $params) = buildConductRecordsQuery();
            $getHeaders = 'getConductRecordsHeaders';
            $formatRow = 'formatConductRecordRow';
            break;
        case 'student_scores':
            list($sql, $params) = buildStudentScoresQuery();
            $getHeaders = 'getStudentScoresHeaders';
            $formatRow = 'formatStudentScoreRow';
            break;
        case 'appeals':
            list($sql, $params) = buildAppealsQuery();
            $getHeaders = 'getAppealsHeaders';
            $formatRow = 'formatAppealRow';
            break;
        default:
            throw new Exception('无效的导出类型');
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    
    // 设置Excel下载头
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    echo "\xEF\xBB\xBF";
    
    // 开始HTML表格
    echo '<table border="1">';
    echo '<tr style="background-color: #f2f2f2; font-weight: bold;">';
    
    $headers = $getHeaders($fields);
    foreach ($headers as $header) {
        echo '<td>' . htmlspecialchars($header) . '</td>';
    }
    echo '</tr>';
    
    // 写入数据
    foreach ($data as $item) {
        echo '<tr>';
        $rowData = $formatRow($item, $fields);
        foreach ($rowData as $value) {
            echo '<td>' . htmlspecialchars($value) . '</td>';
        }
        echo '</tr>';
    }
    
    echo '</table>';
}

function exportConductRecordsHTMLExcel($pdo) {
    exportHTMLExcel($pdo, 'conduct_records', '操行分数据-' . date('Y年m月d日H时i分') . '导出.xls');
}

function exportStudentScoresHTMLExcel($pdo) {
    exportHTMLExcel($pdo, 'student_scores', '操行分数据-' . date('Y年m月d日H时i分') . '导出.xls');
}

function exportAppealsHTMLExcel($pdo) {
    exportHTMLExcel($pdo, 'appeals', '操行分数据-' . date('Y年m月d日H时i分') . '导出.xls');
}



?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操行分管理系统 - 数据导出</title>
    <link rel="icon" type="image/x-icon" href="../../favicon.ico">
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

        /* 导出选项卡片 */
        .export-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }

        .export-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-right: 15px;
        }

        .card-icon.deduction {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }

        .card-icon.conduct {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }

        .card-icon.combined {
            background: none;
            color: #333;
            font-size: 3em;
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .card-description {
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        .export-options {
            margin-bottom: 25px;
        }

        .option-group {
            margin-bottom: 20px;
        }

        .option-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 500;
            color: #2c3e50;
        }

        .filter-group {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .filter-select, .filter-input {
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            min-width: 150px;
            height: 44px;
            box-sizing: border-box;
            vertical-align: middle;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .filter-select:hover, .filter-input:hover {
            border-color: #3498db;
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.15);
            transform: translateY(-1px);
        }
        
        .date-separator {
            color: #666;
            font-weight: 500;
            font-size: 14px;
            vertical-align: middle;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }
        
        /* 日期输入框特殊样式 */
        .filter-input[type="date"] {
            font-family: inherit;
            color: #333;
            background-color: white;
            -webkit-appearance: none;
            -moz-appearance: textfield;
            appearance: none;
            position: relative;
        }
        
        .filter-input[type="date"]::-webkit-calendar-picker-indicator {
            background: transparent;
            bottom: 0;
            color: transparent;
            cursor: pointer;
            height: auto;
            left: 0;
            position: absolute;
            right: 0;
            top: 0;
            width: auto;
        }
        
        .filter-input[type="date"]::-webkit-inner-spin-button,
        .filter-input[type="date"]::-webkit-clear-button {
            display: none;
        }
        
        .filter-input[type="date"]::-moz-focus-inner {
            border: 0;
        }
        
        /* 自定义日期占位符样式 */


        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }





        .checkbox-group {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            min-width: 140px;
        }

        .checkbox-item:hover {
            background: #e3f2fd;
            border-color: #3498db;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(52, 152, 219, 0.2);
        }

        .checkbox-item input[type="checkbox"] {
            display: none;
        }

        .checkmark {
            width: 20px;
            height: 20px;
            border: 2px solid #bdc3c7;
            border-radius: 4px;
            position: relative;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .checkbox-item input[type="checkbox"]:checked + .checkmark {
            background: linear-gradient(135deg, #3498db, #2980b9);
            border-color: #2980b9;
        }

        .checkbox-item input[type="checkbox"]:checked + .checkmark::after {
            content: '';
            position: absolute;
            left: 50%;
            top: 50%;
            width: 6px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: translate(-50%, -60%) rotate(45deg);
        }

        .checkbox-item input[type="checkbox"]:checked ~ i,
        .checkbox-item input[type="checkbox"]:checked ~ span:not(.checkmark) {
            color: #2980b9;
            font-weight: 600;
        }

        .checkbox-item i {
            font-size: 16px;
            color: #7f8c8d;
            transition: color 0.3s ease;
        }

        .checkbox-item span:not(.checkmark) {
            font-size: 14px;
            color: #2c3e50;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .export-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 20px;
        }

        .export-btn {
            padding: 14px 28px;
            border: 2px solid transparent;
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 140px;
            justify-content: center;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
        }

        .export-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn-excel {
            background: linear-gradient(135deg, #27ae60, #229954);
            color: white;
            border-color: #27ae60;
        }

        .btn-excel:hover {
            background: linear-gradient(135deg, #229954, #1e8449);
            border-color: #229954;
        }


        
        /* 导出类型选项卡样式 */
        .export-type-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }
        
        .tab-btn {
            flex: 1;
            padding: 14px 20px;
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #6c757d;
            transition: all 0.3s ease;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .tab-btn:hover {
            background: #f8f9fa;
            border-color: #3498db;
            color: #3498db;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.15);
        }
        
        .tab-btn.active {
            background: linear-gradient(135deg, #3498db, #2980b9);
            border-color: #2980b9;
            color: white;
            font-weight: 600;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }
        
        .tab-btn.active i {
            color: white;
        }
        
        /* 选项卡样式（保留用于其他地方） */
        .export-tabs {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 20px;
            background: #f8f9fa;
            border-radius: 8px 8px 0 0;
            overflow: hidden;
        }
        
        .export-content {
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .score-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .score-excellent {
            background: #d5f4e6;
            color: #27ae60;
        }

        .score-good {
            background: #d1ecf1;
            color: #3498db;
        }

        .score-warning {
            background: #fef9e7;
            color: #f39c12;
        }

        .score-danger {
            background: #fadbd8;
            color: #e74c3c;
        }

        /* 响应式设计 */
        @media (max-width: 768px) {
            .main-layout {
                padding-left: 0;
            }

            .export-cards {
                grid-template-columns: 1fr;
            }

            .filter-group {
                flex-direction: column;
            }

            .export-buttons {
                flex-direction: column;
            }

            .checkbox-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php include '../../modules/admin_sidebar.php'; ?>

    <div class="main-layout">
        <div class="container">
            <div class="header">
                <h1>数据导出</h1>
                <p>操行分管理系统 | Conduct Score System</p>
            </div>

            <!-- 导出选项卡片 -->
            <div class="export-cards">
                <!-- 合并的导出卡片 -->
                <div class="export-card combined-card">
                    <div class="card-header">
                        <div class="card-icon combined">
                            <i class="fa-solid fa-download"></i>
                        </div>
                        <div>
                            <div class="card-title">数据导出中心</div>
                            <div class="card-description">统一导出操行记录、学生分数和申诉信息</div>
                        </div>
                    </div>
                    
                    <!-- 选择导出数据 -->
                    <div class="export-selection">
                        <label class="option-label">选择导出数据</label>
                        <div class="checkbox-group">
                            <label class="checkbox-item">
                                <input type="checkbox" id="export-conduct_records" value="conduct_records" checked>
                                <span class="checkmark"></span>
                                <i class="fa-solid fa-minus-circle"></i> 操行记录
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" id="export-student_scores" value="student_scores">
                                <span class="checkmark"></span>
                                <i class="fa-solid fa-chart-line"></i> 学生分数
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" id="export-appeals" value="appeals">
                                <span class="checkmark"></span>
                                <i class="fa-solid fa-gavel"></i> 申诉信息
                            </label>
                        </div>
                    </div>
                    
                    <!-- 筛选条件 -->
                    <div class="export-options">
                        <div class="option-group">
                            <label class="option-label">筛选条件</label>
                            <div class="filter-group" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; line-height: 1;">
                                <select class="filter-select" id="timeFilter" style="width: 120px;" onchange="handleTimeFilterChange()">
                                    <option value="">全部时间</option>
                                    <option value="today">今天</option>
                                    <option value="week">本周</option>
                                    <option value="month">本月</option>
                                    <option value="custom">精确日期</option>
                                </select>
                                <div id="dateInputs" style="display: none; margin-left: 10px; display: inline-block; vertical-align: top;">
                                    <input type="text" class="filter-input" id="startDate" placeholder="开始日期" style="width: 120px; margin-right: 10px;" maxlength="10">
                                    <span class="date-separator" style="margin: 0 5px;">至</span>
                                    <input type="text" class="filter-input" id="endDate" placeholder="结束日期" style="width: 120px;" maxlength="10">
                                </div>
                            </div>
                            
                            <hr style="margin: 15px 0; border: none; border-top: 1px solid #e9ecef;">
                        </div>
                    </div>
                    
                    <!-- 导出按钮 -->
                    <div class="export-buttons">
                        <button class="export-btn btn-excel" onclick="exportCombinedData()">
                            <i class="fa-solid fa-file-excel"></i> 合并导出Excel
                        </button>
                    </div>

                </div>
            </div>


        </div>
    </div>

    <script>
        // 切换导出类型
        function switchExportType(exportType) {
            // 隐藏所有内容
            const contents = document.querySelectorAll('.export-content');
            contents.forEach(content => {
                content.style.display = 'none';
            });
            
            // 移除所有选项卡的active状态
            const tabs = document.querySelectorAll('.tab-btn');
            tabs.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // 显示选中的内容
            const selectedContent = document.getElementById(`content-${exportType}`);
            if (selectedContent) {
                selectedContent.style.display = 'block';
            }
            
            // 激活选中的选项卡
            const selectedTab = document.getElementById(`tab-${exportType}`);
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
        }
        
        // 切换选项卡
        function switchTab(tabName) {
            // 隐藏所有内容
            const contents = document.querySelectorAll('.export-content');
            contents.forEach(content => {
                content.style.display = 'none';
            });
            
            // 移除所有选项卡的active状态
            const tabs = document.querySelectorAll('.tab-btn');
            tabs.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // 显示选中的内容
            const selectedContent = document.getElementById(`content-${tabName}`);
            if (selectedContent) {
                selectedContent.style.display = 'block';
            }
            
            // 激活选中的选项卡
            const selectedTab = document.querySelector(`[onclick="switchTab('${tabName}')"]`);
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
        }
        
        // 合并导出数据
        function exportCombinedData() {
            // 获取选中的导出类型
            const selectedTypes = [];
            const checkboxes = document.querySelectorAll('.checkbox-item input[type="checkbox"]:checked');
            
            checkboxes.forEach(checkbox => {
                selectedTypes.push(checkbox.value);
            });
            
            // 检查是否至少选择了一种数据类型
            if (selectedTypes.length === 0) {
                notification.warning('请至少选择一种数据类型进行导出！', '数据导出');
                return;
            }
            
            const exportBtn = document.querySelector('[onclick="exportCombinedData()"]');
            exportBtn.disabled = true;
            exportBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 导出中...';
            
            // 显示初始提示
            notification.info('正在生成文件中，请稍后...', '数据导出');
            
            // 获取筛选条件
            const timeFilter = document.getElementById('timeFilter').value;
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            // 验证日期格式
            if (startDate && !validateDateFormat(startDate)) {
                alert('请输入正确的开始日期格式：YYYY-MM-DD');
                exportBtn.disabled = false;
                exportBtn.innerHTML = '<i class="fa-solid fa-file-excel"></i> 合并导出Excel';
                return;
            }
            if (endDate && !validateDateFormat(endDate)) {
                alert('请输入正确的结束日期格式：YYYY-MM-DD');
                exportBtn.disabled = false;
                exportBtn.innerHTML = '<i class="fa-solid fa-file-excel"></i> 合并导出Excel';
                return;
            }
            
            // 验证日期范围
            if (startDate && endDate && startDate > endDate) {
                alert('开始日期不能晚于结束日期');
                exportBtn.disabled = false;
                exportBtn.innerHTML = '<i class="fa-solid fa-file-excel"></i> 合并导出Excel';
                return;
            }
            
            // 构建导出URL
            let exportUrl = `data_export.php?export=excel&type=combined&tables=${selectedTypes.join(',')}`;
            
            // 添加时间筛选参数
            if (timeFilter && timeFilter !== 'custom') {
                exportUrl += `&time_filter=${timeFilter}`;
            }
            if (timeFilter === 'custom' && (startDate || endDate)) {
                if (startDate && endDate) {
                    exportUrl += `&start_date=${startDate}&end_date=${endDate}`;
                } else if (startDate) {
                    exportUrl += `&start_date=${startDate}`;
                } else if (endDate) {
                    exportUrl += `&end_date=${endDate}`;
                }
            }
            
            // 3秒后开始下载
            setTimeout(() => {
                // 创建隐藏的下载链接
                const link = document.createElement('a');
                link.href = exportUrl;
                link.download = '';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                // 显示下载完成提示
                notification.success('文件生成完成，已开始下载', '数据导出');
                
                // 恢复按钮状态
                exportBtn.disabled = false;
                exportBtn.innerHTML = '<i class="fa-solid fa-file-excel"></i> 合并导出Excel';
            }, 3000);
        }
        
        // 切换日期模式
        // 验证日期格式函数
        function validateDateFormat(dateString) {
            const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
            if (!dateRegex.test(dateString)) {
                return false;
            }
            const date = new Date(dateString);
            return date instanceof Date && !isNaN(date) && dateString === date.toISOString().split('T')[0];
        }

        // 处理时间筛选下拉框变化
        function handleTimeFilterChange() {
            const timeFilter = document.getElementById('timeFilter');
            const dateInputs = document.getElementById('dateInputs');
            
            if (timeFilter.value === 'custom') {
                dateInputs.style.display = 'inline-block';
            } else {
                dateInputs.style.display = 'none';
                // 清空日期输入框
                document.getElementById('startDate').value = '';
                document.getElementById('endDate').value = '';
            }
        }

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

        // 页面加载完成后绑定事件
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化日期输入框状态
            const dateInputs = document.getElementById('dateInputs');
            
            if (dateInputs) {
                // 确保页面加载时日期输入框是隐藏的
                dateInputs.style.display = 'none';
                // 清空日期输入框的值
                const startDateInput = document.getElementById('startDate');
                const endDateInput = document.getElementById('endDate');
                if (startDateInput) startDateInput.value = '';
                if (endDateInput) endDateInput.value = '';
            }
            
            // 为日期输入框绑定格式化事件
            const startDateInput = document.getElementById('startDate');
            const endDateInput = document.getElementById('endDate');
            
            if (startDateInput) {
                startDateInput.addEventListener('input', function() {
                    formatDateInput(this);
                });
                startDateInput.addEventListener('keydown', handleDateKeydown);
            }
            
            if (endDateInput) {
                endDateInput.addEventListener('input', function() {
                    formatDateInput(this);
                });
                endDateInput.addEventListener('keydown', handleDateKeydown);
            }
        });
        
        // 导出操行记录数据
         function exportDeductionData(format) {
             // 获取筛选条件
             const timeFilter = document.getElementById('deductionTime').value;
             const startDate = document.getElementById('deductionStartDate').value;
             const endDate = document.getElementById('deductionEndDate').value;
             
             // 禁用所有导出按钮
             const exportButtons = document.querySelectorAll('.export-btn');
             exportButtons.forEach(btn => {
                 btn.disabled = true;
                 btn.style.opacity = '0.6';
                 btn.style.cursor = 'not-allowed';
             });
             
             // 显示初始提示
             notification.info('正在生成文件中，请稍后...', '数据导出');
             
             // 构建导出URL
             const params = new URLSearchParams();
             params.append('export', format); // 使用传入的格式参数
             params.append('type', 'conduct_records');
             
             if (timeFilter) {
                 params.append('time_filter', timeFilter);
             }
             
             if (startDate && endDate) {
                 params.append('start_date', startDate);
                 params.append('end_date', endDate);
             }
             
             // 3秒后开始下载并恢复按钮状态
             const url = window.location.pathname + '?' + params.toString();
             setTimeout(() => {
                 window.location.href = url;
                 
                 // 显示下载完成提示
                 notification.success('文件生成完成，已开始下载', '数据导出');
                 
                 // 恢复按钮状态
                 exportButtons.forEach(btn => {
                     btn.disabled = false;
                     btn.style.opacity = '1';
                     btn.style.cursor = 'pointer';
                 });
             }, 3000);
         }
        
        // 导出学生操行分数据
         function exportConductData(format) {
             // 获取筛选条件
             const scoreFilter = document.getElementById('conductScore').value;
             
             // 禁用所有导出按钮
             const exportButtons = document.querySelectorAll('.export-btn');
             exportButtons.forEach(btn => {
                 btn.disabled = true;
                 btn.style.opacity = '0.6';
                 btn.style.cursor = 'not-allowed';
             });
             
             // 显示初始提示
             notification.info('正在生成文件中，请稍后...', '数据导出');
             
             // 构建导出URL
             const params = new URLSearchParams();
             params.append('export', format); // 使用传入的格式参数
             params.append('type', 'student_scores');
             
             if (scoreFilter) {
                 params.append('score_filter', scoreFilter);
             }
             
             // 3秒后开始下载并恢复按钮状态
             const url = window.location.pathname + '?' + params.toString();
             setTimeout(() => {
                 window.location.href = url;
                 
                 // 显示下载完成提示
                 notification.success('文件生成完成，已开始下载', '数据导出');
                 
                 // 恢复按钮状态
                 exportButtons.forEach(btn => {
                     btn.disabled = false;
                     btn.style.opacity = '1';
                     btn.style.cursor = 'pointer';
                 });
             }, 3000);
         }

        // 导出申诉信息数据
         function exportAppealsData(format) {
             // 获取筛选条件
             const statusFilter = document.getElementById('appealStatus').value;
             const timeFilter = document.getElementById('appealTime').value;
             const startDate = document.getElementById('appealStartDate').value;
             const endDate = document.getElementById('appealEndDate').value;
             
             // 验证日期范围
             if (startDate && endDate && startDate > endDate) {
                 notification.warning('开始日期不能晚于结束日期');
                 return;
             }
             
             // 禁用所有导出按钮
             const exportButtons = document.querySelectorAll('.export-btn');
             exportButtons.forEach(btn => {
                 btn.disabled = true;
                 btn.style.opacity = '0.6';
                 btn.style.cursor = 'not-allowed';
             });
             
             // 显示初始提示
             notification.info('正在生成文件中，请稍后...', '数据导出');
             
             // 构建导出URL
             const params = new URLSearchParams();
             params.append('export', format);
             params.append('type', 'appeals');
             
             if (statusFilter) {
                 params.append('status_filter', statusFilter);
             }
             
             if (timeFilter) {
                 params.append('time_filter', timeFilter);
             }
             
             if (startDate && endDate) {
                 params.append('start_date', startDate);
                 params.append('end_date', endDate);
             }
             
             // 3秒后开始下载并恢复按钮状态
             const url = window.location.pathname + '?' + params.toString();
             setTimeout(() => {
                 window.location.href = url;
                 
                 // 显示下载完成提示
                 notification.success('文件生成完成，已开始下载', '数据导出');
                 
                 // 恢复按钮状态
                 exportButtons.forEach(btn => {
                     btn.disabled = false;
                     btn.style.opacity = '1';
                     btn.style.cursor = 'pointer';
                 });
             }, 3000);
         }
         
         // 页面加载时默认显示第一个选项卡
        document.addEventListener('DOMContentLoaded', function() {
            switchExportType('conduct_records');
        });
    </script>
    
    <!-- 引入通知组件 -->
    <?php include '../../modules/notification.php'; ?>
</body>
</html>