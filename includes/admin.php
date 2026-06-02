<?php
// Vérifier si l'utilisateur est admin
function isAdmin() {
    global $db;
    
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    try {
        $user = $db->select('compte', ['id_compte' => $_SESSION['user_id']], 'role');
        return ($user && $user[0]['role'] === 'admin');
    } catch (Exception $e) {
        return false;
    }
}

// Forcer l'accès admin (redirige si pas admin)
function requireAdmin() {
    if (!isAdmin()) {
        header('Location: index.php?page=dashboard');
        exit;
    }
}
?>