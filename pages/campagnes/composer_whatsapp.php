<?php
require_once 'config.php';
global $db;

$idCompte = $_SESSION['user_id'];

// Utilisation des constantes de config.php
$supabaseUrl = SUPABASE_URL;
$supabaseKey = SUPABASE_KEY;

// ============================================
// RÉCUPÉRATION DE LA CAMPAGNE CONFIG
// ============================================
$campagneConfigId = $_POST['campagne_config_id'] ?? $_SESSION['campagne_config_id'] ?? null;

if (!$campagneConfigId) {
    header('Location: index.php?page=campagnes/index');
    exit;
}

// Récupérer les infos de la campagne config
$campagneConfig = $db->select('campagne_config', [
    'id_campagne_config' => $campagneConfigId,
    'id_compte' => $idCompte
]);

if (empty($campagneConfig)) {
    $_SESSION['flash_error'] = "Campagne non trouvée";
    header('Location: index.php?page=campagnes/index');
    exit;
}

$campagne = $campagneConfig[0];

// Vérifier que le type de message est WhatsApp
$typeMessage = $_SESSION['type_message'] ?? null;
if ($typeMessage !== 'whatsapp') {
    $_SESSION['flash_error'] = "Type de message non valide pour cette page";
    header('Location: index.php?page=campagnes/choix_type&campagne_id=' . $campagneConfigId);
    exit;
}

// ============================================
// RÉCUPÉRATION DE L'ID DU TYPE MESSAGE WHATSAPP
// ============================================
$whatsappTypeId = null;
$typeMessageWhatsapp = $db->select('type_message', ['libelle_type' => 'WhatsApp']);
if (empty($typeMessageWhatsapp)) {
    $typeMessageWhatsapp = $db->select('type_message', ['libelle_type' => 'whatsapp']);
}
if (!empty($typeMessageWhatsapp)) {
    $whatsappTypeId = $typeMessageWhatsapp[0]['id_type_message'];
}

// ============================================
// RÉCUPÉRATION DE LA BLACKLIST POUR WHATSAPP
// ============================================
$blacklistIds = [];
if ($whatsappTypeId) {
    $blacklist = $db->select('blacklist', ['id_type_message' => $whatsappTypeId]);
    foreach ($blacklist as $b) {
        if (!empty($b['id_contact'])) {
            $blacklistIds[] = $b['id_contact'];
        }
    }
}

// Récupérer tous les contacts du compte
$tousContacts = $db->select('contact', ['id_compte' => $idCompte]);

// Filtrer les contacts non blacklistés pour WhatsApp
$contacts = [];
foreach ($tousContacts as $contact) {
    if (!in_array($contact['id_contact'], $blacklistIds)) {
        $contacts[] = $contact;
    }
}

// Récupérer les listes avec le nombre de contacts (excluant blacklist WhatsApp)
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

// Fonction pour formater un numéro WhatsApp
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
    
    return $telephone;
}

// ============================================
// FONCTION POUR UPLOADER SUR SUPABASE STORAGE
// ============================================
function uploadToSupabaseStorage($fileData, $fileName, $fileType, $supabaseUrl, $supabaseKey) {
    $bucketName = 'whatsapp-files';
    
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $uniqueName = 'whatsapp_' . uniqid() . '_' . date('Ymd_His') . '.' . $extension;
    
    if (is_string($fileData) && strpos($fileData, 'base64,') !== false) {
        $fileData = preg_replace('#^data:audio/[^;]+;base64,#', '', $fileData);
        $fileData = base64_decode($fileData);
    }
    
    if (empty($fileData)) {
        return ['success' => false, 'error' => 'Les données du fichier sont vides'];
    }
    
    $uploadUrl = rtrim($supabaseUrl, '/') . "/storage/v1/object/$bucketName/$uniqueName";
    
    $ch = curl_init($uploadUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $supabaseKey",
        "Content-Type: $fileType"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 || $httpCode === 201) {
        $publicUrl = rtrim($supabaseUrl, '/') . "/storage/v1/object/public/$bucketName/$uniqueName";
        return [
            'success' => true,
            'url' => $publicUrl,
            'path' => "$bucketName/$uniqueName",
            'filename' => $uniqueName
        ];
    } else {
        return [
            'success' => false,
            'error' => "Erreur upload: HTTP $httpCode - " . $response
        ];
    }
}

// ============================================
// TRAITEMENT DU FORMULAIRE - ENREGISTREMENT SEULEMENT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_enregistrer'])) {
    $message = trim($_POST['message'] ?? '');
    $type_envoi = $_POST['type_envoi'] ?? 'simple';
    $contact_id = $_POST['contact_unique'] ?? null;
    $liste_id = $_POST['liste_id'] ?? null;
    $min_delay = intval($_POST['min_delay'] ?? 60);
    $max_delay = intval($_POST['max_delay'] ?? 180);
    
    $hasFile = isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK;
    $audioData = $_POST['audio_data'] ?? '';
    $hasAudio = !empty($audioData) && strpos($audioData, 'base64,') !== false;
    
    if (empty($message) && !$hasFile && !$hasAudio) {
        $error = "Veuillez saisir un message ou joindre un fichier/audio";
    } elseif ($type_envoi === 'simple' && empty($contact_id)) {
        $error = "Veuillez sélectionner un destinataire";
    } elseif ($type_envoi === 'multiple' && empty($liste_id)) {
        $error = "Veuillez sélectionner une liste";
    } else {
        $destinataires = [];
        $destinatairesNoms = [];
        $fichierInfo = null;
        $pieceJointeTexte = '';
        $fichierStocke = false;
        
        if ($hasFile) {
            $file = $_FILES['fichier'];
            $fileContent = file_get_contents($file['tmp_name']);
            
            if ($fileContent === false) {
                $error = "Impossible de lire le fichier";
            } else {
                $mimeType = mime_content_type($file['tmp_name']);
                if (!$mimeType) {
                    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $mimeTypes = [
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'gif' => 'image/gif',
                        'webp' => 'image/webp',
                        'mp4' => 'video/mp4',
                        'avi' => 'video/x-msvideo',
                        'mov' => 'video/quicktime',
                        'mp3' => 'audio/mpeg',
                        'wav' => 'audio/wav',
                        'ogg' => 'audio/ogg',
                        'pdf' => 'application/pdf',
                        'doc' => 'application/msword',
                        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'xls' => 'application/vnd.ms-excel',
                        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'txt' => 'text/plain',
                        'webm' => 'video/webm',
                        'm4a' => 'audio/mp4',
                        'aac' => 'audio/aac'
                    ];
                    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
                }
                
                $uploadResult = uploadToSupabaseStorage(
                    $fileContent,
                    $file['name'],
                    $mimeType,
                    $supabaseUrl,
                    $supabaseKey
                );
                
                if ($uploadResult['success']) {
                    $fileType = 'file';
                    if (strpos($mimeType, 'image/') !== false) {
                        $fileType = 'image';
                    } elseif (strpos($mimeType, 'video/') !== false) {
                        $fileType = 'video';
                    } elseif (strpos($mimeType, 'audio/') !== false) {
                        $fileType = 'voice';
                    }
                    
                    $fichierInfo = [
                        'nom' => $file['name'],
                        'nom_stocke' => $uploadResult['filename'],
                        'url' => $uploadResult['url'],
                        'path' => $uploadResult['path'],
                        'taille' => round($file['size'] / 1024 / 1024, 2),
                        'type' => $fileType,
                        'mime_type' => $mimeType
                    ];
                    $fichierStocke = true;
                    $pieceJointeTexte = "📎 Fichier joint: " . $file['name'] . " (" . round($file['size'] / 1024 / 1024, 2) . " Mo)";
                } else {
                    $error = "Erreur lors de l'upload: " . $uploadResult['error'];
                }
            }
        }
        
        if ($hasAudio && !$error) {
            $fileType = 'audio/webm';
            $fileName = 'audio_enregistre_' . date('Ymd_His') . '.webm';
            
            $base64Data = preg_replace('#^data:audio/[^;]+;base64,#', '', $audioData);
            $audioContent = base64_decode($base64Data);
            
            if (empty($audioContent)) {
                $error = "Impossible de décoder l'audio";
            } else {
                $uploadResult = uploadToSupabaseStorage(
                    $audioContent,
                    $fileName,
                    $fileType,
                    $supabaseUrl,
                    $supabaseKey
                );
                
                if ($uploadResult['success']) {
                    $fichierInfo = [
                        'nom' => 'audio_enregistre.webm',
                        'nom_stocke' => $uploadResult['filename'],
                        'url' => $uploadResult['url'],
                        'path' => $uploadResult['path'],
                        'taille' => round(strlen($audioContent) / 1024 / 1024, 2),
                        'type' => 'voice',
                        'mime_type' => 'audio/webm'
                    ];
                    $fichierStocke = true;
                    $pieceJointeTexte = "🎤 Message vocal enregistré le " . date('d/m/Y à H:i');
                } else {
                    $error = "Erreur lors de l'upload de l'audio: " . $uploadResult['error'];
                }
            }
        }
        
        if (!$error) {
            if ($type_envoi === 'simple') {
                $contact = $db->select('contact', ['id_contact' => $contact_id, 'id_compte' => $idCompte]);
                if (!empty($contact)) {
                    $telephone = $contact[0]['telephone'];
                    $whatsappNumber = formatWhatsAppNumber($telephone);
                    $destinataires[] = $whatsappNumber ?: $telephone;
                    $destinatairesNoms[] = $contact[0]['prenom'] . ' ' . $contact[0]['nom'] . ' (' . $telephone . ')';
                }
            } else {
                $listeContacts = $db->select('liste_contact', ['id_liste' => $liste_id]);
                foreach ($listeContacts as $lc) {
                    if (!in_array($lc['id_contact'], $blacklistIds)) {
                        $contact = $db->select('contact', ['id_contact' => $lc['id_contact'], 'id_compte' => $idCompte]);
                        if (!empty($contact)) {
                            $telephone = $contact[0]['telephone'];
                            $whatsappNumber = formatWhatsAppNumber($telephone);
                            $destinataires[] = $whatsappNumber ?: $telephone;
                            $destinatairesNoms[] = $contact[0]['prenom'] . ' ' . $contact[0]['nom'] . ' (' . $telephone . ')';
                        }
                    }
                }
            }
        }
        
        if (!$error) {
            $messageAEnregistrer = $message;
            if ($fichierStocke && empty($message)) {
                $messageAEnregistrer = $pieceJointeTexte;
            } elseif ($fichierStocke && !empty($message)) {
                $messageAEnregistrer = $message . "\n\n" . $pieceJointeTexte;
            }
            
            $campagneData = [
                'id_compte' => $idCompte,
                'id_campagne_config' => $campagneConfigId,
                'type_campagne' => 'whatsapp',
                'titre' => "WhatsApp: " . (strlen($messageAEnregistrer) > 40 ? substr($messageAEnregistrer, 0, 40) . '...' : $messageAEnregistrer),
                'message' => $messageAEnregistrer,
                'destinataires' => json_encode($destinatairesNoms),
                'nb_destinataires' => count($destinataires),
                'nb_envoyes' => 0,
                'nb_succes' => 0,
                'nb_erreurs' => 0,
                'statut' => 'brouillon',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            if ($fichierStocke) {
                $campagneData['piece_jointe'] = json_encode($fichierInfo);
            }
            
            try {
                $db->insert('campagne', $campagneData);
                
                $updateData = [
                    'statut' => 'pret_a_envoyer',
                    'message_content' => $messageAEnregistrer,
                    'min_delay' => $min_delay,
                    'max_delay' => $max_delay
                ];
                
                if ($fichierStocke) {
                    $updateData['piece_jointe'] = json_encode($fichierInfo);
                }
                
                $db->update('campagne_config', $updateData, ['id_campagne_config' => $campagneConfigId]);
                
                $_SESSION['message_content'] = $messageAEnregistrer;
                $_SESSION['type_envoi'] = $type_envoi;
                $_SESSION['campagne_config_id'] = $campagneConfigId;
                
                if ($fichierStocke) {
                    $_SESSION['fichier_info'] = $fichierInfo;
                } else {
                    unset($_SESSION['fichier_info']);
                }
                
                header('Location: index.php?page=campagnes/choix_provider_whatsapp&campagne_id=' . $campagneConfigId);
                exit;
                
            } catch (Exception $e) {
                $error = "Erreur lors de l'enregistrement : " . $e->getMessage();
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
    <title>Composer le message WhatsApp - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================
           STYLES PRINCIPAUX - FULL WIDTH
        ============================================ */
        * { 
            box-sizing: border-box; 
            margin: 0;
            padding: 0;
        }
        
        body { 
            margin: 0; 
            background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
        }
        
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 16px 32px;
            width: 100%;
        }
        
        /* ============================================
           TOAST
        ============================================ */
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
        .toast-notification.warning .toast-content { background: #f59e0b; }
        
        /* ============================================
           STEP INDICATOR
        ============================================ */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 24px;
            padding: 12px 24px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            flex-wrap: wrap;
            width: 100%;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #9ca3af;
        }
        .step .number {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 12px;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }
        .step.active .number {
            background: #25D366;
            color: white;
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);
        }
        .step.done .number {
            background: #10b981;
            color: white;
        }
        .step.active {
            color: #1f2937;
            font-weight: 600;
        }
        .step.done {
            color: #6b7280;
        }
        .step-line {
            width: 40px;
            height: 2px;
            background: #e5e7eb;
            border-radius: 2px;
            flex-shrink: 0;
        }
        .step-line.done {
            background: #10b981;
        }
        
        /* ============================================
           EN-TÊTE
        ============================================ */
        .header-section {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 16px 24px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            width: 100%;
            flex-wrap: wrap;
            gap: 12px;
        }
        .header-section .back-link {
            color: #6b7280;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            flex-shrink: 0;
        }
        .header-section .back-link:hover {
            color: #374151;
            background: #f3f4f6;
        }
        .header-section .icon-wrapper {
            background: #dcfce7;
            padding: 10px 12px;
            border-radius: 12px;
            flex-shrink: 0;
        }
        .header-section .icon-wrapper i {
            color: #16a34a;
            font-size: 22px;
        }
        .header-section .header-text {
            flex: 1;
            min-width: 150px;
        }
        .header-section .title {
            font-size: 22px;
            font-weight: 700;
            color: #1f2937;
        }
        .header-section .subtitle {
            font-size: 14px;
            color: #6b7280;
            margin-top: 2px;
        }
        
        /* ============================================
           CARD PRINCIPALE
        ============================================ */
        .main-card {
            background: white;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            padding: 24px 28px;
            width: 100%;
        }
        
        /* ============================================
           INFO CAMPAGNE
        ============================================ */
        .campagne-info {
            background: #f3e8ff;
            border: 2px solid #d8b4fe;
            border-radius: 12px;
            padding: 14px 20px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            width: 100%;
        }
        .campagne-info .info-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .campagne-info .info-left .campagne-name {
            font-size: 15px;
            font-weight: 700;
            color: #5b21b6;
        }
        .campagne-info .info-left .whatsapp-badge {
            background: #25D366;
            color: white;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .campagne-info .info-right {
            font-size: 14px;
            color: #6b21a8;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .campagne-info .info-right i {
            font-size: 16px;
        }
        
        /* ============================================
           FORMULAIRES
        ============================================ */
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
        }
        .form-label i {
            margin-right: 6px;
        }
        .form-label .required {
            color: #ef4444;
        }
        .form-label .optional {
            font-size: 12px;
            font-weight: 400;
            color: #9ca3af;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        /* ============================================
           TYPE D'ENVOI
        ============================================ */
        .type-envoi-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        .type-envoi-option {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            padding: 14px 16px;
            text-align: center;
            background: white;
        }
        .type-envoi-option:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .type-envoi-option.border-green-500 {
            border-color: #25D366;
            background-color: #f0fdf4;
            box-shadow: 0 0 0 3px rgba(37, 211, 102, 0.1);
        }
        .type-envoi-option i {
            font-size: 24px;
            margin-bottom: 4px;
            display: block;
        }
        .type-envoi-option .option-title {
            font-size: 15px;
            font-weight: 700;
            color: #1f2937;
        }
        .type-envoi-option .option-desc {
            font-size: 13px;
            color: #6b7280;
        }
        
        /* ============================================
           SELECT2
        ============================================ */
        .select2-container--default .select2-selection--single {
            border: 2px solid #d1d5db;
            border-radius: 8px;
            min-height: 42px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 40px;
            padding-left: 12px;
            font-size: 14px;
            color: #1f2937;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
            width: 32px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow b {
            border-width: 5px 5px 0 5px;
            border-color: #6b7280 transparent transparent transparent;
        }
        .select2-dropdown {
            border-radius: 8px;
            border-color: #d1d5db;
            font-size: 14px;
        }
        .select2-search__field {
            border-radius: 6px !important;
            border: 2px solid #d1d5db !important;
            padding: 6px 10px !important;
            font-size: 14px !important;
        }
        .select2-search__field:focus {
            border-color: #25D366 !important;
        }
        .select2-results__option {
            padding: 8px 12px !important;
            font-size: 14px !important;
        }
        .select2-results__option--highlighted {
            background-color: #25D366 !important;
        }
        
        /* ============================================
           TEXTAREA
        ============================================ */
        textarea#message {
            padding: 12px 14px;
            font-size: 14px;
            border-radius: 8px;
            border: 2px solid #d1d5db;
            min-height: 120px;
            width: 100%;
            font-family: inherit;
            transition: all 0.2s ease;
            resize: vertical;
        }
        textarea#message:focus {
            outline: none;
            border-color: #25D366;
            box-shadow: 0 0 0 3px rgba(37, 211, 102, 0.1);
        }
        
        .char-counter {
            font-size: 13px;
            padding: 3px 10px;
            border-radius: 6px;
            background: #f3f4f6;
            display: inline-block;
            font-weight: 500;
            color: #4b5563;
        }
        
        .flex-between {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        /* ============================================
           UPLOAD BUTTONS
        ============================================ */
        .upload-btn-group {
            display: flex;
            gap: 10px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .upload-btn-group button {
            flex: 1;
            min-width: 140px;
            padding: 10px 14px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .upload-btn-group button:hover {
            transform: translateY(-2px);
        }
        .upload-btn-group button i {
            font-size: 14px;
        }
        .upload-btn-group .btn-upload-file {
            background: #e5e7eb;
            color: #4b5563;
        }
        .upload-btn-group .btn-upload-file:hover {
            background: #d1d5db;
        }
        .upload-btn-group .btn-record-audio {
            background: #fef2f2;
            color: #dc2626;
        }
        .upload-btn-group .btn-record-audio:hover {
            background: #fee2e2;
        }
        
        /* ============================================
           UPLOAD AREA
        ============================================ */
        .upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 10px;
            padding: 24px;
            text-align: center;
            transition: all 0.2s;
            cursor: pointer;
            width: 100%;
        }
        .upload-area:hover {
            border-color: #25D366;
            background-color: #fafafa;
        }
        .upload-area .upload-icon {
            font-size: 36px;
            color: #9ca3af;
            margin-bottom: 6px;
        }
        .upload-area .upload-title {
            font-size: 15px;
            color: #4b5563;
            font-weight: 500;
        }
        .upload-area .upload-desc {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 4px;
        }
        
        .upload-area .file-info {
            margin-top: 12px;
            padding: 10px 14px;
            background: #f0fdf4;
            border-radius: 8px;
            border: 1px solid #bbf7d0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        .upload-area .file-info .file-name {
            font-size: 14px;
            color: #166534;
            font-weight: 500;
        }
        .upload-area .file-info .file-remove {
            color: #dc2626;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: color 0.2s;
            background: none;
            border: none;
        }
        .upload-area .file-info .file-remove:hover {
            color: #b91c1c;
        }
        
        /* ============================================
           AUDIO RECORDING
        ============================================ */
        .audio-record-area {
            border: 2px solid #d1d5db;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            width: 100%;
        }
        .audio-record-area .timer {
            font-size: 28px;
            font-weight: 700;
            font-family: monospace;
            color: #374151;
            margin-bottom: 10px;
        }
        .audio-record-area .record-buttons {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .record-btn {
            padding: 10px 22px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .record-btn:hover {
            transform: translateY(-2px);
        }
        .record-btn-start {
            background: #ef4444;
            color: white;
        }
        .record-btn-start:hover {
            background: #dc2626;
            box-shadow: 0 4px 16px rgba(239, 68, 68, 0.3);
        }
        .record-btn-stop {
            background: #6b7280;
            color: white;
        }
        .record-btn-stop:hover {
            background: #4b5563;
        }
        .record-btn-start.recording-active {
            animation: pulse 1s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .audio-record-area .audio-preview {
            margin-top: 12px;
            padding: 10px;
            background: #f3f4f6;
            border-radius: 8px;
        }
        .audio-record-area .audio-preview audio {
            width: 100%;
        }
        .audio-record-area .audio-preview .audio-remove {
            margin-top: 6px;
            color: #dc2626;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: color 0.2s;
            background: none;
            border: none;
        }
        .audio-record-area .audio-preview .audio-remove:hover {
            color: #b91c1c;
        }
        
        /* ============================================
           MIC ERROR
        ============================================ */
        .mic-error {
            background: #fef2f2;
            border: 2px solid #fecaca;
            border-radius: 8px;
            padding: 14px 16px;
            margin-top: 10px;
            display: none;
            text-align: left;
        }
        .mic-error .icon {
            color: #dc2626;
            font-size: 20px;
            margin-right: 10px;
            flex-shrink: 0;
        }
        .mic-error .title {
            font-weight: 700;
            color: #991b1b;
            font-size: 14px;
        }
        .mic-error .description {
            color: #7f1d1d;
            font-size: 13px;
            margin-top: 4px;
        }
        .mic-error .solutions {
            margin-top: 6px;
            padding-left: 18px;
            color: #7f1d1d;
            font-size: 13px;
        }
        .mic-error .solutions li {
            margin-bottom: 3px;
        }
        
        /* ============================================
           BLACKLIST WARNING
        ============================================ */
        .blacklist-warning {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
        }
        .blacklist-warning i {
            color: #ef4444;
            font-size: 16px;
            flex-shrink: 0;
        }
        .blacklist-warning span {
            font-size: 13px;
            color: #991b1b;
            font-weight: 500;
        }
        
        /* ============================================
           ERROR BOX
        ============================================ */
        .error-box {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
        }
        .error-box i {
            color: #ef4444;
            font-size: 18px;
            flex-shrink: 0;
        }
        .error-box span {
            color: #991b1b;
            font-size: 14px;
            font-weight: 500;
        }
        
        /* ============================================
           DELAY SECTION
        ============================================ */
        .delay-section {
            background: #f0fdf4;
            border: 2px solid #bbf7d0;
            border-radius: 10px;
            padding: 16px 20px;
            margin-top: 8px;
            width: 100%;
        }
        .delay-section .delay-label {
            font-size: 14px;
            font-weight: 600;
            color: #166534;
            display: block;
            margin-bottom: 8px;
        }
        .delay-section .delay-label i {
            margin-right: 6px;
        }
        .delay-section .delay-inputs {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .delay-section .delay-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .delay-section .delay-group label {
            font-size: 13px;
            color: #166534;
            font-weight: 500;
        }
        .delay-input {
            width: 100px;
            text-align: center;
            border: 2px solid #bbf7d0;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        .delay-input:focus {
            outline: none;
            border-color: #25D366;
            box-shadow: 0 0 0 3px rgba(37, 211, 102, 0.1);
        }
        .delay-section .delay-hint {
            font-size: 12px;
            color: #15803d;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        /* ============================================
           ACTION BUTTONS
        ============================================ */
        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 2px solid #f3f4f6;
            flex-wrap: wrap;
            width: 100%;
        }
        
        .btn-primary {
            background: #25D366;
            color: white;
            padding: 11px 28px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 180px;
            justify-content: center;
        }
        .btn-primary:hover {
            background: #1da851;
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(37, 211, 102, 0.3);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .btn-outline {
            background: transparent;
            color: #6b7280;
            padding: 11px 22px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            border: 2px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            min-width: 120px;
            justify-content: center;
        }
        .btn-outline:hover {
            background: #f9fafb;
            border-color: #d1d5db;
            color: #374151;
        }
        
        /* ============================================
           UTILITIES
        ============================================ */
        .mb-2 { margin-bottom: 8px; }
        .mb-3 { margin-bottom: 12px; }
        .mb-4 { margin-bottom: 16px; }
        .mb-5 { margin-bottom: 20px; }
        .mt-1 { margin-top: 4px; }
        .mt-2 { margin-top: 8px; }
        .mt-3 { margin-top: 12px; }
        .mr-1 { margin-right: 4px; }
        .text-xs { font-size: 12px; }
        .text-sm { font-size: 14px; }
        .text-gray-400 { color: #9ca3af; }
        .text-gray-500 { color: #6b7280; }
        .text-gray-600 { color: #4b5563; }
        .w-full { width: 100%; }
        .hidden { display: none !important; }
        
        /* ============================================
           RESPONSIVE
        ============================================ */
        @media (max-width: 1200px) {
            .container { padding: 16px 24px; }
        }
        
        @media (max-width: 992px) {
            .container { padding: 14px 20px; }
            .main-card { padding: 20px; }
            .step-indicator { padding: 10px 16px; gap: 8px; }
            .step { font-size: 12px; }
            .step .number { width: 24px; height: 24px; font-size: 10px; }
            .step-line { width: 28px; }
        }
        
        @media (max-width: 768px) {
            .container { padding: 12px 16px; }
            
            .header-section {
                padding: 14px 16px;
                gap: 8px;
            }
            .header-section .title { font-size: 19px; }
            .header-section .subtitle { font-size: 13px; }
            .header-section .icon-wrapper { padding: 8px 10px; }
            .header-section .icon-wrapper i { font-size: 18px; }
            
            .main-card { padding: 16px; }
            
            .campagne-info {
                flex-direction: column;
                align-items: flex-start;
                padding: 12px 16px;
                gap: 6px;
            }
            .campagne-info .info-left .campagne-name { font-size: 14px; }
            .campagne-info .info-right { font-size: 13px; }
            
            .type-envoi-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .type-envoi-option { padding: 12px 14px; }
            .type-envoi-option i { font-size: 20px; }
            .type-envoi-option .option-title { font-size: 14px; }
            .type-envoi-option .option-desc { font-size: 12px; }
            
            .upload-btn-group { flex-direction: column; }
            .upload-btn-group button { min-width: unset; width: 100%; }
            
            .delay-section .delay-inputs {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            .delay-section .delay-group { width: 100%; }
            .delay-input { width: 100%; }
            .delay-section .delay-hint { margin-top: 4px; }
            
            .action-buttons {
                flex-direction: column;
            }
            .action-buttons .btn-primary,
            .action-buttons .btn-outline {
                width: 100%;
                justify-content: center;
                min-width: unset;
            }
            
            .step-indicator {
                gap: 6px;
                padding: 8px 12px;
            }
            .step { font-size: 11px; gap: 4px; }
            .step .number { width: 20px; height: 20px; font-size: 9px; }
            .step-line { width: 16px; }
            .step span:last-child { display: none; }
        }
        
        @media (max-width: 480px) {
            .container { padding: 8px 10px; }
            .header-section { padding: 10px 12px; }
            .header-section .title { font-size: 17px; }
            .header-section .subtitle { font-size: 12px; }
            .header-section .back-link { font-size: 12px; padding: 3px 8px; }
            
            .main-card { padding: 12px; }
            
            .campagne-info { padding: 10px 12px; }
            .campagne-info .info-left .campagne-name { font-size: 13px; }
            .campagne-info .info-left .whatsapp-badge { font-size: 10px; padding: 2px 10px; }
            .campagne-info .info-right { font-size: 12px; }
            
            textarea#message {
                min-height: 90px;
                font-size: 13px;
                padding: 10px 12px;
            }
            
            .upload-area { padding: 16px; }
            .upload-area .upload-icon { font-size: 28px; }
            .upload-area .upload-title { font-size: 14px; }
            .upload-area .upload-desc { font-size: 12px; }
            
            .audio-record-area { padding: 14px; }
            .audio-record-area .timer { font-size: 22px; }
            
            .btn-primary {
                padding: 10px 20px;
                font-size: 14px;
            }
            .btn-outline {
                padding: 10px 18px;
                font-size: 13px;
            }
            
            .select2-container--default .select2-selection--single {
                min-height: 36px;
            }
            .select2-container--default .select2-selection--single .select2-selection__rendered {
                line-height: 34px;
                font-size: 13px;
            }
            .select2-container--default .select2-selection--single .select2-selection__arrow {
                height: 34px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- ===== STEP INDICATOR ===== -->
    <div class="step-indicator">
        <div class="step done">
            <span class="number"><i class="fas fa-check"></i></span>
            <span>Type</span>
        </div>
        <div class="step-line done"></div>
        <div class="step active">
            <span class="number">2</span>
            <span>Composition</span>
        </div>
        <div class="step-line"></div>
        <div class="step">
            <span class="number">3</span>
            <span>Provider</span>
        </div>
        <div class="step-line"></div>
        <div class="step">
            <span class="number">4</span>
            <span>Envoi</span>
        </div>
    </div>

    <!-- ===== EN-TÊTE ===== -->
    <div class="header-section">
        <a href="javascript:history.back()" class="back-link">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="icon-wrapper">
            <i class="fab fa-whatsapp"></i>
        </div>
        <div class="header-text">
            <div class="title">Composer le message WhatsApp</div>
            <div class="subtitle">Rédigez votre message et choisissez les destinataires</div>
        </div>
    </div>

    <!-- ===== CARD PRINCIPALE ===== -->
    <div class="main-card">
        <!-- Info campagne -->
        <div class="campagne-info">
            <div class="info-left">
                <i class="fas fa-bullhorn" style="color: #7c3aed; font-size: 16px;"></i>
                <span class="campagne-name"><?= htmlspecialchars($campagne['nom_campagne']) ?></span>
                <span class="whatsapp-badge"><i class="fab fa-whatsapp"></i> WhatsApp</span>
            </div>
            <div class="info-right">
                <i class="fas fa-users"></i> <?= count($contacts) ?> contact(s) disponibles
            </div>
        </div>
        
        <!-- Erreur -->
        <?php if ($error): ?>
            <div class="error-box">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Avertissement blacklist -->
        <?php if (count($contacts) < count($tousContacts)): ?>
            <div class="blacklist-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?= (count($tousContacts) - count($contacts)) ?> contact(s) blacklisté(s) pour WhatsApp ne sont pas affichés.</span>
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" id="composerForm">
            <input type="hidden" name="campagne_config_id" value="<?= $campagneConfigId ?>">
            <input type="hidden" name="action_enregistrer" value="1">
            
            <!-- ===== TYPE D'ENVOI ===== -->
            <div class="form-group">
                <label class="form-label"><i class="fas fa-envelope"></i> Type d'envoi <span class="required">*</span></label>
                <div class="type-envoi-grid">
                    <div id="typeSimple" 
                         class="type-envoi-option <?= (!isset($_POST['type_envoi']) || $_POST['type_envoi'] == 'simple') ? 'border-green-500' : '' ?>">
                        <i class="fas fa-user text-green-600"></i>
                        <div class="option-title">Envoi unique</div>
                        <div class="option-desc">À un seul destinataire</div>
                    </div>
                    <div id="typeMultiple" 
                         class="type-envoi-option <?= (isset($_POST['type_envoi']) && $_POST['type_envoi'] == 'multiple') ? 'border-green-500' : '' ?>">
                        <i class="fas fa-list text-green-600"></i>
                        <div class="option-title">Envoi par liste</div>
                        <div class="option-desc">À tous les contacts d'une liste</div>
                    </div>
                </div>
                <input type="hidden" name="type_envoi" id="type_envoi" value="<?= isset($_POST['type_envoi']) ? $_POST['type_envoi'] : 'simple' ?>">
            </div>
            
            <!-- Envoi unique -->
            <div id="simpleZone" class="form-group">
                <label class="form-label"><i class="fab fa-whatsapp text-green-600"></i> Destinataire <span class="required">*</span></label>
                <select name="contact_unique" id="contact_unique" class="w-full" style="width: 100%;">
                    <option value="">Sélectionnez un contact...</option>
                    <?php foreach ($contacts as $contact): ?>
                        <option value="<?= $contact['id_contact'] ?>" <?= (isset($_POST['contact_unique']) && $_POST['contact_unique'] == $contact['id_contact']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?> - <?= htmlspecialchars($contact['telephone']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Envoi par liste -->
            <div id="multipleZone" class="form-group" style="display: none;">
                <label class="form-label"><i class="fas fa-list"></i> Sélectionner une liste <span class="required">*</span></label>
                <select name="liste_id" id="liste_id" class="w-full" style="width: 100%;">
                    <option value="">-- Sélectionnez une liste --</option>
                    <?php foreach ($listes as $liste): ?>
                        <option value="<?= $liste['id_liste'] ?>" <?= (isset($_POST['liste_id']) && $_POST['liste_id'] == $liste['id_liste']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($liste['nom_liste']) ?> (<?= $liste['nombre_contacts'] ?> contact<?= $liste['nombre_contacts'] > 1 ? 's' : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="fas fa-info-circle"></i>
                    Les contacts blacklistés pour WhatsApp seront automatiquement exclus.
                </p>
            </div>
            
            <!-- ===== MESSAGE ===== -->
            <div class="form-group">
                <label class="form-label"><i class="fas fa-comment"></i> Message <span class="optional">(optionnel si fichier/audio)</span></label>
                <textarea name="message" id="message" rows="4"
                          placeholder="Votre message WhatsApp..." 
                          class="w-full"><?= isset($_POST['message']) ? htmlspecialchars($_POST['message']) : '' ?></textarea>
                <div class="flex-between mt-1">
                    <span class="char-counter" id="charCounter">0 caractères</span>
                    <span class="text-xs text-gray-500">WhatsApp accepte les messages longs</span>
                </div>
            </div>
            
            <!-- ===== PIÈCE JOINTE ===== -->
            <div class="form-group">
                <label class="form-label"><i class="fas fa-paperclip"></i> Pièce jointe <span class="optional">(optionnel)</span></label>
                
                <div class="upload-btn-group">
                    <button type="button" id="uploadFileBtn" class="btn-upload-file">
                        <i class="fas fa-upload"></i> Fichier
                    </button>
                    <button type="button" id="recordAudioBtn" class="btn-record-audio">
                        <i class="fas fa-microphone"></i> Enregistrer audio
                    </button>
                </div>
                
                <!-- Upload fichier -->
                <div id="fileUploadArea" class="upload-area hidden">
                    <input type="file" name="fichier" id="fichier" class="hidden">
                    <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                    <div class="upload-title">Cliquez ou glissez un fichier ici</div>
                    <div class="upload-desc">Images, vidéos, audio, PDF (Max 10 Mo)</div>
                    <div id="fileInfo" class="file-info hidden">
                        <span class="file-name"><i class="fas fa-file mr-1"></i> <span id="fileName"></span></span>
                        <button type="button" id="removeFileBtn" class="file-remove">
                            <i class="fas fa-times"></i> Supprimer
                        </button>
                    </div>
                </div>
                
                <!-- Enregistrement audio -->
                <div id="audioRecordArea" class="audio-record-area hidden">
                    <div class="timer" id="recordingTimer">00:00</div>
                    <div class="record-buttons">
                        <button type="button" id="startRecordBtn" class="record-btn record-btn-start">
                            <i class="fas fa-circle"></i> Commencer
                        </button>
                        <button type="button" id="stopRecordBtn" class="record-btn record-btn-stop hidden">
                            <i class="fas fa-stop"></i> Arrêter
                        </button>
                    </div>
                    
                    <!-- Erreur microphone -->
                    <div id="micError" class="mic-error">
                        <div class="flex" style="display:flex; gap:10px; align-items:flex-start;">
                            <i class="fas fa-microphone-slash icon"></i>
                            <div>
                                <div class="title">Impossible d'accéder au microphone</div>
                                <div class="description">
                                    Pour enregistrer un message vocal, vous devez autoriser l'accès au microphone.
                                </div>
                                <ul class="solutions">
                                    <li>Utilisez une connexion <strong>HTTPS</strong> (ou <strong>localhost</strong> en développement)</li>
                                    <li>Vérifiez les autorisations du navigateur</li>
                                    <li>Assurez-vous qu'aucun autre logiciel n'utilise le microphone</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div id="audioPreview" class="audio-preview hidden">
                        <audio controls class="w-full"></audio>
                        <button type="button" id="removeAudioBtn" class="audio-remove">
                            <i class="fas fa-times"></i> Supprimer l'audio
                        </button>
                    </div>
                    <input type="hidden" name="audio_data" id="audioData">
                </div>
            </div>
            
            <!-- ===== DÉLAIS (visible uniquement pour envoi multiple) ===== -->
            <div id="delaySection" class="delay-section" style="<?= (isset($_POST['type_envoi']) && $_POST['type_envoi'] == 'multiple') ? 'display:block;' : 'display:none;' ?>">
                <label class="delay-label"><i class="fas fa-hourglass-half"></i> Délai entre les messages</label>
                <div class="delay-inputs">
                    <div class="delay-group">
                        <label>Min :</label>
                        <input type="number" name="min_delay" id="min_delay" value="<?= isset($_POST['min_delay']) ? $_POST['min_delay'] : 60 ?>" 
                               class="delay-input" min="1" max="3600" step="1">
                        <span class="text-xs text-gray-500">sec</span>
                    </div>
                    <div class="delay-group">
                        <label>Max :</label>
                        <input type="number" name="max_delay" id="max_delay" value="<?= isset($_POST['max_delay']) ? $_POST['max_delay'] : 180 ?>" 
                               class="delay-input" min="1" max="3600" step="1">
                        <span class="text-xs text-gray-500">sec</span>
                    </div>
                    <span class="delay-hint">
                        <i class="fas fa-info-circle"></i> Délai aléatoire entre chaque envoi
                    </span>
                </div>
            </div>
            
            <!-- ===== BOUTONS ACTION ===== -->
            <div class="action-buttons">
                <a href="index.php?page=campagnes/choix_type&campagne_id=<?= $campagneConfigId ?>" class="btn-outline">
                    <i class="fas fa-times"></i> Annuler
                </a>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i> Enregistrer le message
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/fr.js"></script>

<script>
$(document).ready(function() {
    $('#contact_unique').select2({
        placeholder: "Sélectionnez un contact...",
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

// ============================================
// GESTION DU TYPE D'ENVOI
// ============================================
const typeSimple = document.getElementById('typeSimple');
const typeMultiple = document.getElementById('typeMultiple');
const simpleZone = document.getElementById('simpleZone');
const multipleZone = document.getElementById('multipleZone');
const typeEnvoiInput = document.getElementById('type_envoi');
const delaySection = document.getElementById('delaySection');

function setTypeEnvoi(type) {
    if (type === 'simple') {
        typeSimple.classList.add('border-green-500');
        typeSimple.classList.remove('border-gray-200');
        typeMultiple.classList.remove('border-green-500');
        typeMultiple.classList.add('border-gray-200');
        simpleZone.style.display = 'block';
        multipleZone.style.display = 'none';
        typeEnvoiInput.value = 'simple';
        delaySection.style.display = 'none';
        
        $('#liste_id').prop('disabled', true);
        $('#liste_id').next().css('opacity', '0.5');
        $('#contact_unique').prop('disabled', false);
        $('#contact_unique').next().css('opacity', '1');
    } else {
        typeMultiple.classList.add('border-green-500');
        typeMultiple.classList.remove('border-gray-200');
        typeSimple.classList.remove('border-green-500');
        typeSimple.classList.add('border-gray-200');
        simpleZone.style.display = 'none';
        multipleZone.style.display = 'block';
        typeEnvoiInput.value = 'multiple';
        delaySection.style.display = 'block';
        
        $('#contact_unique').prop('disabled', true);
        $('#contact_unique').next().css('opacity', '0.5');
        $('#liste_id').prop('disabled', false);
        $('#liste_id').next().css('opacity', '1');
    }
}

typeSimple.addEventListener('click', () => setTypeEnvoi('simple'));
typeMultiple.addEventListener('click', () => setTypeEnvoi('multiple'));

setTypeEnvoi(typeEnvoiInput.value);

// ============================================
// GESTION DU FICHIER
// ============================================
const uploadFileBtn = document.getElementById('uploadFileBtn');
const recordAudioBtn = document.getElementById('recordAudioBtn');
const fileUploadArea = document.getElementById('fileUploadArea');
const audioRecordArea = document.getElementById('audioRecordArea');
const fichierInput = document.getElementById('fichier');
const fileInfo = document.getElementById('fileInfo');
const fileNameSpan = document.getElementById('fileName');
const removeFileBtn = document.getElementById('removeFileBtn');

uploadFileBtn.addEventListener('click', () => {
    fileUploadArea.classList.remove('hidden');
    audioRecordArea.classList.add('hidden');
    resetRecording();
});

recordAudioBtn.addEventListener('click', () => {
    audioRecordArea.classList.remove('hidden');
    fileUploadArea.classList.add('hidden');
    resetFileUpload();
    checkMicrophoneSupport();
});

async function checkMicrophoneSupport() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        document.getElementById('micError').style.display = 'block';
        return;
    }
    
    const isSecure = window.location.protocol === 'https:' || 
                     window.location.hostname === 'localhost' || 
                     window.location.hostname === '127.0.0.1';
    
    if (!isSecure) {
        document.getElementById('micError').style.display = 'block';
        return;
    }
    
    try {
        const testStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        testStream.getTracks().forEach(track => track.stop());
        document.getElementById('micError').style.display = 'none';
    } catch (err) {
        document.getElementById('micError').style.display = 'block';
    }
}

function handleFile(file) {
    const sizeMB = (file.size / 1024 / 1024).toFixed(2);
    
    if (file.size > 10 * 1024 * 1024) {
        showToast('Le fichier est trop volumineux. Maximum 10 Mo.', 'error');
        resetFileUpload();
        return;
    }
    
    fileNameSpan.textContent = `${file.name} (${sizeMB} Mo)`;
    fileInfo.classList.remove('hidden');
    
    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    fichierInput.files = dataTransfer.files;
}

fileUploadArea.addEventListener('click', (e) => {
    if (e.target !== removeFileBtn && !removeFileBtn.contains(e.target) && 
        e.target !== document.querySelector('#fileInfo') && !document.querySelector('#fileInfo').contains(e.target)) {
        fichierInput.click();
    }
});

fichierInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        handleFile(e.target.files[0]);
    }
});

fileUploadArea.addEventListener('dragover', (e) => {
    e.preventDefault();
    fileUploadArea.style.borderColor = '#25D366';
    fileUploadArea.style.backgroundColor = '#f0fdf4';
});

fileUploadArea.addEventListener('dragleave', (e) => {
    e.preventDefault();
    fileUploadArea.style.borderColor = '#d1d5db';
    fileUploadArea.style.backgroundColor = '';
});

fileUploadArea.addEventListener('drop', (e) => {
    e.preventDefault();
    fileUploadArea.style.borderColor = '#d1d5db';
    fileUploadArea.style.backgroundColor = '';
    if (e.dataTransfer.files.length > 0) {
        handleFile(e.dataTransfer.files[0]);
    }
});

removeFileBtn.addEventListener('click', () => {
    resetFileUpload();
});

function resetFileUpload() {
    fichierInput.value = '';
    fileInfo.classList.add('hidden');
}

// ============================================
// ENREGISTREMENT AUDIO
// ============================================
let mediaRecorder = null;
let audioChunks = [];
let recordingTimer = null;
let recordingSeconds = 0;
let stream = null;

const startRecordBtn = document.getElementById('startRecordBtn');
const stopRecordBtn = document.getElementById('stopRecordBtn');
const recordingTimerSpan = document.getElementById('recordingTimer');
const audioPreview = document.getElementById('audioPreview');
const audioDataInput = document.getElementById('audioData');
const removeAudioBtn = document.getElementById('removeAudioBtn');

document.addEventListener('DOMContentLoaded', checkMicrophoneSupport);

async function startRecording() {
    const isSecure = window.location.protocol === 'https:' || 
                     window.location.hostname === 'localhost' || 
                     window.location.hostname === '127.0.0.1';
    
    if (!isSecure) {
        document.getElementById('micError').style.display = 'block';
        showToast('Utilisez HTTPS ou localhost pour accéder au microphone', 'error');
        return;
    }
    
    try {
        stream = await navigator.mediaDevices.getUserMedia({ 
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
            } 
        });
        
        document.getElementById('micError').style.display = 'none';
        
        mediaRecorder = new MediaRecorder(stream);
        audioChunks = [];
        
        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                audioChunks.push(event.data);
            }
        };
        
        mediaRecorder.onstop = () => {
            if (audioChunks.length === 0) {
                showToast('Aucun audio enregistré', 'error');
                return;
            }
            
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            const audioUrl = URL.createObjectURL(audioBlob);
            const audioElement = audioPreview.querySelector('audio');
            audioElement.src = audioUrl;
            
            const reader = new FileReader();
            reader.onloadend = () => {
                audioDataInput.value = reader.result;
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
        document.getElementById('micError').style.display = 'block';
        showToast('Impossible d\'accéder au microphone', 'error');
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

startRecordBtn.addEventListener('click', startRecording);
stopRecordBtn.addEventListener('click', stopRecording);

removeAudioBtn.addEventListener('click', () => {
    resetRecording();
});

// ============================================
// COMPTEUR DE CARACTÈRES
// ============================================
const messageTextarea = document.getElementById('message');
const charCounter = document.getElementById('charCounter');

if (messageTextarea) {
    messageTextarea.addEventListener('input', function() {
        const length = this.value.length;
        charCounter.textContent = length + ' caractères';
    });
    
    charCounter.textContent = messageTextarea.value.length + ' caractères';
}

// ============================================
// TOAST NOTIFICATION
// ============================================
function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const colors = { success: '#10b981', error: '#ef4444', info: '#3b82f6', warning: '#f59e0b' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// ============================================
// VALIDATION DU FORMULAIRE
// ============================================
document.getElementById('composerForm').addEventListener('submit', function(e) {
    const type_envoi = document.getElementById('type_envoi').value;
    const message = document.getElementById('message').value.trim();
    const hasFile = fichierInput.files.length > 0;
    const hasAudio = audioDataInput.value !== '';
    let hasRecipients = false;
    
    if (type_envoi === 'simple') {
        const contact = $('#contact_unique').val();
        hasRecipients = contact && contact !== '';
        if (!hasRecipients) {
            e.preventDefault();
            showToast('Veuillez sélectionner un destinataire', 'error');
            return false;
        }
    } else {
        const liste = $('#liste_id').val();
        hasRecipients = liste && liste !== '';
        if (!hasRecipients) {
            e.preventDefault();
            showToast('Veuillez sélectionner une liste', 'error');
            return false;
        }
    }
    
    if (!message && !hasFile && !hasAudio) {
        e.preventDefault();
        showToast('Veuillez saisir un message ou joindre un fichier/audio', 'error');
        return false;
    }
});
</script>

</body>
</html>