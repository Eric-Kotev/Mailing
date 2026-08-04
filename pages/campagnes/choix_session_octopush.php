<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer l'ID de la campagne
$campagneConfigId = $_GET['campagne_id'] ?? $_SESSION['campagne_config_id_temp'] ?? null;

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

// Récupérer les sessions Octopush de l'utilisateur
$octopushSessions = $db->select('octopush_config', [
    'id_compte' => $idCompte
]);

$error = '';
$success = '';

// ============================================
// TRAITEMENT DU FORMULAIRE - Sélection de la session
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_choisir_session'])) {
    $idConfig = (int)($_POST['id_config'] ?? 0);
    
    if (!$idConfig) {
        $error = "Veuillez sélectionner une session Octopush";
    } else {
        try {
            // Récupérer les détails de la session sélectionnée
            $selectedSession = $db->select('octopush_config', [
                'id_config' => $idConfig,
                'id_compte' => $idCompte
            ]);
            
            if (empty($selectedSession)) {
                throw new Exception("Session non trouvée");
            }
            
            $session = $selectedSession[0];
            
            // Récupérer les données du message depuis la session temporaire
            $message = $_SESSION['message_sms_temp'] ?? null;
            $destinataires = $_SESSION['destinataires_sms_temp'] ?? null;
            $titre = $_SESSION['titre_sms_temp'] ?? 'SMS';
            $typeEnvoi = $_SESSION['type_envoi_temp'] ?? 'simple';
            $providerId = $_SESSION['octopush_provider_id'] ?? null;
            
            // Si les sessions temporaires sont vides, récupérer depuis la base
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
            
            // Compter les destinataires
            $destArray = json_decode($destinataires, true);
            $nbDestinataires = is_array($destArray) ? count($destArray) : 0;
            
            // Vérifier s'il y a un enregistrement existant pour cette campagne
            $existingCampagne = $db->select('campagne', [
                'id_campagne_config' => $campagneConfigId,
                'id_compte' => $idCompte
            ], '*', 'created_at DESC', 1);
            
            // Préparer les données de la campagne
            $campagneData = [
                'message' => $message,
                'destinataires' => $destinataires,
                'titre' => $titre,
                'type_campagne' => 'sms',
                'nb_destinataires' => $nbDestinataires,
                'statut' => 'pret_a_envoyer',
                'provider_id' => $providerId,
                'appareil_utilise' => 'Octopush - ' . $session['nom_config'],
                'api_login' => $session['api_login'],
                'api_key' => $session['api_key'],
                'octopush_config_id' => $idConfig,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            if (!empty($existingCampagne)) {
                // Mettre à jour l'enregistrement existant
                $db->update('campagne', $campagneData, [
                    'id_campagne' => $existingCampagne[0]['id_campagne']
                ]);
            } else {
                // Créer un nouvel enregistrement
                $campagneData['id_compte'] = $idCompte;
                $campagneData['id_campagne_config'] = $campagneConfigId;
                $campagneData['created_at'] = date('Y-m-d H:i:s');
                $db->insert('campagne', $campagneData);
            }
            
            // Mettre à jour la campagne config
            $db->update('campagne_config', [
                'provider_id' => $providerId,
                'statut' => 'pret_a_envoyer',
                'type_envoi' => $typeEnvoi,
                'octopush_config_id' => $idConfig,
                'updated_at' => date('Y-m-d H:i:s')
            ], [
                'id_campagne_config' => $campagneConfigId,
                'id_compte' => $idCompte
            ]);
            
            // Sauvegarder l'ID de la session dans la session PHP
            $_SESSION['octopush_session_id'] = $idConfig;
            $_SESSION['octopush_api_login'] = $session['api_login'];
            $_SESSION['octopush_api_key'] = $session['api_key'];
            
            // Nettoyer les variables de session temporaires
            unset($_SESSION['message_sms_temp']);
            unset($_SESSION['destinataires_sms_temp']);
            unset($_SESSION['titre_sms_temp']);
            unset($_SESSION['type_envoi_temp']);
            unset($_SESSION['campagne_config_id_temp']);
            unset($_SESSION['octopush_provider_id']);
            
            $_SESSION['flash_message'] = " Session Octopush sélectionnée avec succès. La campagne est prête à être envoyée.";
            
            // 🔥 REDIRIGER VERS LA PAGE DES DÉTAILS
            header('Location: index.php?page=campagnes/details&id=' . $campagneConfigId);
            exit;
            
        } catch (Exception $e) {
            $error = "Erreur lors de la sélection de la session : " . $e->getMessage();
            error_log("Erreur: " . $e->getMessage());
        }
    }
}

// ============================================
// RÉCUPÉRER LES INFOS DU MESSAGE
// ============================================
$messagePreview = $_SESSION['message_sms_temp'] ?? null;
$destinatairesPreview = $_SESSION['destinataires_sms_temp'] ?? null;
$titrePreview = $_SESSION['titre_sms_temp'] ?? 'SMS';

// Si les sessions temporaires sont vides, récupérer depuis la base
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
    <title>Choisir la session Octopush - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===== STYLES ===== */
        * { box-sizing: border-box; }
        body { 
            margin: 0; 
            background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 16px 20px;
        }
        
        /* Toast */
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
        
        /* Step indicator */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 24px;
            padding: 12px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
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
        }
        .step-line.done {
            background: #10b981;
        }
        
        /* En-tête */
        .header-section {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 16px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .header-section .back-link {
            color: #6b7280;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
            margin-right: 16px;
            text-decoration: none;
        }
        .header-section .back-link:hover {
            color: #374151;
        }
        .header-section .icon-wrapper {
            background: #fff7ed;
            padding: 10px;
            border-radius: 12px;
            margin-right: 14px;
        }
        .header-section .icon-wrapper i {
            color: #ea580c;
            font-size: 22px;
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
        
        /* Card principale */
        .main-card {
            background: white;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            padding: 24px 28px;
        }
        
        /* Info campagne */
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
        }
        .campagne-info .info-right {
            font-size: 14px;
            color: #6b21a8;
        }
        .campagne-info .info-right i {
            margin-right: 6px;
        }
        
        /* Info message */
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
        }
        .message-preview .preview-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .message-preview .preview-left .label {
            font-size: 13px;
            color: #166534;
            font-weight: 600;
        }
        .message-preview .preview-left .message-text {
            font-size: 14px;
            color: #14532d;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .message-preview .preview-right {
            font-size: 13px;
            color: #166534;
        }
        .message-preview .preview-right i {
            margin-right: 4px;
        }
        
        /* Session cards */
        .session-option {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 12px;
            padding: 20px 16px;
            position: relative;
        }
        .session-option:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .session-option.selected {
            border-color: #ea580c;
            background-color: #fff7ed;
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.15);
        }
        .session-option .header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        .session-option .icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: #fff7ed;
            color: #ea580c;
            flex-shrink: 0;
        }
        .session-option .session-name {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
        }
        .session-option .session-details {
            margin-top: 8px;
            padding-left: 60px;
        }
        .session-option .session-details .detail-item {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        .session-option .session-details .detail-item i {
            width: 18px;
            color: #9ca3af;
        }
        .session-option .badge-selected {
            position: absolute;
            top: 12px;
            right: 12px;
            background: #ea580c;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .session-option .badge-selected i {
            margin-right: 4px;
        }
        
        /* Info Octopush */
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
        }
        .octopush-info i {
            font-size: 18px;
            color: #ea580c;
        }
        
        /* Empty state */
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
        .empty-state .btn-add {
            display: inline-block;
            margin-top: 16px;
            padding: 10px 24px;
            background: #ea580c;
            color: white;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .empty-state .btn-add:hover {
            background: #c2410c;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.3);
        }
        
        /* Boutons */
        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 2px solid #f3f4f6;
        }
        .btn-primary {
            background: #ea580c;
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
        }
        .btn-primary:hover:not(:disabled) {
            background: #c2410c;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(234, 88, 12, 0.3);
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
        }
        .btn-outline:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        
        /* Erreur */
        .error-box {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .error-box i {
            color: #ef4444;
            font-size: 18px;
        }
        .error-box span {
            color: #991b1b;
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Grille */
        .sessions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 0;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container { padding: 12px; }
            .header-section { flex-wrap: wrap; padding: 14px; }
            .header-section .title { font-size: 18px; }
            .header-section .subtitle { font-size: 13px; }
            .main-card { padding: 16px; }
            .campagne-info { flex-direction: column; align-items: flex-start; gap: 8px; }
            .message-preview { flex-direction: column; align-items: flex-start; gap: 8px; }
            .message-preview .preview-left .message-text { max-width: 100%; white-space: normal; }
            .step-indicator { flex-wrap: wrap; gap: 8px; padding: 10px 14px; }
            .step { font-size: 12px; }
            .step .number { width: 24px; height: 24px; font-size: 10px; }
            .step-line { width: 24px; }
            .sessions-grid { grid-template-columns: 1fr; gap: 12px; }
            .session-option { padding: 16px; }
            .session-option .session-details { padding-left: 0; }
            .session-option .header { flex-wrap: wrap; }
            .action-buttons { flex-direction: column; }
            .action-buttons .btn-primary,
            .action-buttons .btn-outline { width: 100%; justify-content: center; }
            .empty-state { padding: 32px 16px; }
            .empty-state i { font-size: 44px; }
            .empty-state h3 { font-size: 18px; }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .main-card { padding: 22px; }
            .sessions-grid { gap: 14px; }
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
            <span>Session</span>
        </div>
    </div>

    <!-- ===== EN-TÊTE ===== -->
    <div class="header-section">
        <a href="javascript:history.back()" class="back-link">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="icon-wrapper">
            <i class="fas fa-bolt"></i>
        </div>
        <div>
            <div class="title">Choisir la session Octopush</div>
            <div class="subtitle">Sélectionnez la configuration Octopush à utiliser pour l'envoi</div>
        </div>
    </div>

    <!-- ===== CARD PRINCIPALE ===== -->
    <div class="main-card">
        <!-- Info campagne -->
        <div class="campagne-info">
            <div class="info-left">
                <i class="fas fa-bullhorn" style="color: #7c3aed; font-size: 16px;"></i>
                <span class="campagne-name"><?= htmlspecialchars($campagne['nom_campagne']) ?></span>
                <span class="sms-badge"><i class="fas fa-comment-dots mr-1"></i>SMS</span>
            </div>
            <div class="info-right">
                <i class="fas fa-arrow-right"></i> Étape 4 sur 4
            </div>
        </div>
        
        <!-- ===== PRÉVISUALISATION DU MESSAGE ===== -->
        <?php if ($messagePreview): ?>
            <div class="message-preview">
                <div class="preview-left">
                    <span class="label"><i class="fas fa-envelope"></i> Message :</span>
                    <span class="message-text"><?= htmlspecialchars(mb_substr($messagePreview, 0, 100)) ?><?= mb_strlen($messagePreview) > 100 ? '...' : '' ?></span>
                </div>
                <div class="preview-right">
                    <i class="fas fa-users"></i> <?= $nbDestinataires ?> destinataire(s)
                </div>
            </div>
        <?php else: ?>
            <div class="message-preview" style="background: #fef2f2; border-color: #fecaca;">
                <div class="preview-left">
                    <span class="label" style="color: #991b1b;"><i class="fas fa-exclamation-triangle"></i> Avertissement :</span>
                    <span class="message-text" style="color: #991b1b;">Aucun message trouvé.</span>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Info Octopush -->
        <div class="octopush-info">
            <i class="fas fa-info-circle"></i>
            <span>
                Sélectionnez la configuration Octopush que vous souhaitez utiliser pour l'envoi de vos SMS.
                Les identifiants seront utilisés pour l'API Octopush.
            </span>
        </div>
        
        <!-- Erreur -->
        <?php if ($error): ?>
            <div class="error-box">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Sessions -->
        <?php if (empty($octopushSessions)): ?>
            <div class="empty-state">
                <i class="fas fa-bolt"></i>
                <h3>Aucune session Octopush</h3>
                <p>Vous n'avez pas encore configuré de session Octopush.</p>
                <p class="help-text">Contactez votre administrateur pour pouvoir envoyer des SMS via cette plateforme.</p>
            </div>
        <?php else: ?>
            <form method="POST" id="sessionForm">
                <input type="hidden" name="action_choisir_session" value="1">
                
                <!-- ===== SESSIONS CARDS ===== -->
                <div class="sessions-grid">
                    <?php foreach ($octopushSessions as $session): ?>
                        <div class="session-option" 
                             data-session-id="<?= $session['id_config'] ?>"
                             onclick="selectSession('<?= $session['id_config'] ?>')"
                             role="button"
                             tabindex="0"
                             aria-label="Sélectionner <?= htmlspecialchars($session['nom_config']) ?>">
                            
                            <div class="header">
                                <div class="icon-wrapper">
                                    <i class="fas fa-bolt"></i>
                                </div>
                                <div>
                                    <div class="session-name"><?= htmlspecialchars($session['nom_config']) ?></div>
                                </div>
                            </div>
                            
                            <div class="session-details">
                                <div class="detail-item">
                                    <i class="fas fa-user"></i> API Login: <?= htmlspecialchars(substr($session['api_login'], 0, 10)) ?>...
                                </div>
                                <div class="detail-item">
                                    <i class="fas fa-key"></i> API Key: ••••••••••••••••••••
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <input type="hidden" name="id_config" id="id_config" value="">
                
                <!-- ===== BOUTONS ACTION ===== -->
                <div class="action-buttons">
                    <a href="index.php?page=campagnes/choisir_provider_sms&campagne_id=<?= $campagneConfigId ?>" class="btn-outline">
                        <i class="fas fa-arrow-left"></i> Retour
                    </a>
                    <button type="submit" class="btn-primary" id="btnContinuer" disabled>
                        <span>Confirmer et voir les détails</span>
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
let selectedSession = null;

function selectSession(sessionId) {
    selectedSession = sessionId;
    
    // Mettre à jour l'interface
    document.querySelectorAll('.session-option').forEach(el => {
        el.classList.remove('selected');
        // Supprimer le badge sélectionné
        const badge = el.querySelector('.badge-selected');
        if (badge) badge.remove();
    });
    
    // Sélectionner la carte
    const selectedEl = document.querySelector(`.session-option[data-session-id="${sessionId}"]`);
    if (selectedEl) {
        selectedEl.classList.add('selected');
        
        // Ajouter le badge sélectionné
        const badge = document.createElement('div');
        badge.className = 'badge-selected';
        badge.innerHTML = '<i class="fas fa-check"></i> Sélectionné';
        selectedEl.appendChild(badge);
    }
    
    // Activer le bouton
    document.getElementById('id_config').value = sessionId;
    document.getElementById('btnContinuer').disabled = false;
}

// Gestion du clavier pour l'accessibilité
document.querySelectorAll('.session-option').forEach(el => {
    el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            const sessionId = this.dataset.sessionId;
            selectSession(sessionId);
        }
    });
});

// Toast notification
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