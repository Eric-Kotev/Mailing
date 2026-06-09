<?php
header('Content-Type: application/json');
error_reporting(0);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

$id_campagne = $_GET['id'] ?? '';
if (empty($id_campagne)) {
    echo json_encode(['success' => false, 'error' => 'ID campagne requis']);
    exit;
}

$idCompte = $_SESSION['user_id'];

try {
    // Récupérer la campagne
    $campagne = $db->select('campagne', [
        'id_campagne' => $id_campagne,
        'id_compte' => $idCompte
    ]);
    
    if (empty($campagne)) {
        echo json_encode(['success' => false, 'error' => 'Campagne non trouvée']);
        exit;
    }
    
    $c = $campagne[0];
    
    // Décoder les destinataires (stockés en JSON)
    $destinataires = [];
    if (!empty($c['destinataires'])) {
        $destinataires = json_decode($c['destinataires'], true);
        if (!is_array($destinataires)) {
            $destinataires = [$c['destinataires']];
        }
    }
    
    // Formater la date
    $date = date('d/m/Y H:i:s', strtotime($c['created_at']));
    
    // Retourner les données
    echo json_encode([
        'success' => true,
        'id' => $c['id_campagne'],
        'titre' => $c['titre'],
        'date' => $date,
        'type' => $c['type_campagne'],
        'message' => $c['message'],
        'destinataires' => $destinataires,
        'nb_destinataires' => (int)$c['nb_destinataires'],
        'nb_envoyes' => (int)$c['nb_envoyes'],
        'nb_succes' => (int)$c['nb_succes'],
        'nb_erreurs' => (int)$c['nb_erreurs'],
        'appareil_utilise' => $c['appareil_utilise'],
        'statut' => $c['statut']
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>