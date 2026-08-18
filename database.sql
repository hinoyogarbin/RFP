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
