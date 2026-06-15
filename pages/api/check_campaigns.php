<?php
require_once '../../config.php';
global $db;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['campagnes' => []]);
    exit;
}

$idCompte = $_SESSION['user_id'];
$now = date('Y-m-d H:i:s');

// Récupérer les campagnes planifiées dont la date est passée
$campagnesPlanifiees = $db->select('campagne_config', [
    'id_compte' => $idCompte,
    'statut' => 'planifiee'
]);

$campagnesAAfficher = [];
foreach ($campagnesPlanifiees as $campagne) {
    if (!empty($campagne['date_planification']) && strtotime($campagne['date_planification']) <= strtotime($now)) {
        // Vérifier si déjà notifiée dans la session
        $sessionKey = 'campagne_notifiee_' . $campagne['id_campagne_config'];
        if (!isset($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = true;
            
            $campagnesAAfficher[] = [
                'id_campagne_config' => $campagne['id_campagne_config'],
                'nom_campagne' => $campagne['nom_campagne']
            ];
        }
    }
}

echo json_encode(['campagnes' => $campagnesAAfficher]);
?>