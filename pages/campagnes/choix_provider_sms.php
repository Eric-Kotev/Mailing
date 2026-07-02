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

// Traitement du formulaire - Sélection du provider
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_choisir_provider'])) {
    $id_provider = (int)($_POST['id_provider'] ?? 0);
    
    if (!$id_provider) {
        $error = "Veuillez sélectionner un provider";
    } else {
        try {
            // Vérifier si le provider existe déjà pour ce compte
            $existing = $db->select('provider_actif', [
                'id_compte' => $idCompte,
                'id_type_message' => $smsTypeId
            ]);
            
            if (!empty($existing)) {
                // Mettre à jour
                $db->update('provider_actif', [
                    'id_provider' => $id_provider,
                    'updated_at' => date('Y-m-d H:i:s')
                ], [
                    'id_compte' => $idCompte,
                    'id_type_message' => $smsTypeId
                ]);
            } else {
                // Insérer
                $db->insert('provider_actif', [
                    'id_compte' => $idCompte,
                    'id_type_message' => $smsTypeId,
                    'id_provider' => $id_provider,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
            
            $_SESSION['provider_sms_id'] = $id_provider;
            
            // Rediriger vers la page de choix du canal
            header('Location: index.php?page=campagnes/choix_appareil_sms&campagne_id=' . $campagneConfigId);
            exit;
            
        } catch (Exception $e) {
            $error = "Erreur lors de la sélection du provider : " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choisir le provider SMS - <?= APP_NAME ?></title>
    <style>
        /* ===== STYLES ÉQUILIBRÉS ===== */
        * { box-sizing: border-box; }
        body { 
            margin: 0; 
            background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .container {
            max-width: 1000px;
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
        }
        .header-section .back-link:hover {
            color: #374151;
        }
        .header-section .icon-wrapper {
            background: #dbeafe;
            padding: 10px;
            border-radius: 12px;
            margin-right: 14px;
        }
        .header-section .icon-wrapper i {
            color: #2563eb;
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
        
        /* Provider cards */
        .provider-option {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 12px;
            padding: 20px 16px;
            text-align: center;
        }
        .provider-option:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
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
        }
        .btn-primary:hover:not(:disabled) {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.3);
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
        .providers-grid {
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
            .step-indicator { flex-wrap: wrap; gap: 8px; padding: 10px 14px; }
            .step { font-size: 12px; }
            .step .number { width: 24px; height: 24px; font-size: 10px; }
            .step-line { width: 24px; }
            .providers-grid { grid-template-columns: 1fr; gap: 12px; }
            .provider-option { padding: 16px 12px; }
            .provider-option .icon-wrapper { width: 56px; height: 56px; font-size: 22px; }
            .provider-option .provider-name { font-size: 15px; }
            .action-buttons { flex-direction: column; }
            .action-buttons .btn-primary,
            .action-buttons .btn-outline { width: 100%; justify-content: center; }
            .empty-state { padding: 32px 16px; }
            .empty-state i { font-size: 44px; }
            .empty-state h3 { font-size: 18px; }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .main-card { padding: 22px; }
            .providers-grid { gap: 14px; }
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
        <div>
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
                <span class="sms-badge"><i class="fas fa-comment-dots mr-1"></i>SMS</span>
            </div>
            <div class="info-right">
                <i class="fas fa-arrow-right"></i> Étape 3 sur 4
            </div>
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
                    <?php foreach ($providers as $provider): ?>
                        <div class="provider-option <?= ($providerActif == $provider['id_provider']) ? 'selected' : '' ?>" 
                             data-provider-id="<?= $provider['id_provider'] ?>"
                             onclick="selectProvider('<?= $provider['id_provider'] ?>')">
                            <div class="icon-wrapper">
                                <i class="fas fa-building"></i>
                            </div>
                            <div class="provider-name"><?= htmlspecialchars($provider['nom_providers']) ?></div>
                            <?php if (!empty($provider['description'])): ?>
                                <div class="provider-desc"><?= htmlspecialchars($provider['description']) ?></div>
                            <?php endif; ?>
                            <?php if ($providerActif == $provider['id_provider']): ?>
                                <span class="badge-actif"><i class="fas fa-check-circle"></i>Actif</span>
                            <?php endif; ?>
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
                        <span>Continuer vers l'appareil</span>
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
let selectedProvider = <?= json_encode($providerActif) ?>;

function selectProvider(providerId) {
    selectedProvider = providerId;
    
    // Mettre à jour l'interface
    document.querySelectorAll('.provider-option').forEach(el => {
        el.classList.remove('selected');
        // Supprimer le badge actif
        const badge = el.querySelector('.badge-actif');
        if (badge) badge.remove();
    });
    
    // Sélectionner la carte
    const selectedEl = document.querySelector(`.provider-option[data-provider-id="${providerId}"]`);
    if (selectedEl) {
        selectedEl.classList.add('selected');
        
        // Ajouter le badge actif
        const badge = document.createElement('span');
        badge.className = 'badge-actif';
        badge.innerHTML = '<i class="fas fa-check-circle"></i>Actif';
        selectedEl.appendChild(badge);
    }
    
    // Activer le bouton
    document.getElementById('id_provider').value = providerId;
    document.getElementById('btnContinuer').disabled = false;
}

// Si un provider est déjà sélectionné, activer le bouton
document.addEventListener('DOMContentLoaded', function() {
    if (selectedProvider) {
        document.getElementById('btnContinuer').disabled = false;
    }
});

// Gestion du clavier pour l'accessibilité
document.querySelectorAll('.provider-option').forEach(el => {
    el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            const providerId = this.dataset.providerId;
            selectProvider(providerId);
        }
    });
    el.setAttribute('tabindex', '0');
    el.setAttribute('role', 'button');
    el.setAttribute('aria-label', 'Sélectionner ce provider');
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
    setTimeout(() => toast.remove(), 3000);
}
</script>

</body>
</html>