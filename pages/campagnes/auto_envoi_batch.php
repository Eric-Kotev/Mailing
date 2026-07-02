<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/envoi_functions.php';
require_once __DIR__ . '/../includes/db.php';


header('Content-Type: application/json');

$headers = getallheaders();
$secretRecu = $headers['X-Cron-Secret'] ?? '';

if ($secretRecu !== CRON_TRIGGER_SECRET) {
    http_response_code(403);
    exit(json_encode(['error' => 'Accès refusé']));
}

$body = json_decode(file_get_contents('php://input'), true);
$campagneConfigId = $body['id_campagne_config'] ?? null;

if (!$campagneConfigId) {
    http_response_code(400);
    exit(json_encode(['error' => 'id_campagne_config manquant']));
}

global $db;

$campagneConfig = $db->select('campagne_config', ['id_campagne_config' => $campagneConfigId]);
if (empty($campagneConfig)) {
    http_response_code(404);
    exit(json_encode(['error' => 'Campagne introuvable']));
}
$campagne = $campagneConfig[0];
$idCompte = $campagne['id_compte'];

$messages = $db->select('campagne', [
    'id_campagne_config' => $campagneConfigId,
    'statut' => 'pret_a_envoyer'
]);

$resultats = [];
foreach ($messages as $msg) {
    // Marquer immédiatement pour éviter tout traitement concurrent
    $db->update('campagne', ['declenchee_at' => date('Y-m-d H:i:s')], ['id_campagne' => $msg['id_campagne']]);

    $destinataires = json_decode($msg['destinataires'] ?? '[]', true);
    $message = $msg['message'] ?? '';
    $pieceJointe = !empty($msg['piece_jointe']) ? json_decode($msg['piece_jointe'], true) : null;

    switch ($msg['type_campagne']) {
        case 'sms':
            $resultats[] = envoyerSMS($idCompte, $campagneConfigId, $campagne, $msg, $message, $destinataires);
            break;
        case 'whatsapp':
            $resultats[] = envoyerWhatsApp($idCompte, $campagneConfigId, $campagne, $msg, $message, $destinataires, $pieceJointe, $campagne['min_delay'] ?? 60, $campagne['max_delay'] ?? 180);
            break;
        case 'email':
            $resultats[] = envoyerEmail($idCompte, $campagneConfigId, $campagne, $msg, $message, $destinataires);
            break;
    }
}

mettreAJourStatutCampagne($campagneConfigId, $idCompte);

echo json_encode(['success' => true, 'nb_traites' => count($resultats)]);