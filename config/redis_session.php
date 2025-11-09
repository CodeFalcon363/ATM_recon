<?php
/**
 * Redis session configuration for high-concurrency deployments
 *
 * Installation:
 * 1. Install Redis: apt-get install redis-server (Linux) or download for Windows
 * 2. Install PHP Redis extension: pecl install redis
 * 3. Enable in php.ini: extension=redis
 * 4. Include this file at the start of session_start() calls
 *
 * Usage: require_once __DIR__ . '/../config/redis_session.php';
 */

// Configure Redis for sessions (handles 100+ concurrent users efficiently)
ini_set('session.save_handler', 'redis');
ini_set('session.save_path', 'tcp://127.0.0.1:6379?database=0');
ini_set('session.gc_maxlifetime', 7200); // 2 hours

// Session configuration for high concurrency
ini_set('session.cookie_lifetime', 0);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
