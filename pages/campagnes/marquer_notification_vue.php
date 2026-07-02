<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Non authentifié']));
}

$idCompte = $_SESSION['user_id'];
$body = json_decode(file_get_contents('php://input'), true);
$idCampagne = $body['id_campagne'] ?? null;

if (!$idCampagne) {
    http_response_code(400);
    exit(json_encode(['error' => 'id_campagne manquant']));
}

global $db;

$db->insert('notifications_vues', [
    'id_compte' => $idCompte,
    'id_campagne' => $idCampagne
]);

echo json_encode(['success' => true]);