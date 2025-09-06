<?php
// 防止直接访问
if (!defined('INCLUDED_FROM_APP') && basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    exit();
}

/**
 * 提示框组件模块
 * @version 2.1
 */
?>

<style>
.notification-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    display: none;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(2px);
}

.notification-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    max-width: 420px;
    width: 90%;
    padding: 0;
    overflow: hidden;
    transform: scale(0.8);
    opacity: 0;
    transition: all 0.3s ease;
}

.notification-overlay.show .notification-container {
    transform: scale(1);
    opacity: 1;
}

.notification-header {
    padding: 25px 30px 20px;
    border-bottom: 1px solid #ecf0f1;
    display: flex;
    align-items: center;
    gap: 15px;
}

.notification-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: white;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.notification-icon.success { background: linear-gradient(135deg, #27ae60, #2ecc71); }
.notification-icon.error { background: linear-gradient(135deg, #e74c3c, #ec7063); }
.notification-icon.warning { background: linear-gradient(135deg, #f39c12, #f7dc6f); }
.notification-icon.info { background: linear-gradient(135deg, #3498db, #5dade2); }

.notification-title {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 18px;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.notification-body {
    padding: 25px 30px;
}

.notification-message {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 14px;
    line-height: 1.5;
    color: #2c3e50;
    margin: 0;
    font-weight: 400;
}

.notification-footer {
    padding: 20px 30px 25px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.notification-btn {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    padding: 10px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    min-width: 80px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.notification-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.notification-btn.primary { background: #3498db; color: white; }
.notification-btn.primary:hover { background: #2980b9; }
.notification-btn.secondary { background: #ecf0f1; color: #7f8c8d; }
.notification-btn.secondary:hover { background: #d5dbdb; }
.notification-btn.success { background: #27ae60; color: white; }
.notification-btn.success:hover { background: #229954; }
.notification-btn.danger { background: #e74c3c; color: white; }
.notification-btn.danger:hover { background: #c0392b; }

@keyframes slideInDown {
    from { transform: translateY(-100px) scale(0.8); opacity: 0; }
    to { transform: translateY(0) scale(1); opacity: 1; }
}

.notification-container.slide-in {
    animation: slideInDown 0.3s ease;
}
</style>
<div id="notificationOverlay" class="notification-overlay">
    <div class="notification-container">
        <div class="notification-header">
            <div id="notificationIcon" class="notification-icon info">
                <i class="fa-solid fa-info"></i>
            </div>
            <h3 id="notificationTitle" class="notification-title">提示</h3>
        </div>
        <div class="notification-body">
            <p id="notificationMessage" class="notification-message">这是一条提示信息</p>
        </div>
        <div class="notification-footer">
            <button id="notificationCancel" class="notification-btn secondary" style="display: none;">取消</button>
            <button id="notificationConfirm" class="notification-btn primary">确定</button>
        </div>
    </div>
</div>

<script>
class NotificationManager {
    constructor() {
        this.overlay = document.getElementById('notificationOverlay');
        this.container = this.overlay.querySelector('.notification-container');
        this.icon = document.getElementById('notificationIcon');
        this.title = document.getElementById('notificationTitle');
        this.message = document.getElementById('notificationMessage');
        this.confirmBtn = document.getElementById('notificationConfirm');
        this.cancelBtn = document.getElementById('notificationCancel');
        this.init();
    }
    
    init() {
        this.overlay.addEventListener('click', (e) => {
            if (e.target === this.overlay) this.hide();
        });
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.overlay.style.display === 'flex') this.hide();
        });
    }
    
    show(options = {}) {
        const config = {
            type: 'info',
            title: '提示',
            message: '这是一条提示信息',
            showCancel: false,
            confirmText: '确定',
            cancelText: '取消',
            onConfirm: null,
            onCancel: null,
            ...options
        };
        
        if (!config.showCancel) {
            this.toast(config.message, config.type, { title: config.title, duration: 3000 });
            return;
        }
        
        this.setType(config.type);
        this.title.innerHTML = config.title;
        this.message.innerHTML = config.message;
        this.confirmBtn.innerHTML = config.confirmText;
        this.cancelBtn.innerHTML = config.cancelText;
        this.cancelBtn.style.display = config.showCancel ? 'inline-block' : 'none';
        this.confirmBtn.className = `notification-btn ${this.getButtonClass(config.type)}`;
        
        this.confirmBtn.onclick = () => {
            config.onConfirm && config.onConfirm();
            this.hide();
        };
        
        this.cancelBtn.onclick = () => {
            config.onCancel && config.onCancel();
            this.hide();
        };
        
        this.overlay.style.display = 'flex';
        setTimeout(() => {
            this.overlay.classList.add('show');
            this.container.classList.add('slide-in');
        }, 10);
    }
    
    toast(message, type = 'info', options = {}) {
        const config = {
            title: '提示',
            duration: options.duration || 3000,
            ...options
        };
        
        this.ensureToastContainer();
        
        const container = document.getElementById('toastContainer');
        const existingToasts = container.querySelectorAll('.toast-notification:not(.removing)');
        
        if (existingToasts.length >= 3) {
            this.removeToast(existingToasts[0]);
        }
        
        const toast = document.createElement('div');
        toast.className = `toast-notification ${type}`;
        
        toast.innerHTML = `
            <div class="toast-content">
                <div class="toast-icon ${type}">
                    <i class="fa-solid ${this.getToastIcon(type)}"></i>
                </div>
                <div class="toast-text">
                    <div class="toast-message">${message}</div>
                </div>
            </div>
        `;
        
        container.appendChild(toast);
        this.updateToastPositions();
        
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => this.removeToast(toast), config.duration);
    }
    
    ensureToastContainer() {
        if (!document.getElementById('toastContainer')) {
            const container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        
        if (!document.getElementById('toastStyles')) {
            const style = document.createElement('style');
            style.id = 'toastStyles';
            style.textContent = `
                .toast-container {
                    position: fixed;
                    top: 20px;
                    left: 0;
                    right: 0;
                    z-index: 10000;
                    pointer-events: none;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                }
                .toast-notification {
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
                    margin-bottom: 12px;
                    min-width: 220px;
                    max-width: 70vw;
                    width: fit-content;
                    opacity: 0;
                    transform: translateY(-20px);
                    transition: all 0.3s ease;
                    pointer-events: auto;
                    position: relative;
                    max-height: 0;
                    overflow: hidden;
                    border-left: 4px solid #3498db;
                }
                .toast-notification.success {
                    border-left-color: #27ae60;
                }
                .toast-notification.error {
                    border-left-color: #e74c3c;
                }
                .toast-notification.warning {
                    border-left-color: #f39c12;
                }
                .toast-notification.info {
                    border-left-color: #3498db;
                }
                .toast-notification.show {
                    opacity: 1;
                    transform: translateY(0);
                    max-height: 200px;
                }
                .toast-notification.removing {
                    opacity: 0;
                    transform: translateY(-20px) scale(0.95);
                    max-height: 0;
                    margin-bottom: 0;
                    padding: 0;
                }
                .toast-notification:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
                }
                .toast-content {
                    display: flex;
                    align-items: flex-start;
                    justify-content: flex-start;
                    padding: 16px 20px;
                    gap: 12px;
                    position: relative;
                    min-height: 48px;
                }
                .toast-icon {
                    width: 28px;
                    height: 28px;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 14px;
                    color: white;
                    flex-shrink: 0;
                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
                }
                .toast-icon.success { 
                    background: linear-gradient(135deg, #27ae60, #2ecc71);
                }
                .toast-icon.error { 
                    background: linear-gradient(135deg, #e74c3c, #ec7063);
                }
                .toast-icon.warning { 
                    background: linear-gradient(135deg, #f39c12, #f7dc6f);
                }
                .toast-icon.info { 
                    background: linear-gradient(135deg, #3498db, #5dade2);
                }
                .toast-text {
                    flex: 1;
                    display: flex;
                    align-items: flex-start;
                    justify-content: flex-start;
                    padding-top: 2px;
                }
                .toast-message {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    font-size: 14px;
                    color: #2c3e50;
                    line-height: 1.5;
                    margin: 0;
                    word-wrap: break-word;
                    white-space: normal;
                    text-align: left;
                    max-width: 100%;
                    overflow-wrap: break-word;
                    hyphens: auto;
                    font-weight: 500;
                }

            `;
            document.head.appendChild(style);
        }
    }
    
    removeToast(toast) {
        if (!toast || !toast.parentNode) return;
        
        toast.classList.remove('show');
        toast.classList.add('removing');
        
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
                this.updateToastPositions();
            }
        }, 300);
    }
    
    updateToastPositions() {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        
        const toasts = container.querySelectorAll('.toast-notification');
        toasts.forEach((toast, index) => {
            toast.style.zIndex = 10000 - index;
        });
    }
    
    getToastIcon(type) {
        const icons = {
            success: 'fa-check',
            error: 'fa-times',
            warning: 'fa-exclamation',
            info: 'fa-info'
        };
        return icons[type] || icons.info;
    }
    
    hide() {
        this.overlay.classList.remove('show');
        this.container.classList.remove('slide-in');
        setTimeout(() => this.overlay.style.display = 'none', 300);
    }
    
    setType(type) {
        this.icon.className = `notification-icon ${type}`;
        const icons = {
            success: 'fa-check',
            error: 'fa-times',
            warning: 'fa-exclamation',
            info: 'fa-info'
        };
        this.icon.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}"></i>`;
    }
    
    getButtonClass(type) {
        const classes = {
            success: 'success',
            error: 'danger',
            warning: 'primary',
            info: 'primary'
        };
        return classes[type] || 'primary';
    }
    destroy() {
        const toastContainer = document.getElementById('toastContainer');
        if (toastContainer) toastContainer.remove();
        
        const toastStyles = document.getElementById('toastStyles');
        if (toastStyles) toastStyles.remove();
    }
    
    success(message, title = '成功', options = {}) {
        this.toast(message, 'success', { title, ...options });
    }
    
    error(message, title = '错误', options = {}) {
        this.toast(message, 'error', { title, ...options });
    }
    
    warning(message, title = '警告', options = {}) {
        this.toast(message, 'warning', { title, ...options });
    }
    
    info(message, title = '提示', options = {}) {
        this.toast(message, 'info', { title, ...options });
    }
    
    confirm(message, title = '确认', options = {}) {
        this.show({
            type: 'warning',
            title,
            message,
            showCancel: true,
            confirmText: '确定',
            cancelText: '取消',
            ...options
        });
    }
    
    dialog(message, title = '提示', options = {}) {
        this.show({
            type: 'info',
            title,
            message,
            showCancel: true,
            confirmText: '确定',
            cancelText: '取消',
            ...options
        });
    }
}

window.notification = new NotificationManager();
</script>
