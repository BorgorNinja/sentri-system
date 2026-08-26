<?php
/**
 * dashboard.php - Legacy entry point
 *
 * This file has been superseded by the role-based portal system.
 * All users arriving here are routed to their dedicated portal via
 * redirect_to_portal() in config/auth.php based on their session role.
 *
 * Previous versions contained the full community portal inline; that code
 * has been moved to portal/community.php during the portal refactor.
 */

session_start([
 'cookie_httponly' => true,
 'cookie_samesite' => 'Lax',
 'cookie_secure' => !empty($_SERVER['HTTPS']),
]);

if (!isset($_SESSION['user_id'])) {
 header('Location: login.php');
 exit;
}

require_once __DIR__ . '/config/auth.php';

redirect_to_portal();

