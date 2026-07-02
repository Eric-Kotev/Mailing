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

// Vérifier que le provider est sélectionné
if (!isset($_SESSION['provider_whatsapp_id']) || !$_SESSION['provider_whatsapp_id']) {
    header('Location: index.php?page=campagnes/choix_provider_whatsapp&campagne_id=' . $campagneConfigId);
    exit;
}

// Récupérer toutes les sessions WhatsApp de l'utilisateur
$sessions = $db->select('whatsapp_sessions', ['id_compte' => $idCompte], '*', 'created_at.desc');

// Récupérer la session active WhatsApp
$sessionActive = null;
foreach ($sessions as $s) {
    if ($s['est_active']) {
        $sessionActive = $s['id_session'];
        break;
    }
}

$error = '';

// Traitement de la sélection de la session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_choisir_session'])) {
    $id_session = $_POST['id_session'] ?? null;
    
    if (!$id_session) {
        $error = "Veuillez sélectionner une session";
    } else {
        try {
            // Récupérer les infos de la session
            $session = $db->select('whatsapp_sessions', [
                'id_session' => $id_session,
                'id_compte' => $idCompte
            ]);
            
            if (empty($session)) {
                $error = "Session non trouvée";
            } else {
                // Désactiver toutes les sessions
                $db->update('whatsapp_sessions', ['est_active' => false], ['id_compte' => $idCompte]);
                
                // Activer la session sélectionnée
                $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $id_session]);
                
                // Stocker en session
                $_SESSION['whatsapp_session_id'] = $id_session;
                $_SESSION['whatsapp_session_name'] = $session[0]['nom_session'];
                
                // Récupérer le provider ID
                $providerId = (int)$_SESSION['provider_whatsapp_id'];
                
                // Mettre à jour la campagne config avec le statut et le provider
                $db->update('campagne_config', [
                    'statut' => 'pret_a_envoyer',
                    'provider_id' => $providerId,
                    'updated_at' => date('Y-m-d H:i:s')
                ], [
                    'id_campagne_config' => $campagneConfigId,
                    'id_compte' => $idCompte
                ]);
                
                // Mettre à jour la table campagne (historique)
                $campagneHistorique = $db->select('campagne', [
                    'id_campagne_config' => $campagneConfigId,
                    'id_compte' => $idCompte,
                    'statut' => 'brouillon'
                ], '*', 'created_at DESC', 1);
                
                if (!empty($campagneHistorique)) {
                    $db->update('campagne', [
                        'statut' => 'pret_a_envoyer',
                        'provider_id' => $providerId,
                        'appareil_utilise' => $session[0]['nom_session']
                    ], ['id_campagne' => $campagneHistorique[0]['id_campagne']]);
                }
                
                // Nettoyer les variables de session
                unset($_SESSION['message_content']);
                unset($_SESSION['type_envoi']);
                unset($_SESSION['campagne_config_id']);
                unset($_SESSION['type_message']);
                unset($_SESSION['provider_whatsapp_id']);
                unset($_SESSION['fichier_info']);
                
                // 🔥 REDIRECTION VERS details.php AVEC LE campagne_id
                $_SESSION['flash_message'] = "✅ Message WhatsApp ajouté avec succès à la campagne !";
                header('Location: index.php?page=campagnes/details&id=' . $campagneConfigId);
                exit;
            }
        } catch (Exception $e) {
            $error = "Erreur lors de l'ajout du message : " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choisir la session WhatsApp - <?= APP_NAME ?></title>
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
        .toast-notification.warning .toast-content { background: #f59e0b; }
        
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
            flex-wrap: wrap;
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
        .campagne-info .info-left .provider-info {
            font-size: 14px;
            color: #6b21a8;
            background: #ede9fe;
            padding: 4px 12px;
            border-radius: 16px;
        }
        .campagne-info .info-right {
            font-size: 15px;
            color: #6b21a8;
        }
        .campagne-info .info-right i {
            margin-right: 6px;
        }
        
        /* Session cards - agrandies */
        .session-option {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 3px solid #e5e7eb;
            background: white;
            border-radius: 16px;
            padding: 28px 20px;
            text-align: center;
        }
        .session-option:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.1);
        }
        .session-option.selected {
            border-color: #25D366;
            background-color: #f0fdf4;
            box-shadow: 0 0 0 4px rgba(37, 211, 102, 0.15);
        }
        .session-option .icon-wrapper {
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
        .session-option .session-name {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
        }
        .session-option .session-date {
            font-size: 14px;
            color: #6b7280;
            margin-top: 4px;
        }
        .session-option .badge-actif {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 600;
            background: #dcfce7;
            color: #166534;
            margin-top: 8px;
        }
        .session-option .badge-actif i {
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
        .empty-state .btn-config {
            display: inline-block;
            margin-top: 20px;
            background: #25D366;
            color: white;
            padding: 14px 32px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .empty-state .btn-config:hover {
            background: #1da851;
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(37, 211, 102, 0.3);
        }
        .empty-state .btn-config i {
            font-size: 16px;
            color: white;
            margin-right: 8px;
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
        
        /* Grille sessions */
        .sessions-grid {
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
            .campagne-info .info-left { flex-direction: column; align-items: flex-start; gap: 6px; }
            .sessions-grid { grid-template-columns: 1fr; gap: 14px; }
            .session-option { padding: 20px 16px; }
            .session-option .icon-wrapper { width: 64px; height: 64px; font-size: 28px; }
            .session-option .session-name { font-size: 17px; }
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
            .empty-state .btn-config { width: 100%; text-align: center; }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .main-card { padding: 28px; }
            .sessions-grid { gap: 16px; }
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
        <div class="step done">
            <span class="number"><i class="fas fa-check"></i></span>
            <span>Provider</span>
        </div>
        <div class="step-line done"></div>
        <div class="step active">
            <span class="number">4</span>
            <span>Session</span>
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
            <i class="fab fa-whatsapp"></i>
        </div>
        <div>
            <div class="title">Choisir la session WhatsApp</div>
            <div class="subtitle">Sélectionnez la session pour l'envoi de vos messages WhatsApp</div>
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
                <span class="provider-info">
                    <i class="fas fa-server mr-1"></i>
                    Provider #<?= htmlspecialchars($_SESSION['provider_whatsapp_id'] ?? 'Non sélectionné') ?>
                </span>
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
        
        <!-- Sessions -->
        <?php if (empty($sessions)): ?>
            <div class="empty-state">
                <i class="fab fa-whatsapp"></i>
                <h3>Aucune session disponible</h3>
                <p>Vous n'avez pas encore configuré de session WhatsApp.</p>
                <p class="help-text">Veuillez configurer une session WhatsApp avant de continuer.</p>
                <a href="index.php?page=parametres/whatsapp" class="btn-config">
                    <i class="fas fa-plus-circle"></i> Configurer une session
                </a>
            </div>
        <?php else: ?>
            <form method="POST" id="sessionForm">
                <input type="hidden" name="action_choisir_session" value="1">
                
                <!-- ===== SESSIONS CARDS ===== -->
                <div class="sessions-grid">
                    <?php foreach ($sessions as $session): ?>
                        <div class="session-option <?= ($sessionActive == $session['id_session']) ? 'selected' : '' ?>" 
                             data-session-id="<?= $session['id_session'] ?>"
                             onclick="selectSession('<?= $session['id_session'] ?>')">
                            <div class="icon-wrapper">
                                <i class="fab fa-whatsapp"></i>
                            </div>
                            <div class="session-name"><?= htmlspecialchars($session['nom_session']) ?></div>
                            <div class="session-date">
                                <i class="far fa-calendar-alt mr-1"></i>
                                Créée le <?= date('d/m/Y H:i', strtotime($session['created_at'])) ?>
                            </div>
                            <?php if ($sessionActive == $session['id_session']): ?>
                                <span class="badge-actif"><i class="fas fa-check-circle"></i>Active</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <input type="hidden" name="id_session" id="id_session" value="<?= $sessionActive ?>">
                
                <!-- ===== BOUTONS ACTION ===== -->
                <div class="action-buttons">
                    <a href="index.php?page=campagnes/choix_provider_whatsapp&campagne_id=<?= $campagneConfigId ?>" class="btn-outline">
                        <i class="fas fa-times"></i> Annuler
                    </a>
                    <button type="submit" class="btn-primary" id="btnContinuer" <?= !$sessionActive ? 'disabled' : '' ?>>
                        <i class="fas fa-check-circle"></i>
                        <span>Ajouter le message</span>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
let selectedSession = <?= json_encode($sessionActive) ?>;

function selectSession(sessionId) {
    selectedSession = sessionId;
    
    // Mettre à jour l'interface
    document.querySelectorAll('.session-option').forEach(el => {
        el.classList.remove('selected');
        // Supprimer le badge actif
        const badge = el.querySelector('.badge-actif');
        if (badge) badge.remove();
    });
    
    // Sélectionner la carte
    const selectedEl = document.querySelector(`.session-option[data-session-id="${sessionId}"]`);
    if (selectedEl) {
        selectedEl.classList.add('selected');
        
        // Ajouter le badge actif
        const badge = document.createElement('span');
        badge.className = 'badge-actif';
        badge.innerHTML = '<i class="fas fa-check-circle"></i>Active';
        selectedEl.appendChild(badge);
    }
    
    // Activer le bouton
    document.getElementById('id_session').value = sessionId;
    document.getElementById('btnContinuer').disabled = false;
}

// Si une session est déjà sélectionnée, activer le bouton
document.addEventListener('DOMContentLoaded', function() {
    if (selectedSession) {
        document.getElementById('btnContinuer').disabled = false;
    }
});

// Gestion du clavier pour l'accessibilité
document.querySelectorAll('.session-option').forEach(el => {
    el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            const sessionId = this.dataset.sessionId;
            selectSession(sessionId);
        }
    });
    el.setAttribute('tabindex', '0');
    el.setAttribute('role', 'button');
    el.setAttribute('aria-label', 'Sélectionner cette session');
});

// Confirmation avant la création
document.getElementById('sessionForm').addEventListener('submit', function(e) {
    const selected = document.getElementById('id_session').value;
    if (!selected) {
        e.preventDefault();
        showToast('Veuillez sélectionner une session', 'error');
        return false;
    }
});

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