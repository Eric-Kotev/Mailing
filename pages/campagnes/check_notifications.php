<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Non authentifié']));
}

$idCompte = $_SESSION['user_id'];
global $db;

// Récupérer les envois récents (statuts finaux uniquement)
$messages = $db->select('campagne', [
    'id_compte' => $idCompte
], '*', 'created_at DESC', 50);

// Récupérer les notifications déjà vues pour ce compte
$dejaVues = $db->select('notifications_vues', ['id_compte' => $idCompte]);
$idsVus = array_column($dejaVues, 'id_campagne');

$nouveaux = [];
foreach ($messages as $msg) {
    if (in_array($msg['statut'], ['envoye', 'echoue', 'partiel'])
        && !in_array($msg['id_campagne'], $idsVus)) {
        $nouveaux[] = $msg;
    }
}

echo json_encode([
    'success' => true,
    'nouveaux' => $nouveaux
]);