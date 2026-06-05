<?php
header('Content-Type: application/json');

// Chemin absolu vers la racine du projet
$root = dirname(__DIR__, 2); // Remonte de 'pages/campagnes' vers 'mailing'

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

// MISE À JOUR DIRECTE AVEC CURL
$url = SUPABASE_URL . '/rest/v1/compte?id_compte=eq.' . $idCompte;
$headers = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Content-Type: application/json'
];
$data = json_encode(['waha_session' => $nom_session]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 || $httpCode === 204) {
    echo json_encode(['success' => true, 'session' => $nom_session]);
} else {
    echo json_encode(['success' => false, 'error' => 'Erreur HTTP ' . $httpCode]);
}
?>