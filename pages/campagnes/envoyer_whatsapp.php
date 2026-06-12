<?php
global $db;

$idCompte = $_SESSION['user_id'];

// ============================================
// RÉCUPÉRATION DE LA CAMPAGNE CONFIG
// ============================================
$campagneConfigId = $_POST['campagne_config_id'] ?? $_SESSION['campagne_config_id'] ?? null;

if (!$campagneConfigId) {
    header('Location: index.php?page=campagnes/creer');
    exit;
}

// Récupérer les infos de la campagne config
$campagneConfig = $db->select('campagne_config', [
    'id_campagne_config' => $campagneConfigId,
    'id_compte' => $idCompte
]);

if (empty($campagneConfig)) {
    header('Location: index.php?page=campagnes/creer');
    exit;
}

$campagne = $campagneConfig[0];

// Nettoyer la session
unset($_SESSION['campagne_config_id']);

// Récupérer la session WhatsApp active
$sessions = $db->select('whatsapp_sessions', [
    'id_compte' => $idCompte,
    'est_active' => true
]);

$whatsappSession = null;
if (!empty($sessions)) {
    $whatsappSession = $sessions[0]['nom_session'];
} else {
    $sessions = $db->select('whatsapp_sessions', ['id_compte' => $idCompte], '*', 'created_at.desc');
    if (!empty($sessions)) {
        $whatsappSession = $sessions[0]['nom_session'];
        $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $sessions[0]['id_session']]);
    }
}

if (!$whatsappSession) {
    header('Location: index.php?page=campagnes/choix');
    exit;
}

// Récupérer les IDs des contacts blacklistés
$blacklist = $db->select('blacklist');
$blacklistIds = [];
foreach ($blacklist as $b) {
    if (!empty($b['id_contact'])) {
        $blacklistIds[] = $b['id_contact'];
    }
}

// Récupérer tous les contacts du compte
$tousContacts = $db->select('contact', ['id_compte' => $idCompte]);

// Filtrer les contacts non blacklistés
$contacts = [];
foreach ($tousContacts as $contact) {
    if (!in_array($contact['id_contact'], $blacklistIds)) {
        $contacts[] = $contact;
    }
}

// Récupérer les listes avec le nombre de contacts
$listesBrutes = $db->select('liste', ['id_compte' => $idCompte]);
$listes = [];

foreach ($listesBrutes as $liste) {
    $listeContacts = $db->select('liste_contact', ['id_liste' => $liste['id_liste']]);
    $nbContacts = 0;
    foreach ($listeContacts as $lc) {
        if (!in_array($lc['id_contact'], $blacklistIds)) {
            $nbContacts++;
        }
    }
    
    $listes[] = [
        'id_liste' => $liste['id_liste'],
        'nom_liste' => $liste['nom_liste'],
        'nombre_contacts' => $nbContacts
    ];
}

$error = '';
$success = '';
$resultats = [];

// Fonction pour formater correctement un numéro WhatsApp
function formatWhatsAppNumber($telephone) {
    if (empty($telephone)) {
        return null;
    }
    
    $telephone = preg_replace('/[^0-9]/', '', $telephone);
    
    if (strlen($telephone) == 9) {
        $telephone = '261' . $telephone;
    } elseif (strlen($telephone) == 10 && substr($telephone, 0, 1) == '0') {
        $telephone = '33' . substr($telephone, 1);
    } elseif (strlen($telephone) == 9 && substr($telephone, 0, 1) != '0') {
        $telephone = '261' . $telephone;
    }
    
    if (strlen($telephone) < 10 || strlen($telephone) > 15) {
        return null;
    }
    
    return $telephone . '@c.us';
}

// Fonction pour vérifier le statut de la session WhatsApp
function checkWhatsAppSession($whatsappSession, $apiUrl, $apiKey) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . '/sessions/' . $whatsappSession . '/status');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type': 'application/json',
        'X-Controller-Key: ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return false;
    }
    
    $result = json_decode($response, true);
    $status = $result['status'] ?? $result['state'] ?? '';
    return ($status === 'WORKING' || $status === 'connected');
}

// Fonction pour envoyer un message WhatsApp
function envoyerMessageWhatsApp($chatId, $message, $hasFile, $hasAudio, $whatsappSession, $contactNom, $apiUrl, $apiKey, $campagneConfigId = null) {
    global $db, $idCompte;
    
    $endpoint = '/messages/send-text';
    $data = [];
    
    // Récupérer les fichiers si présents
    if ($hasAudio) {
        $audioData = $_POST['audio_data'] ?? '';
        $base64Data = preg_replace('#^data:audio/[^;]+;base64,#', '', $audioData);
        $fileData = $base64Data;
        $originalName = 'audio_enregistre_' . date('Ymd_His') . '.webm';
        
        $endpoint = '/messages/send-voice';
        $data = [
            'session' => $whatsappSession,
            'chatId' => $chatId,
            'data' => $fileData,
            'mimetype' => 'audio/webm',
            'filename' => $originalName,
            'convert' => true
        ];
        
        if (!empty($message)) {
            $data['caption'] = $message;
        }
    } elseif ($hasFile) {
        // Utiliser /tmp qui est toujours accessible
        $uploadDir = '/tmp/whatsapp_uploads/';
        
        // Créer le dossier temporaire si besoin
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $originalName = $_FILES['fichier']['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $tempName = uniqid() . '.' . $extension;
        $filePath = $uploadDir . $tempName;
        
        // Déplacer le fichier
        if (move_uploaded_file($_FILES['fichier']['tmp_name'], $filePath)) {
            $mimeType = mime_content_type($filePath);
            $fileData = base64_encode(file_get_contents($filePath));
            
            if (strpos($mimeType, 'image/') !== false) {
                $endpoint = '/messages/send-image';
                $data = [
                    'session' => $whatsappSession,
                    'chatId' => $chatId,
                    'data' => $fileData,
                    'mimetype' => $mimeType,
                    'filename' => $originalName,
                    'caption' => $message
                ];
            } elseif (strpos($mimeType, 'video/') !== false) {
                $endpoint = '/messages/send-video';
                $data = [
                    'session' => $whatsappSession,
                    'chatId' => $chatId,
                    'data' => $fileData,
                    'mimetype' => $mimeType,
                    'filename' => $originalName,
                    'caption' => $message,
                    'asNote' => false,
                    'convert' => false
                ];
            } elseif (strpos($mimeType, 'audio/') !== false) {
                $endpoint = '/messages/send-voice';
                $data = [
                    'session' => $whatsappSession,
                    'chatId' => $chatId,
                    'data' => $fileData,
                    'mimetype' => $mimeType,
                    'filename' => $originalName,
                    'convert' => true
                ];
                if (!empty($message)) {
                    $data['caption'] = $message;
                }
            } else {
                $endpoint = '/messages/send-file';
                $data = [
                    'session' => $whatsappSession,
                    'chatId' => $chatId,
                    'data' => $fileData,
                    'mimetype' => $mimeType,
                    'filename' => $originalName,
                    'caption' => $message
                ];
            }
            
            unlink($filePath);
        } else {
            error_log("Erreur: Impossible de déplacer le fichier uploadé");
            return ['success' => false, 'error' => "Erreur lors de l'upload du fichier"];
        }
    } else {
        $endpoint = '/messages/send-text';
        $data = [
            'session' => $whatsappSession,
            'chatId' => $chatId,
            'text' => $message
        ];
    }
    
    $fullUrl = $apiUrl . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
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
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Log pour debug
    error_log("=== WhatsApp Envoi ===");
    error_log("URL: " . $fullUrl);
    error_log("Session: " . $whatsappSession);
    error_log("ChatId: " . $chatId);
    error_log("HTTP Code: " . $httpCode);
    error_log("Response: " . $response);
    if ($curlError) error_log("Curl Error: " . $curlError);
    
    // Préparer les données pour l'historique
    $destinatairesNoms = [$contactNom . ' (' . $chatId . ')'];
    $destinatairesJson = json_encode($destinatairesNoms);
    
    $titre = "WhatsApp - " . date('d/m/Y H:i');
    if (!empty($message)) {
        $titre = "WhatsApp: " . (strlen($message) > 40 ? substr($message, 0, 40) . '...' : $message);
    }
    
    $responseData = json_decode($response, true);
    $isSuccess = ($httpCode === 200 || $httpCode === 201) && isset($responseData['ok']) && $responseData['ok'] === true;
    
    if ($isSuccess) {
        $campagneData = [
            'id_compte' => $idCompte,
            'id_campagne_config' => $campagneConfigId,
            'type_campagne' => 'whatsapp',
            'titre' => $titre,
            'message' => $message,
            'destinataires' => $destinatairesJson,
            'nb_destinataires' => 1,
            'nb_envoyes' => 1,
            'nb_succes' => 1,
            'nb_erreurs' => 0,
            'appareil_utilise' => $whatsappSession,
            'statut' => 'envoye',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        try {
            $db->insert('campagne', $campagneData);
        } catch (Exception $e) {
            error_log("Erreur insertion historique WhatsApp: " . $e->getMessage());
        }
        
        return ['success' => true, 'message' => 'Message envoyé avec succès !'];
    } else {
        $errorMsg = $responseData['error'] ?? substr($response, 0, 200);
        
        $campagneData = [
            'id_compte' => $idCompte,
            'id_campagne_config' => $campagneConfigId,
            'type_campagne' => 'whatsapp',
            'titre' => $titre,
            'message' => $message,
            'destinataires' => $destinatairesJson,
            'nb_destinataires' => 1,
            'nb_envoyes' => 1,
            'nb_succes' => 0,
            'nb_erreurs' => 1,
            'appareil_utilise' => $whatsappSession,
            'statut' => 'echoue',
            'erreur' => $errorMsg,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        try {
            $db->insert('campagne', $campagneData);
        } catch (Exception $e) {
            error_log("Erreur insertion historique WhatsApp: " . $e->getMessage());
        }
        
        return ['success' => false, 'error' => "Échec de l'envoi: " . $errorMsg];
    }
}

// Configuration API
$apiUrl = 'http://164.68.103.147:8081/api/controller.php';
$apiKey = defined('WHATSAPP_API_KEY') ? WHATSAPP_API_KEY : '29f51fbe00e64ac5a5e3ce6eefbb79b5';

// Vérifier le statut de la session avant l'envoi
$sessionConnected = checkWhatsAppSession($whatsappSession, $apiUrl, $apiKey);
if (!$sessionConnected && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = "La session WhatsApp n'est pas connectée. Veuillez vous reconnecter.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $type_envoi = $_POST['type_envoi'] ?? 'simple';
    $message = trim($_POST['message'] ?? '');
    $audioData = $_POST['audio_data'] ?? '';
    $hasAudio = !empty($audioData) && strpos($audioData, 'base64,') !== false;
    $hasFile = isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK;
    
    if ($type_envoi === 'simple') {
        $chatId = $_POST['chat_id'] ?? '';
        
        if (empty($chatId)) {
            $error = "Veuillez sélectionner un destinataire";
        } elseif (empty($message) && !$hasFile && !$hasAudio) {
            $error = "Veuillez saisir un message ou ajouter un fichier/audio";
        } else {
            $contactNom = '';
            foreach ($contacts as $contact) {
                $telephone = $contact['telephone'] ?? '';
                if (!empty($telephone)) {
                    $formattedNumber = formatWhatsAppNumber($telephone);
                    if ($formattedNumber === $chatId) {
                        $contactNom = $contact['prenom'] . ' ' . $contact['nom'];
                        break;
                    }
                }
            }
            
            $resultat = envoyerMessageWhatsApp($chatId, $message, $hasFile, $hasAudio, $whatsappSession, $contactNom, $apiUrl, $apiKey, $campagneConfigId);
            
            if ($resultat['success']) {
                $success = $resultat['message'];
                $db->update('campagne_config', [
                    'statut' => 'envoyee',
                    'sent_at' => date('Y-m-d H:i:s')
                ], ['id_campagne_config' => $campagneConfigId]);
            } else {
                $error = $resultat['error'];
            }
        }
    } else {
        $liste_id = $_POST['liste_id'] ?? '';
        
        if (empty($liste_id)) {
            $error = "Veuillez sélectionner une liste";
        } elseif (empty($message) && !$hasFile && !$hasAudio) {
            $error = "Veuillez saisir un message ou ajouter un fichier/audio";
        } else {
            $listeContacts = $db->select('liste_contact', ['id_liste' => $liste_id]);
            $destinataires = [];
            
            foreach ($listeContacts as $lc) {
                if (!in_array($lc['id_contact'], $blacklistIds)) {
                    $contact = $db->select('contact', ['id_contact' => $lc['id_contact']]);
                    if (!empty($contact)) {
                        $contact = $contact[0];
                        $telephone = $contact['telephone'] ?? '';
                        if (!empty($telephone)) {
                            $whatsappNumber = formatWhatsAppNumber($telephone);
                            if ($whatsappNumber) {
                                $destinataires[] = [
                                    'chat_id' => $whatsappNumber,
                                    'nom' => $contact['prenom'] . ' ' . $contact['nom']
                                ];
                            }
                        }
                    }
                }
            }
            
            if (empty($destinataires)) {
                $error = "Aucun destinataire valide dans cette liste";
            } else {
                $total = count($destinataires);
                $envoyes = 0;
                $erreurs = 0;
                $resultats = [];
                
                foreach ($destinataires as $index => $dest) {
                    $resultat = envoyerMessageWhatsApp($dest['chat_id'], $message, $hasFile, $hasAudio, $whatsappSession, $dest['nom'], $apiUrl, $apiKey, $campagneConfigId);
                    
                    if ($resultat['success']) {
                        $envoyes++;
                        $resultats[] = [
                            'destinataire' => $dest['nom'],
                            'statut' => 'succes',
                            'message' => $resultat['message']
                        ];
                    } else {
                        $erreurs++;
                        $resultats[] = [
                            'destinataire' => $dest['nom'],
                            'statut' => 'erreur',
                            'message' => $resultat['error']
                        ];
                    }
                    
                    if ($index < $total - 1) {
                        $delai = rand(120, 300);
                        sleep($delai);
                    }
                }
                
                $success = "Envoi terminé : $envoyes message(s) envoyé(s), $erreurs erreur(s)";
                
                $db->update('campagne_config', [
                    'statut' => 'envoyee',
                    'sent_at' => date('Y-m-d H:i:s')
                ], ['id_campagne_config' => $campagneConfigId]);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Envoyer WhatsApp - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .select2-container--default .select2-selection--single {
            height: 42px;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 4px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            color: #1f2937;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }
        .select2-dropdown {
            border-radius: 0.5rem;
            border-color: #d1d5db;
        }
        .select2-search__field {
            border-radius: 0.5rem !important;
            border: 1px solid #d1d5db !important;
            padding: 6px !important;
        }
        .select2-results__option--highlighted {
            background-color: #22c55e !important;
        }
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
        .toast-notification.info .toast-content { background: #3b82f6; }
        
        .type-envoi-option {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .type-envoi-option:hover {
            transform: translateY(-2px);
        }
        
        .recording-active {
            animation: pulse 1s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }

        #fileUploadArea {
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .btn-loading {
            opacity: 0.7;
            cursor: not-allowed;
            position: relative;
            pointer-events: none;
        }
        
        .btn-loading i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9998;
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .loading-overlay.active {
            visibility: visible;
            opacity: 1;
        }
        
        .loading-spinner {
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            text-align: center;
            min-width: 350px;
        }
        
        .loading-spinner i {
            font-size: 48px;
            color: #22c55e;
            animation: spin 1s linear infinite;
            margin-bottom: 10px;
        }
        
        .loading-spinner p {
            margin: 0;
            color: #333;
            font-size: 14px;
        }
        
        .progress-bar-container {
            width: 100%;
            margin-top: 15px;
        }
        
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: #22c55e;
            width: 0%;
            transition: width 0.3s ease;
        }
        
        .resultats-detail {
            max-height: 300px;
            overflow-y: auto;
            text-align: left;
            margin-top: 15px;
            font-size: 12px;
        }
        
        .resultat-succes {
            color: #10b981;
            padding: 5px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .resultat-erreur {
            color: #ef4444;
            padding: 5px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .campagne-info {
            background: #f3e8ff;
            border: 1px solid #d8b4fe;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }
        .campagne-info-title {
            font-size: 14px;
            font-weight: 600;
            color: #6b21a5;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>

<!-- Overlay de chargement global -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner">
        <i class="fab fa-whatsapp"></i>
        <p id="loadingMessage">Envoi du message en cours...</p>
        <div class="progress-bar-container">
            <div class="progress-bar">
                <div class="progress-bar-fill" id="progressBarFill"></div>
            </div>
        </div>
        <div id="resultatsDetail" class="resultats-detail" style="display: none;"></div>
    </div>
</div>

<div class="max-w-3xl mx-auto py-8 px-4">
    <div class="flex items-center mb-6">
        <a href="javascript:history.back()" class="text-blue-600 hover:text-blue-800 mr-4">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="bg-green-100 p-3 rounded-full mr-4">
            <i class="fab fa-whatsapp text-green-600 text-xl"></i>
        </div>
        <h1 class="text-2xl font-bold text-gray-800">Envoyer un message WhatsApp</h1>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <!-- Affichage des informations de la campagne -->
        <div class="campagne-info">
            <div class="campagne-info-title">
                <i class="fas fa-bullhorn mr-2"></i>
                Campagne : <?= htmlspecialchars($campagne['nom_campagne']) ?>
            </div>
        </div>
        
        <div class="bg-green-50 p-3 rounded mb-4">
            <p class="text-sm text-green-700">
                <i class="fas fa-check-circle mr-1"></i> Session active: <strong><?= htmlspecialchars($whatsappSession) ?></strong>
            </p>
        </div>
        
        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded"><?= nl2br($success) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded"><?= $error ?></div>
        <?php endif; ?>
        
        <?php if (!empty($resultats)): ?>
            <div class="mb-4 border rounded-lg overflow-hidden">
                <div class="bg-gray-50 px-4 py-2 font-bold border-b">Détail des envois</div>
                <div class="max-h-64 overflow-y-auto">
                    <?php foreach ($resultats as $r): ?>
                        <div class="px-4 py-2 border-b <?= $r['statut'] == 'succes' ? 'text-green-600' : 'text-red-600' ?>">
                            <i class="fas <?= $r['statut'] == 'succes' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> mr-2"></i>
                            <?= htmlspecialchars($r['destinataire']) ?> : <?= htmlspecialchars($r['message']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (empty($contacts)): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4 rounded">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                Aucun contact disponible. 
                <a href="index.php?page=contacts/ajouter" class="underline font-semibold">Ajoutez d'abord des contacts</a>
            </div>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data" id="whatsappForm">
                <input type="hidden" name="campagne_config_id" value="<?= $campagneConfigId ?>">
                
                <!-- Type d'envoi -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-envelope mr-1"></i> Type d'envoi *
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <div id="typeSimple" 
                             class="type-envoi-option border-2 rounded-lg p-3 text-center cursor-pointer transition <?= (!isset($_POST['type_envoi']) || $_POST['type_envoi'] == 'simple') ? 'border-green-500 bg-green-50' : 'border-gray-200 hover:border-green-300' ?>">
                            <i class="fas fa-user text-green-600 text-xl mb-1"></i>
                            <p class="font-medium text-gray-800">Envoi unique</p>
                            <p class="text-xs text-gray-500">À un seul destinataire</p>
                        </div>
                        <div id="typeMultiple" 
                             class="type-envoi-option border-2 rounded-lg p-3 text-center cursor-pointer transition <?= (isset($_POST['type_envoi']) && $_POST['type_envoi'] == 'multiple') ? 'border-green-500 bg-green-50' : 'border-gray-200 hover:border-green-300' ?>">
                            <i class="fas fa-list text-green-600 text-xl mb-1"></i>
                            <p class="font-medium text-gray-800">Envoi par liste</p>
                            <p class="text-xs text-gray-500">À tous les contacts d'une liste</p>
                        </div>
                    </div>
                    <input type="hidden" name="type_envoi" id="type_envoi" value="<?= isset($_POST['type_envoi']) ? $_POST['type_envoi'] : 'simple' ?>">
                </div>
                
                <!-- Zone Envoi unique -->
                <div id="simpleZone" class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fab fa-whatsapp mr-1 text-green-600"></i> Destinataire *
                    </label>
                    <select name="chat_id" id="contact_search" class="w-full" style="width: 100%;">
                        <option value="">Tapez le nom, prénom ou numéro...</option>
                        <?php foreach ($contacts as $contact): 
                            $telephone = $contact['telephone'] ?? '';
                            $whatsappNumber = !empty($telephone) ? formatWhatsAppNumber($telephone) : '';
                        ?>
                            <option value="<?= htmlspecialchars($whatsappNumber) ?>" <?= empty($whatsappNumber) ? 'disabled' : '' ?>>
                                <?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?>
                                <?php if (!empty($telephone)): ?>
                                    (<?= htmlspecialchars($telephone) ?>)
                                <?php else: ?>
                                    (⚠️ Pas de numéro)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-search mr-1"></i> Tapez pour rechercher par nom, prénom ou numéro
                    </p>
                </div>
                
                <!-- Zone Envoi multiple -->
                <div id="multipleZone" class="mb-4" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-list mr-1"></i> Sélectionner une liste *
                    </label>
                    <select name="liste_id" id="liste_id" class="w-full" style="width: 100%;">
                        <option value="">-- Sélectionnez une liste --</option>
                        <?php foreach ($listes as $liste): ?>
                            <option value="<?= $liste['id_liste'] ?>">
                                <?= htmlspecialchars($liste['nom_liste']) ?> (<?= $liste['nombre_contacts'] ?> contact<?= $liste['nombre_contacts'] > 1 ? 's' : '' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-clock mr-1"></i> Les messages seront envoyés avec un délai aléatoire. Cela peut prendre un peu de temps
                    </p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Message <span id="messageRequired" class="text-gray-400 text-xs">(optionnel si fichier/audio)</span></label>
                    <textarea name="message" id="message" rows="4" 
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500"
                              placeholder="Votre message..."></textarea>
                    <p class="text-xs text-gray-500 mt-1" id="charCount">0 caractères</p>
                </div>
                
                <!-- Options de pièce jointe -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Pièce jointe (optionnel)</label>
                    
                    <div class="flex space-x-2 mb-3">
                        <button type="button" id="uploadFileBtn" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg transition">
                            <i class="fas fa-upload mr-2"></i>Fichier
                        </button>
                        <button type="button" id="recordAudioBtn" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg transition">
                            <i class="fas fa-microphone mr-2"></i>Enregistrer audio
                        </button>
                    </div>
                    
                    <div id="fileUploadArea" class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hidden">
                        <input type="file" name="fichier" id="fichier" class="hidden" accept="image/*,video/*,audio/*,.pdf">
                        <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2"></i>
                        <p class="text-gray-500">Cliquez ou glissez un fichier ici</p>
                        <p class="text-xs text-gray-400 mt-1">Images, vidéos, audio, PDF (Max 10 Mo)</p>
                        <div id="fileInfo" class="mt-2 text-sm hidden"></div>
                        <button type="button" id="removeFileBtn" class="text-red-500 text-sm mt-2 hidden">Supprimer</button>
                    </div>
                    
                    <div id="audioRecordArea" class="border-2 border-gray-300 rounded-lg p-4 text-center hidden">
                        <div class="mb-3">
                            <div id="recordingTimer" class="text-2xl font-mono text-gray-700 mb-2">00:00</div>
                        </div>
                        <div class="flex justify-center space-x-3">
                            <button type="button" id="startRecordBtn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition">
                                <i class="fas fa-circle mr-2"></i>Commencer
                            </button>
                            <button type="button" id="stopRecordBtn" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition hidden">
                                <i class="fas fa-stop mr-2"></i>Arrêter
                            </button>
                        </div>
                        <div id="audioPreview" class="mt-3 hidden">
                            <audio controls class="w-full"></audio>
                            <button type="button" id="removeAudioBtn" class="text-red-500 text-sm mt-2">Supprimer l'audio</button>
                        </div>
                        <input type="hidden" name="audio_data" id="audioData">
                    </div>
                </div>
                
                <div class="flex justify-end mt-6">
                    <button type="submit" id="submitBtn" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg transition">
                        <i class="fab fa-whatsapp mr-2"></i>Envoyer
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/fr.js"></script>

<script>
$(document).ready(function() {
    $('#contact_search').select2({
        placeholder: "Tapez le nom, prénom ou numéro...",
        allowClear: true,
        width: '100%',
        language: 'fr'
    });
    
    $('#liste_id').select2({
        placeholder: "-- Sélectionnez une liste --",
        allowClear: true,
        width: '100%',
        language: 'fr'
    });
});

const typeSimple = document.getElementById('typeSimple');
const typeMultiple = document.getElementById('typeMultiple');
const simpleZone = document.getElementById('simpleZone');
const multipleZone = document.getElementById('multipleZone');
const typeEnvoiInput = document.getElementById('type_envoi');

function setTypeEnvoi(type) {
    if (type === 'simple') {
        typeSimple.classList.add('border-green-500', 'bg-green-50');
        typeSimple.classList.remove('border-gray-200');
        typeMultiple.classList.remove('border-green-500', 'bg-green-50');
        typeMultiple.classList.add('border-gray-200');
        simpleZone.style.display = 'block';
        multipleZone.style.display = 'none';
        typeEnvoiInput.value = 'simple';
        
        $('#liste_id').prop('disabled', true);
        $('#liste_id').next().css('opacity', '0.5');
        $('#contact_search').prop('disabled', false);
        $('#contact_search').next().css('opacity', '1');
    } else {
        typeMultiple.classList.add('border-green-500', 'bg-green-50');
        typeMultiple.classList.remove('border-gray-200');
        typeSimple.classList.remove('border-green-500', 'bg-green-50');
        typeSimple.classList.add('border-gray-200');
        simpleZone.style.display = 'none';
        multipleZone.style.display = 'block';
        typeEnvoiInput.value = 'multiple';
        
        $('#contact_search').prop('disabled', true);
        $('#contact_search').next().css('opacity', '0.5');
        $('#liste_id').prop('disabled', false);
        $('#liste_id').next().css('opacity', '1');
    }
}

typeSimple.addEventListener('click', () => setTypeEnvoi('simple'));
typeMultiple.addEventListener('click', () => setTypeEnvoi('multiple'));

setTypeEnvoi(typeEnvoiInput.value);

function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `<div class="toast-content">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

let mediaRecorder = null;
let audioChunks = [];
let recordingTimer = null;
let recordingSeconds = 0;
let stream = null;

const uploadFileBtn = document.getElementById('uploadFileBtn');
const recordAudioBtn = document.getElementById('recordAudioBtn');
const fileUploadArea = document.getElementById('fileUploadArea');
const audioRecordArea = document.getElementById('audioRecordArea');
const fichierInput = document.getElementById('fichier');
const fileInfoDiv = document.getElementById('fileInfo');
const removeFileBtn = document.getElementById('removeFileBtn');
const startRecordBtn = document.getElementById('startRecordBtn');
const stopRecordBtn = document.getElementById('stopRecordBtn');
const recordingTimerSpan = document.getElementById('recordingTimer');
const audioPreview = document.getElementById('audioPreview');
const audioDataInput = document.getElementById('audioData');
const removeAudioBtn = document.getElementById('removeAudioBtn');
const messageRequired = document.getElementById('messageRequired');

uploadFileBtn.addEventListener('click', () => {
    fileUploadArea.classList.remove('hidden');
    audioRecordArea.classList.add('hidden');
    resetRecording();
});

recordAudioBtn.addEventListener('click', () => {
    audioRecordArea.classList.remove('hidden');
    fileUploadArea.classList.add('hidden');
    resetFileUpload();
});

function handleFile(file) {
    const sizeMB = (file.size / 1024 / 1024).toFixed(2);
    
    if (file.size > 10 * 1024 * 1024) {
        showToast('Le fichier est trop volumineux. Maximum 10 Mo.', 'error');
        resetFileUpload();
        return;
    }
    
    let typeLabel = '';
    if (file.type.startsWith('image/')) typeLabel = 'Image';
    else if (file.type.startsWith('video/')) typeLabel = 'Vidéo';
    else if (file.type.startsWith('audio/')) typeLabel = 'Audio';
    else typeLabel = 'Document';
    
    fileInfoDiv.innerHTML = `<i class="fas fa-paperclip mr-1"></i> ${typeLabel}: ${file.name} (${sizeMB} Mo)`;
    fileInfoDiv.classList.remove('hidden');
    removeFileBtn.classList.remove('hidden');
    messageRequired.innerHTML = '<span class="text-green-600">(optionnel)</span>';
}

fileUploadArea.addEventListener('click', (e) => {
    if (e.target !== removeFileBtn && !removeFileBtn.contains(e.target)) {
        fichierInput.click();
    }
});

fichierInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        handleFile(e.target.files[0]);
    }
});

['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    fileUploadArea.addEventListener(eventName, preventDefaults, false);
    document.body.addEventListener(eventName, preventDefaults, false);
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

['dragenter', 'dragover'].forEach(eventName => {
    fileUploadArea.addEventListener(eventName, highlight, false);
});

['dragleave', 'drop'].forEach(eventName => {
    fileUploadArea.addEventListener(eventName, unhighlight, false);
});

function highlight(e) {
    fileUploadArea.classList.add('border-green-500', 'bg-green-50');
    fileUploadArea.classList.remove('border-gray-300');
}

function unhighlight(e) {
    fileUploadArea.classList.remove('border-green-500', 'bg-green-50');
    fileUploadArea.classList.add('border-gray-300');
}

fileUploadArea.addEventListener('drop', handleDrop, false);

function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    
    if (files.length > 0) {
        fichierInput.files = files;
        handleFile(files[0]);
    }
}

removeFileBtn.addEventListener('click', () => {
    fichierInput.value = '';
    fileInfoDiv.classList.add('hidden');
    removeFileBtn.classList.add('hidden');
    if (!audioDataInput.value) {
        messageRequired.innerHTML = '<span class="text-gray-400 text-xs">(optionnel si fichier/audio)</span>';
    }
});

async function startRecording() {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        audioChunks = [];
        
        mediaRecorder.ondataavailable = (event) => {
            audioChunks.push(event.data);
        };
        
        mediaRecorder.onstop = () => {
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            const audioUrl = URL.createObjectURL(audioBlob);
            const audioElement = audioPreview.querySelector('audio');
            audioElement.src = audioUrl;
            
            const reader = new FileReader();
            reader.onloadend = () => {
                audioDataInput.value = reader.result;
                messageRequired.innerHTML = '<span class="text-green-600">(optionnel)</span>';
            };
            reader.readAsDataURL(audioBlob);
            
            audioPreview.classList.remove('hidden');
            startRecordBtn.classList.remove('hidden');
            stopRecordBtn.classList.add('hidden');
            startRecordBtn.classList.remove('recording-active');
        };
        
        mediaRecorder.start();
        startRecordBtn.classList.add('hidden');
        stopRecordBtn.classList.remove('hidden');
        startRecordBtn.classList.add('recording-active');
        
        recordingSeconds = 0;
        updateTimerDisplay();
        recordingTimer = setInterval(() => {
            recordingSeconds++;
            updateTimerDisplay();
        }, 1000);
        
    } catch (err) {
        showToast('Impossible d\'accéder au microphone: ' + err.message, 'error');
    }
}

function stopRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
        clearInterval(recordingTimer);
    }
}

function updateTimerDisplay() {
    const minutes = Math.floor(recordingSeconds / 60);
    const seconds = recordingSeconds % 60;
    recordingTimerSpan.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
}

function resetRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
    }
    clearInterval(recordingTimer);
    audioChunks = [];
    recordingSeconds = 0;
    updateTimerDisplay();
    audioPreview.classList.add('hidden');
    audioDataInput.value = '';
    startRecordBtn.classList.remove('hidden');
    stopRecordBtn.classList.add('hidden');
    startRecordBtn.classList.remove('recording-active');
}

function resetFileUpload() {
    fichierInput.value = '';
    fileInfoDiv.classList.add('hidden');
    removeFileBtn.classList.add('hidden');
}

startRecordBtn.addEventListener('click', startRecording);
stopRecordBtn.addEventListener('click', stopRecording);

removeAudioBtn.addEventListener('click', () => {
    resetRecording();
    if (!fichierInput.files.length && !document.getElementById('message').value.trim()) {
        messageRequired.innerHTML = '<span class="text-gray-400 text-xs">(optionnel si fichier/audio)</span>';
    }
});

const messageTextarea = document.getElementById('message');
if (messageTextarea) {
    messageTextarea.addEventListener('input', function() {
        const countSpan = document.getElementById('charCount');
        if (countSpan) countSpan.textContent = this.value.length + ' caractères';
    });
}

const submitBtn = document.getElementById('submitBtn');
const loadingOverlay = document.getElementById('loadingOverlay');
const whatsappForm = document.getElementById('whatsappForm');
const progressBarFill = document.getElementById('progressBarFill');
const loadingMessage = document.getElementById('loadingMessage');
const resultatsDetail = document.getElementById('resultatsDetail');

function setLoading(loading, totalMessages = 0) {
    if (loading) {
        submitBtn.classList.add('btn-loading');
        submitBtn.disabled = true;
        const originalContent = submitBtn.innerHTML;
        submitBtn.setAttribute('data-original-content', originalContent);
        submitBtn.innerHTML = '<i class="fab fa-whatsapp fa-spin mr-2"></i>Envoi en cours...';
        loadingOverlay.classList.add('active');
        
        if (totalMessages > 1) {
            loadingMessage.innerHTML = 'Envoi groupé en cours...<br><span class="text-sm text-gray-500">Délai de 2 à 5 minutes entre chaque message</span>';
            resultatsDetail.style.display = 'block';
            resultatsDetail.innerHTML = '<div class="text-center text-gray-500">Démarrage de l\'envoi...</div>';
        } else {
            loadingMessage.innerHTML = 'Envoi du message en cours...';
        }
    } else {
        submitBtn.classList.remove('btn-loading');
        submitBtn.disabled = false;
        const originalContent = submitBtn.getAttribute('data-original-content');
        if (originalContent) {
            submitBtn.innerHTML = originalContent;
        }
        loadingOverlay.classList.remove('active');
        resultatsDetail.style.display = 'none';
    }
}

whatsappForm.addEventListener('submit', function(e) {
    const type_envoi = document.getElementById('type_envoi').value;
    const message = messageTextarea?.value.trim() || '';
    const hasFile = fichierInput.files.length > 0;
    const hasAudio = audioDataInput.value !== '';
    let hasRecipients = false;
    let totalMessages = 0;
    
    if (type_envoi === 'simple') {
        const chatId = $('#contact_search').val();
        hasRecipients = chatId && chatId !== '';
        totalMessages = 1;
    } else {
        const liste = $('#liste_id').val();
        hasRecipients = liste && liste !== '';
        const selectedOption = $('#liste_id option:selected');
        const text = selectedOption.text();
        const match = text.match(/\((\d+)/);
        if (match && match[1]) {
            totalMessages = parseInt(match[1]);
        }
    }
    
    if (!hasRecipients) {
        e.preventDefault();
        showToast('Veuillez sélectionner un destinataire ou une liste', 'error');
        return false;
    }
    
    if (!message && !hasFile && !hasAudio) {
        e.preventDefault();
        showToast('Veuillez saisir un message ou ajouter un fichier/audio', 'error');
        return false;
    }
    
    if (totalMessages > 10) {
        if (!confirm(`Vous allez envoyer ${totalMessages} messages avec un délai de 2 à 5 minutes entre chaque. Cela peut prendre plusieurs minutes. Continuer ?`)) {
            e.preventDefault();
            return false;
        }
    }
    
    setLoading(true, totalMessages);
});

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

</body>
</html>