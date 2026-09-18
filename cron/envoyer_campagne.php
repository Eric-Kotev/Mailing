<?php
// ============================================
// CRON : Envoi automatique des campagnes planifiées échues
// Exécution recommandée : toutes les minutes
// ============================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/campagnes_functions.php';

global $db;

$now = date('Y-m-d H:i:s');
$logFile = __DIR__ . '/../logs/cron_campagnes.log';

function cronLog($msg) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
    error_log('[CRON CAMPAGNES] ' . $msg);
}

// ============================================
// VERROU ANTI-DOUBLON (empêche 2 crons de tourner en parallèle)
// ============================================
$lockFile = __DIR__ . '/../logs/cron_campagnes.lock';
$fp = fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    cronLog("Un autre cron est déjà en cours, abandon.");
    exit;
}

cronLog("=== Démarrage ===");

// ============================================
// RÉCUPÉRATION DES CAMPAGNES PLANIFIÉES ÉCHOUES
// ============================================
$campagnes = $db->select('campagne_config', ['statut' => 'planifiee']);

cronLog(count($campagnes) . " campagne(s) planifiée(s) en base");

foreach ($campagnes as $campagne) {
    if (empty($campagne['date_planification'])) continue;
    
    if (strtotime($campagne['date_planification']) > strtotime($now)) {
        continue; // Pas encore l'heure
    }
    
    $idCompte = $campagne['id_compte'];
    $idCampagneConfig = $campagne['id_campagne_config'];
    
    cronLog("Traitement campagne #{$idCampagneConfig} (compte #{$idCompte})");
    
    // Récupérer les messages en statut 'planifiee' de cette campagne
    $messages = $db->select('campagne', [
        'id_campagne_config' => $idCampagneConfig,
        'id_compte' => $idCompte,
        'statut' => 'planifiee'
    ]);
    
    if (empty($messages)) {
        cronLog("Aucun message 'planifiee' à traiter");
        continue;
    }
    
    foreach ($messages as $msg) {
        $type = $msg['type_campagne'];
        $message = $msg['message'] ?? '';
        $destinataires = json_decode($msg['destinataires'] ?? '[]', true);
        
        if (empty($destinataires)) {
            cronLog("Message #{$msg['id_campagne']} : aucun destinataire, marqué échoué");
            $db->update('campagne', [
                'statut' => 'echoue',
                'erreur' => 'Aucun destinataire',
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id_campagne' => $msg['id_campagne']]);
            continue;
        }
        
        // Passer en pret_a_envoyer AVANT l'envoi (évite double traitement)
        $db->update('campagne', ['statut' => 'pret_a_envoyer'], 
                    ['id_campagne' => $msg['id_campagne']]);
        
        $pieceJointe = null;
        if (!empty($msg['piece_jointe'])) {
            $pieceJointe = json_decode($msg['piece_jointe'], true);
        }
        
        switch ($type) {
            case 'sms':
                $resultat = envoyerSMS($idCompte, $idCampagneConfig, $campagne, $msg, $message, $destinataires);
                break;
            case 'whatsapp':
                $resultat = envoyerWhatsApp($idCompte, $idCampagneConfig, $campagne, $msg, $message, $destinataires, $pieceJointe);
                break;
            case 'email':
                $resultat = envoyerEmail($idCompte, $idCampagneConfig, $campagne, $msg, $message, $destinataires);
                break;
            default:
                cronLog("Type inconnu : {$type}");
                continue 2;
        }
        
        $statutLog = $resultat['success'] ? 'OK' : 'ERREUR';
        $detailLog = $resultat['message'] ?? $resultat['error'] ?? '';
        cronLog("Message #{$msg['id_campagne']} [{$type}] : {$statutLog} - {$detailLog}");
    }
    
    // Mettre à jour le statut global de la campagne
    mettreAJourStatutCampagne($idCampagneConfig, $idCompte);
}

cronLog("=== Fin ===");

flock($fp, LOCK_UN);
fclose($fp);