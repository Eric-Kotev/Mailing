<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer l'ID de la campagne depuis l'URL
$campagneConfigId = $_GET['campagne_id'] ?? null;

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

// Stocker l'ID en session pour les pages suivantes
$_SESSION['campagne_config_id'] = $campagneConfigId;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choisir le type de message - <?= APP_NAME ?></title>
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
        
        .container-full {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px 32px;
            width: 100%;
        }
        
        /* ============================================
           EN-TÊTE
        ============================================ */
        .header-section {
            display: flex;
            align-items: center;
            margin-bottom: 28px;
            padding: 20px 28px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            width: 100%;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .header-section .back-link {
            color: #6b7280;
            font-size: 15px;
            font-weight: 500;
            transition: color 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 8px;
            flex-shrink: 0;
        }
        .header-section .back-link:hover {
            color: #374151;
            background: #f3f4f6;
        }
        
        .header-section .icon-wrapper {
            background: #f3e8ff;
            padding: 12px 14px;
            border-radius: 14px;
            flex-shrink: 0;
        }
        .header-section .icon-wrapper i {
            color: #7c3aed;
            font-size: 26px;
        }
        
        .header-section .header-text {
            flex: 1;
            min-width: 200px;
        }
        .header-section .title {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
        }
        .header-section .subtitle {
            font-size: 16px;
            color: #6b7280;
            margin-top: 2px;
        }
        
        /* ============================================
           INFO CAMPAGNE
        ============================================ */
        .campagne-info {
            background: #f3e8ff;
            border: 2px solid #d8b4fe;
            border-radius: 16px;
            padding: 18px 28px;
            margin-bottom: 28px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            width: 100%;
        }
        .campagne-info .info-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .campagne-info .info-left .campagne-name {
            font-size: 18px;
            font-weight: 700;
            color: #5b21b6;
        }
        .campagne-info .info-left .badge-campagne {
            background: #7c3aed;
            color: white;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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
           CARDS DE CHOIX - GRID 3 COLONNES
        ============================================ */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 28px;
            width: 100%;
        }
        
        .type-option {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 3px solid #e5e7eb;
            background: white;
            border-radius: 16px;
            padding: 32px 20px;
            text-align: center;
            position: relative;
            min-height: 220px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .type-option:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.1);
        }
        .type-option.selected {
            border-color: #8b5cf6;
            background-color: #f5f3ff;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.15);
        }
        .type-option.selected::after {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            top: 12px;
            right: 16px;
            color: #8b5cf6;
            font-size: 22px;
        }
        
        .type-option .icon-wrapper {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            font-size: 32px;
            flex-shrink: 0;
        }
        .type-option.sms .icon-wrapper { background: #dbeafe; color: #2563eb; }
        .type-option.whatsapp .icon-wrapper { background: #dcfce7; color: #16a34a; }
        .type-option.email .icon-wrapper { background: #fef3c7; color: #d97706; }
        
        .type-option h3 {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
        }
        .type-option p {
            font-size: 14px;
            color: #6b7280;
            margin: 0;
        }
        .type-option .type-icon-big {
            display: none;
        }
        
        /* ============================================
           BOUTON SECTION
        ============================================ */
        .btn-section {
            text-align: center;
            margin-top: 0;
            padding: 20px 28px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            width: 100%;
        }
        
        .btn-continue {
            background: #8b5cf6;
            color: white;
            padding: 16px 48px;
            border-radius: 14px;
            font-size: 18px;
            font-weight: 700;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            min-width: 240px;
            justify-content: center;
        }
        .btn-continue:hover:not(:disabled) {
            background: #7c3aed;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(139, 92, 246, 0.35);
        }
        .btn-continue:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }
        .btn-continue i {
            font-size: 18px;
        }
        
        .btn-section .hint {
            font-size: 14px;
            color: #9ca3af;
            margin-top: 12px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn-section .hint i {
            font-size: 16px;
        }
        
        /* ============================================
           RESPONSIVE
        ============================================ */
        @media (max-width: 1200px) {
            .container-full {
                padding: 16px 24px;
            }
            .cards-grid {
                gap: 18px;
            }
            .type-option {
                padding: 28px 16px;
                min-height: 200px;
            }
            .type-option .icon-wrapper {
                width: 68px;
                height: 68px;
                font-size: 28px;
            }
            .type-option h3 {
                font-size: 18px;
            }
        }
        
        @media (max-width: 992px) {
            .container-full {
                padding: 16px 20px;
            }
            .header-section {
                padding: 16px 20px;
            }
            .header-section .title {
                font-size: 24px;
            }
            .header-section .subtitle {
                font-size: 15px;
            }
            .cards-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 14px;
            }
            .type-option {
                padding: 24px 14px;
                min-height: 180px;
            }
            .type-option .icon-wrapper {
                width: 60px;
                height: 60px;
                font-size: 24px;
                margin-bottom: 10px;
            }
            .type-option h3 {
                font-size: 17px;
            }
            .type-option p {
                font-size: 13px;
            }
            .campagne-info {
                padding: 14px 20px;
            }
            .campagne-info .info-left .campagne-name {
                font-size: 16px;
            }
        }
        
        @media (max-width: 768px) {
            .container-full {
                padding: 12px 16px;
            }
            
            .header-section {
                flex-direction: column;
                align-items: flex-start;
                padding: 16px;
                gap: 8px;
            }
            .header-section .back-link {
                margin-bottom: 4px;
            }
            .header-section .header-text {
                width: 100%;
            }
            .header-section .title {
                font-size: 22px;
            }
            .header-section .subtitle {
                font-size: 14px;
            }
            .header-section .icon-wrapper {
                padding: 10px 12px;
            }
            .header-section .icon-wrapper i {
                font-size: 22px;
            }
            
            .campagne-info {
                flex-direction: column;
                align-items: flex-start;
                padding: 14px 18px;
                gap: 8px;
            }
            .campagne-info .info-left {
                width: 100%;
            }
            .campagne-info .info-left .campagne-name {
                font-size: 15px;
            }
            .campagne-info .info-right {
                font-size: 13px;
                width: 100%;
            }
            
            .cards-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .type-option {
                padding: 20px 16px;
                min-height: 140px;
                flex-direction: row;
                align-items: center;
                gap: 16px;
                text-align: left;
            }
            .type-option .icon-wrapper {
                width: 56px;
                height: 56px;
                font-size: 22px;
                margin: 0;
                flex-shrink: 0;
            }
            .type-option h3 {
                font-size: 17px;
                margin-bottom: 2px;
            }
            .type-option p {
                font-size: 13px;
            }
            .type-option.selected::after {
                top: 8px;
                right: 12px;
                font-size: 18px;
            }
            
            .btn-section {
                padding: 16px 20px;
            }
            .btn-continue {
                padding: 14px 32px;
                font-size: 16px;
                width: 100%;
                min-width: unset;
                justify-content: center;
            }
            .btn-section .hint {
                font-size: 13px;
            }
        }
        
        @media (max-width: 480px) {
            .container-full {
                padding: 8px 10px;
            }
            .header-section {
                padding: 12px 14px;
            }
            .header-section .title {
                font-size: 19px;
            }
            .header-section .subtitle {
                font-size: 13px;
            }
            .header-section .back-link {
                font-size: 13px;
                padding: 4px 10px;
            }
            .header-section .icon-wrapper {
                padding: 8px 10px;
            }
            .header-section .icon-wrapper i {
                font-size: 18px;
            }
            
            .campagne-info {
                padding: 12px 14px;
            }
            .campagne-info .info-left .campagne-name {
                font-size: 14px;
            }
            .campagne-info .info-left .badge-campagne {
                font-size: 10px;
                padding: 3px 10px;
            }
            
            .type-option {
                padding: 14px 12px;
                min-height: 100px;
                gap: 12px;
            }
            .type-option .icon-wrapper {
                width: 44px;
                height: 44px;
                font-size: 18px;
            }
            .type-option h3 {
                font-size: 15px;
            }
            .type-option p {
                font-size: 12px;
            }
            .type-option.selected::after {
                font-size: 14px;
                top: 6px;
                right: 8px;
            }
            
            .btn-section {
                padding: 12px 14px;
            }
            .btn-continue {
                padding: 12px 24px;
                font-size: 14px;
                gap: 8px;
            }
            .btn-continue i {
                font-size: 14px;
            }
            .btn-section .hint {
                font-size: 12px;
            }
        }
        
        /* ============================================
           UTILITIES
        ============================================ */
        .flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .items-center { align-items: center; }
        .gap-2 { gap: 8px; }
        .gap-3 { gap: 12px; }
        .gap-4 { gap: 16px; }
        .mr-2 { margin-right: 8px; }
        .mb-2 { margin-bottom: 8px; }
        .w-full { width: 100%; }
        .text-center { text-align: center; }
        .text-sm { font-size: 14px; }
        .text-gray-500 { color: #6b7280; }
        .font-semibold { font-weight: 600; }
        .font-bold { font-weight: 700; }
        
        .bg-white { background: white; }
        .rounded-lg { border-radius: 8px; }
        .rounded-xl { border-radius: 12px; }
        .shadow-sm { box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .border { border: 1px solid #e5e7eb; }
        
        .cursor-pointer { cursor: pointer; }
        .transition { transition: all 0.3s ease; }
        .hover-shadow:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    </style>
</head>
<body>

<div class="container-full">
    <!-- ===== EN-TÊTE ===== -->
    <div class="header-section">
        <a href="index.php?page=campagnes/details&id=<?= $campagneConfigId ?>" class="back-link">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="icon-wrapper">
            <i class="fas fa-comment-dots"></i>
        </div>
        <div class="header-text">
            <div class="title">Choisir le type de message</div>
            <div class="subtitle">Sélectionnez comment vous voulez envoyer votre message</div>
        </div>
    </div>

    <!-- ===== INFO CAMPAGNE ===== -->
    <div class="campagne-info">
        <div class="info-left">
            <i class="fas fa-bullhorn" style="color: #7c3aed; font-size: 18px;"></i>
            <span class="campagne-name"><?= htmlspecialchars($campagne['nom_campagne']) ?></span>
            <span class="badge-campagne">
                <i class="fas fa-plus"></i> Nouveau message
            </span>
        </div>
        <div class="info-right">
            <i class="fas fa-info-circle"></i>
            Un nouveau message sera ajouté à cette campagne
        </div>
    </div>

    <!-- ===== CARDS DE CHOIX ===== -->
    <div class="cards-grid">
        <!-- SMS -->
        <div class="type-option sms" data-type="sms" onclick="selectType('sms')" role="button" tabindex="0">
            <div class="icon-wrapper">
                <i class="fas fa-comment-dots"></i>
            </div>
            <div>
                <h3>SMS</h3>
                <p>Messages courts et rapides</p>
            </div>
        </div>

        <!-- WhatsApp -->
        <div class="type-option whatsapp" data-type="whatsapp" onclick="selectType('whatsapp')" role="button" tabindex="0">
            <div class="icon-wrapper">
                <i class="fab fa-whatsapp"></i>
            </div>
            <div>
                <h3>WhatsApp</h3>
                <p>Messages riches avec médias</p>
            </div>
        </div>

        <!-- Email -->
        <div class="type-option email" data-type="email" onclick="selectType('email')" role="button" tabindex="0">
            <div class="icon-wrapper">
                <i class="fas fa-envelope"></i>
            </div>
            <div>
                <h3>Email</h3>
                <p>Messages détaillés et professionnels</p>
            </div>
        </div>
    </div>

    <!-- ===== BOUTON CONTINUER ===== -->
    <div class="btn-section">
        <form id="choixTypeForm" method="POST" action="index.php?page=campagnes/configurer_message">
            <input type="hidden" name="campagne_config_id" value="<?= $campagneConfigId ?>">
            <input type="hidden" name="type_message" id="type_message" value="">
            <button type="submit" id="btnContinuer" class="btn-continue" disabled>
                <span>Continuer</span>
                <i class="fas fa-arrow-right"></i>
            </button>
        </form>
        <p class="hint">
            <i class="fas fa-hand-pointer"></i>
            Sélectionnez un type de message pour continuer
        </p>
    </div>
</div>

<script>
// ============================================
// SÉLECTION DU TYPE
// ============================================
let selectedType = null;

function selectType(type) {
    selectedType = type;
    
    // Mettre à jour l'interface
    document.querySelectorAll('.type-option').forEach(el => {
        el.classList.remove('selected');
    });
    const selectedEl = document.querySelector(`.type-option[data-type="${type}"]`);
    if (selectedEl) {
        selectedEl.classList.add('selected');
    }
    
    // Activer le bouton
    document.getElementById('type_message').value = type;
    document.getElementById('btnContinuer').disabled = false;
}

// ============================================
// CLAVIER (Entrée/Espace)
// ============================================
document.querySelectorAll('.type-option').forEach(el => {
    el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            selectType(this.dataset.type);
        }
    });
});

// ============================================
// SOUMISSION DU FORMULAIRE AVEC ENTRÉE
// ============================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && selectedType) {
        const form = document.getElementById('choixTypeForm');
        if (form) {
            form.submit();
        }
    }
});

// ============================================
// INITIALISATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Si un type est déjà sélectionné via une redirection
    const urlParams = new URLSearchParams(window.location.search);
    const preSelected = urlParams.get('type');
    if (preSelected && ['sms', 'whatsapp', 'email'].includes(preSelected)) {
        selectType(preSelected);
    }
});
</script>

</body>
</html>