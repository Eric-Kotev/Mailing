<?php
global $db;

$idCompte = $_SESSION['user_id'];
$campagneId = $_GET['id'] ?? null;

if (!$campagneId) {
    header('Location: index.php?page=campagnes/index');
    exit;
}

// Récupérer la campagne
$campagne = $db->select('campagne_config', ['id_campagne_config' => $campagneId, 'id_compte' => $idCompte]);
if (empty($campagne)) {
    header('Location: index.php?page=campagnes/index');
    exit;
}
$campagne = $campagne[0];

// Récupérer tous les envois liés à cette campagne (exclure les brouillons)
// Récupérer d'abord tous les envois
$allEnvois = $db->select('campagne', ['id_campagne_config' => $campagneId], '*', 'created_at DESC');

// Filtrer pour exclure les brouillons
$envois = array_filter($allEnvois, function($e) {
    return $e['statut'] !== 'brouillon';
});

// Réindexer le tableau
$envois = array_values($envois);

$totalEnvois = count($envois);
$totalSucces = 0;
$totalErreurs = 0;
$totalWhatsApp = 0;
$totalSms = 0;
$totalAPreparer = 0;

foreach ($envois as $e) {
    $totalSucces += $e['nb_succes'];
    $totalErreurs += $e['nb_erreurs'];
    if ($e['type_campagne'] == 'whatsapp') {
        $totalWhatsApp++;
    } else {
        $totalSms++;
    }
    // Ne compter que les messages prêts à envoyer
    if ($e['statut'] == 'pret_a_envoyer') {
        $totalAPreparer++;
    }
}

// ============================================
// FONCTION DE MISE À JOUR DU STATUT GLOBAL
// ============================================
function mettreAJourStatutCampagne($idCampagneConfig, $idCompte) {
    global $db;
    
    // Récupérer tous les messages de la campagne
    $messages = $db->select('campagne', [
        'id_campagne_config' => $idCampagneConfig,
        'id_compte' => $idCompte
    ]);
    
    if (empty($messages)) {
        $db->update('campagne_config', [
            'statut' => 'brouillon'
        ], [
            'id_campagne_config' => $idCampagneConfig,
            'id_compte' => $idCompte
        ]);
        return;
    }
    
    $nbTotal = count($messages);
    $nbEnvoyes = 0;
    $nbEchoues = 0;
    $nbPret = 0;
    $nbBrouillon = 0;
    
    foreach ($messages as $msg) {
        switch ($msg['statut']) {
            case 'envoye':
                $nbEnvoyes++;
                break;
            case 'echoue':
                $nbEchoues++;
                break;
            case 'pret_a_envoyer':
                $nbPret++;
                break;
            case 'brouillon':
                $nbBrouillon++;
                break;
        }
    }
    
    // Déterminer le statut global
    if ($nbEnvoyes == $nbTotal) {
        $statut = 'envoyee';
        $sent_at = date('Y-m-d H:i:s');
    } elseif ($nbEchoues == $nbTotal) {
        $statut = 'echoue';
        $sent_at = null;
    } elseif ($nbEnvoyes > 0 || $nbEchoues > 0) {
        $statut = 'partiel';
        $sent_at = null;
    } elseif ($nbPret > 0) {
        $statut = 'pret_a_envoyer';
        $sent_at = null;
    } else {
        $statut = 'brouillon';
        $sent_at = null;
    }
    
    // Mettre à jour la campagne config
    $updateData = ['statut' => $statut];
    if ($statut === 'envoyee') {
        $updateData['sent_at'] = $sent_at;
    } else {
        $updateData['sent_at'] = null;
    }
    
    $db->update('campagne_config', $updateData, [
        'id_campagne_config' => $idCampagneConfig,
        'id_compte' => $idCompte
    ]);
}

// ============================================
// TRAITEMENT DE L'ENVOI D'UN MESSAGE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_envoyer_message'])) {
    $id_campagne_historique = $_POST['id_campagne_historique'] ?? null;
    
    if (!$id_campagne_historique) {
        $_SESSION['flash_error'] = "Message non trouvé";
        header('Location: index.php?page=campagnes/details&id=' . $campagneId);
        exit;
    }
    
    try {
        // Récupérer l'historique du message
        $historique = $db->select('campagne', [
            'id_campagne' => $id_campagne_historique,
            'id_compte' => $idCompte
        ]);
        
        if (empty($historique)) {
            $_SESSION['flash_error'] = "Message non trouvé";
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        $campagneData = $historique[0];
        $typeMessage = $campagneData['type_campagne'] ?? 'sms';
        
        // Récupérer les destinataires
        $destinataires = json_decode($campagneData['destinataires'] ?? '[]', true);
        if (empty($destinataires)) {
            $_SESSION['flash_error'] = "Aucun destinataire trouvé pour ce message";
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        // Récupérer le message
        $message = $campagneData['message'] ?? '';
        if (empty($message)) {
            $_SESSION['flash_error'] = "Aucun message trouvé";
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        // Récupérer la campagne config pour avoir provider et session
        $campagneConfig = $db->select('campagne_config', [
            'id_campagne_config' => $campagneId,
            'id_compte' => $idCompte
        ]);
        
        if (empty($campagneConfig)) {
            $_SESSION['flash_error'] = "Campagne non trouvée";
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        $campagne = $campagneConfig[0];
        
        // Récupérer les délais
        $min_delay = $_SESSION['min_delay'] ?? $campagne['min_delay'] ?? 60;
        $max_delay = $_SESSION['max_delay'] ?? $campagne['max_delay'] ?? 180;
        
        // Récupérer la pièce jointe si présente
        $pieceJointe = null;
        if (!empty($campagneData['piece_jointe'])) {
            $pieceJointe = json_decode($campagneData['piece_jointe'], true);
        }
        
        // Traiter l'envoi selon le type de message
        switch ($typeMessage) {
            case 'sms':
                $resultat = envoyerSMS($idCompte, $campagneId, $campagne, $campagneData, $message, $destinataires);
                break;
            case 'whatsapp':
                $resultat = envoyerWhatsApp($idCompte, $campagneId, $campagne, $campagneData, $message, $destinataires, $pieceJointe, $min_delay, $max_delay);
                break;
            case 'email':
                $resultat = envoyerEmail($idCompte, $campagneId, $campagne, $campagneData, $message, $destinataires);
                break;
            default:
                $_SESSION['flash_error'] = "Type de message non supporté: " . $typeMessage;
                header('Location: index.php?page=campagnes/details&id=' . $campagneId);
                exit;
        }
        
        if ($resultat['success']) {
            $_SESSION['flash_message'] = "✅ " . $resultat['message'];
        } else {
            $_SESSION['flash_error'] = "❌ Erreur lors de l'envoi : " . $resultat['error'];
        }
        
        // Mettre à jour le statut global de la campagne
        mettreAJourStatutCampagne($campagneId, $idCompte);
        
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "❌ Erreur lors de l'envoi : " . $e->getMessage();
    }
    
    header('Location: index.php?page=campagnes/details&id=' . $campagneId);
    exit;
}

// ============================================
// FONCTIONS D'ENVOI
// ============================================

function envoyerSMS($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires) {
    global $db;
    
    try {
        // Récupérer les infos depuis la campagne
        $device_id = $campagne['device_id'] ?? null;
        $appareilId = $campagne['appareil_id'] ?? null;
        $providerId = $campagne['provider_id'] ?? null;
        
        if (!$providerId) {
            return ['success' => false, 'error' => 'Provider non configuré'];
        }
        
        if (empty($device_id)) {
            return ['success' => false, 'error' => 'device_id non configuré. Veuillez recréer le message.'];
        }
        
        if (empty($appareilId)) {
            return ['success' => false, 'error' => 'appareil_id non configuré. Veuillez recréer le message.'];
        }
        
        $appareil = $db->select('sms_appareils', [
            'id_appareil' => $appareilId,
            'id_compte' => $idCompte
        ]);
        
        if (empty($appareil)) {
            return ['success' => false, 'error' => 'Appareil non trouvé'];
        }
        
        $device_name = $appareil[0]['device_name'] ?? 'Appareil SMS';
        $api_username = $appareil[0]['api_username'];
        $api_password = $appareil[0]['api_password'];
        
        if (empty($api_username) || empty($api_password)) {
            return ['success' => false, 'error' => 'Identifiants API SMS manquants pour cet appareil.'];
        }
        
        $recipients = [];
        foreach ($destinataires as $dest) {
            if (preg_match('/\(([^)]+)\)/', $dest, $matches)) {
                $telephone = $matches[1];
                $telephone = preg_replace('/[^0-9]/', '', $telephone);
                
                if (strlen($telephone) == 10 && substr($telephone, 0, 1) == '0') {
                    $telephone = '261' . substr($telephone, 1);
                }
                if (substr($telephone, 0, 3) != '261' && strlen($telephone) > 0) {
                    $telephone = '261' . $telephone;
                }
                $recipients[] = '+' . $telephone;
            }
        }
        
        if (empty($recipients)) {
            return ['success' => false, 'error' => 'Aucun numéro de téléphone valide trouvé'];
        }
        
        $apiUrl = 'http://164.68.103.147:8085/api.php/sendBulk';
        
        $data = [
            'text' => $message,
            'recipients' => $recipients,
            'api_username' => $api_username,
            'api_password' => $api_password,
            'device_id' => $device_id,
            'user_id' => 'campagne_' . $id_campagne . '_' . date('Ymd_His')
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $statut = ($httpCode === 200) ? 'envoye' : 'echoue';
        $nb_succes = ($httpCode === 200) ? count($recipients) : 0;
        $nb_erreurs = ($httpCode === 200) ? 0 : count($recipients);
        
        $db->update('campagne', [
            'statut' => $statut,
            'nb_envoyes' => count($recipients),
            'nb_succes' => $nb_succes,
            'nb_erreurs' => $nb_erreurs,
            'appareil_utilise' => $device_name . ' (' . $device_id . ')',
            'erreur' => ($httpCode !== 200) ? $response : null
        ], ['id_campagne' => $campagneData['id_campagne']]);
        
        if ($httpCode === 200) {
            return ['success' => true, 'message' => count($recipients) . ' SMS envoyés avec succès'];
        } else {
            return ['success' => false, 'error' => 'Erreur API (HTTP ' . $httpCode . '): ' . substr($response, 0, 200)];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function envoyerWhatsApp($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires, $pieceJointe = null, $min_delay = 60, $max_delay = 180) {
    global $db;
    
    try {
        $session = $db->select('whatsapp_sessions', [
            'id_compte' => $idCompte,
            'est_active' => true
        ]);
        
        if (empty($session)) {
            $session = $db->select('whatsapp_sessions', [
                'id_compte' => $idCompte
            ], '*', 'created_at DESC', 1);
            
            if (empty($session)) {
                return ['success' => false, 'error' => 'Aucune session WhatsApp configurée'];
            }
            
            $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $session[0]['id_session']]);
        }
        
        $whatsappSession = $session[0]['nom_session'];
        
        $apiUrl = 'http://164.68.103.147:8081/api/controller.php';
        $apiKey = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
        
        $contacts = [];
        foreach ($destinataires as $dest) {
            if (preg_match('/\(([^)]+)\)/', $dest, $matches)) {
                $telephone = $matches[1];
                $telephone = preg_replace('/[^0-9]/', '', $telephone);
                if (strlen($telephone) == 10 && substr($telephone, 0, 1) == '0') {
                    $telephone = '261' . substr($telephone, 1);
                }
                if (substr($telephone, 0, 3) != '261') {
                    $telephone = '261' . $telephone;
                }
                $contacts[] = $telephone;
            }
        }
        
        if (empty($contacts)) {
            return ['success' => false, 'error' => 'Aucun numéro de téléphone valide trouvé'];
        }
        
        $fichierData = null;
        if ($pieceJointe && isset($pieceJointe['url']) && !empty($pieceJointe['url'])) {
            $fileUrl = $pieceJointe['url'];
            $fileMimeType = $pieceJointe['mime_type'] ?? 'application/octet-stream';
            $fileName = $pieceJointe['nom'] ?? 'fichier';
            
            $ch = curl_init($fileUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $fileContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && !empty($fileContent)) {
                $fileData = base64_encode($fileContent);
                
                $mediaType = 'file';
                if (strpos($fileMimeType, 'image/') !== false) {
                    $mediaType = 'image';
                } elseif (strpos($fileMimeType, 'video/') !== false) {
                    $mediaType = 'video';
                } elseif (strpos($fileMimeType, 'audio/') !== false) {
                    $mediaType = 'voice';
                }
                
                $fichierData = [
                    'type' => $mediaType,
                    'payload' => [
                        'data' => $fileData,
                        'mimetype' => $fileMimeType,
                        'filename' => $fileName
                    ],
                    'fichier_pret' => true
                ];
            }
        }
        
        $succes = 0;
        $echecs = 0;
        $erreurs = [];
        
        foreach ($contacts as $index => $contact) {
            if ($index > 0) {
                $delay = rand($min_delay, $max_delay);
                sleep($delay);
            }
            
            if ($fichierData && $fichierData['fichier_pret']) {
                $data = [
                    'session' => $whatsappSession,
                    'type' => $fichierData['type'],
                    'contacts' => [$contact],
                    'payload' => $fichierData['payload'],
                    'min_delay' => 0,
                    'max_delay' => 0
                ];
                
                if ($fichierData['type'] !== 'text' && !empty($message) && $fichierData['type'] !== 'voice') {
                    $data['payload']['caption'] = $message;
                }
            } else {
                $data = [
                    'session' => $whatsappSession,
                    'type' => 'text',
                    'contacts' => [$contact],
                    'payload' => ['text' => $message],
                    'min_delay' => 0,
                    'max_delay' => 0
                ];
            }
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl . '/messages/send-bulk');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Controller-Key: ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 || $httpCode === 201) {
                $succes++;
            } else {
                $echecs++;
                $erreurs[] = $contact . ': ' . substr($response, 0, 100);
            }
        }
        
        if ($echecs > 0 && $succes > 0) {
            $statut = 'partiel';
        } elseif ($echecs > 0 && $succes == 0) {
            $statut = 'echoue';
        } else {
            $statut = 'envoye';
        }
        
        $db->update('campagne', [
            'statut' => $statut,
            'nb_envoyes' => count($contacts),
            'nb_succes' => $succes,
            'nb_erreurs' => $echecs,
            'appareil_utilise' => $whatsappSession,
            'erreur' => !empty($erreurs) ? json_encode($erreurs) : null
        ], ['id_campagne' => $campagneData['id_campagne']]);
        
        if ($echecs == 0) {
            return ['success' => true, 'message' => $succes . ' messages WhatsApp envoyés avec succès'];
        } elseif ($succes > 0) {
            return ['success' => true, 'message' => $succes . ' messages envoyés, ' . $echecs . ' échecs'];
        } else {
            return ['success' => false, 'error' => 'Tous les messages ont échoué'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function envoyerEmail($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires) {
    return ['success' => false, 'error' => 'Envoi d\'email non encore implémenté'];
}

$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
unset($_SESSION['flash_message']);
unset($_SESSION['flash_error']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($campagne['nom_campagne']) ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { 
            margin: 0; 
            background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 16px 24px;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-brouillon { background: #f3f4f6; color: #4b5563; }
        .status-planifiee { background: #fef3c7; color: #92400e; }
        .status-envoyee { background: #dcfce7; color: #166534; }
        .status-pret_a_envoyer { background: #dbeafe; color: #1e40af; }
        .status-partiel { background: #fef3c7; color: #92400e; }
        .status-echoue { background: #fee2e2; color: #991b1b; }
        
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideInRight 0.3s ease-out;
        }
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .toast-notification .toast-content {
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 14px;
            font-weight: 500;
        }
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        
        .btn-send-message {
            background: #10b981;
            color: white;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-send-message:hover {
            background: #059669;
        }
        .btn-send-message:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .header-title {
            font-size: 24px;
            font-weight: 700;
        }
        .header-subtitle {
            font-size: 15px;
            color: #6b7280;
        }
        
        .stat-card {
            padding: 16px;
            border-radius: 12px;
        }
        .stat-card .stat-number {
            font-size: 28px;
            font-weight: 800;
        }
        .stat-card .stat-label {
            font-size: 13px;
            color: #6b7280;
            margin-top: 2px;
        }
        
        .table-container {
            overflow-x: auto;
        }
        .table-container table {
            width: 100%;
            font-size: 14px;
        }
        .table-container th {
            padding: 10px 16px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            text-align: left;
        }
        .table-container td {
            padding: 10px 16px;
            font-size: 14px;
        }
        .table-container th.text-center,
        .table-container td.text-center {
            text-align: center;
        }
        
        .filter-container {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-top: 12px;
        }
        .filter-container label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }
        .filter-container select {
            padding: 6px 14px;
            border: 1.5px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            cursor: pointer;
            min-width: 140px;
        }
        .filter-container select:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.12);
        }
        .filter-container .filter-info {
            font-size: 13px;
            color: #6b7280;
        }
        .filter-container .btn-clear-filter {
            background: #e5e7eb;
            color: #4b5563;
            padding: 6px 14px;
            border-radius: 6px;
            border: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-container .btn-clear-filter:hover {
            background: #d1d5db;
        }
        
        #searchInput {
            padding: 8px 12px 8px 38px;
            font-size: 14px;
            border-radius: 8px;
            border: 1.5px solid #d1d5db;
            width: 100%;
        }
        #searchInput:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .stat-type {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .stat-type-whatsapp { background: #d1fae5; color: #065f46; }
        .stat-type-sms { background: #dbeafe; color: #1e40af; }
        
        .envoi-row {
            cursor: pointer;
            transition: background 0.15s;
        }
        .envoi-row:hover {
            background-color: #f9fafb;
        }
        
        #detailsModal .modal-content {
            max-width: 800px;
            max-height: 90vh;
        }
        #detailsModal .modal-header {
            padding: 16px 24px;
        }
        #detailsModal .modal-header h3 {
            font-size: 20px;
        }
        #detailsModal .modal-body {
            padding: 24px;
        }
        #detailsModal .modal-footer {
            padding: 12px 24px;
        }
        
        .grid-cols-2 { grid-template-columns: repeat(2, 1fr); }
        .grid-cols-5 { grid-template-columns: repeat(5, 1fr); }
        .gap-4 { gap: 16px; }
        
        @media (max-width: 768px) {
            .container { padding: 12px; }
            .header-title { font-size: 20px; }
            .stat-card .stat-number { font-size: 22px; }
            .filter-container { flex-direction: column; align-items: stretch; }
            .filter-container select { width: 100%; }
            .grid-cols-5 { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- ===== EN-TÊTE ===== -->
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div class="flex items-center">
            <a href="index.php?page=campagnes/creer" class="text-blue-600 hover:text-blue-800 mr-4 font-medium">
                <i class="fas fa-arrow-left"></i> Retour
            </a>
            <div class="bg-purple-100 p-3 rounded-full mr-4">
                <i class="fas fa-bullhorn text-purple-600 text-xl"></i>
            </div>
            <div>
                <h1 class="header-title text-gray-800"><?= htmlspecialchars($campagne['nom_campagne']) ?></h1>
                <p class="header-subtitle">Gérez les messages de cette campagne</p>
            </div>
        </div>
        <a href="index.php?page=campagnes/choix_type&campagne_id=<?= $campagneId ?>" 
           class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition font-semibold text-sm">
            <i class="fas fa-plus mr-2"></i>Nouveau message
        </a>
    </div>

    <!-- ===== TOASTS ===== -->
    <?php if ($flashMessage): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?= addslashes($flashMessage) ?>', 'success');
            });
        </script>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?= addslashes($flashError) ?>', 'error');
            });
        </script>
    <?php endif; ?>

    <!-- ===== INFOS CAMPAGNE ===== -->
    <div class="bg-white rounded-xl shadow-md p-5 mb-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <label class="text-xs text-gray-500 uppercase font-semibold">Date de création</label>
                <div class="mt-1 font-medium"><?= date('d/m/Y H:i', strtotime($campagne['created_at'])) ?></div>
            </div>
            <?php if ($campagne['date_planification']): ?>
                <div>
                    <label class="text-xs text-gray-500 uppercase font-semibold">Planifiée le</label>
                    <div class="mt-1 font-medium"><?= date('d/m/Y H:i', strtotime($campagne['date_planification'])) ?></div>
                </div>
            <?php endif; ?>
            <div>
                <label class="text-xs text-gray-500 uppercase font-semibold">Statut</label>
                <div class="mt-1">
                    <span class="status-badge status-<?= $campagne['statut'] ?>">
                        <?php
                        $statusText = [
                            'brouillon' => 'Brouillon',
                            'planifiee' => 'Planifiée',
                            'envoyee' => 'Envoyée',
                            'pret_a_envoyer' => 'Prêt à envoyer',
                            'partiel' => 'Partiel',
                            'echoue' => 'Échoué'
                        ];
                        echo $statusText[$campagne['statut']] ?? $campagne['statut'];
                        ?>
                    </span>
                </div>
            </div>
            <div>
                <label class="text-xs text-gray-500 uppercase font-semibold">Messages en attente</label>
                <div class="mt-1 text-lg font-bold text-orange-600"><?= $totalAPreparer ?></div>
            </div>
        </div>
    </div>

    <!-- ===== STATISTIQUES ===== -->
    <div class="grid grid-cols-5 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-md stat-card text-center">
            <div class="stat-number text-blue-600"><?= $totalEnvois ?></div>
            <div class="stat-label">Messages envoyés</div>
        </div>
        <div class="bg-white rounded-xl shadow-md stat-card text-center">
            <div class="stat-number text-green-600"><?= $totalSucces ?></div>
            <div class="stat-label">Destinataires touchés</div>
        </div>
        <div class="bg-white rounded-xl shadow-md stat-card text-center">
            <div class="stat-number text-red-600"><?= $totalErreurs ?></div>
            <div class="stat-label">Échecs</div>
        </div>
        <div class="bg-white rounded-xl shadow-md stat-card text-center">
            <div class="stat-number text-green-600"><?= $totalWhatsApp ?></div>
            <div class="stat-label">
                <span class="stat-type stat-type-whatsapp"><i class="fab fa-whatsapp mr-1"></i> WhatsApp</span>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-md stat-card text-center">
            <div class="stat-number text-blue-600"><?= $totalSms ?></div>
            <div class="stat-label">
                <span class="stat-type stat-type-sms"><i class="fas fa-comment-dots mr-1"></i> SMS</span>
            </div>
        </div>
    </div>

    <!-- ===== FILTRES ===== -->
    <div class="bg-white rounded-xl shadow-md p-5 mb-6">
        <div class="relative">
            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            <input type="text" id="searchInput" placeholder="Rechercher un message (date, contenu, statut...)" 
                   class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
        </div>
        
        <div class="filter-container">
            <label for="filterType"><i class="fas fa-filter mr-1"></i> Type :</label>
            <select id="filterType">
                <option value="all">Tous les types</option>
                <option value="whatsapp">📱 WhatsApp</option>
                <option value="sms">💬 SMS</option>
            </select>
            
            <label for="filterStatus" class="ml-1"><i class="fas fa-check-circle mr-1"></i> Statut :</label>
            <select id="filterStatus">
                <option value="all">Tous les statuts</option>
                <option value="envoye">Envoyé</option>
                <option value="echoue">Échoué</option>
                <option value="partiel">Partiel</option>
                <option value="pret_a_envoyer">Prêt à envoyer</option>
            </select>
            
            <button id="clearFilters" class="btn-clear-filter">
                <i class="fas fa-times mr-1"></i> Effacer
            </button>
            
            <span class="filter-info">
                <span id="visibleCount"><?= $totalEnvois ?></span> message(s)
            </span>
        </div>
    </div>

    <!-- ===== LISTE DES ENVOIS ===== -->
    <div class="bg-white rounded-xl shadow-md overflow-hidden">
        <div class="p-4 border-b bg-gray-50">
            <h2 class="text-lg font-bold">Historique des envois</h2>
            <p class="text-sm text-gray-500">Cliquez sur un message pour voir les détails</p>
        </div>
        
        <?php if (empty($envois)): ?>
            <div class="text-center py-12">
                <i class="fas fa-envelope text-4xl text-gray-300 mb-3"></i>
                <p class="text-gray-500">Aucun message.</p>
                <a href="index.php?page=campagnes/choix_type&campagne_id=<?= $campagneId ?>" 
                   class="text-green-600 mt-2 inline-block font-semibold">
                    <i class="fas fa-plus mr-1"></i>Créer votre premier message
                </a>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Message</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Destinataires</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Statut</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="envoisTableBody">
                        <?php foreach ($envois as $envoi): 
                            $statutClass = $envoi['statut'] == 'envoye' ? 'text-green-600' : ($envoi['statut'] == 'partiel' ? 'text-yellow-600' : ($envoi['statut'] == 'pret_a_envoyer' ? 'text-blue-600' : 'text-red-600'));
                            $statutIcon = $envoi['statut'] == 'envoye' ? 'fa-check-circle' : ($envoi['statut'] == 'partiel' ? 'fa-exclamation-triangle' : ($envoi['statut'] == 'pret_a_envoyer' ? 'fa-clock' : 'fa-exclamation-circle'));
                            $statutLabel = $envoi['statut'] == 'envoye' ? 'Envoyé' : ($envoi['statut'] == 'partiel' ? 'Partiel' : ($envoi['statut'] == 'pret_a_envoyer' ? 'Prêt à envoyer' : 'Échoué'));
                            $typeClass = $envoi['type_campagne'] == 'whatsapp' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700';
                            $typeIcon = $envoi['type_campagne'] == 'whatsapp' ? 'fab fa-whatsapp' : 'fas fa-comment-dots';
                            $typeLabel = $envoi['type_campagne'] == 'whatsapp' ? 'WhatsApp' : 'SMS';
                        ?>
                            <tr class="envoi-row" 
                                data-id="<?= $envoi['id_campagne'] ?>"
                                data-type="<?= $envoi['type_campagne'] ?>"
                                data-status="<?= $envoi['statut'] ?>"
                                onclick="showDetails(<?= htmlspecialchars(json_encode($envoi)) ?>)">
                                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                                    <?= date('d/m/Y H:i', strtotime($envoi['created_at'])) ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="<?= $typeClass ?> px-2 py-1 rounded-full text-xs font-semibold">
                                        <i class="<?= $typeIcon ?> mr-1"></i>
                                        <?= $typeLabel ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-gray-800 max-w-xs truncate" title="<?= htmlspecialchars($envoi['message']) ?>">
                                        <?= htmlspecialchars(substr($envoi['message'], 0, 50)) ?>...
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center font-medium"><?= $envoi['nb_destinataires'] ?></td>
                                <td class="px-4 py-3 text-center">
                                    <i class="fas <?= $statutIcon ?> <?= $statutClass ?> mr-1"></i>
                                    <span class="text-sm font-medium <?= $statutClass ?>"><?= $statutLabel ?></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <?php if ($envoi['statut'] == 'pret_a_envoyer'): ?>
                                            <form method="POST" style="display:inline;" id="sendForm_<?= $envoi['id_campagne'] ?>">
                                                <input type="hidden" name="action_envoyer_message" value="1">
                                                <input type="hidden" name="id_campagne_historique" value="<?= $envoi['id_campagne'] ?>">
                                                <button type="submit" class="btn-send-message" title="Envoyer le message">
                                                    <i class="fas fa-paper-plane"></i> Envoyer
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <button onclick="event.stopPropagation(); showDetails(<?= htmlspecialchars(json_encode($envoi)) ?>)" 
                                                class="text-blue-600 hover:text-blue-800" title="Voir détails">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== MODAL DÉTAILS ===== -->
<div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl mx-4 max-h-[90vh] overflow-y-auto transform transition-all duration-300 scale-95 opacity-0" id="modalContainer">
        <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 flex justify-between items-center rounded-t-2xl">
            <div class="flex items-center">
                <div id="modalIcon" class="w-10 h-10 rounded-full flex items-center justify-center mr-3">
                    <i id="modalIconImg" class="text-xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800" id="modalTitle"></h3>
            </div>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition-colors text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="p-6" id="modalContent">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-500"></i>
                <p class="text-gray-500 mt-2">Chargement...</p>
            </div>
        </div>
        
        <div class="sticky bottom-0 bg-gray-50 border-t border-gray-200 px-6 py-3 flex justify-end rounded-b-2xl">
            <button onclick="closeModal()" class="px-5 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition font-medium">
                Fermer
            </button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
// ===== TOAST NOTIFICATION =====
function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const colors = { success: '#10b981', error: '#ef4444' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s';
        setTimeout(() => toast.remove(), 500);
    }, 3000);
}

// ===== FILTRES =====
const searchInput = document.getElementById('searchInput');
const filterType = document.getElementById('filterType');
const filterStatus = document.getElementById('filterStatus');
const envoisRows = document.querySelectorAll('.envoi-row');
const visibleCountSpan = document.getElementById('visibleCount');

function applyFilters() {
    const searchTerm = searchInput.value.toLowerCase().trim();
    const typeFilter = filterType.value;
    const statusFilter = filterStatus.value;
    let visibleCount = 0;
    
    envoisRows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const type = row.dataset.type || '';
        const status = row.dataset.status || '';
        let show = true;
        
        // Ignorer les brouillons (ils ne devraient pas être présents, mais au cas où)
        if (status === 'brouillon') {
            row.style.display = 'none';
            return;
        }
        
        if (searchTerm !== '' && !text.includes(searchTerm)) show = false;
        if (show && typeFilter !== 'all' && type !== typeFilter) show = false;
        if (show && statusFilter !== 'all' && status !== statusFilter) show = false;
        
        if (show) { row.style.display = ''; visibleCount++; } 
        else { row.style.display = 'none'; }
    });
    
    visibleCountSpan.textContent = visibleCount;
    
    const noResult = document.getElementById('noResultMessage');
    if (visibleCount === 0 && envoisRows.length > 0) {
        if (!noResult) {
            const tbody = document.getElementById('envoisTableBody');
            const tr = document.createElement('tr');
            tr.id = 'noResultMessage';
            tr.innerHTML = `
                <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                    <i class="fas fa-search text-3xl mb-2 block"></i>
                    Aucun message ne correspond aux filtres sélectionnés.
                    <div class="mt-2">
                        <button onclick="resetFilters()" class="text-purple-600 hover:text-purple-800 font-semibold">
                            <i class="fas fa-undo mr-1"></i> Réinitialiser
                        </button>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        }
    } else {
        if (noResult) noResult.remove();
    }
}

function resetFilters() {
    searchInput.value = '';
    filterType.value = 'all';
    filterStatus.value = 'all';
    applyFilters();
}

searchInput.addEventListener('input', applyFilters);
filterType.addEventListener('change', applyFilters);
filterStatus.addEventListener('change', applyFilters);
document.getElementById('clearFilters').addEventListener('click', resetFilters);

// ===== MODAL DÉTAILS =====
function showDetails(envoi) {
    const modal = document.getElementById('detailsModal');
    const modalContainer = document.getElementById('modalContainer');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    const modalIcon = document.getElementById('modalIcon');
    const modalIconImg = document.getElementById('modalIconImg');
    
    if (envoi.type_campagne === 'whatsapp') {
        modalIcon.className = 'w-10 h-10 rounded-full bg-green-100 flex items-center justify-center mr-3';
        modalIconImg.className = 'fab fa-whatsapp text-green-600 text-xl';
    } else {
        modalIcon.className = 'w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center mr-3';
        modalIconImg.className = 'fas fa-comment-dots text-blue-600 text-xl';
    }
    
    modalTitle.textContent = envoi.titre || 'Détails du message';
    
    let destinataires = [];
    try { destinataires = JSON.parse(envoi.destinataires); } 
    catch(e) { destinataires = [envoi.destinataires]; }
    
    let destHtml = '';
    if (destinataires && destinataires.length > 0) {
        destHtml = '<div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto">';
        for (let i = 0; i < destinataires.length; i++) {
            destHtml += '<div class="flex items-center p-2 bg-gray-50 rounded-lg">' +
                        '<i class="fas fa-user-circle text-gray-400 mr-2"></i>' +
                        '<span class="text-sm">' + escapeHtml(destinataires[i]) + '</span>' +
                        '</div>';
        }
        destHtml += '</div>';
    } else {
        destHtml = '<p class="text-gray-500 italic">Aucun destinataire enregistré</p>';
    }
    
    const statusBadge = envoi.statut === 'envoye' 
        ? '<span class="bg-green-100 text-green-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-check-circle mr-1"></i>Envoyé</span>'
        : (envoi.statut === 'partiel' 
            ? '<span class="bg-yellow-100 text-yellow-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i>Partiel</span>'
            : (envoi.statut === 'pret_a_envoyer'
                ? '<span class="bg-blue-100 text-blue-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-clock mr-1"></i>Prêt à envoyer</span>'
                : '<span class="bg-red-100 text-red-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-exclamation-circle mr-1"></i>Échoué</span>'));
    
    const typeBadge = envoi.type_campagne === 'whatsapp'
        ? '<span class="bg-green-100 text-green-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fab fa-whatsapp mr-1"></i>WhatsApp</span>'
        : '<span class="bg-blue-100 text-blue-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-comment-dots mr-1"></i>SMS</span>';
    
    modalContent.innerHTML = `
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 font-semibold mb-1">Date d'envoi</div>
                    <div class="font-medium">${formatDate(envoi.created_at)}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 font-semibold mb-1">Statut</div>
                    <div>${statusBadge}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 font-semibold mb-1">Appareil / Session</div>
                    <div class="text-sm font-medium">${escapeHtml(envoi.appareil_utilise || '-')}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 font-semibold mb-1">Type</div>
                    <div>${typeBadge}</div>
                </div>
            </div>
            
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-1">Message</div>
                <div class="bg-gray-50 rounded-lg p-3 max-h-32 overflow-y-auto">
                    <p class="text-sm text-gray-700 whitespace-pre-wrap">${escapeHtml(envoi.message || '-')}</p>
                </div>
            </div>
            
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-1">Statistiques d'envoi</div>
                <div class="grid grid-cols-3 gap-3">
                    <div class="bg-blue-50 rounded-lg p-3 text-center">
                        <div class="text-2xl font-bold text-blue-600">${envoi.nb_destinataires || 0}</div>
                        <div class="text-xs text-gray-500">Total</div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-3 text-center">
                        <div class="text-2xl font-bold text-green-600">${envoi.nb_succes || 0}</div>
                        <div class="text-xs text-gray-500">Succès</div>
                    </div>
                    <div class="bg-red-50 rounded-lg p-3 text-center">
                        <div class="text-2xl font-bold text-red-600">${envoi.nb_erreurs || 0}</div>
                        <div class="text-xs text-gray-500">Échecs</div>
                    </div>
                </div>
            </div>
            
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-1">Destinataires (${envoi.nb_destinataires || 0})</div>
                <div class="bg-gray-50 rounded-lg p-3">
                    ${destHtml}
                </div>
            </div>
            ${envoi.erreur ? `
            <div>
                <div class="text-xs text-red-500 font-semibold mb-1">Message d'erreur</div>
                <div class="bg-red-50 border-l-4 border-red-500 rounded-lg p-3">
                    <p class="text-sm text-red-700">${escapeHtml(envoi.erreur)}</p>
                </div>
            </div>
            ` : ''}
        </div>
    `;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => modalContainer.classList.remove('scale-95', 'opacity-0'), 10);
}

function closeModal() {
    const modal = document.getElementById('detailsModal');
    const modalContainer = document.getElementById('modalContainer');
    
    modalContainer.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 300);
}

function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('fr-FR') + ' ' + date.toLocaleTimeString('fr-FR');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

document.getElementById('detailsModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

</body>
</html>