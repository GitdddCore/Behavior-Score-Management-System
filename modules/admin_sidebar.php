<?php
// 定义常量以允许访问（如果尚未定义）
if (!defined('INCLUDED_FROM_APP')) {
    define('INCLUDED_FROM_APP', true);
}

// 防止直接访问
if (!defined('INCLUDED_FROM_APP') && basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Access Denied');
}

// 管理员侧边栏组件

// 引入数据库类
require_once __DIR__ . '/../functions/database.php';

// 处理AJAX登出请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    try {
        session_start();
        
        // 在清空Session前保存user_id用于Redis清理
        $userId = $_SESSION['user_id'] ?? null;
        $rememberToken = $_COOKIE['remember_token'] ?? null;
        
        // 清理Redis Session（在清空Session之前）
        if ($userId) {
            try {
                $database = new Database();
                $redis = $database->getRedisConnection('session');
                if ($redis) {
                    // 删除当前用户的Session数据
                    $sessionKey = 'user_session:' . $userId;
                    $redis->del($sessionKey);
                    
                    // 如果有记住我的token，也删除相关的Redis记录
                    if ($rememberToken) {
                        $tokenKey = 'remember_token:' . $rememberToken;
                        $redis->del($tokenKey);
                    }
                    
                    $database->releaseRedisConnection($redis);
                }
            } catch (Exception $sessionError) {
                // Redis Session清理失败不影响登出流程，只记录错误
            }
        }
        
        // 清空Session数据
        $_SESSION = array();
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // 删除cookie
        setcookie('remember_token', '', time() - 3600, '/', '', false, false);
        
        session_destroy();    
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => '登出成功']);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '登出失败']);
        exit;
    }
}

// 从URL路径中获取当前页面
$request_uri = $_SERVER['REQUEST_URI'];
$path_parts = explode('/', trim($request_uri, '/'));
$current_file = end($path_parts);

// 提取页面名称（去掉.php后缀）
if (strpos($current_file, '.php') !== false) {
    $current_page = str_replace('.php', '', $current_file);
} else {
    // 如果没有.php文件，默认为dashboard
    $current_page = 'dashboard';
}

// 检查导航项是否为当前页面
function isActivePage($page, $current_page) {
    return $page === $current_page ? 'active' : '';
}
?>

<!-- Font Awesome CDN with SRI -->
<!-- 引入Font Awesome图标库，使用SRI确保资源完整性 -->
<link rel="stylesheet" 
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" 
      integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" 
      crossorigin="anonymous" 
      referrerpolicy="no-referrer" />
<style>
/* CSS自定义属性 - 颜色主题 */
:root {
    --primary-color: #2c3e50;
    --secondary-color: #34495e;
    --accent-color: #3498db;
    --success-color: #27ae60;
    --danger-color: #e74c3c;
    --text-light: #ecf0f1;
    --text-muted: #bdc3c7;
    --shadow-color: rgba(0, 0, 0, 0.1);
    --overlay-color: rgba(0, 0, 0, 0.5);
    --transition-time: 0.3s;
}

/* 移动端汉堡菜单按钮 */
.mobile-menu-toggle {
    display: none;
    position: fixed;
    top: 15px;
    left: 15px;
    z-index: 1001;
    background: var(--primary-color);
    color: var(--text-light);
    border: none;
    padding: 10px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 18px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.3);
    transition: all var(--transition-time) ease;
}

.mobile-menu-toggle:hover {
    background: var(--secondary-color);
}

/* 汉堡菜单隐藏状态 */
.mobile-menu-toggle.hidden {
    opacity: 0;
    pointer-events: none;
    transform: translateX(-100%);
}

/* 关闭按钮样式 */
.sidebar-close {
    display: none;
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 18px;
    cursor: pointer;
    padding: 8px;
    border-radius: 3px;
    transition: all var(--transition-time) ease;
    margin-left: auto;
}

.sidebar-close:hover {
    color: var(--danger-color);
    background: rgba(231, 76, 60, 0.1);
}

/* 移动端遮罩层 */
.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: var(--overlay-color);
    z-index: 1001;
    opacity: 0;
    pointer-events: none;
    touch-action: none;
    cursor: pointer;
    transition: opacity var(--transition-time) ease;
}

.sidebar-overlay.active {
    opacity: 1;
    pointer-events: auto; /* 激活时才接收点击事件 */
}

/* 侧边栏主容器样式 */
.sidebar {
    width: 200px;           /* 固定宽度200px */
    height: 100vh;          /* 全屏高度 */
    background: var(--primary-color);    /* 深蓝灰色背景 */
    color: var(--text-light);         /* 浅色文字 */
    position: fixed;        /* 固定定位 */
    left: 0;
    top: 0;
    overflow-y: auto;       /* 内容溢出时显示滚动条 */
    box-shadow: 2px 0 10px var(--shadow-color); /* 右侧阴影效果 */
    z-index: 1002;          /* 确保在其他元素之上 */
    transition: all var(--transition-time) ease; /* 平滑过渡动画 */
}

/* 侧边栏头部样式 */
.sidebar-header {
    padding: 20px;
    border-bottom: 1px solid var(--secondary-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* 侧边栏标题样式 */
.sidebar-title {
    display: flex; 
    align-items: center; 
    gap: 10px;
    font-size: 16px; 
    font-weight: 600;
}

/* 盾牌图标特殊颜色 */
.sidebar-title .fa-user-shield { 
    font-size: 20px; 
    color: var(--danger-color); /* 红色强调 */
}

/* 导航区域容器 */
.sidebar-nav { 
    padding: 10px 0; 
}

/* 导航项基础样式 */
.nav-item {
    display: flex;
    align-items: center;
    width: 100%;
    height: 48px;
    padding: 0 20px;
    cursor: pointer;
    transition: all var(--transition-time) ease;
    border-left: 3px solid transparent;
    color: var(--text-light);
    text-decoration: none;
    box-sizing: border-box;
}

/* 导航项悬停效果 */
.nav-item:hover {
    background: var(--secondary-color);
    border-left-color: var(--accent-color);
    color: var(--text-light);
    text-decoration: none;
}

/* 当前激活的导航项 */
.nav-item.active {
    background: var(--secondary-color);
    border-left-color: var(--success-color);
    text-decoration: none;
}

/* 激活状态的图标颜色 */
.nav-item.active .nav-icon {
    color: var(--success-color);
}

/* 导航图标样式 */
.nav-icon {
    width: 20px;
    margin-right: 12px;
    font-size: 16px;
    color: var(--text-muted);
    transition: color var(--transition-time) ease;
    text-align: center;
}

/* 导航文字样式 */
.nav-text {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-light);
}

/* 悬停时图标和文字颜色变化 */
.nav-item:hover .nav-icon,
.nav-item:hover .nav-text {
    color: var(--accent-color);
}

/* 移动端响应式设计 */
@media (max-width: 768px) {
    /* 隐藏的管理功能 */
    .nav-item[data-page="students_manager"],
    .nav-item[data-page="committee_manager"],
    .nav-item[data-page="rules_manager"],
    .nav-item[data-page="data_export"] {
        display: none;
    }
    
    /* 显示汉堡菜单按钮 */
    .mobile-menu-toggle {
        display: block;
    }
    
    .sidebar {
        width: min(50vw, 280px); /* 限制移动端侧边栏最多占屏幕一半 */
        max-width: 50vw;
        transform: translateX(-100%);
        transition: transform var(--transition-time) ease;
        touch-action: pan-y;
        z-index: 1002;
    }
    
    .sidebar.mobile-open {
        transform: translateX(0);
    }
    
    .sidebar.mobile-open .sidebar-close {
        display: block;
    }
    
    /* 显示移动端遮罩层 */
    .sidebar-overlay {
        display: block;
    }
}

/* 桌面端确保遮罩层不干扰 */
@media (min-width: 769px) {
    .sidebar-overlay {
        display: none !important;
        pointer-events: none !important;
    }
    
    .mobile-menu-toggle {
        display: none !important;
    }
    
    .sidebar-close {
        display: none !important;
    }
    
    /* 桌面端导航项固定尺寸 */
    .nav-item {
        height: 56px;
        padding: 0 20px;
    }
    
    /* 增大图标和文字 */
    .nav-icon {
        font-size: 18px;
        width: 24px;
        margin-right: 15px;
    }
    
    .nav-text {
        font-size: 16px;
    }
    
    /* 侧边栏头部在桌面端的调整 */
    .sidebar-header {
        padding: 25px 20px;
    }
    
    .sidebar-title {
        font-size: 18px;
    }
    
    .sidebar-title .fa-user-shield {
        font-size: 22px;
    }
}

/* 超小屏幕优化 */
@media (max-width: 480px) {
    .sidebar {
        width: min(50vw, 240px); /* 小屏幕也限制为屏幕一半 */
        max-width: 50vw;
    }
    
    .mobile-menu-toggle {
        font-size: 18px;
    }
    
    .nav-item {
        height: 60px;
        padding: 0 20px;
    }
    
    .nav-icon {
        font-size: 20px;
        width: 26px;
    }
    
    .nav-text {
        font-size: 17px;
    }
}

/* 登出按钮样式 */
.logout-btn {
    cursor: pointer;
}

/* 触摸设备优化 */
@media (hover: none) and (pointer: coarse) {
    .nav-item:hover {
        background: inherit;
        border-left-color: transparent;
    }
    
    .nav-item:hover .nav-icon,
    .nav-item:hover .nav-text {
        color: inherit;
    }
    
    /* 触摸时的反馈效果 */
    .nav-item:active {
        background: var(--secondary-color);
        border-left-color: var(--accent-color);
    }
    
    .nav-item:active .nav-icon,
    .nav-item:active .nav-text {
        color: var(--accent-color);
    }
}
</style>

<!-- 移动端汉堡菜单按钮 -->
<button class="mobile-menu-toggle" id="mobileMenuToggle" onclick="toggleMobileMenu()" aria-label="打开菜单">
    <i class="fa-solid fa-bars"></i>
</button>

<!-- 移动端遮罩层 -->
<div class="sidebar-overlay" id="mobileOverlay"></div>

<!-- 侧边栏主容器 -->
<div class="sidebar" id="sidebar">
    <!-- 侧边栏头部：显示用户信息和图标 -->
    <div class="sidebar-header">
        <div class="sidebar-title">
            <i class="fa-solid fa-user-shield"></i>
            <span>管理员页面</span>
        </div>
        <!-- 移动端关闭按钮 -->
        <button class="sidebar-close" onclick="closeMobileMenu()" aria-label="关闭侧边栏">
            <i class="fa-solid fa-times"></i>
        </button>
    </div>
    
    <!-- 导航菜单区域 -->
    <div class="sidebar-nav">
        <!-- 首页导航 -->
        <a href="dashboard.php" class="nav-item <?php echo isActivePage('dashboard', $current_page); ?>" data-page="dashboard">
            <div class="nav-icon"><i class="fa-solid fa-house"></i></div>
            <div class="nav-text">首页</div>
        </a>
        
        <!-- 学生管理导航 -->
        <a href="students_manager.php" class="nav-item <?php echo isActivePage('students_manager', $current_page); ?>" data-page="students_manager">
            <div class="nav-icon"><i class="fa-solid fa-users"></i></div>
            <div class="nav-text">学生管理</div>
        </a>
        
        <!-- 班级管理人管理导航 -->
        <a href="committee_manager.php" class="nav-item <?php echo isActivePage('committee_manager', $current_page); ?>" data-page="committee_manager">
            <div class="nav-icon"><i class="fa-solid fa-user-tie"></i></div>
            <div class="nav-text">班级管理人管理</div>
        </a>
        
        <!-- 规则管理导航 -->
        <a href="rules_manager.php" class="nav-item <?php echo isActivePage('rules_manager', $current_page); ?>" data-page="rules_manager">
            <div class="nav-icon"><i class="fa-solid fa-gavel"></i></div>
            <div class="nav-text">规则管理</div>
        </a>
        
        <!-- 操行分管理导航 -->
        <a href="conduct_manager.php" class="nav-item <?php echo isActivePage('conduct_manager', $current_page); ?>" data-page="conduct_manager">
            <div class="nav-icon"><i class="fa-solid fa-star"></i></div>
            <div class="nav-text">操行分管理</div>
        </a>
        
        <!-- 申诉管理导航 -->
        <a href="appeals_manager.php" class="nav-item <?php echo isActivePage('appeals_manager', $current_page); ?>" data-page="appeals_manager">
            <div class="nav-icon"><i class="fa-solid fa-comments"></i></div>
            <div class="nav-text">申诉管理</div>
        </a>
        
        <!-- 数据导出导航 -->
        <a href="data_export.php" class="nav-item <?php echo isActivePage('data_export', $current_page); ?>" data-page="data_export">
            <div class="nav-icon"><i class="fa-solid fa-download"></i></div>
            <div class="nav-text">数据导出</div>
        </a>
        
        <!-- 登出按钮 -->
        <div class="nav-item logout-btn" data-page="logout" id="logoutBtn" onclick="handleLogout()">
            <div class="nav-icon" id="logoutIcon"><i class="fa-solid fa-sign-out-alt"></i></div>
            <div class="nav-text" id="logoutText">退出登录</div>
        </div>
    </div>
</div>

<script>
// 移动端菜单控制函数
function toggleMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const menuToggle = document.querySelector('.mobile-menu-toggle');
    
    sidebar.classList.toggle('mobile-open');
    overlay.classList.toggle('active');
    menuToggle.classList.toggle('hidden');
}

function closeMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const menuToggle = document.querySelector('.mobile-menu-toggle');
    
    sidebar.classList.remove('mobile-open');
    overlay.classList.remove('active');
    menuToggle.classList.remove('hidden');
}

// 滑动手势支持
function initSwipeGesture() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    let startX = 0;
    let currentX = 0;
    let isDragging = false;
    
    // 只在侧边栏上监听触摸事件，避免干扰遮罩层
    sidebar.addEventListener('touchstart', function(e) {
        if (sidebar.classList.contains('mobile-open')) {
            startX = e.touches[0].clientX;
            isDragging = true;
        }
    }, { passive: true });
    
    sidebar.addEventListener('touchmove', function(e) {
        if (!isDragging || !sidebar.classList.contains('mobile-open')) return;
        currentX = e.touches[0].clientX;
        const diffX = startX - currentX;
        
        // 只允许向左滑动关闭（从右向左滑动）
        if (diffX > 0 && diffX < 200) {
            sidebar.style.transform = `translateX(-${Math.max(0, diffX)}px)`;
        }
    }, { passive: true });
    
    sidebar.addEventListener('touchend', function(e) {
        if (!isDragging || !sidebar.classList.contains('mobile-open')) return;
        isDragging = false;
        
        const diffX = startX - currentX;
        sidebar.style.transform = '';
        
        // 如果滑动距离超过50px，关闭菜单
        if (diffX > 50) {
            closeMobileMenu();
        }
    }, { passive: true });
    
    // 确保遮罩层点击事件正常工作
    overlay.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        closeMobileMenu();
    });
    
    // 防止遮罩层上的触摸事件穿透
    overlay.addEventListener('touchstart', function(e) {
        e.preventDefault();
        e.stopPropagation();
    }, { passive: false });
    
    overlay.addEventListener('touchend', function(e) {
        e.preventDefault();
        e.stopPropagation();
        closeMobileMenu();
    }, { passive: false });
}

// DOM加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    // 导航项点击后关闭侧边栏（移动端）
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                closeMobileMenu();
            }
        });
    });
    
    // 键盘ESC键关闭
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMobileMenu();
        }
    });
    
    // 初始化滑动手势
    initSwipeGesture();
    
    // 窗口大小改变时的处理
    window.addEventListener('resize', function() {
        const hamburger = document.querySelector('.mobile-menu-toggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        
        if (window.innerWidth > 768) {
            // 桌面端：清理移动端状态
            if (hamburger) {
                hamburger.style.display = '';
                hamburger.classList.remove('hidden');
            }
            if (sidebar) {
                sidebar.classList.remove('mobile-open');
                sidebar.style.transform = '';
            }
            if (overlay) {
                overlay.classList.remove('active');
            }
        } else {
            // 移动端：清理桌面端内联样式，恢复CSS控制
            if (hamburger) {
                hamburger.style.display = '';
            }
            if (sidebar) {
                sidebar.style.transform = '';
                // 确保侧边栏在移动端默认隐藏
                if (!sidebar.classList.contains('mobile-open')) {
                    // 让CSS类来控制，不使用内联样式
                }
            }
        }
    });
});

// 设置登出按钮loading状态
function setLogoutLoading(isLoading) {
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutIcon = document.getElementById('logoutIcon');
    const logoutText = document.getElementById('logoutText');
    
    if (isLoading) {
        logoutBtn.style.pointerEvents = 'none';
        logoutBtn.style.opacity = '0.7';
        logoutIcon.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        logoutText.textContent = '退出中...';
    } else {
        logoutBtn.style.pointerEvents = '';
        logoutBtn.style.opacity = '';
        logoutIcon.innerHTML = '<i class="fa-solid fa-sign-out-alt"></i>';
        logoutText.textContent = '退出登录';
    }
}

// 处理登出功能
function handleLogout() {
    // 使用notification.php的确认对话框
    if (typeof window.notification !== 'undefined') {
        window.notification.confirm(
            '确定要退出登录吗？',
            '确认退出',
            {
                confirmText: '确定退出',
                cancelText: '取消',
                onConfirm: function() {
                    // 执行登出操作
                    performLogout();
                },
                onCancel: function() {
                    // 用户取消，不执行任何操作
                }
            }
        );
    } else {
        // 如果notification组件未加载，使用原生确认框
        if (confirm('确定要退出登录吗？')) {
            performLogout();
        }
    }
}

// 执行登出操作
function performLogout() {
    // 设置loading状态
    setLogoutLoading(true);
    
    const xhr = new XMLHttpRequest();
    const currentPath = window.location.pathname;
    
    // 根据当前页面位置确定请求URL
    let requestUrl = 'modules/admin_sidebar.php';
    if (currentPath.includes('/modules/')) {
        requestUrl = 'admin_sidebar.php';
    } else if (currentPath.includes('/page/admin/')) {
        requestUrl = '../../modules/admin_sidebar.php';
    } else if (currentPath.includes('/admin/')) {
        requestUrl = '../modules/admin_sidebar.php';
    }
    
    xhr.open('POST', requestUrl, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.status === 'success') {
                        // 根据当前路径确定登录页面URL
                        let loginUrl = 'index.php';
                        if (currentPath.includes('/page/admin/')) {
                            loginUrl = '../../index.php';
                        } else if (currentPath.includes('/admin/')) {
                            loginUrl = '../index.php';
                        }
                        window.location.href = loginUrl;
                    } else {
                        throw new Error(response.message || '登出失败');
                    }
                } catch (e) {
                    setLogoutLoading(false);
                    const errorMsg = '登出失败，请重试';
                    if (typeof window.notification !== 'undefined') {
                        window.notification.error(errorMsg);
                    } else {
                        alert(errorMsg);
                    }
                }
            } else {
                setLogoutLoading(false);
                const errorMsg = '登出失败，请重试';
                if (typeof window.notification !== 'undefined') {
                    window.notification.error(errorMsg);
                } else {
                    alert(errorMsg);
                }
            }
        }
    };
    
    xhr.onerror = function() {
        setLogoutLoading(false);
        const errorMsg = '网络请求失败，请检查网络连接';
        if (typeof window.notification !== 'undefined') {
            window.notification.error(errorMsg);
        } else {
            alert(errorMsg);
        }
    };
    
    xhr.send('action=logout');
}
</script>
