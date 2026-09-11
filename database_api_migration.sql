-- ============================================================
-- Reforestation Management Platform - Mobile API Migration
-- Run this AFTER database.sql. Adds token storage for the
-- mobile app's Bearer-token authentication.
-- ============================================================

USE reforestation_db;

CREATE TABLE IF NOT EXISTS api_tokens (
    token_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token       VARCHAR(64) NOT NULL UNIQUE,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at  TIMESTAMP NOT NULL,

    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,

    INDEX idx_token (token),
    INDEX idx_user (user_id)
);
