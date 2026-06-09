<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$response = ['success' => false, 'error' => ''];

try {
    // Vérifier l'authentification
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Non authentifié');
    }

    $idCompte = $_SESSION['user_id'];
    $appareilId = trim($_POST['appareil_id'] ?? '');

    if (empty($appareilId)) {
        throw new Exception('ID appareil requis');
    }

    // Supprimer avec DELETE direct vers Supabase
    $supabaseUrl = SUPABASE_URL . '/rest/v1/sms_appareils';
    $deleteUrl = $supabaseUrl . '?id_appareil=eq.' . urlencode($appareilId) . '&id_compte=eq.' . urlencode($idCompte);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $deleteUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception('CURL Error: ' . $curlError);
    }
    
    if ($httpCode === 200 || $httpCode === 204) {
        $response['success'] = true;
    } else {
        throw new Exception('Erreur HTTP ' . $httpCode . ': ' . $result);
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
}

// S'assurer qu'il n'y a rien d'autre avant l'envoi
while (ob_get_level()) ob_end_clean();
echo json_encode($response);
exit;
?>