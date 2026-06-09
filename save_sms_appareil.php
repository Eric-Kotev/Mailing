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
    $device_id = trim($_POST['device_id'] ?? '');
    $device_name = trim($_POST['device_name'] ?? '');
    $api_username = trim($_POST['api_username'] ?? '');
    $api_password = trim($_POST['api_password'] ?? '');

    if (empty($device_id)) {
        throw new Exception('ID appareil requis');
    }

    // Vérifier si l'appareil existe déjà
    $existing = $db->select('sms_appareils', [
        'id_compte' => $idCompte,
        'device_id' => $device_id
    ]);
    
    if (!empty($existing)) {
        // Appareil existe déjà → mettre à jour et l'activer
        // 1. Désactiver tous les appareils du compte
        $db->update('sms_appareils', ['est_actif' => false], ['id_compte' => $idCompte]);
        // 2. Activer cet appareil (sans last_used)
        $db->update('sms_appareils', [
            'est_actif' => true,
            'device_name' => $device_name,
            'api_username' => $api_username,
            'api_password' => $api_password
        ], ['id_appareil' => $existing[0]['id_appareil']]);
    } else {
        // Nouvel appareil → l'ajouter et l'activer
        // 1. Désactiver tous les appareils existants
        $db->update('sms_appareils', ['est_actif' => false], ['id_compte' => $idCompte]);
        // 2. Insérer le nouvel appareil actif (sans last_used)
        $data = [
            'id_compte' => $idCompte,
            'device_id' => $device_id,
            'device_name' => $device_name,
            'api_username' => $api_username,
            'api_password' => $api_password,
            'est_actif' => true,
            'created_at' => date('Y-m-d H:i:s')
        ];
        $db->insert('sms_appareils', $data);
    }
    
    // Stocker l'appareil actif en session
    $_SESSION['sms_device_id'] = $device_id;
    $_SESSION['sms_device_name'] = $device_name;
    $_SESSION['sms_api_username'] = $api_username;
    $_SESSION['sms_api_password'] = $api_password;
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>