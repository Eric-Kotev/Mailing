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
    $sessionId = trim($_POST['session_id'] ?? '');
    $nomSession = trim($_POST['nom_session'] ?? '');

    if (empty($sessionId)) {
        throw new Exception('ID de session requis');
    }

    // Vérifier que la session appartient bien à l'utilisateur
    $session = $db->select('whatsapp_sessions', [
        'id_session' => $sessionId,
        'id_compte' => $idCompte
    ]);
    
    if (empty($session)) {
        throw new Exception('Session non trouvée');
    }
    
    
    // Activer la session sélectionnée
    $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $sessionId]);
    
    echo json_encode(['success' => true, 'session' => $nomSession]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>