-- 1. 学生表（单班级简化版）
CREATE TABLE students (
    id INT PRIMARY KEY AUTO_INCREMENT COMMENT '学生编号',
    student_id VARCHAR(20) UNIQUE NOT NULL COMMENT '学号',
    name VARCHAR(50) NOT NULL COMMENT '姓名',
    current_score DECIMAL(4,1) COMMENT '当前操行分',
    status ENUM('active', 'inactive') DEFAULT 'active' COMMENT '状态',
    appeal_password VARCHAR(255) DEFAULT NULL COMMENT '申诉密码',
    appeal_permission BOOLEAN DEFAULT TRUE COMMENT '申诉权限',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
) COMMENT '学生信息表';

-- 2. 操行分规则表
CREATE TABLE conduct_rules (
    id INT PRIMARY KEY AUTO_INCREMENT COMMENT '规则编号',
    name VARCHAR(100) NOT NULL COMMENT '规则名称',
    description TEXT COMMENT '规则描述',
    type ENUM('reward', 'penalty') NOT NULL COMMENT '规则类型',
    score_value DECIMAL(4,1) NOT NULL COMMENT '规则分值',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
) COMMENT '操行分规则表';

-- 3. 操行分记录表
CREATE TABLE conduct_records (
    id INT PRIMARY KEY AUTO_INCREMENT COMMENT '记录编号',
    student_id INT NOT NULL COMMENT '学生编号',
    reason VARCHAR(200) NOT NULL COMMENT '操作原因',
    score_change DECIMAL(4,1) NOT NULL COMMENT '分数变化',
    score_after DECIMAL(4,1) NOT NULL COMMENT '操作后分数',
    operator_name VARCHAR(50) NOT NULL COMMENT '操作人',
    status ENUM('valid', 'invalid') DEFAULT 'valid' COMMENT '记录状态',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
) COMMENT '操行分记录表';

-- 4. 申诉表
CREATE TABLE appeals (
    id INT PRIMARY KEY AUTO_INCREMENT COMMENT '申诉编号',
    student_id INT NOT NULL COMMENT '学生编号',
    record_id INT NOT NULL COMMENT '申诉记录编号',
    reason TEXT NOT NULL COMMENT '申诉理由',
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' COMMENT '申诉状态',
    processed_by VARCHAR(50) COMMENT '处理人',
    processed_at TIMESTAMP NULL COMMENT '处理时间',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
) COMMENT '申诉表';

-- 5. 班级管理人表（单班级简化版）
CREATE TABLE class_committee (
    id INT PRIMARY KEY AUTO_INCREMENT COMMENT '班级管理人编号',
    student_id VARCHAR(20) NOT NULL COMMENT '学号',
    link_student_id VARCHAR(20) NOT NULL COMMENT '关联学生编号',
    position VARCHAR(50) NOT NULL COMMENT '职务',
    password VARCHAR(255) NOT NULL COMMENT '登录密码',
    start_date DATE NOT NULL COMMENT '任职开始日期',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
) COMMENT '班级管理人信息表';

-- 6. 管理员表
CREATE TABLE admins (
    id INT PRIMARY KEY AUTO_INCREMENT COMMENT '管理员编号',
    username VARCHAR(50) UNIQUE NOT NULL COMMENT '用户名',
    password VARCHAR(255) NOT NULL COMMENT '密码',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
) COMMENT '管理员表';

-- 创建索引
CREATE INDEX idx_students_student_id ON students(student_id);
CREATE INDEX idx_students_name_score ON students(name, current_score);
CREATE INDEX idx_students_score_id ON students(current_score DESC, id ASC);
CREATE INDEX idx_conduct_records_student ON conduct_records(student_id);
CREATE INDEX idx_conduct_records_created ON conduct_records(created_at);
CREATE INDEX idx_conduct_records_status ON conduct_records(status);
CREATE INDEX idx_appeals_student ON appeals(student_id);
CREATE INDEX idx_appeals_status ON appeals(status);
CREATE INDEX idx_committee_student ON class_committee(student_id);

-- 插入默认管理员账户（密码留空，需要后续设置）
INSERT INTO admins (username, password) VALUES ('admin', '请在此处填入管理员密码 | Please insert admin password');
