<?php
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Non authentifié');
    }

    $idCompte = $_SESSION['user_id'];
    $sessionId = trim($_POST['session_id'] ?? '');
    $sessionName = trim($_POST['session_name'] ?? '');

    if (empty($sessionId)) {
        throw new Exception('ID de session requis');
    }

    // Vérifier que la session existe et appartient à l'utilisateur
    $session = $db->select('whatsapp_sessions', [
        'id_session' => $sessionId,
        'id_compte' => $idCompte
    ]);
    
    if (empty($session)) {
        throw new Exception('Session non trouvée');
    }
    
    $sessionName = $session[0]['nom_session'];
    $isActive = $session[0]['est_active'];
    
    // Récupérer toutes les sessions
    $allSessions = $db->select('whatsapp_sessions', ['id_compte' => $idCompte]);
    
    // SUPPRESSION DE LA RESTRICTION - On peut maintenant supprimer la dernière session
    // if (count($allSessions) <= 1) {
    //     throw new Exception('Vous ne pouvez pas supprimer votre dernière session');
    // }
    
    // Si la session à supprimer est active, activer une autre session (s'il y en a)
    if ($isActive && count($allSessions) > 1) {
        foreach ($allSessions as $s) {
            if ($s['id_session'] !== $sessionId) {
                $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $s['id_session']]);
                break;
            }
        }
    }
    
    // SUPPRESSION DANS LA BASE DE DONNÉES (Supabase)
    $supabaseUrl = SUPABASE_URL . '/rest/v1/whatsapp_sessions';
    $encodedSessionId = urlencode($sessionId);
    $encodedCompteId = urlencode($idCompte);
    $deleteUrl = $supabaseUrl . '?id_session=eq.' . $encodedSessionId . '&id_compte=eq.' . $encodedCompteId;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $deleteUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=minimal'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception('CURL Error: ' . $curlError);
    }
    
    if ($httpCode === 200 || $httpCode === 204 || $httpCode === 202) {
        echo json_encode(['success' => true]);
    } else {
        throw new Exception('Erreur suppression: HTTP ' . $httpCode . ' - ' . $response);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>