<?php
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getCurrentUserName() {
    return $_SESSION['user_name'] ?? 'Invité';
}

function getCurrentUserCredits() {
    return $_SESSION['user_credits'] ?? 0;
}

function updateSessionCredits($credits) {
    $_SESSION['user_credits'] = $credits;
}

function logout() {
    session_destroy();
    header('Location: login.php');
    exit;
}
?>