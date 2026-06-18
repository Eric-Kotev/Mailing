<?php
header('Content-Type: application/json');
error_reporting(0);

// Chemins absolus
$root = __DIR__;

// Inclure les fichiers nécessaires
require_once $root . '/includes/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/config.php';

// Démarrer la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

$idCompte = $_SESSION['user_id'];
$nom_session = trim($_POST['nom_session'] ?? '');

if (empty($nom_session)) {
    echo json_encode(['success' => false, 'error' => 'Nom de session requis']);
    exit;
}

try {
    // Vérifier si la session existe déjà pour ce compte
    $existing = $db->select('whatsapp_sessions', [
        'id_compte' => $idCompte,
        'nom_session' => $nom_session
    ]);
    
    if (!empty($existing)) {
        // La session existe déjà, on la rend active (sans désactiver les autres)
        $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $existing[0]['id_session']]);
        echo json_encode(['success' => true, 'session' => $nom_session, 'existing' => true, 'active' => true]);
    } else {
        // Créer une nouvelle session active (sans désactiver les autres)
        $data = [
            'id_compte' => $idCompte,
            'nom_session' => $nom_session,
            'est_active' => true,
            'created_at' => date('Y-m-d H:i:s')
        ];
        $db->insert('whatsapp_sessions', $data);
        echo json_encode(['success' => true, 'session' => $nom_session, 'existing' => false, 'active' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>