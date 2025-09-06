<?php
// 定义应用程序常量，允许包含的文件正常访问
define('INCLUDED_FROM_APP', true);

/**
 * 操行分管理系统 - 登录页面
 * 处理用户登录验证和页面显示
 */
session_start();

// 引入数据库连接类
require_once __DIR__ . '/functions/database.php';

// 检查记住我功能 - 自动登录
if (!isset($_SESSION['logged_in']) && isset($_COOKIE['remember_token'])) {
    $remember_token = $_COOKIE['remember_token'];
    
    // 使用Database类的连接池管理Redis连接
    $db = new Database();
    try {
        $redis = $db->getRedisConnection('session');
        if ($redis) {
            $token_data = $redis->get('remember_token:' . $remember_token);
            if ($token_data) {
                $user_info = json_decode($token_data, true);
                if ($user_info && $user_info['expire_time'] > time()) {
                    // Token有效，自动登录
                    $_SESSION['username'] = $user_info['username'];
                    $_SESSION['user_type'] = $user_info['user_type'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time();
                    
                    if ($user_info['user_type'] === 'admin') {
                        $_SESSION['student_id'] = $user_info['username'];
                        header('Location: page/admin/dashboard.php');
                    } else {
                        $_SESSION['student_id'] = $user_info['username'];
                        header('Location: page/committee/dashboard.php');
                    }
                    $db->releaseRedisConnection($redis);
                    exit;
                } else {
                    // Token过期，删除cookie和Redis中的token
                    setcookie('remember_token', '', time() - 3600, '/', '', false, false);
                    $redis->del('remember_token:' . $remember_token);
                }
            } else {
                // Token不存在，删除cookie
                setcookie('remember_token', '', time() - 3600, '/', '', false, false);
            }
            $db->releaseRedisConnection($redis);
        }
    } catch (Exception $e) {
        // Redis连接失败时忽略自动登录
        error_log("Redis连接失败: " . $e->getMessage());
    }
}

// 处理AJAX登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    header('Content-Type: application/json');
    
    // 获取并清理用户输入
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'auto');
    
    // 验证输入不能为空
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => '用户名和密码不能为空']);
        exit;
    }
    
    try {
        // 加载数据库配置文件
        $configPath = 'config/config.json';
        if (!file_exists($configPath)) {
            echo json_encode(['success' => false, 'message' => '系统配置异常']);
            exit;
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        if (!$config || !isset($config['database']['mysql'])) {
            echo json_encode(['success' => false, 'message' => '系统配置异常']);
            exit;
        }
        
        // 使用Database类的连接池管理
        $db = new Database();
        
        // 检查登录失败次数限制
        try {
            $redis = $db->getRedisConnection('login_security');
            if ($redis) {
                $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $attempt_key = 'login_attempts:' . $client_ip;
                $failed_attempts = (int)$redis->get($attempt_key);
                
                // 检查是否超过最大尝试次数（5次）
                if ($failed_attempts >= 5) {
                    $db->releaseRedisConnection($redis);
                    echo json_encode([
                        'success' => false, 
                        'message' => '登录失败次数过多，请稍后再试'
                    ]);
                    exit;
                }
                $db->releaseRedisConnection($redis);
            }
        } catch (Exception $e) {
            error_log("Redis连接失败: " . $e->getMessage());
        }
        
        // 使用连接池获取MySQL连接
        $pdo = $db->getMysqlConnection();
        
        // 初始化用户验证变量
        $userType = null;
        $userData = null;
        
        // 验证管理员账户
        if ($role === 'admin' || $role === 'auto') {
            $stmt = $pdo->prepare("SELECT id, username, password FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin) {
                $userType = 'admin';
                $userData = $admin;
            }
        }
        
        // 验证班级管理人账户（如果管理员验证失败）
        if (!$userData && ($role === 'committee' || $role === 'auto')) {
            $stmt = $pdo->prepare("SELECT student_id, password FROM class_committee WHERE student_id = ?");
            $stmt->execute([$username]);
            $committee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($committee) {
                $userType = 'committee';
                $userData = $committee;
            }
        }
        
        // 检查用户是否存在
        if (!$userData) {
            // 记录登录失败
            try {
                $redis = $db->getRedisConnection('login_security');
                if ($redis) {
                    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $attempt_key = 'login_attempts:' . $client_ip;
                    $redis->incr($attempt_key);
                    $redis->expire($attempt_key, 900); // 15分钟后过期
                    $db->releaseRedisConnection($redis);
                }
            } catch (Exception $e) {
                error_log("Redis连接失败: " . $e->getMessage());
            }
            // 释放数据库连接回连接池
            $db->releaseMysqlConnection($pdo);
            echo json_encode(['success' => false, 'message' => '用户名或密码错误']);
            exit;
        }
        
        if (!password_verify($password, $userData['password'])) {
            // 记录登录失败
            try {
                $redis = $db->getRedisConnection('login_security');
                if ($redis) {
                    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $attempt_key = 'login_attempts:' . $client_ip;
                    $redis->incr($attempt_key);
                    $redis->expire($attempt_key, 900); // 15分钟后过期
                    $db->releaseRedisConnection($redis);
                }
            } catch (Exception $e) {
                error_log("Redis连接失败: " . $e->getMessage());
            }
            // 释放数据库连接回连接池
            $db->releaseMysqlConnection($pdo);
            echo json_encode(['success' => false, 'message' => '用户名或密码错误']);
            exit;
        }
        
        // 登录成功，清除失败记录并设置会话信息
        try {
            $redis = $db->getRedisConnection('login_security');
            if ($redis) {
                $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $attempt_key = 'login_attempts:' . $client_ip;
                $redis->del($attempt_key); // 清除失败记录
                $db->releaseRedisConnection($redis);
            }
        } catch (Exception $e) {
            error_log("Redis连接失败: " . $e->getMessage());
        }
        
        $login_time = time();
        
        if ($userType === 'admin') {
            // 设置管理员会话
            $_SESSION['user_id'] = $userData['id'];
            $_SESSION['username'] = $userData['username'];
            $_SESSION['user_type'] = 'admin';
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = $login_time;
            
            // 检查是否勾选了记住我功能
            if (isset($_POST['remember_me']) && $_POST['remember_me'] === 'on') {
                // 生成记住我token
                $remember_token = bin2hex(random_bytes(32));
                $expire_time = time() + (7 * 24 * 60 * 60); // 7天
                
                // 存储token信息到Redis
                try {
                    $redis = $db->getRedisConnection('session');
                    if ($redis) {
                        // 在Redis中存储token信息
                        $token_data = json_encode([
                            'username' => $userData['username'],
                            'user_type' => 'admin',
                            'expire_time' => $expire_time,
                            'created_time' => time()
                        ]);
                        $redis->setex('remember_token:' . $remember_token, 7 * 24 * 60 * 60, $token_data);
                        $db->releaseRedisConnection($redis);
                    }
                } catch (Exception $e) {
                    error_log("Redis连接失败: " . $e->getMessage());
                }
                
                // 设置cookie（只存储token）
                setcookie('remember_token', $remember_token, $expire_time, '/', '', false, false);
            }
            
            // 释放数据库连接回连接池
            $db->releaseMysqlConnection($pdo);
            
            echo json_encode([
                'success' => true,
                'message' => '管理员登录成功',
                'redirect' => 'page/admin/dashboard.php',
                'user_type' => 'admin'
            ]);
            exit;
        } else {
            // 设置班级管理人会话
            $_SESSION['user_id'] = $userData['student_id'];
            $_SESSION['username'] = $userData['student_id'];
            $_SESSION['student_id'] = $userData['student_id'];
            $_SESSION['user_type'] = 'committee';
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = $login_time;
            
            // 检查是否勾选了记住我功能
            if (isset($_POST['remember_me']) && $_POST['remember_me'] === 'on') {
                // 生成记住我token
                $remember_token = bin2hex(random_bytes(32));
                $expire_time = time() + (7 * 24 * 60 * 60); // 7天
                
                // 存储token信息到Redis
                try {
                    $redis = $db->getRedisConnection('session');
                    if ($redis) {
                        // 在Redis中存储token信息
                        $token_data = json_encode([
                            'username' => $userData['student_id'],
                            'user_type' => 'committee',
                            'expire_time' => $expire_time,
                            'created_time' => time()
                        ]);
                        $redis->setex('remember_token:' . $remember_token, 7 * 24 * 60 * 60, $token_data);
                        $db->releaseRedisConnection($redis);
                    }
                } catch (Exception $e) {
                    error_log("Redis连接失败: " . $e->getMessage());
                }
                
                // 设置cookie（只存储token）
                setcookie('remember_token', $remember_token, $expire_time, '/', '', false, false);
            }
            
            // 释放数据库连接回连接池
            $db->releaseMysqlConnection($pdo);
            
            echo json_encode([
                'success' => true,
                'message' => '班级管理人登录成功',
                'redirect' => 'page/committee/dashboard.php',
                'user_type' => 'committee'
            ]);
            exit;
        }
        
    } catch (PDOException $e) {
        // 确保释放数据库连接
        if (isset($pdo) && isset($db)) {
            $db->releaseMysqlConnection($pdo);
        }
        echo json_encode(['success' => false, 'message' => '系统暂时不可用']);
        exit;
    } catch (Exception $e) {
        // 确保释放数据库连接
        if (isset($pdo) && isset($db)) {
            $db->releaseMysqlConnection($pdo);
        }
        echo json_encode(['success' => false, 'message' => '系统暂时不可用']);
        exit;
    }
}

// 检查用户是否已登录，已登录则自动跳转到对应页面
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    if ($_SESSION['user_type'] === 'admin') {
        header('Location: page/admin/dashboard.php');
    } else {
        header('Location: page/committee/dashboard.php');
    }
    exit;
}
?>
<!-- 登录页面HTML结构 -->
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>操行分管理系统 - 用户登录</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <!-- 引入Font Awesome图标库 -->
    <link rel="stylesheet" 
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" 
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" 
          crossorigin="anonymous" 
          referrerpolicy="no-referrer" />
    
    <?php include 'modules/notification.php'; ?>
    <!-- 页面样式 -->
    <style>
        /* 全局样式重置 */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* 页面主体样式 */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
            overflow: hidden;
            position: fixed;
            width: 100%;
            top: 0;
            left: 0;
        }

        /* 登录容器样式 */
        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12), 0 4px 15px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            width: 100%;
            max-width: 900px;
            min-height: 500px;
            display: flex;
            transition: box-shadow 0.3s ease;
        }

        /* 左侧品牌展示区域 */
        .login-left {
            flex: 1;
            background: linear-gradient(135deg, #3498db, #2980b9);
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            text-align: center;
        }

        /* 品牌Logo样式 */
        .login-logo {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
        }

        /* 品牌标题样式 */
        .login-title {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 15px;
        }

        /* 品牌副标题样式 */
        .login-subtitle {
            font-size: 1.1rem;
            opacity: 0.8;
            line-height: 1.6;
        }

        /* 右侧表单区域 */
        .login-right {
            flex: 1;
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        /* 表单头部样式 */
        .form-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .form-header h2 {
            font-size: 2.5rem;
            color: #2c3e50;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .form-header p {
            color: #7f8c8d;
            font-size: 1.1rem;
            opacity: 0.7;
        }

        /* 登录表单样式 */
        .login-form {
            width: 100%;
        }

        /* 表单组样式 */
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        /* 表单标签样式 */
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 500;
            font-size: 0.95rem;
        }

        /* 表单输入框样式 */
        .form-input {
            width: 100%;
            padding: 15px 20px;
            padding-left: 50px;
            border: 2px solid #ecf0f1;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        /* 密码输入框特殊样式 */
        .form-input[type="password"] {
            padding-right: 50px;
        }

        /* 输入框聚焦状态 */
        .form-input:focus {
            outline: none;
            border-color: #3498db;
            background: white;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        /* 输入框图标样式 */
        .form-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
            font-size: 1.1rem;
        }

        /* 密码显示切换按钮 */
        .password-toggle {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
            cursor: pointer;
            font-size: 1.1rem;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: #3498db;
        }

        /* 隐藏Edge浏览器默认密码显示按钮 */
        .form-input[type="password"]::-ms-reveal {
            display: none;
        }

        /* 表单选项区域 */
        .form-options {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            font-size: 0.9rem;
        }

        /* 记住我功能样式 */
        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
            font-size: 14px;
        }

        .remember-me input[type="checkbox"] {
            margin: 0;
            cursor: pointer;
            width: 16px;
            height: 16px;
            vertical-align: middle;
        }

        .remember-me label {
            cursor: pointer;
            user-select: none;
            line-height: 16px;
            vertical-align: middle;
        }

        /* 返回首页链接样式 */
        .back-to-index {
            color: #3498db;
            text-decoration: none;
            transition: color 0.3s ease;
            font-size: 14px;
        }

        .back-to-index:hover {
            color: #2980b9;
        }

        /* form-options布局 */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: nowrap;
        }

        /* 登录按钮样式 */
        .login-button {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        /* 按钮悬停效果 */
        .login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(52, 152, 219, 0.3);
        }

        /* 按钮点击效果 */
        .login-button:active {
            transform: translateY(0);
        }

        /* 按钮禁用状态 */
        .login-button:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* 加载动画样式 */
        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }

        /* 旋转动画关键帧 */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* 移动端适配 */
        @media (max-width: 768px) {
            .login-left {
                display: none;
            }

            .login-container {
                margin: 20px;
                width: calc(100% - 40px);
            }

            .login-right {
                padding: 30px 20px;
            }

            .form-header h2 {
                font-size: 1.8rem;
            }
        }


    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-left">
            <div class="login-logo">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <h1 class="login-title">操行分管理系统</h1>
            <p class="login-subtitle">
                Behavior Score Management System<br>
                For class use only
            </p>
        </div>


        <div class="login-right">
            <div class="form-header">
                <h2>用户登录</h2>
                <p>请输入您的用户名和密码</p>
            </div>


            <form id="loginForm" class="login-form" novalidate>

                <div class="form-group">
                    <label for="username" class="form-label">用户名</label>
                    <div style="position: relative;">
                        <i class="fa-solid fa-user form-icon"></i>
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            class="form-input" 
                            placeholder="请输入用户名"
                            required
                            autocomplete="username"
                            maxlength="50"
                        >
                    </div>
                </div>


                <div class="form-group">
                    <label for="password" class="form-label">密码</label>
                    <div style="position: relative;">
                        <i class="fa-solid fa-lock form-icon"></i>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-input" 
                            placeholder="请输入登录密码"
                            required
                            autocomplete="current-password"
                            maxlength="100"
                        >
                        <i class="fa-solid fa-eye password-toggle" id="passwordToggle" title="显示/隐藏密码"></i>
                    </div>
                </div>




                <div class="form-options">
                    <a href="index.php" class="back-to-index" id="backToIndexLink">返回首页</a>
                    <div class="remember-me">
                        <input type="checkbox" id="rememberMe" name="rememberMe">
                        <label for="rememberMe">记住我</label>
                    </div>
                </div>


                <button type="button" id="loginButton" class="login-button">
                    <span class="loading-spinner" id="loadingSpinner"></span>
                    <span id="buttonText">登录</span>
                </button>

            </form>
        </div>
    </div>
    <script>
        
        // 执行登录的函数
         function performLogin() {
             // 获取DOM元素引用
             var loginButton = document.getElementById('loginButton');
             var loadingSpinner = document.getElementById('loadingSpinner');
             var buttonText = document.getElementById('buttonText');
             
             // 获取用户输入的用户名和密码
             var username = document.getElementById('username').value.trim();
             var password = document.getElementById('password').value.trim();
             
             // 创建并配置AJAX请求
             var xhr = new XMLHttpRequest();
             xhr.open('POST', 'login.php', true);
             xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
             
             // 处理AJAX响应
             xhr.onreadystatechange = function() {
                 if (xhr.readyState === 4) {
                     // 恢复按钮状态
                     loginButton.disabled = false;
                     loadingSpinner.style.display = 'none';
                     buttonText.textContent = '登录';
                     
                     // 检查HTTP状态码
                     if (xhr.status === 200) {
                         try {
                             // 解析服务器返回的JSON响应
                             var response = JSON.parse(xhr.responseText);
                             if (response.success) {
                                 // 登录成功，显示成功消息并跳转
                                 notification.success('登录成功');
                                 setTimeout(function() {
                                     window.location.href = response.redirect;
                                 }, 2000);
                             } else {
                                 // 登录失败，显示错误消息
                                 notification.error(response.message, '登录失败');
                             }
                         } catch (e) {
                             // JSON解析失败
                             notification.error('服务器响应异常，请稍后重试', '登录失败');
                         }
                     } else {
                         // HTTP请求失败
                         notification.error('网络连接异常，请检查网络后重试', '登录失败');
                     }
                 }
             };
             
             // 发送登录请求数据
             var requestData = 'action=login&username=' + encodeURIComponent(username) + '&password=' + encodeURIComponent(password);
             // 检查是否勾选了记住我
             if (document.getElementById('rememberMe').checked) {
                 requestData += '&remember_me=on';
             }
             xhr.send(requestData);
         }
         
         // 登录按钮点击事件处理
         document.getElementById('loginButton').addEventListener('click', function() {
             // 获取用户输入的用户名和密码进行验证
             var username = document.getElementById('username').value.trim();
             var password = document.getElementById('password').value.trim();
             
             // 验证输入是否为空
             if (username === '' || password === '') {
                 notification.warning('请输入用户名和密码', '登录验证');
                 return;
             }
             
             // 获取DOM元素引用
             var loginButton = document.getElementById('loginButton');
             var loadingSpinner = document.getElementById('loadingSpinner');
             var buttonText = document.getElementById('buttonText');
             
             // 表单验证通过后，禁用按钮并显示加载状态
             loginButton.disabled = true;
             loadingSpinner.style.display = 'inline-block';
             buttonText.textContent = '登录中...';
             
             // 等待1秒后执行实际登录
             setTimeout(function() {
                 performLogin();
             }, 1000);
         });
         
         // Enter键登录功能
         document.addEventListener('keydown', function(event) {
             if (event.key === 'Enter') {
                 event.preventDefault();
                 
                 // 获取用户输入的用户名和密码进行验证
                 var username = document.getElementById('username').value.trim();
                 var password = document.getElementById('password').value.trim();
                 
                 // 验证输入是否为空
                 if (username === '' || password === '') {
                     notification.warning('请输入用户名和密码', '登录验证');
                     return;
                 }
                 
                 // 获取DOM元素引用
                 var loginButton = document.getElementById('loginButton');
                 var loadingSpinner = document.getElementById('loadingSpinner');
                 var buttonText = document.getElementById('buttonText');
                 
                 // 检查按钮是否已经被禁用，如果是则不执行任何操作
                 if (loginButton.disabled) {
                     return;
                 }
                 
                 // 表单验证通过后，禁用按钮并显示加载状态
                 loginButton.disabled = true;
                 loadingSpinner.style.display = 'inline-block';
                 buttonText.textContent = '登录中...';
                 
                 // 等待1秒后执行实际登录
                 setTimeout(function() {
                     performLogin();
                 }, 1000);
             }
         });
         
         // 硬编码的有效消息列表
         const VALID_MESSAGES = {
             'success': [
             ],
             'error': [
                "会话不存在，请重新登录",
                "系统服务暂时不可用，请稍后再试",
                "会话已失效，请重新登录",
                "系统连接异常，请稍后再试",
                "班级管理员权限已被撤销",
                "身份验证失败，请重新登录",
                "您暂无访问权限"

             ],
             'warning': [
             ],
         };

         // 验证消息是否在预定义列表中
         function validateMessage(message) {
             for (const type in VALID_MESSAGES) {
                 if (VALID_MESSAGES[type].includes(message)) {
                     return type;
                 }
             }
             return null;
         }

         // 根据消息内容自动判断消息类型
         function getMessageType(message) {
             // 首先检查消息是否在硬编码列表中
             const validType = validateMessage(message);
             if (validType) {
                 return validType;
             }
             
             // 如果不在硬编码列表中，返回null表示无效消息
             return null;
         }
         
         // 页面加载完成后检查URL参数中的消息
         window.addEventListener('DOMContentLoaded', function() {
             // 从URL参数中获取消息
             const message = new URLSearchParams(window.location.search).get('message');
             if (message) {
                 // 解码消息内容
                 const decodedMessage = decodeURIComponent(message);
                 
                 // 验证消息是否在预定义列表中
                 const messageType = getMessageType(decodedMessage);
                 if (messageType) {
                     // 消息验证通过，显示通知
                     notification[messageType](decodedMessage, '系统提示');
                 } else {
                     // 消息不在预定义列表中，不执行任何操作
                 }
                 
                 // 清除URL中的消息参数，避免刷新页面时重复显示
                 window.history.replaceState({}, document.title, window.location.pathname);
             }
         });
         
         // 密码显示/隐藏切换功能
         document.getElementById('passwordToggle').addEventListener('click', function() {
             // 获取密码输入框
             var passwordInput = document.getElementById('password');
             
             // 判断当前是否为密码类型
             var isPassword = passwordInput.type === 'password';
             
             // 切换输入框类型
             passwordInput.type = isPassword ? 'text' : 'password';
             
             // 切换图标样式
             this.classList.toggle('fa-eye', !isPassword);
             this.classList.toggle('fa-eye-slash', isPassword);
             
             // 更新提示文本
             this.title = isPassword ? '隐藏密码' : '显示密码';
         });
    </script>

</body>
</html>