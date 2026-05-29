<?php
session_start();
require_once __DIR__ . '/../config/auth.php';
require_role(['community','user']);
// Community users use the main dashboard
header('Location: ../dashboard.php'); exit;
