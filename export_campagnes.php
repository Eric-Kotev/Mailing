<?php
// Désactiver l'affichage des erreurs pour ne pas polluer le CSV
error_reporting(0);
ini_set('display_errors', 0);

// Nettoyer les buffers de sortie
while (ob_get_level()) {
    ob_end_clean();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// session_start() est déjà dans auth.php, ne pas rappeler

if (!isset($_SESSION['user_id'])) {
    die('Non autorisé');
}

$idCompte = $_SESSION['user_id'];
$type = $_GET['type'] ?? 'tous';

// Récupérer les campagnes
if ($type === 'tous') {
    $campagnes = $db->select('campagne', ['id_compte' => $idCompte], '*', 'created_at DESC');
} else {
    $campagnes = $db->select('campagne', ['id_compte' => $idCompte, 'type_campagne' => $type], '*', 'created_at DESC');
}

// Nom du fichier
$filename = 'campagnes_' . date('Y-m-d') . ($type !== 'tous' ? '_' . $type : '') . '.csv';

// En-têtes pour le téléchargement
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Créer le fichier CSV
$output = fopen('php://output', 'w');

// Ajouter le BOM pour UTF-8 (compatible Excel avec accents)
fwrite($output, "\xEF\xBB\xBF");

// En-têtes des colonnes
fputcsv($output, [
    'ID',
    'Date',
    'Type',
    'Titre',
    'Message',
    'Destinataires',
    'Nb Destinataires',
    'Envoyés',
    'Succès',
    'Échecs',
    'Appareil/Session',
    'Statut',
    'Erreur'
]);

foreach ($campagnes as $c) {
    // Décoder les destinataires
    $destinataires = '';
    $destArray = json_decode($c['destinataires'], true);
    if (is_array($destArray)) {
        $destinataires = implode('; ', $destArray);
    } else {
        $destinataires = $c['destinataires'];
    }
    
    // Traduire le type
    $typeLabel = $c['type_campagne'] == 'whatsapp' ? 'WhatsApp' : 'SMS';
    
    // Traduire le statut
    $statutLabel = '';
    switch ($c['statut']) {
        case 'envoye': $statutLabel = 'Envoyé'; break;
        case 'en_cours': $statutLabel = 'En cours'; break;
        case 'echoue': $statutLabel = 'Échoué'; break;
        default: $statutLabel = $c['statut'];
    }
    
    // Nettoyer l'erreur si elle existe
    $erreur = '';
    if (!empty($c['erreur'])) {
        $erreur = $c['erreur'];
        // Tronquer si trop long
        if (strlen($erreur) > 200) {
            $erreur = substr($erreur, 0, 200) . '...';
        }
    }
    
    fputcsv($output, [
        $c['id_campagne'],
        date('d/m/Y H:i:s', strtotime($c['created_at'])),
        $typeLabel,
        $c['titre'],
        $c['message'],
        $destinataires,
        $c['nb_destinataires'],
        $c['nb_envoyes'],
        $c['nb_succes'],
        $c['nb_erreurs'],
        $c['appareil_utilise'],
        $statutLabel,
        $erreur
    ]);
}

fclose($output);
exit;
?>