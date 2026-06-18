<?php
header('Content-Type: application/json');
error_reporting(0);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Non authentifié');
    }

    $idCompte = $_SESSION['user_id'];
    $appareilId = trim($_POST['appareil_id'] ?? '');
    $device_id = trim($_POST['device_id'] ?? '');
    $device_name = trim($_POST['device_name'] ?? '');
    $api_username = trim($_POST['api_username'] ?? '');
    $api_password = trim($_POST['api_password'] ?? '');

    if (empty($appareilId)) {
        throw new Exception('ID appareil requis');
    }

    
    // Activer l'appareil sélectionné
    $db->update('sms_appareils', ['est_actif' => true], ['id_appareil' => $appareilId]);
    
    // Mettre à jour la session
    $_SESSION['sms_device_id'] = $device_id;
    $_SESSION['sms_device_name'] = $device_name;
    $_SESSION['sms_api_username'] = $api_username;
    $_SESSION['sms_api_password'] = $api_password;
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>