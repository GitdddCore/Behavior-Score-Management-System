<?php
// 定义常量以允许包含的文件访问
define('INCLUDED_FROM_APP', true);

/**
 * 学生排名页面 - 显示班级操行分排名
 */

// 引入数据库类
require_once '../../functions/database.php';

// 初始化数据库连接
$db = new Database();

// 获取排名数据（参考index.php的TopRank实现）
function getRankingData() {
    global $db;
    
    try {
        // 生成缓存键
        $cacheKey = 'AllRank';
        
        // 优先查询Redis缓存
        try {
            $cachedData = getCache($cacheKey);
            if ($cachedData !== null && !empty($cachedData)) {
                return [
                    'success' => true,
                    'rankings' => $cachedData
                ];
            }
        } catch (Exception $e) {
            // Redis缓存不可用时继续执行SQL查询
        }
        
        $sql = "SELECT id, name, student_id, current_score FROM students WHERE status = 'active' ORDER BY current_score DESC, id ASC";
        
        $pdo = $db->getMysqlConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 释放数据库连接
        $db->releaseMysqlConnection($pdo);
        
        // 计算排名
        $rankings = [];
        foreach ($students as $index => $student) {
            $student['ranking'] = $index + 1;
            $rankings[] = $student;
        }
        
        // 缓存结果
        if (!empty($rankings)) {
            try {
                $cacheTime = 300; // 5分钟缓存
                setCache($cacheKey, $rankings, $cacheTime);
            } catch (Exception $e) {
                // Redis不可用时忽略缓存设置
            }
        }
        
        return [
            'success' => true,
            'rankings' => $rankings
        ];
    } catch(PDOException $e) {
        error_log("Database error in getRankingData: " . $e->getMessage());
        return ['success' => false, 'error' => '请求失败，请稍后再试'];
    }
}

// ==================== 简化的缓存管理方法 ====================

/**
 * Redis缓存设置
 */
function setCache($key, $value, $expire = 300) {
    global $db;
    try {
        $redis = $db->getRedisConnection('cache');
        if ($redis) {
            $result = $redis->setex($key, $expire, json_encode($value));
            $db->releaseRedisConnection($redis);
            return $result;
        }
    } catch (Exception $e) {
        // Redis设置缓存失败时静默处理
        if (isset($redis)) {
            $db->releaseRedisConnection($redis);
        }
    }
    return false;
}

/**
 * Redis缓存获取
 */
function getCache($key) {
    global $db;
    try {
        $redis = $db->getRedisConnection('cache');
        if ($redis) {
            $data = $redis->get($key);
            $db->releaseRedisConnection($redis);
            return $data ? json_decode($data, true) : null;
        }
    } catch (Exception $e) {
        // Redis获取缓存失败时静默处理
        if (isset($redis)) {
            $db->releaseRedisConnection($redis);
        }
    }
    return null;
}

// AJAX请求处理
if (isset($_GET['action']) && $_GET['action'] === 'refresh') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    $data = getRankingData();
    echo json_encode($data);
    exit;
}

// 初始化数据
$data = getRankingData();
if ($data['success']) {
    $rankings = $data['rankings'];
} else {
    $rankings = [];
    $error_message = $data['error'] ?? '数据获取失败';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操行分管理系统 - 排行榜</title>
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
        /* 排名表格 */
        .ranking-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            max-height: 70vh;
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
        .refresh-btn:hover, .refresh-btn.disabled, .refresh-btn.disabled:hover {
            background: #e9ecef;
        }
        .refresh-btn.disabled {
            color: #adb5bd;
            cursor: not-allowed;
        }

        .ranking-table {
            width: 100%;
            border-collapse: collapse;
        }
        .table-container {
            flex: 1;
            overflow-y: auto;
            max-height: calc(70vh - 120px);
        }
        .table-header {
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .ranking-table th, .ranking-table td {
            padding: 15px 20px;
            text-align: left;
            border-bottom: 1px solid #f1f3f4;
        }
        .ranking-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.95rem;
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .ranking-table td {
            font-size: 0.9rem;
        }
        .ranking-table tbody tr:hover {
            background: #f8f9fa;
        }
        .ranking-table a:hover {
            color: #2980b9 !important;
            text-decoration: underline !important;
        }
        .mobile-name a:hover {
            color: #2980b9 !important;
            text-decoration: underline !important;
        }
        .rank-number {
            font-weight: bold;
            font-size: 1.1rem;
        }
        .rank-1 { color: #f39c12; }
        .rank-2 { color: #95a5a6; }
        .rank-3 { color: #cd7f32; }

        .score-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 500;
            font-size: 0.85rem;
        }
        .score-excellent { background: #d4edda; color: #155724; }
        .score-good { background: #d1ecf1; color: #0c5460; }
        .score-average { background: #fff3cd; color: #856404; }
        .score-poor { background: #f8d7da; color: #721c24; }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #95a5a6;
        }
        
        /* 加载动画 */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            border-radius: 12px;
        }
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .loading-text {
            margin-left: 15px;
            color: #666;
            font-size: 14px;
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

        /* 移动端卡片样式 */
        .mobile-card {
            display: none;
            background: white;
            border-radius: 8px;
            margin-bottom: 15px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .mobile-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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
            font-weight: 600;
            color: #2c3e50;
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
            color: #7f8c8d;
            margin-bottom: 2px;
        }
        .mobile-detail-value {
            font-size: 0.9rem;
            font-weight: 500;
            color: #2c3e50;
        }

        /* 移动端适配 */
        @media (max-width: 768px) {
            .main-layout { padding-left: 0; }
            .container { padding: 15px; }
            .header { text-align: center; margin-bottom: 25px; }
            .header h1 { font-size: 1.8rem; margin-bottom: 5px; }
            .header p { font-size: 1rem; }
            .section-title { font-size: 1.1rem; padding: 20px 20px 0 20px; margin-bottom: 15px; }
            .refresh-btn { position: static; display: block; width: calc(100% - 40px); margin: 0 20px 20px 20px; text-align: center; padding: 12px; font-size: 14px; }
            .table-container { display: none; }
            .mobile-card { display: block; }
            .mobile-cards {
                max-height: calc(70vh - 120px);
                overflow-y: auto;
            }
            .empty-state { padding: 30px 20px; }
            .empty-state i { font-size: 2.5rem; }
            .empty-state p { font-size: 1rem; }
            .empty-state span { font-size: 0.85rem; }
        }
    </style>
</head>
<body>
<?php include '../../modules/student_sidebar.php'; ?>
<div class="main-layout">
    <div class="container">
        <div class="header">
            <h1>排行榜</h1>
            <p>操行分管理系统 | Behavior Score Management System</p>
        </div>
        <div class="ranking-section" style="position: relative;">
            <div class="loading-overlay" id="loadingOverlay" style="display: none;">
                <div class="loading-spinner"></div>
                <div class="loading-text">正在加载排名数据...</div>
            </div>
            <div style="position: relative;">
                <div class="section-title">
                    <i class="fa-solid fa-trophy"></i>
                    班级排名榜
                </div>
                <a href="javascript:void(0)" class="refresh-btn" onclick="refreshRanking()"><i class="fas fa-sync-alt"></i> 刷新</a>
            </div>

            <?php if (empty($rankings)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-users-slash"></i>
                <p><?php echo isset($error_message) ? $error_message : '暂无排名数据'; ?></p>
                <span>系统中还没有学生数据</span>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="ranking-table">
                    <thead class="table-header">
                        <tr>
                            <th>排名</th>
                            <th>姓名</th>
                            <th>学号</th>
                            <th>操行分</th>
                            <th>等级</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rankings as $student): ?>
                        <tr>
                            <td>
                                <span class="rank-number <?php 
                                    if ($student['ranking'] == 1) echo 'rank-1';
                                    elseif ($student['ranking'] == 2) echo 'rank-2';
                                    elseif ($student['ranking'] == 3) echo 'rank-3';
                                ?>">
                                    <?php if ($student['ranking'] <= 3): ?><i class="fa-solid fa-medal"></i><?php endif; ?>
                                    <?php echo $student['ranking']; ?>
                                </span>
                            </td>
                            <td><a href="profile.php?student_id=<?php echo urlencode($student['student_id']); ?>" style="color: #3498db; text-decoration: none; font-weight: 500;"><?php echo htmlspecialchars($student['name']); ?></a></td>
                            <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                            <td><?php echo $student['current_score']; ?>分</td>
                            <td>
                                <?php 
                                $score = $student['current_score'];
                                if ($score >= 90) echo '<span class="score-badge score-excellent">优秀</span>';
                                elseif ($score >= 70) echo '<span class="score-badge score-good">良好</span>';
                                elseif ($score >= 60) echo '<span class="score-badge score-average">及格</span>';
                                else echo '<span class="score-badge score-poor">不及格</span>';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mobile-cards">
                <?php foreach ($rankings as $student): ?>
                <div class="mobile-card" onclick="window.location.href='profile.php?student_id=<?php echo urlencode($student['student_id']); ?>'">
                    <div class="mobile-card-header">
                        <div class="mobile-rank <?php 
                            if ($student['ranking'] == 1) echo 'rank-1';
                            elseif ($student['ranking'] == 2) echo 'rank-2';
                            elseif ($student['ranking'] == 3) echo 'rank-3';
                        ?>">
                            <?php if ($student['ranking'] <= 3): ?><i class="fa-solid fa-medal"></i><?php endif; ?>
                            #<?php echo $student['ranking']; ?>
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
                                if ($score >= 90) echo '<span class="score-badge score-excellent">优秀</span>';
                                elseif ($score >= 70) echo '<span class="score-badge score-good">良好</span>';
                                elseif ($score >= 60) echo '<span class="score-badge score-average">及格</span>';
                                else echo '<span class="score-badge score-poor">不及格</span>';
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
let isRefreshing = false;
function refreshRanking() {
    if (isRefreshing) return;
    isRefreshing = true;
    
    // 显示加载动画
    $('#loadingOverlay').show();
    
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
        url: 'rank.php?action=refresh',
        type: 'GET',
        dataType: 'json',
        success: function(data) {
            // 隐藏加载动画
            $('#loadingOverlay').hide();
            
            if (data && data.success) {
                updateRankingTable(data.rankings);
                notification.success('排名数据刷新成功');
            } else {
                notification.error(data && data.error ? data.error : '刷新失败，请稍后再试');
            }
        },
        error: function(xhr, status, error) {
            // 隐藏加载动画
            $('#loadingOverlay').hide();
            notification.error('网络请求失败，请检查连接');
        }
    });
}

function updateRankingTable(rankings) {
    const tbody = $('.ranking-table tbody');
    const mobileCards = $('.mobile-cards');
    if (rankings.length === 0) {
        $('.ranking-section').html(`
            <div class="loading-overlay" id="loadingOverlay" style="display: none;">
                <div class="loading-spinner"></div>
                <div class="loading-text">正在加载排名数据...</div>
            </div>
            <div style="position: relative;">
                <div class="section-title">
                    <i class="fa-solid fa-trophy"></i>
                    班级排名榜
                </div>
                <a href="javascript:void(0)" class="refresh-btn" onclick="refreshRanking()"><i class="fas fa-sync-alt"></i> 刷新</a>
            </div>
            <div class="empty-state">
                <i class="fa-solid fa-users-slash"></i>
                <p>暂无排名数据</p>
                <span>系统中还没有学生数据</span>
            </div>
        `);
        return;
    }
    let tableHtml = '';
    rankings.forEach(student => {
        let rankClass = '', medalIcon = '';
        if (student.ranking == 1) { rankClass = 'rank-1'; medalIcon = '<i class="fa-solid fa-medal"></i> '; }
        else if (student.ranking == 2) { rankClass = 'rank-2'; medalIcon = '<i class="fa-solid fa-medal"></i> '; }
        else if (student.ranking == 3) { rankClass = 'rank-3'; medalIcon = '<i class="fa-solid fa-medal"></i> '; }
        let scoreBadge = '';
        if (student.current_score >= 90) scoreBadge = '<span class="score-badge score-excellent">优秀</span>';
        else if (student.current_score >= 70) scoreBadge = '<span class="score-badge score-good">良好</span>';
        else if (student.current_score >= 60) scoreBadge = '<span class="score-badge score-average">及格</span>';
        else scoreBadge = '<span class="score-badge score-poor">不及格</span>';
        tableHtml += `
            <tr>
                <td><span class="rank-number ${rankClass}">${medalIcon}${student.ranking}</span></td>
                <td><a href="profile.php?student_id=${encodeURIComponent(student.student_id)}" style="color: #3498db; text-decoration: none; font-weight: 500;">${student.name}</a></td>
                <td>${student.student_id}</td>
                <td>${student.current_score}分</td>
                <td>${scoreBadge}</td>
            </tr>
        `;
    });
    tbody.html(tableHtml);
    let cardHtml = '';
    rankings.forEach(student => {
        let rankClass = '', medalIcon = '';
        if (student.ranking == 1) { rankClass = 'rank-1'; medalIcon = '<i class="fa-solid fa-medal"></i> '; }
        else if (student.ranking == 2) { rankClass = 'rank-2'; medalIcon = '<i class="fa-solid fa-medal"></i> '; }
        else if (student.ranking == 3) { rankClass = 'rank-3'; medalIcon = '<i class="fa-solid fa-medal"></i> '; }
        let scoreBadge = '';
        if (student.current_score >= 90) scoreBadge = '<span class="score-badge score-excellent">优秀</span>';
        else if (student.current_score >= 70) scoreBadge = '<span class="score-badge score-good">良好</span>';
        else if (student.current_score >= 60) scoreBadge = '<span class="score-badge score-average">及格</span>';
        else scoreBadge = '<span class="score-badge score-poor">不及格</span>';
        
        cardHtml += `
            <div class="mobile-card" onclick="window.location.href='profile.php?student_id=${encodeURIComponent(student.student_id)}'">
                <div class="mobile-card-header">
                    <div class="mobile-rank ${rankClass}">
                        ${medalIcon}#${student.ranking}
                    </div>
                    <div class="mobile-name">${student.name}</div>
                </div>
                <div class="mobile-details">
                    <div class="mobile-detail-item">
                        <div class="mobile-detail-label">学号</div>
                        <div class="mobile-detail-value">${student.student_id}</div>
                    </div>
                    <div class="mobile-detail-item">
                        <div class="mobile-detail-label">操行分</div>
                        <div class="mobile-detail-value">${student.current_score}分</div>
                    </div>
                    <div class="mobile-detail-item">
                        <div class="mobile-detail-label">等级</div>
                        <div class="mobile-detail-value">${scoreBadge}</div>
                    </div>
                </div>
            </div>
        `;
    });
    mobileCards.html(cardHtml);
}
</script>
</body>
</html>