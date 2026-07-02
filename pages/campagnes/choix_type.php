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
    <style>
        /* ===== STYLES AGRANDIS ===== */
        * { box-sizing: border-box; }
        body { 
            margin: 0; 
            background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .container-full {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 30px;
        }
        
        /* En-tête agrandi */
        .header-section {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px 24px;
            background: white;
            border-radius: 16px;
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
            background: #f3e8ff;
            padding: 14px;
            border-radius: 14px;
            margin-right: 18px;
        }
        .header-section .icon-wrapper i {
            color: #7c3aed;
            font-size: 28px;
        }
        .header-section .title {
            font-size: 30px;
            font-weight: 700;
            color: #1f2937;
        }
        .header-section .subtitle {
            font-size: 18px;
            color: #6b7280;
            margin-top: 2px;
        }
        
        /* Info campagne agrandie */
        .campagne-info {
            background: #f3e8ff;
            border: 2px solid #d8b4fe;
            border-radius: 16px;
            padding: 20px 28px;
            margin-bottom: 32px;
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
            font-size: 18px;
            font-weight: 700;
            color: #5b21b6;
        }
        .campagne-info .info-left .badge-campagne {
            background: #7c3aed;
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
        
        /* Cards de choix - TAILLE CONSERVÉE */
        .type-option {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 3px solid #e5e7eb;
            background: white;
            border-radius: 16px;
            padding: 32px 20px;
            text-align: center;
        }
        .type-option:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 40px rgba(0,0,0,0.12);
        }
        .type-option.selected {
            border-color: #8b5cf6;
            background-color: #f5f3ff;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.15);
        }
        .type-option .icon-wrapper {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            font-size: 34px;
        }
        .type-option.sms .icon-wrapper { background: #dbeafe; color: #2563eb; }
        .type-option.whatsapp .icon-wrapper { background: #dcfce7; color: #16a34a; }
        .type-option.email .icon-wrapper { background: #fef3c7; color: #d97706; }
        .type-option h3 {
            font-size: 22px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
        }
        .type-option p {
            font-size: 15px;
            color: #6b7280;
        }
        
        /* Bouton continuer agrandi */
        .btn-section {
            text-align: center;
            margin-top: 36px;
            padding: 20px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
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
        }
        .btn-continue:hover:not(:disabled) {
            background: #7c3aed;
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(139, 92, 246, 0.35);
        }
        .btn-continue:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }
        .btn-continue i {
            font-size: 20px;
        }
        .btn-section .hint {
            font-size: 15px;
            color: #9ca3af;
            margin-top: 12px;
            font-weight: 500;
        }
        
        /* Grille des cards - 3 colonnes */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 0;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container-full { padding: 12px 16px; }
            .header-section { flex-wrap: wrap; padding: 16px; }
            .header-section .title { font-size: 24px; }
            .header-section .subtitle { font-size: 15px; }
            .campagne-info { flex-direction: column; align-items: flex-start; gap: 10px; }
            .campagne-info .info-left .campagne-name { font-size: 16px; }
            .cards-grid { grid-template-columns: 1fr; gap: 16px; }
            .type-option { padding: 24px 16px; }
            .type-option .icon-wrapper { width: 64px; height: 64px; font-size: 28px; }
            .type-option h3 { font-size: 19px; }
            .btn-continue { padding: 14px 32px; font-size: 16px; width: 100%; justify-content: center; }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .cards-grid { gap: 18px; }
            .type-option { padding: 26px 16px; }
        }
    </style>
</head>
<body>

<div class="container-full">
    <!-- ===== EN-TÊTE AGRANDI ===== -->
    <div class="header-section">
        <a href="index.php?page=campagnes/details&id=<?= $campagneConfigId ?>" class="back-link">
            <i class="fas fa-arrow-left"></i> Retour à la campagne
        </a>
        <div class="icon-wrapper">
            <i class="fas fa-comment-dots"></i>
        </div>
        <div>
            <div class="title">Choisir le type de message</div>
            <div class="subtitle">Sélectionnez comment vous voulez envoyer votre message</div>
        </div>
    </div>

    <!-- ===== INFO CAMPAGNE AGRANDIE ===== -->
    <div class="campagne-info">
        <div class="info-left">
            <i class="fas fa-bullhorn" style="color: #7c3aed; font-size: 20px;"></i>
            <span class="campagne-name"><?= htmlspecialchars($campagne['nom_campagne']) ?></span>
            <span class="badge-campagne"><i class="fas fa-plus mr-1"></i>Nouveau message</span>
        </div>
        <div class="info-right">
            <i class="fas fa-info-circle"></i>
            Un nouveau message sera ajouté à cette campagne
        </div>
    </div>

    <!-- ===== CARDS DE CHOIX ===== -->
    <div class="cards-grid">
        <!-- SMS -->
        <div class="type-option sms" data-type="sms" onclick="selectType('sms')">
            <div class="icon-wrapper">
                <i class="fas fa-comment-dots"></i>
            </div>
            <h3>SMS</h3>
            <p>Messages courts</p>
        </div>

        <!-- WhatsApp -->
        <div class="type-option whatsapp" data-type="whatsapp" onclick="selectType('whatsapp')">
            <div class="icon-wrapper">
                <i class="fab fa-whatsapp"></i>
            </div>
            <h3>WhatsApp</h3>
            <p>Messages riches</p>
        </div>

        <!-- Email -->
        <div class="type-option email" data-type="email" onclick="selectType('email')">
            <div class="icon-wrapper">
                <i class="fas fa-envelope"></i>
            </div>
            <h3>Email</h3>
            <p>Messages détaillés</p>
        </div>
    </div>

    <!-- ===== BOUTON CONTINUER AGRANDI ===== -->
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
            <i class="fas fa-hand-pointer mr-1"></i>
            Sélectionnez un type de message pour continuer
        </p>
    </div>
</div>

<script>
let selectedType = null;

function selectType(type) {
    selectedType = type;
    
    // Mettre à jour l'interface
    document.querySelectorAll('.type-option').forEach(el => {
        el.classList.remove('selected');
    });
    document.querySelector(`.type-option[data-type="${type}"]`).classList.add('selected');
    
    // Activer le bouton
    document.getElementById('type_message').value = type;
    document.getElementById('btnContinuer').disabled = false;
}

// Sélection au clavier (Entrée)
document.querySelectorAll('.type-option').forEach(el => {
    el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            selectType(this.dataset.type);
        }
    });
    el.setAttribute('tabindex', '0');
    el.setAttribute('role', 'button');
});
</script>

</body>
</html>