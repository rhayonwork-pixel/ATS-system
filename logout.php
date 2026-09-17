<?php require_once 'includes/config.php'; audit('logout','user',$_SESSION['user_id'] ?? null); $_SESSION=[]; session_destroy(); header('Location: index.php'); exit; ?>
