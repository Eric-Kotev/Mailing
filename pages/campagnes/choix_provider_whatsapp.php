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

// Vérifier que le type de message est WhatsApp
$typeMessage = $_SESSION['type_message'] ?? null;
if ($typeMessage !== 'whatsapp') {
    $_SESSION['flash_error'] = "Type de message non valide";
    header('Location: index.php?page=campagnes/choix_type&campagne_id=' . $campagneConfigId);
    exit;
}

// Récupérer l'ID du type WhatsApp (id_type_message = 3)
$typeMessageWhatsapp = $db->select('type_message', ['libelle_type' => 'WhatsApp']);
if (empty($typeMessageWhatsapp)) {
    $typeMessageWhatsapp = $db->select('type_message', ['libelle_type' => 'whatsapp']);
}
$whatsappTypeId = !empty($typeMessageWhatsapp) ? (int)$typeMessageWhatsapp[0]['id_type_message'] : null;

// Récupérer les providers WhatsApp disponibles
$providers = $db->select('provider', ['id_type_message' => $whatsappTypeId]);

// Récupérer le provider actif de l'utilisateur pour WhatsApp
$providerActif = null;
$providerActifData = $db->select('provider_actif', [
    'id_compte' => $idCompte,
    'id_type_message' => $whatsappTypeId
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
                'id_type_message' => $whatsappTypeId
            ]);
            
            if (!empty($existing)) {
                // Mettre à jour
                $db->update('provider_actif', [
                    'id_provider' => $id_provider,
                    'updated_at' => date('Y-m-d H:i:s')
                ], [
                    'id_compte' => $idCompte,
                    'id_type_message' => $whatsappTypeId
                ]);
            } else {
                // Insérer
                $db->insert('provider_actif', [
                    'id_compte' => $idCompte,
                    'id_type_message' => $whatsappTypeId,
                    'id_provider' => $id_provider,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
            
            $_SESSION['provider_whatsapp_id'] = $id_provider;
            
            // Rediriger vers la page de choix de la session WhatsApp
            header('Location: index.php?page=campagnes/choix_session_whatsapp&campagne_id=' . $campagneConfigId);
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
    <title>Choisir le provider WhatsApp - <?= APP_NAME ?></title>
    <style>
        /* ===== STYLES AGRANDIS ===== */
        * { box-sizing: border-box; }
        body { 
            margin: 0; 
            background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .container-full {
            max-width: 1100px;
            margin: 0 auto;
            padding: 20px 30px;
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
            padding: 14px 24px;
            border-radius: 10px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            font-size: 15px;
            font-weight: 500;
        }
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        .toast-notification.info .toast-content { background: #3b82f6; }
        
        /* Step indicator agrandi */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin-bottom: 36px;
            padding: 16px 24px;
            background: white;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .step {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            color: #9ca3af;
        }
        .step .number {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.3s ease;
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
            width: 50px;
            height: 3px;
            background: #e5e7eb;
            border-radius: 2px;
        }
        .step-line.done {
            background: #10b981;
        }
        
        /* En-tête agrandi */
        .header-section {
            display: flex;
            align-items: center;
            margin-bottom: 28px;
            padding: 20px 24px;
            background: white;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .header-section .back-link {
            color: #6b7280;
            font-size: 16px;
            font-weight: 500;
            transition: color 0.2s;
            margin-right: 20px;
        }
        .header-section .back-link:hover {
            color: #374151;
        }
        .header-section .icon-wrapper {
            background: #dcfce7;
            padding: 14px;
            border-radius: 14px;
            margin-right: 18px;
        }
        .header-section .icon-wrapper i {
            color: #16a34a;
            font-size: 28px;
        }
        .header-section .title {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
        }
        .header-section .subtitle {
            font-size: 17px;
            color: #6b7280;
            margin-top: 2px;
        }
        
        /* Card principale agrandie */
        .main-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            padding: 32px 36px;
        }
        
        /* Info campagne agrandie */
        .campagne-info {
            background: #f3e8ff;
            border: 2px solid #d8b4fe;
            border-radius: 14px;
            padding: 18px 24px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
        }
        .campagne-info .info-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .campagne-info .info-left .campagne-name {
            font-size: 17px;
            font-weight: 700;
            color: #5b21b6;
        }
        .campagne-info .info-left .whatsapp-badge {
            background: #25D366;
            color: white;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        .campagne-info .info-right {
            font-size: 15px;
            color: #6b21a8;
        }
        .campagne-info .info-right i {
            margin-right: 6px;
        }
        
        /* Provider cards - agrandies */
        .provider-option {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 3px solid #e5e7eb;
            background: white;
            border-radius: 16px;
            padding: 28px 20px;
            text-align: center;
        }
        .provider-option:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.1);
        }
        .provider-option.selected {
            border-color: #25D366;
            background-color: #f0fdf4;
            box-shadow: 0 0 0 4px rgba(37, 211, 102, 0.15);
        }
        .provider-option .icon-wrapper {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            font-size: 34px;
            background: #dcfce7;
            color: #16a34a;
        }
        .provider-option .provider-name {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
        }
        .provider-option .provider-desc {
            font-size: 15px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        .provider-option .badge-actif {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 600;
            background: #dcfce7;
            color: #166534;
        }
        .provider-option .badge-actif i {
            margin-right: 4px;
        }
        
        /* Empty state agrandi */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-state i {
            font-size: 64px;
            color: #d1d5db;
            margin-bottom: 20px;
        }
        .empty-state h3 {
            font-size: 22px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 8px;
        }
        .empty-state p {
            color: #6b7280;
            font-size: 16px;
        }
        .empty-state .help-text {
            font-size: 14px;
            color: #9ca3af;
            margin-top: 8px;
        }
        
        /* Boutons d'action agrandis */
        .action-buttons {
            display: flex;
            gap: 14px;
            justify-content: flex-end;
            margin-top: 28px;
            padding-top: 20px;
            border-top: 2px solid #f3f4f6;
        }
        .btn-primary {
            background: #25D366;
            color: white;
            padding: 14px 36px;
            border-radius: 10px;
            font-size: 17px;
            font-weight: 700;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .btn-primary:hover:not(:disabled) {
            background: #1da851;
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(37, 211, 102, 0.35);
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
            padding: 14px 28px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            border: 2px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-outline:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        
        /* Erreur */
        .error-box {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .error-box i {
            color: #ef4444;
            font-size: 20px;
        }
        .error-box span {
            color: #991b1b;
            font-size: 15px;
            font-weight: 500;
        }
        
        /* Grille providers */
        .providers-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 0;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container-full { padding: 12px 16px; }
            .header-section { flex-wrap: wrap; padding: 16px; }
            .header-section .title { font-size: 22px; }
            .header-section .subtitle { font-size: 14px; }
            .main-card { padding: 20px; }
            .campagne-info { flex-direction: column; align-items: flex-start; gap: 10px; }
            .providers-grid { grid-template-columns: 1fr; gap: 14px; }
            .provider-option { padding: 20px 16px; }
            .provider-option .icon-wrapper { width: 64px; height: 64px; font-size: 28px; }
            .provider-option .provider-name { font-size: 17px; }
            .step-indicator { flex-wrap: wrap; gap: 10px; padding: 12px 16px; }
            .step { font-size: 13px; }
            .step .number { width: 28px; height: 28px; font-size: 12px; }
            .step-line { width: 30px; }
            .action-buttons { flex-direction: column; }
            .action-buttons .btn-primary,
            .action-buttons .btn-outline { width: 100%; justify-content: center; }
            .empty-state { padding: 40px 16px; }
            .empty-state i { font-size: 48px; }
            .empty-state h3 { font-size: 19px; }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .main-card { padding: 28px; }
            .providers-grid { gap: 16px; }
        }
    </style>
</head>
<body>

<div class="container-full">
    <!-- ===== STEP INDICATOR ===== -->
    <div class="step-indicator">
        <div class="step done">
            <span class="number"><i class="fas fa-check"></i></span>
            <span>Type de message</span>
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
            <span>Session</span>
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
        <div>
            <div class="title">Choisir le provider WhatsApp</div>
            <div class="subtitle">Sélectionnez le provider pour l'envoi de vos messages WhatsApp</div>
        </div>
    </div>

    <!-- ===== CARD PRINCIPALE ===== -->
    <div class="main-card">
        <!-- Info campagne -->
        <div class="campagne-info">
            <div class="info-left">
                <i class="fas fa-bullhorn" style="color: #7c3aed; font-size: 18px;"></i>
                <span class="campagne-name"><?= htmlspecialchars($campagne['nom_campagne']) ?></span>
                <span class="whatsapp-badge"><i class="fab fa-whatsapp mr-1"></i>WhatsApp</span>
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
                <i class="fab fa-whatsapp"></i>
                <h3>Aucun provider disponible</h3>
                <p>Aucun provider WhatsApp n'est configuré pour le moment.</p>
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
                                <i class="fab fa-whatsapp"></i>
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
                    <a href="index.php?page=campagnes/composer_whatsapp&campagne_id=<?= $campagneConfigId ?>" class="btn-outline">
                        <i class="fas fa-times"></i> Annuler
                    </a>
                    <button type="submit" class="btn-primary" id="btnContinuer" <?= !$providerActif ? 'disabled' : '' ?>>
                        <span>Continuer vers la session</span>
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
</script>

</body>
</html>