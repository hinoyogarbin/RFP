-- ============================================================
-- Reforestation Management Platform - User Management Module
-- Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS reforestation_db;
USE reforestation_db;

CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    contact_number VARCHAR(30) NULL,
    role ENUM('admin', 'manager', 'user') NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- Seed default Admin account
-- Username: admin
-- Password: Admin@123
-- (Hash generated with PHP password_hash() - bcrypt)
-- NOTE: Log in with the above credentials, then change the
-- password immediately from the Edit User page.
-- ============================================================
INSERT INTO users (full_name, username, password, contact_number, role, status)
VALUES (
    'System Administrator',
    'admin',
    '$2y$10$UpOq27zYqkJEHrbz0zKUXujvfOOLqBLH.thx4ZFPnPWmJ5.KKAnAS',
    NULL,
    'admin',
    'active'
);

-- ============================================================
-- Activity Logs
-- Tracks who navigated where and when, for admin oversight.
-- Filterable by role (admin/manager/user) in the admin panel.
-- ============================================================
CREATE TABLE activity_logs (
    log_id       INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NULL,
    full_name    VARCHAR(150) NOT NULL,
    role         ENUM('admin', 'manager', 'user') NOT NULL,
    action       VARCHAR(100) NOT NULL,
    module       VARCHAR(100) NOT NULL,
    description  TEXT NULL,
    ip_address   VARCHAR(45) NULL,
    user_agent   VARCHAR(255) NULL,
    status       ENUM('success', 'failed', 'warning') NOT NULL DEFAULT 'success',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,

    INDEX idx_role (role),
    INDEX idx_action (action),
    INDEX idx_module (module),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- ============================================================
-- Field Photos
-- Photos captured/uploaded by field operators (User role).
-- GPS coordinates, capture date/time, and camera make/model are
-- extracted automatically from each photo's EXIF metadata on
-- upload. Every upload starts pending until an Admin or Manager
-- confirms or rejects it.
-- ============================================================
CREATE TABLE field_photos (
    photo_id       INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    file_name      VARCHAR(255) NOT NULL,
    original_name  VARCHAR(255) NOT NULL,
    file_size      INT NOT NULL,

    exif_latitude  DECIMAL(10,7) NULL,
    exif_longitude DECIMAL(10,7) NULL,
    exif_datetime  DATETIME NULL,
    camera_make    VARCHAR(100) NULL,
    camera_model   VARCHAR(100) NULL,

    status         ENUM('pending', 'confirmed', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by    INT NULL,
    reviewed_at    TIMESTAMP NULL,
    review_notes   VARCHAR(255) NULL,

    uploaded_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL,

    INDEX idx_user (user_id),
    INDEX idx_uploaded_at (uploaded_at),
    INDEX idx_status (status)
);
