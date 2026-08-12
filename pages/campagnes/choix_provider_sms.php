<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer l'ID de la campagne
$campagneConfigId = $_GET['campagne_id'] ?? $_SESSION['campagne_config_id'] ?? null;

if (!$campagneConfigId) {
    header('Location: index.php?page=campagnes/index');
    exit;
}

// Vérifier que la campagne appartient à l'utilisateur
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

// Vérifier que le type de message est SMS
$typeMessage = $_SESSION['type_message'] ?? null;
if ($typeMessage !== 'sms') {
    $_SESSION['flash_error'] = "Type de message non valide";
    header('Location: index.php?page=campagnes/choix_type&campagne_id=' . $campagneConfigId);
    exit;
}

// Récupérer l'ID du type SMS (INTEGER)
$typeMessageSms = $db->select('type_message', ['libelle_type' => 'SMS']);
if (empty($typeMessageSms)) {
    $typeMessageSms = $db->select('type_message', ['libelle_type' => 'sms']);
}
$smsTypeId = !empty($typeMessageSms) ? (int)$typeMessageSms[0]['id_type_message'] : null;

// Récupérer les providers SMS disponibles
$providers = $db->select('provider', ['id_type_message' => $smsTypeId]);

// Récupérer le provider actif de l'utilisateur
$providerActif = null;
$providerActifData = $db->select('provider_actif', [
    'id_compte' => $idCompte,
    'id_type_message' => $smsTypeId
]);

if (!empty($providerActifData)) {
    $providerActif = $providerActifData[0]['id_provider'];
}

$error = '';
$success = '';

// ============================================
// TRAITEMENT DU FORMULAIRE - Sélection du provider
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_choisir_provider'])) {
    $id_provider = (int)($_POST['id_provider'] ?? 0);
    
    if (!$id_provider) {
        $error = "Veuillez sélectionner un provider";
    } else {
        try {
            // Récupérer le nom du provider sélectionné
            $providerSelected = $db->select('provider', ['id_provider' => $id_provider]);
            $providerName = !empty($providerSelected) ? $providerSelected[0]['nom_providers'] : '';
            
            // Vérifier si le provider existe déjà pour ce compte
            $existing = $db->select('provider_actif', [
                'id_compte' => $idCompte,
                'id_type_message' => $smsTypeId
            ]);
            
            if (!empty($existing)) {
                $db->update('provider_actif', [
                    'id_provider' => $id_provider,
                    'updated_at' => date('Y-m-d H:i:s')
                ], [
                    'id_compte' => $idCompte,
                    'id_type_message' => $smsTypeId
                ]);
            } else {
                $db->insert('provider_actif', [
                    'id_compte' => $idCompte,
                    'id_type_message' => $smsTypeId,
                    'id_provider' => $id_provider,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
            
            $_SESSION['provider_sms_id'] = $id_provider;
            
            // Vérifier si Octopush
            $isOctopush = stripos($providerName, 'octopush') !== false;
            
            // 🔥 POUR OCTOPUSH - REDIRIGER VERS LE CHOIX DE SESSION
            if ($isOctopush) {
                // Sauvegarder l'ID du provider dans la session
                $_SESSION['octopush_provider_id'] = $id_provider;
                
                // Récupérer les données du message depuis la session
                $message = $_SESSION['message_sms'] ?? null;
                $destinataires = $_SESSION['destinataires_sms'] ?? null;
                $titre = $_SESSION['titre_sms'] ?? 'SMS';
                $typeEnvoi = $_SESSION['type_envoi'] ?? 'simple';
                
                // Si les sessions sont vides, récupérer depuis la base
                if (empty($message) || empty($destinataires)) {
                    $lastMessage = $db->select('campagne', [
                        'id_campagne_config' => $campagneConfigId,
                        'id_compte' => $idCompte
                    ], '*', 'created_at DESC', 1);
                    
                    if (!empty($lastMessage)) {
                        $message = $lastMessage[0]['message'] ?? null;
                        $destinataires = $lastMessage[0]['destinataires'] ?? null;
                        $titre = $lastMessage[0]['titre'] ?? 'SMS';
                        $typeEnvoi = $lastMessage[0]['type_envoi'] ?? 'simple';
                    }
                }
                
                // Sauvegarder les données du message dans la session pour les réutiliser
                $_SESSION['message_sms_temp'] = $message;
                $_SESSION['destinataires_sms_temp'] = $destinataires;
                $_SESSION['titre_sms_temp'] = $titre;
                $_SESSION['type_envoi_temp'] = $typeEnvoi;
                $_SESSION['campagne_config_id_temp'] = $campagneConfigId;
                
                // 🔥 REDIRIGER VERS LE CHOIX DE SESSION OCTOPUSH
                header('Location: index.php?page=campagnes/choix_session_octopush&campagne_id=' . $campagneConfigId);
                exit;
            }
            
            // ============================================
            // POUR LES AUTRES PROVIDERS (NON OCTOPUSH)
            // ============================================
            
            // Sauvegarder les données du message pour les autres providers
            $message = $_SESSION['message_sms'] ?? null;
            $destinataires = $_SESSION['destinataires_sms'] ?? null;
            $titre = $_SESSION['titre_sms'] ?? 'SMS';
            $typeEnvoi = $_SESSION['type_envoi'] ?? 'simple';
            
            // Si les sessions sont vides, récupérer depuis la base
            if (empty($message) || empty($destinataires)) {
                $lastMessage = $db->select('campagne', [
                    'id_campagne_config' => $campagneConfigId,
                    'id_compte' => $idCompte
                ], '*', 'created_at DESC', 1);
                
                if (!empty($lastMessage)) {
                    $message = $lastMessage[0]['message'] ?? null;
                    $destinataires = $lastMessage[0]['destinataires'] ?? null;
                    $titre = $lastMessage[0]['titre'] ?? 'SMS';
                    $typeEnvoi = $lastMessage[0]['type_envoi'] ?? 'simple';
                }
            }
            
            // Sauvegarder les données dans la session pour la prochaine étape
            $_SESSION['message_sms'] = $message;
            $_SESSION['destinataires_sms'] = $destinataires;
            $_SESSION['titre_sms'] = $titre;
            $_SESSION['type_envoi'] = $typeEnvoi;
            
            // Mettre à jour le provider dans campagne_config
            $updateData = [
                'provider_id' => $id_provider,
                'statut' => 'pret_a_envoyer',
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Récupérer l'appareil depuis la session
            $appareilId = $_SESSION['sms_appareil_id'] ?? null;
            $deviceId = $_SESSION['sms_device_id'] ?? null;
            $deviceName = $_SESSION['sms_device_name'] ?? null;
            
            if ($appareilId) {
                $updateData['appareil_id'] = $appareilId;
                $updateData['device_id'] = $deviceId;
            }
            
            // Mettre à jour ou créer un historique
            if ($message && $destinataires) {
                $destArray = json_decode($destinataires, true);
                $nbDestinataires = is_array($destArray) ? count($destArray) : 0;
                
                // Vérifier s'il y a un enregistrement existant
                $existingCampagne = $db->select('campagne', [
                    'id_campagne_config' => $campagneConfigId,
                    'id_compte' => $idCompte
                ], '*', 'created_at DESC', 1);
                
                if (!empty($existingCampagne)) {
                    $db->update('campagne', [
                        'message' => $message,
                        'destinataires' => $destinataires,
                        'titre' => $titre,
                        'type_campagne' => 'sms',
                        'nb_destinataires' => $nbDestinataires,
                        'statut' => 'pret_a_envoyer',
                        'provider_id' => $id_provider,
                        'appareil_id' => $appareilId,
                        'device_id' => $deviceId,
                        'appareil_utilise' => $deviceName,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], [
                        'id_campagne' => $existingCampagne[0]['id_campagne']
                    ]);
                } else {
                    $db->insert('campagne', [
                        'id_compte' => $idCompte,
                        'id_campagne_config' => $campagneConfigId,
                        'type_campagne' => 'sms',
                        'titre' => $titre,
                        'message' => $message,
                        'destinataires' => $destinataires,
                        'nb_destinataires' => $nbDestinataires,
                        'statut' => 'pret_a_envoyer',
                        'provider_id' => $id_provider,
                        'appareil_id' => $appareilId,
                        'device_id' => $deviceId,
                        'appareil_utilise' => $deviceName,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }
            
            // Mettre à jour la campagne config
            $db->update('campagne_config', $updateData, [
                'id_campagne_config' => $campagneConfigId,
                'id_compte' => $idCompte
            ]);
            
            // Nettoyer les variables de session
            unset($_SESSION['message_sms']);
            unset($_SESSION['destinataires_sms']);
            unset($_SESSION['titre_sms']);
            unset($_SESSION['type_envoi']);
            
            // Rediriger vers le choix de l'appareil
            $_SESSION['flash_message'] = "✅ Provider sélectionné avec succès. Veuillez choisir un appareil.";
            header('Location: index.php?page=campagnes/choix_appareil_sms&campagne_id=' . $campagneConfigId);
            exit;
            
        } catch (Exception $e) {
            $error = "Erreur lors de la sélection du provider : " . $e->getMessage();
            error_log("❌ Erreur: " . $e->getMessage());
        }
    }
}

// ============================================
// FONCTION POUR VÉRIFIER SI LE PROVIDER EST OCTOPUSH
// ============================================
function isOctopushProvider($providerName) {
    return stripos($providerName, 'octopush') !== false;
}

// ============================================
// RÉCUPÉRER LES INFOS DU MESSAGE
// ============================================
$messagePreview = $_SESSION['message_sms'] ?? null;
$destinatairesPreview = $_SESSION['destinataires_sms'] ?? null;
$titrePreview = $_SESSION['titre_sms'] ?? 'SMS';

// Si les sessions sont vides, récupérer depuis la base
if (empty($messagePreview) || empty($destinatairesPreview)) {
    $lastMessage = $db->select('campagne', [
        'id_campagne_config' => $campagneConfigId,
        'id_compte' => $idCompte
    ], '*', 'created_at DESC', 1);
    
    if (!empty($lastMessage)) {
        $messagePreview = $lastMessage[0]['message'] ?? null;
        $destinatairesPreview = $lastMessage[0]['destinataires'] ?? null;
        $titrePreview = $lastMessage[0]['titre'] ?? 'SMS';
    }
}

// Compter les destinataires
$nbDestinataires = 0;
if ($destinatairesPreview) {
    $destArray = json_decode($destinatairesPreview, true);
    $nbDestinataires = is_array($destArray) ? count($destArray) : 0;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choisir le provider SMS - <?= APP_NAME ?></title>
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
            background: #3b82f6;
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
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
            background: #dbeafe;
            padding: 10px 12px;
            border-radius: 12px;
            flex-shrink: 0;
        }
        .header-section .icon-wrapper i {
            color: #2563eb;
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
        .campagne-info .info-left .sms-badge {
            background: #3b82f6;
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
           MESSAGE PREVIEW
        ============================================ */
        .message-preview {
            background: #f0fdf4;
            border: 2px solid #bbf7d0;
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
        .message-preview .preview-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            flex: 1;
            min-width: 150px;
        }
        .message-preview .preview-left .label {
            font-size: 13px;
            color: #166534;
            font-weight: 600;
            flex-shrink: 0;
        }
        .message-preview .preview-left .message-text {
            font-size: 14px;
            color: #14532d;
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .message-preview .preview-right {
            font-size: 13px;
            color: #166534;
            display: flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }
        .message-preview .preview-right i {
            font-size: 14px;
        }
        
        .message-preview.warning {
            background: #fef2f2;
            border-color: #fecaca;
        }
        .message-preview.warning .preview-left .label {
            color: #991b1b;
        }
        .message-preview.warning .preview-left .message-text {
            color: #991b1b;
        }
        
        /* ============================================
           OCTOPUSH INFO
        ============================================ */
        .octopush-info {
            background: #fff7ed;
            border: 2px solid #fed7aa;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: #9a3412;
            width: 100%;
        }
        .octopush-info i {
            font-size: 18px;
            color: #ea580c;
            flex-shrink: 0;
        }
        .octopush-info strong {
            color: #7c2d12;
        }
        .octopush-info span {
            flex: 1;
        }
        
        /* ============================================
           PROVIDERS GRID
        ============================================ */
        .providers-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 0;
            width: 100%;
        }
        
        .provider-option {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 12px;
            padding: 20px 16px;
            text-align: center;
            position: relative;
            min-height: 160px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .provider-option:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }
        .provider-option.selected {
            border-color: #3b82f6;
            background-color: #eff6ff;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .provider-option .icon-wrapper {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 26px;
            background: #dbeafe;
            color: #2563eb;
            flex-shrink: 0;
        }
        .provider-option .provider-name {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 2px;
        }
        .provider-option .provider-desc {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 6px;
        }
        .provider-option .badge-actif {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 14px;
            font-size: 12px;
            font-weight: 600;
            background: #dcfce7;
            color: #166534;
        }
        .provider-option .badge-actif i {
            margin-right: 4px;
        }
        
        /* ============================================
           BADGE OCTOPUSH SPÉCIAL
        ============================================ */
        .provider-option .badge-octopush {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #f97316;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 8px rgba(249, 115, 22, 0.3);
        }
        .provider-option .badge-octopush i {
            margin-right: 4px;
            font-size: 10px;
        }
        
        .provider-option .octopush-hint {
            font-size: 11px;
            color: #ea580c;
            font-weight: 600;
            display: block;
            margin-top: 2px;
        }
        .provider-option .octopush-hint i {
            margin-right: 2px;
        }
        
        /* ============================================
           EMPTY STATE
        ============================================ */
        .empty-state {
            text-align: center;
            padding: 48px 20px;
        }
        .empty-state i {
            font-size: 56px;
            color: #d1d5db;
            margin-bottom: 16px;
        }
        .empty-state h3 {
            font-size: 20px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 8px;
        }
        .empty-state p {
            color: #6b7280;
            font-size: 15px;
        }
        .empty-state .help-text {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 6px;
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
            background: #3b82f6;
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
        .btn-primary:hover:not(:disabled) {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(59, 130, 246, 0.3);
        }
        .btn-primary:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
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
        .text-gray-500 { color: #6b7280; }
        .text-gray-400 { color: #9ca3af; }
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
            .providers-grid { gap: 14px; }
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
            
            .message-preview {
                flex-direction: column;
                align-items: flex-start;
                padding: 12px 16px;
                gap: 6px;
            }
            .message-preview .preview-left {
                width: 100%;
                min-width: unset;
            }
            .message-preview .preview-left .message-text {
                max-width: 100%;
                white-space: normal;
                word-break: break-word;
            }
            .message-preview .preview-right {
                width: 100%;
            }
            
            .providers-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .provider-option {
                padding: 16px 14px;
                min-height: 120px;
                flex-direction: row;
                text-align: left;
                gap: 14px;
                align-items: center;
            }
            .provider-option .icon-wrapper {
                width: 50px;
                height: 50px;
                font-size: 20px;
                margin: 0;
                flex-shrink: 0;
            }
            .provider-option .provider-name {
                font-size: 15px;
            }
            .provider-option .provider-desc {
                font-size: 12px;
            }
            .provider-option .badge-octopush {
                top: -6px;
                right: -6px;
                font-size: 8px;
                padding: 2px 8px;
            }
            .provider-option .octopush-hint {
                font-size: 10px;
            }
            
            .octopush-info {
                font-size: 13px;
                padding: 10px 14px;
            }
            .octopush-info i {
                font-size: 16px;
            }
            
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
            
            .empty-state { padding: 32px 16px; }
            .empty-state i { font-size: 44px; }
            .empty-state h3 { font-size: 18px; }
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
            .campagne-info .info-left .sms-badge { font-size: 10px; padding: 2px 10px; }
            .campagne-info .info-right { font-size: 12px; }
            
            .provider-option {
                padding: 12px 12px;
                min-height: 90px;
                gap: 10px;
            }
            .provider-option .icon-wrapper {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }
            .provider-option .provider-name { font-size: 14px; }
            .provider-option .provider-desc { font-size: 11px; }
            
            .btn-primary {
                padding: 10px 20px;
                font-size: 14px;
            }
            .btn-outline {
                padding: 10px 18px;
                font-size: 13px;
            }
            
            .octopush-info {
                font-size: 12px;
                padding: 8px 12px;
            }
            .octopush-info i {
                font-size: 14px;
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
        <div class="step done">
            <span class="number"><i class="fas fa-check"></i></span>
            <span>Composition</span>
        </div>
        <div class="step-line done"></div>
        <div class="step active">
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
            <i class="fas fa-server"></i>
        </div>
        <div class="header-text">
            <div class="title">Choisir le provider SMS</div>
            <div class="subtitle">Sélectionnez le provider pour l'envoi de vos SMS</div>
        </div>
    </div>

    <!-- ===== CARD PRINCIPALE ===== -->
    <div class="main-card">
        <!-- Info campagne -->
        <div class="campagne-info">
            <div class="info-left">
                <i class="fas fa-bullhorn" style="color: #7c3aed; font-size: 16px;"></i>
                <span class="campagne-name"><?= htmlspecialchars($campagne['nom_campagne']) ?></span>
                <span class="sms-badge"><i class="fas fa-comment-dots"></i> SMS</span>
            </div>
            <div class="info-right">
                <i class="fas fa-arrow-right"></i> Étape 3 sur 4
            </div>
        </div>
        
        <!-- ===== PRÉVISUALISATION DU MESSAGE ===== -->
        <?php if ($messagePreview): ?>
            <div class="message-preview">
                <div class="preview-left">
                    <span class="label"><i class="fas fa-envelope"></i> Message :</span>
                    <span class="message-text" title="<?= htmlspecialchars($messagePreview) ?>">
                        <?= htmlspecialchars(mb_substr($messagePreview, 0, 100)) ?><?= mb_strlen($messagePreview) > 100 ? '...' : '' ?>
                    </span>
                </div>
                <div class="preview-right">
                    <i class="fas fa-users"></i> <?= $nbDestinataires ?> destinataire(s)
                </div>
            </div>
        <?php else: ?>
            <div class="message-preview warning">
                <div class="preview-left">
                    <span class="label"><i class="fas fa-exclamation-triangle"></i> Avertissement :</span>
                    <span class="message-text">Aucun message n'a été composé. Veuillez composer un message d'abord.</span>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Info Octopush -->
        <div class="octopush-info">
            <i class="fas fa-info-circle"></i>
            <span>
                <strong>Info :</strong> 
                Si vous sélectionnez <strong>Octopush</strong>, vous serez redirigé vers le choix de la session Octopush à utiliser.
            </span>
        </div>
        
        <!-- Erreur -->
        <?php if ($error): ?>
            <div class="error-box">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Providers -->
        <?php if (empty($providers)): ?>
            <div class="empty-state">
                <i class="fas fa-server"></i>
                <h3>Aucun provider disponible</h3>
                <p>Aucun provider SMS n'est configuré pour le moment.</p>
                <p class="help-text">Contactez l'administrateur pour ajouter des providers.</p>
            </div>
        <?php else: ?>
            <form method="POST" id="providerForm">
                <input type="hidden" name="action_choisir_provider" value="1">
                
                <!-- ===== PROVIDERS CARDS ===== -->
                <div class="providers-grid">
                    <?php foreach ($providers as $provider): 
                        $isOctopush = isOctopushProvider($provider['nom_providers']);
                    ?>
                        <div class="provider-option <?= ($providerActif == $provider['id_provider']) ? 'selected' : '' ?>" 
                             data-provider-id="<?= $provider['id_provider'] ?>"
                             data-is-octopush="<?= $isOctopush ? 'true' : 'false' ?>"
                             onclick="selectProvider('<?= $provider['id_provider'] ?>')"
                             role="button"
                             tabindex="0"
                             aria-label="Sélectionner <?= htmlspecialchars($provider['nom_providers']) ?>">
                            
                            <?php if ($isOctopush): ?>
                                <span class="badge-octopush"><i class="fas fa-bolt"></i> API</span>
                            <?php endif; ?>
                            
                            <div class="icon-wrapper">
                                <?php if ($isOctopush): ?>
                                    <i class="fas fa-bolt" style="color: #ea580c;"></i>
                                <?php else: ?>
                                    <i class="fas fa-building"></i>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="provider-name">
                                    <?= htmlspecialchars($provider['nom_providers']) ?>
                                    <?php if ($isOctopush): ?>
                                        <span class="octopush-hint">
                                            <i class="fas fa-arrow-right"></i> Choix de session
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($provider['description'])): ?>
                                    <div class="provider-desc"><?= htmlspecialchars($provider['description']) ?></div>
                                <?php endif; ?>
                                <?php if ($providerActif == $provider['id_provider']): ?>
                                    <span class="badge-actif"><i class="fas fa-check-circle"></i> Actif</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <input type="hidden" name="id_provider" id="id_provider" value="<?= $providerActif ?>">
                
                <!-- ===== BOUTONS ACTION ===== -->
                <div class="action-buttons">
                    <a href="index.php?page=campagnes/composer_sms&campagne_id=<?= $campagneConfigId ?>" class="btn-outline">
                        <i class="fas fa-times"></i> Annuler
                    </a>
                    <button type="submit" class="btn-primary" id="btnContinuer" <?= !$providerActif ? 'disabled' : '' ?>>
                        <span>Continuer</span>
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
// ============================================
// SÉLECTION DU PROVIDER
// ============================================
let selectedProvider = <?= json_encode($providerActif) ?>;
let selectedIsOctopush = false;

function selectProvider(providerId) {
    selectedProvider = providerId;
    
    // Vérifier si c'est Octopush
    const selectedEl = document.querySelector(`.provider-option[data-provider-id="${providerId}"]`);
    if (selectedEl) {
        selectedIsOctopush = selectedEl.dataset.isOctopush === 'true';
    }
    
    // Mettre à jour l'interface
    document.querySelectorAll('.provider-option').forEach(el => {
        el.classList.remove('selected');
        // Supprimer le badge actif
        const badge = el.querySelector('.badge-actif');
        if (badge) badge.remove();
    });
    
    // Sélectionner la carte
    if (selectedEl) {
        selectedEl.classList.add('selected');
        
        // Ajouter le badge actif
        const badge = document.createElement('span');
        badge.className = 'badge-actif';
        badge.innerHTML = '<i class="fas fa-check-circle"></i> Actif';
        selectedEl.appendChild(badge);
    }
    
    // Activer le bouton
    document.getElementById('id_provider').value = providerId;
    const btn = document.getElementById('btnContinuer');
    btn.disabled = false;
    
    // Mettre à jour le texte du bouton si Octopush
    if (selectedIsOctopush) {
        btn.innerHTML = '<span>Choisir la session</span> <i class="fas fa-arrow-right"></i>';
    } else {
        btn.innerHTML = '<span>Continuer vers l\'appareil</span> <i class="fas fa-arrow-right"></i>';
    }
}

// ============================================
// CLAVIER (Entrée/Espace)
// ============================================
document.querySelectorAll('.provider-option').forEach(el => {
    el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            const providerId = this.dataset.providerId;
            selectProvider(providerId);
        }
    });
});

// ============================================
// INITIALISATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    if (selectedProvider) {
        const btn = document.getElementById('btnContinuer');
        btn.disabled = false;
        
        // Vérifier si le provider actif est Octopush
        const activeEl = document.querySelector(`.provider-option[data-provider-id="${selectedProvider}"]`);
        if (activeEl && activeEl.dataset.isOctopush === 'true') {
            selectedIsOctopush = true;
            btn.innerHTML = '<span>Choisir la session</span> <i class="fas fa-arrow-right"></i>';
        }
    }
});

// ============================================
// TOAST NOTIFICATION
// ============================================
function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const colors = { success: '#10b981', error: '#ef4444', info: '#3b82f6' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}
</script>

</body>
</html>