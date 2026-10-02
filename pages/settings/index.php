<?php
// pages/settings/index.php
require_once __DIR__ . '/../../config/auth.php';

requireLogin();

if (hasRole('admin')) {
    header('Location: users.php'); exit;
}
if (hasRole('manager')) {
    header('Location: alerts.php'); exit;
}
header('Location: profile.php'); exit;