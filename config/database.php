<?php
/**
 * Centralized Database Connection
 * Reforestation Management Platform - User Management Module
 *
 * Uses PDO with prepared statements.
 * Adjust the constants below to match your local XAMPP setup.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'reforestation_db');
define('DB_USER', 'root');
define('DB_PASS', ''); // default XAMPP MySQL password is empty

function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=3306;dbname=' . DB_NAME . ';charset=utf8mb4';

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Do not leak DB credentials or details to the browser.
            error_log('Database connection failed: ' . $e->getMessage());
            die('Database connection failed. Please check the server configuration.');
        }
    }

    return $pdo;
}
