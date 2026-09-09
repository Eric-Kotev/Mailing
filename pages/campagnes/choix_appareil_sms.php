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

// Vérifier que le provider est sélectionné
if (!isset($_SESSION['provider_sms_id']) || !$_SESSION['provider_sms_id']) {
    header('Location: index.php?page=campagnes/choix_provider_sms&campagne_id=' . $campagneConfigId);
    exit;
}

// Récupérer les appareils SMS du compte
$smsAppareils = $db->select('sms_appareils', ['id_compte' => $idCompte]);

// Trier : actif en premier
usort($smsAppareils, function($a, $b) {
    return ($b['est_actif'] ?? 0) - ($a['est_actif'] ?? 0);
});

$error = '';
$success = '';

// Traitement de la sélection de l'appareil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_choisir_appareil'])) {
    $id_appareil = $_POST['id_appareil'] ?? null;
    
    if (!$id_appareil) {
        $error = "Veuillez sélectionner un appareil";
    } else {
        try {
            // Récupérer l'appareil par son id_appareil (UUID)
            $appareil = $db->select('sms_appareils', [
                'id_appareil' => $id_appareil,
                'id_compte' => $idCompte
            ]);
            
            if (empty($appareil)) {
                $error = "Appareil non trouvé (ID: $id_appareil)";
            } else {
                // Récupérer les infos de l'appareil
                $device_id = $appareil[0]['device_id']; // VARCHAR de l'API
                $device_name = $appareil[0]['device_name'] ?? 'Appareil SMS';
                $api_username = $appareil[0]['api_username'];
                $api_password = $appareil[0]['api_password'];
                $appareilUuid = $appareil[0]['id_appareil']; // UUID de l'appareil
                
                // Vérifier que l'appareil a bien un device_id
                if (empty($device_id)) {
                    $error = "L'appareil sélectionné n'a pas de device_id configuré.";
                } else {
                    // Désactiver tous les appareils
                    $db->update('sms_appareils', ['est_actif' => false], ['id_compte' => $idCompte]);
                    
                    // Activer l'appareil sélectionné
                    $db->update('sms_appareils', ['est_actif' => true], ['id_appareil' => $id_appareil]);
                    
                    // STOCKER EN SESSION
                    $_SESSION['sms_device_id'] = $device_id; // VARCHAR de l'API
                    $_SESSION['sms_device_name'] = $device_name;
                    $_SESSION['sms_api_username'] = $api_username;
                    $_SESSION['sms_api_password'] = $api_password;
                    $_SESSION['sms_appareil_id'] = $appareilUuid; // UUID de l'appareil
                    
                    // Récupérer le message et les destinataires depuis la session
                    $messageContent = $_SESSION['message_content'] ?? '';
                    $typeEnvoi = $_SESSION['type_envoi'] ?? 'simple';
                    
                    // Récupérer l'ID du provider depuis la session
                    $providerId = (int)$_SESSION['provider_sms_id'];
                    
                    // 🔥 METTRE À JOUR LA CAMPAGNE CONFIG
                    $updateData = [
                        'statut' => 'pret_a_envoyer',
                        'provider_id' => $providerId,
                        'appareil_id' => $appareilUuid, // UUID de l'appareil
                        'device_id' => $device_id, // VARCHAR de l'API
                        'type_envoi' => $typeEnvoi,
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $db->update('campagne_config', $updateData, [
                        'id_campagne_config' => $campagneConfigId,
                        'id_compte' => $idCompte
                    ]);
                    
                    // 🔥 METTRE À JOUR LA TABLE CAMPAGNE (historique)
                    $campagneHistorique = $db->select('campagne', [
                        'id_campagne_config' => $campagneConfigId,
                        'id_compte' => $idCompte,
                        'statut' => 'brouillon'
                    ], '*', 'created_at DESC', 1);
                    
                    if (!empty($campagneHistorique)) {
                        $db->update('campagne', [
                            'statut' => 'pret_a_envoyer',
                            'provider_id' => $providerId,
                            'appareil_id' => $appareilUuid, // UUID de l'appareil
                            'device_id' => $device_id, // VARCHAR de l'API
                            'appareil_utilise' => $device_name
                        ], ['id_campagne' => $campagneHistorique[0]['id_campagne']]);
                    }
                    
                    // Nettoyer les variables de session
                    unset($_SESSION['message_content']);
                    unset($_SESSION['type_envoi']);
                    unset($_SESSION['campagne_config_id']);
                    unset($_SESSION['type_message']);
                    unset($_SESSION['provider_sms_id']);
                    
                    // Redirection vers details.php
                    $_SESSION['flash_message'] = "✅ Message SMS ajouté avec succès à la campagne !";
                    header('Location: index.php?page=campagnes/details&id=' . $campagneConfigId);
                    exit;
                }
            }
        } catch (Exception $e) {
            error_log("❌ Erreur: " . $e->getMessage());
            $error = "Erreur lors de la création de la campagne : " . $e->getMessage();
        }
    }
}

// Récupérer l'appareil actif
$appareilActif = null;
foreach ($smsAppareils as $appareil) {
    if (isset($appareil['est_actif']) && $appareil['est_actif']) {
        $appareilActif = $appareil['id_appareil'];
        break;
    }
}

// Si aucun actif, prendre le premier
if (!$appareilActif && !empty($smsAppareils)) {
    $appareilActif = $smsAppareils[0]['id_appareil'];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choisir l'appareil SMS - <?= APP_NAME ?></title>
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
           APPAREILS GRID
        ============================================ */
        .appareils-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 0;
            width: 100%;
        }
        
        .appareil-option {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 12px;
            padding: 24px 18px;
            text-align: center;
            position: relative;
            min-height: 180px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .appareil-option:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }
        .appareil-option.selected {
            border-color: #3b82f6;
            background-color: #eff6ff;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .appareil-option .icon-wrapper {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 28px;
            background: #dbeafe;
            color: #2563eb;
            flex-shrink: 0;
        }
        .appareil-option .appareil-name {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
        }
        .appareil-option .device-id {
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 8px;
            word-break: break-all;
        }
        .appareil-option .badge-actif {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 14px;
            font-size: 12px;
            font-weight: 600;
            background: #dcfce7;
            color: #166534;
        }
        .appareil-option .badge-incomplet {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 14px;
            font-size: 12px;
            font-weight: 600;
            background: #fef3c7;
            color: #92400e;
        }
        .appareil-option .badge-selected {
            position: absolute;
            top: 12px;
            right: 12px;
            background: #3b82f6;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .appareil-option .badge-selected i {
            font-size: 12px;
        }
        
        /* ============================================
           EMPTY STATE
        ============================================ */
        .empty-state {
            text-align: center;
            padding: 48px 20px;
            width: 100%;
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
            min-width: 200px;
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
           CONFIRM MODAL
        ============================================ */
        .confirm-modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            backdrop-filter: blur(4px);
        }
        .confirm-modal.active {
            display: flex;
        }
        .confirm-modal .confirm-box {
            background: white;
            border-radius: 16px;
            padding: 32px 40px;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalPop 0.25s ease-out;
        }
        @keyframes modalPop {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .confirm-modal .confirm-icon {
            text-align: center;
            font-size: 48px;
            margin-bottom: 10px;
        }
        .confirm-modal .confirm-title {
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 6px;
        }
        .confirm-modal .confirm-message {
            text-align: center;
            font-size: 15px;
            color: #6b7280;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .confirm-modal .confirm-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .confirm-modal .confirm-actions button {
            padding: 10px 28px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .confirm-modal .confirm-actions .btn-cancel {
            background: #e5e7eb;
            color: #4b5563;
        }
        .confirm-modal .confirm-actions .btn-cancel:hover {
            background: #d1d5db;
        }
        .confirm-modal .confirm-actions .btn-confirm {
            background: #3b82f6;
            color: white;
        }
        .confirm-modal .confirm-actions .btn-confirm:hover {
            background: #2563eb;
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
            .appareils-grid {
                grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            }
        }
        
        @media (max-width: 992px) {
            .container { padding: 14px 20px; }
            .main-card { padding: 20px; }
            .step-indicator { padding: 10px 16px; gap: 8px; }
            .step { font-size: 12px; }
            .step .number { width: 24px; height: 24px; font-size: 10px; }
            .step-line { width: 28px; }
            .appareils-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 14px;
            }
            .appareil-option { min-height: 160px; padding: 20px 14px; }
            .appareil-option .icon-wrapper { width: 56px; height: 56px; font-size: 24px; }
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
            
            .appareils-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 12px;
            }
            .appareil-option {
                padding: 16px 12px;
                min-height: 140px;
            }
            .appareil-option .icon-wrapper {
                width: 48px;
                height: 48px;
                font-size: 20px;
                margin-bottom: 8px;
            }
            .appareil-option .appareil-name { font-size: 14px; }
            .appareil-option .badge-selected {
                top: 8px;
                right: 8px;
                font-size: 10px;
                padding: 3px 10px;
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
            
            .confirm-modal .confirm-box {
                padding: 24px 20px;
                max-width: 95%;
            }
            .confirm-modal .confirm-actions {
                flex-direction: column;
            }
            .confirm-modal .confirm-actions button {
                width: 100%;
            }
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
            
            .appareils-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
            .appareil-option {
                padding: 12px 8px;
                min-height: 120px;
            }
            .appareil-option .icon-wrapper {
                width: 40px;
                height: 40px;
                font-size: 16px;
                margin-bottom: 4px;
            }
            .appareil-option .appareil-name { font-size: 12px; }
            .appareil-option .device-id { font-size: 9px; }
            .appareil-option .badge-actif,
            .appareil-option .badge-incomplet {
                font-size: 9px;
                padding: 2px 10px;
            }
            .appareil-option .badge-selected {
                font-size: 9px;
                padding: 2px 8px;
                top: 4px;
                right: 4px;
            }
            
            .btn-primary {
                padding: 10px 20px;
                font-size: 14px;
            }
            .btn-outline {
                padding: 10px 18px;
                font-size: 13px;
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
        <div class="step done">
            <span class="number"><i class="fas fa-check"></i></span>
            <span>Provider</span>
        </div>
        <div class="step-line done"></div>
        <div class="step active">
            <span class="number">4</span>
            <span>Appareil</span>
        </div>
        <div class="step-line"></div>
        <div class="step">
            <span class="number">5</span>
            <span>Envoi</span>
        </div>
    </div>

    <!-- ===== EN-TÊTE ===== -->
    <div class="header-section">
        <a href="javascript:history.back()" class="back-link">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="icon-wrapper">
            <i class="fas fa-mobile-alt"></i>
        </div>
        <div class="header-text">
            <div class="title">Choisir l'appareil SMS</div>
            <div class="subtitle">Sélectionnez l'appareil pour l'envoi de vos SMS</div>
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
                <i class="fas fa-arrow-right"></i> Étape 4 sur 5
            </div>
        </div>
        
        <!-- Erreur -->
        <?php if ($error): ?>
            <div class="error-box">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Appareils -->
        <?php if (empty($smsAppareils)): ?>
            <div class="empty-state">
                <i class="fas fa-mobile-alt"></i>
                <h3>Aucun appareil disponible</h3>
                <p>Vous n'avez pas encore d'appareil SMS.</p>
                <p class="help-text">Veuillez contacter votre administrateur avant de continuer.</p>
            </div>
        <?php else: ?>
            <form method="POST" id="appareilForm">
                <input type="hidden" name="action_choisir_appareil" value="1">
                <input type="hidden" name="id_appareil" id="id_appareil" value="<?= $appareilActif ?>">
                
                <!-- ===== APPAREILS CARDS ===== -->
                <div class="appareils-grid">
                    <?php foreach ($smsAppareils as $appareil): 
                        $estComplet = !empty($appareil['device_id']) && !empty($appareil['api_username']) && !empty($appareil['api_password']);
                    ?>
                        <div class="appareil-option <?= ($appareilActif == $appareil['id_appareil']) ? 'selected' : '' ?>" 
                             data-appareil-id="<?= $appareil['id_appareil'] ?>"
                             onclick="selectAppareil('<?= $appareil['id_appareil'] ?>')"
                             role="button"
                             tabindex="0"
                             aria-label="Sélectionner <?= htmlspecialchars($appareil['device_name'] ?: 'Appareil SMS') ?>">
                            
                            <?php if ($appareilActif == $appareil['id_appareil']): ?>
                                <div class="badge-selected"><i class="fas fa-check"></i> Sélectionné</div>
                            <?php endif; ?>
                            
                            <div class="icon-wrapper">
                                <i class="fas fa-mobile-alt"></i>
                            </div>
                            <div class="appareil-name"><?= htmlspecialchars($appareil['device_name'] ?: 'Appareil SMS') ?></div>
                            <div class="device-id">Device ID: <?= htmlspecialchars(substr($appareil['device_id'] ?? '', 0, 20)) ?>...</div>
                            <?php if ($appareilActif == $appareil['id_appareil']): ?>
                                <span class="badge-actif"><i class="fas fa-check-circle"></i> Actif</span>
                            <?php elseif (!$estComplet): ?>
                                <span class="badge-incomplet"><i class="fas fa-exclamation-triangle"></i> Incomplet</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- ===== BOUTONS ACTION ===== -->
                <div class="action-buttons">
                    <a href="index.php?page=campagnes/choix_provider_sms&campagne_id=<?= $campagneConfigId ?>" class="btn-outline">
                        <i class="fas fa-times"></i> Annuler
                    </a>
                    <button type="button" class="btn-primary" id="btnContinuer" <?= !$appareilActif ? 'disabled' : '' ?> onclick="openConfirm()">
                        <i class="fas fa-check-circle"></i>
                        <span>Créer la campagne</span>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- ===== MODAL DE CONFIRMATION ===== -->
<div id="confirmModal" class="confirm-modal">
    <div class="confirm-box">
        <div class="confirm-icon">📱</div>
        <div class="confirm-title">Confirmer la création</div>
        <div class="confirm-message">
            Êtes-vous sûr de vouloir créer cette campagne avec l'appareil sélectionné ?
            <br><span style="font-size:12px;color:#9ca3af;">Vous pourrez l'envoyer depuis la page de détails.</span>
        </div>
        <div class="confirm-actions">
            <button class="btn-cancel" onclick="closeConfirm()">Annuler</button>
            <button class="btn-confirm" id="confirmBtn">Confirmer</button>
        </div>
    </div>
</div>

<script>
// ============================================
// SÉLECTION DE L'APPAREIL
// ============================================
let selectedAppareil = <?= json_encode($appareilActif) ?>;

function selectAppareil(appareilId) {
    selectedAppareil = appareilId;
    
    document.querySelectorAll('.appareil-option').forEach(el => {
        el.classList.remove('selected');
        // Supprimer le badge sélectionné
        const badge = el.querySelector('.badge-selected');
        if (badge) badge.remove();
        
        // Mettre à jour le badge actif
        const badgeActif = el.querySelector('.badge-actif');
        if (badgeActif) badgeActif.remove();
        const badgeIncomplet = el.querySelector('.badge-incomplet');
        if (badgeIncomplet) badgeIncomplet.remove();
    });
    
    const selectedEl = document.querySelector(`.appareil-option[data-appareil-id="${appareilId}"]`);
    if (selectedEl) {
        selectedEl.classList.add('selected');
        
        // Ajouter le badge sélectionné
        const badge = document.createElement('div');
        badge.className = 'badge-selected';
        badge.innerHTML = '<i class="fas fa-check"></i> Sélectionné';
        selectedEl.appendChild(badge);
        
        // Ajouter le badge actif
        const badgeActif = document.createElement('span');
        badgeActif.className = 'badge-actif';
        badgeActif.innerHTML = '<i class="fas fa-check-circle"></i> Actif';
        selectedEl.appendChild(badgeActif);
    }
    
    document.getElementById('id_appareil').value = appareilId;
    document.getElementById('btnContinuer').disabled = false;
}

// ============================================
// CLAVIER (Entrée/Espace)
// ============================================
document.querySelectorAll('.appareil-option').forEach(el => {
    el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            const appareilId = this.dataset.appareilId;
            selectAppareil(appareilId);
        }
    });
});

// ============================================
// INITIALISATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    if (selectedAppareil) {
        document.getElementById('btnContinuer').disabled = false;
        // Ajouter le badge sélectionné sur l'élément actif
        const selectedEl = document.querySelector(`.appareil-option[data-appareil-id="${selectedAppareil}"]`);
        if (selectedEl) {
            const badge = document.createElement('div');
            badge.className = 'badge-selected';
            badge.innerHTML = '<i class="fas fa-check"></i> Sélectionné';
            selectedEl.appendChild(badge);
        }
    }
});

// ============================================
// MODAL
// ============================================
function openConfirm() {
    const selected = document.getElementById('id_appareil').value;
    if (!selected) {
        showToast('Veuillez sélectionner un appareil', 'error');
        return;
    }
    document.getElementById('confirmModal').classList.add('active');
}

function closeConfirm() {
    document.getElementById('confirmModal').classList.remove('active');
}

document.getElementById('confirmBtn').addEventListener('click', function() {
    document.getElementById('appareilForm').submit();
});

document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirm();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeConfirm();
});

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
    setTimeout(() => toast.remove(), 5000);
}
</script>

</body>
</html>