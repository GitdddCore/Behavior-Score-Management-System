<?php
// 防止直接访问
if (!defined('INCLUDED_FROM_APP') && basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    exit();
}

// 学生侧边栏组件
?>

<!-- Font Awesome CDN -->
<link rel="stylesheet" 
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" 
      integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" 
      crossorigin="anonymous" 
      referrerpolicy="no-referrer" />

<style>
/* CSS自定义属性 - 颜色主题 */
:root {
    --sidebar-bg: #2c3e50;
    --sidebar-text: #ecf0f1;
    --sidebar-border: #34495e;
    --sidebar-hover-bg: #34495e;
    --accent-color: #3498db;
    --secondary-text: #bdc3c7;
    --danger-color: #e74c3c;
    --overlay-bg: rgba(0,0,0,0.5);
    --shadow-color: rgba(0,0,0,0.1);
    --danger-bg: rgba(231, 76, 60, 0.1);
    --mobile-shadow: rgba(0,0,0,0.3);
}

/* 侧边栏主容器样式 */
.sidebar {
    width: 200px;
    height: 100vh;
    background: var(--sidebar-bg);
    color: var(--sidebar-text);
    position: fixed;
    left: 0;
    top: 0;
    overflow-y: auto;
    box-shadow: 2px 0 10px var(--shadow-color);
    z-index: 1000;
}

/* 侧边栏头部样式 */
.sidebar-header {
    padding: 20px;
    border-bottom: 1px solid var(--sidebar-border);
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

/* 关闭按钮样式 */
.sidebar-close {
    display: none;
    background: none;
    border: none;
    color: var(--secondary-text);
    font-size: 18px;
    cursor: pointer;
    padding: 5px;
    border-radius: 3px;
    transition: all 0.3s ease;
}

.sidebar-close:hover {
    color: var(--danger-color);
    background: var(--danger-bg);
}

/* 学生图标特殊颜色 */
.sidebar-title .fa-user-graduate {
    font-size: 20px;
    color: var(--accent-color);
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
    transition: all 0.3s ease;
    border-left: 3px solid transparent;
    color: var(--sidebar-text);
    text-decoration: none;
    box-sizing: border-box;
}

/* 导航项悬停效果 */
.nav-item:hover {
    background: var(--sidebar-hover-bg);
    border-left-color: var(--accent-color);
}

/* 导航图标样式 */
.nav-icon {
    width: 20px;
    margin-right: 12px;
    font-size: 16px;
    color: var(--secondary-text);
    transition: color 0.3s ease;
    text-align: center;
}

/* 导航文字样式 */
.nav-text {
    font-size: 14px;
    font-weight: 500;
    color: var(--sidebar-text);
}

/* 悬停时图标和文字颜色变化 */
.nav-item:hover .nav-icon,
.nav-item:hover .nav-text {
    color: var(--accent-color);
}

/* 移动端汉堡菜单按钮 */
.mobile-menu-toggle {
    display: none;
    position: fixed;
    top: 15px;
    left: 15px;
    z-index: 1001;
    background: var(--sidebar-bg);
    color: var(--sidebar-text);
    border: none;
    padding: 10px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 18px;
    box-shadow: 0 2px 10px var(--mobile-shadow);
    transition: all 0.3s ease;
}

.mobile-menu-toggle:hover {
    background: var(--sidebar-hover-bg);
}

/* 汉堡菜单隐藏状态 */
.mobile-menu-toggle.hidden {
    opacity: 0;
    pointer-events: none;
    transform: translateX(-100%);
}

/* 平板端响应式样式 */
@media (max-width: 1024px) and (min-width: 769px) {
    .sidebar {
        width: 180px;
    }
    
    .sidebar-title {
        font-size: 15px;
    }
    
    .nav-text {
        font-size: 13px;
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
    
    .sidebar-close-btn {
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
}

/* 移动端响应式样式 */
@media (max-width: 768px) {
    .mobile-menu-toggle {
        display: block;
    }
    
    .sidebar {
        width: min(280px, 50vw); /* 最多占屏幕一半 */
        max-width: 50vw;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
        touch-action: pan-y;
        z-index: 1002;
    }
    
    .sidebar.mobile-open {
        transform: translateX(0);
    }
    
    .sidebar.mobile-open .sidebar-close {
        display: block;
    }
    
    /* 移动端遮罩层 */
    .mobile-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: var(--overlay-bg);
        z-index: 1001;
        pointer-events: none;
        touch-action: none;
        cursor: pointer;
    }
    
    .mobile-overlay.active {
        display: block;
        pointer-events: auto;
    }
}

/* 超小屏幕优化 */
@media (max-width: 480px) {
    .sidebar {
        width: min(260px, 50vw); /* 小屏幕上稍微减小宽度但仍限制为一半 */
        max-width: 50vw;
    }
    
    .mobile-menu-toggle {
        font-size: 18px;
    }
    
    .nav-item {
        height: 60px;
        padding: 0 15px; /* 减小内边距以适应更窄的侧边栏 */
    }
    
    .nav-icon {
        font-size: 18px;
        width: 24px;
        margin-right: 10px; /* 减小图标间距 */
    }
    
    .nav-text {
        font-size: 15px; /* 稍微减小字体 */
    }
    
    .sidebar-header {
        padding: 15px; /* 减小头部内边距 */
    }
    
    .sidebar-title {
        font-size: 14px; /* 减小标题字体 */
    }
}
</style>

<!-- 移动端汉堡菜单按钮 -->
<button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
    <i class="fa-solid fa-bars"></i>
</button>

<!-- 移动端遮罩层 -->
<div class="mobile-overlay"></div>

<!-- 侧边栏主容器 -->
<div class="sidebar" id="sidebar">
    <!-- 侧边栏头部：显示用户信息和图标 -->
    <div class="sidebar-header">
        <div class="sidebar-title">
            <i class="fa-solid fa-user-graduate"></i>
            <span>学生页面</span>
        </div>
        <button class="sidebar-close" onclick="closeMobileMenu()">
            <i class="fa-solid fa-times"></i>
        </button>
    </div>
    
    <!-- 导航菜单区域 -->
    <div class="sidebar-nav">
        <!-- 首页导航 -->
        <div class="nav-item" onclick="window.location.href='/index.php'">
            <div class="nav-icon"><i class="fa-solid fa-house"></i></div>
            <div class="nav-text">首页</div>
        </div>

        <!-- 排行榜 -->
        <div class="nav-item" onclick="window.location.href='/page/student/rank.php'">
            <div class="nav-icon"><i class="fa-solid fa-ranking-star"></i></div>
            <div class="nav-text">排行榜</div>
        </div>
        
        <!-- 登录按钮 -->
        <div class="nav-item" onclick="window.location.href='/login.php'">
            <div class="nav-icon"><i class="fa-solid fa-sign-in-alt"></i></div>
            <div class="nav-text">登录</div>
        </div>
    </div>
</div>

<script>

// 移动端菜单控制函数
function toggleMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.mobile-overlay');
    const menuToggle = document.querySelector('.mobile-menu-toggle');
    
    sidebar.classList.toggle('mobile-open');
    overlay.classList.toggle('active');
    menuToggle.classList.toggle('hidden');
}

function closeMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.mobile-overlay');
    const menuToggle = document.querySelector('.mobile-menu-toggle');
    
    sidebar.classList.remove('mobile-open');
    overlay.classList.remove('active');
    menuToggle.classList.remove('hidden');
}



// 滑动手势支持
function initSwipeGesture() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.mobile-overlay');
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

// 点击导航项后自动关闭移动端菜单
document.addEventListener('DOMContentLoaded', function() {
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                closeMobileMenu();
            }
        });
    });
    
    // 初始化功能
    initSwipeGesture();
});
</script>
