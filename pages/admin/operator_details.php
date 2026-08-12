<?php
// operator_details.php - Détails d'un opérateur : liste des clients connectés
global $db;

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?page=login');
    exit;
}

$idCompte = $_SESSION['user_id'];

// ============================================
// MAPPING FOURNISSEUR -> TABLE DE SESSIONS
// (identique à operators.php)
// ============================================
function getFournisseurTableMap()
{
    return [
        'octopush'         => 'octopush_config',
        'waha'             => 'whatsapp_sessions',
        'sms api gateway'  => 'sms_appareils',
        'listmonk'         => 'email_accounts',
    ];
}

function resolveFournisseurTable($fournisseur)
{
    $map = getFournisseurTableMap();
    $key = mb_strtolower(trim($fournisseur));
    return $map[$key] ?? null;
}

// ============================================
// TRAITEMENT DES ACTIONS (AJAX)
// ============================================

// Changement de statut (Activer/Désactiver)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    header('Content-Type: application/json');
    
    try {
        $id_provider = isset($_POST['id_provider']) ? intval($_POST['id_provider']) : 0;
        
        if ($id_provider <= 0) {
            throw new Exception('ID invalide');
        }
        
        // Vérifier que le provider appartient au compte
        $provider = $db->select('provider', [
            'id_provider' => $id_provider,
            'id_compte' => $idCompte
        ]);
        
        if (empty($provider)) {
            throw new Exception('Opérateur non trouvé');
        }
        
        $currentStatus = $provider[0]['statut'] ?? 'actif';
        $newStatus = ($currentStatus === 'actif') ? 'inactif' : 'actif';
        
        $result = $db->update('provider', ['statut' => $newStatus], [
            'id_provider' => $id_provider,
            'id_compte' => $idCompte
        ]);
        
        if ($result !== false) {
            echo json_encode([
                'success' => true,
                'message' => 'Statut mis à jour avec succès',
                'new_status' => $newStatus
            ]);
        } else {
            throw new Exception('Erreur lors de la mise à jour du statut');
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// RÉCUPÉRATION DE L'OPÉRATEUR
// ============================================
$idProvider = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($idProvider <= 0) {
    header('Location: index.php?page=admin/operators');
    exit;
}

$provider = $db->select('provider', [
    'id_provider' => $idProvider,
    'id_compte' => $idCompte
]);

if (empty($provider)) {
    $_SESSION['flash_error'] = "Opérateur non trouvé";
    header('Location: index.php?page=admin/operators');
    exit;
}

$provider = $provider[0];

// Canal (libelle)
$typeMessage = $db->select('type_message', ['id_type_message' => $provider['id_type_message']]);
$canalName = !empty($typeMessage) ? $typeMessage[0]['libelle_type'] : 'Inconnu';
$canalClass = strtolower($canalName);
$canalClass = in_array($canalClass, ['whatsapp', 'sms', 'email']) ? $canalClass : 'default';

// Statut de l'opérateur (avec fallback si la colonne n'existe pas encore)
$operatorStatus = isset($provider['statut']) ? $provider['statut'] : 'actif';
$isActif = ($operatorStatus === 'actif');

// ============================================
// RÉCUPÉRATION DES CLIENTS CONNECTÉS
// ============================================
$table = resolveFournisseurTable($provider['description']);
$clients = [];

if ($table) {
    try {
        $rows = $db->select($table, [], 'id_compte');
    } catch (Exception $e) {
        $rows = [];
    }

    // ids de comptes distincts présents dans la table de sessions
    $ids = array_unique(array_filter(array_map(function ($row) {
        return $row['id_compte'] ?? null;
    }, $rows ?: [])));

    // Jointure manuelle avec la table compte pour récupérer nom + statut actif
    foreach ($ids as $clientId) {
        $compte = $db->select('compte', ['id_compte' => $clientId]);
        $isActif = !empty($compte) ? (bool)$compte[0]['actif'] : false;

        $clients[] = [
            'id_compte' => $clientId,
            'nom' => !empty($compte) ? $compte[0]['nom'] : 'Client inconnu',
            'actif' => $isActif,
            // Pour l'instant on affiche le tarif par défaut de l'opérateur.
            // Un tarif personnalisé par client pourra remplacer cette valeur plus tard.
            'tarif' => $provider['tarif'],
        ];
    }

    // Tri alphabétique par nom
    usort($clients, function ($a, $b) {
        return strcasecmp($a['nom'], $b['nom']);
    });
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails opérateur - <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: oklch(0.975 0.004 275);
            --text: oklch(0.25 0.01 275);
            --muted: oklch(0.5 0.01 275);
            --muted-2: oklch(0.55 0.01 275);
            --border: oklch(0.93 0.005 275);
            --border-light: oklch(0.96 0.003 275);
            --accent: oklch(0.55 0.18 275);
            --accent-dark: oklch(0.48 0.18 275);
            --sms-bg: oklch(0.92 0.05 250);
            --sms-fg: oklch(0.45 0.15 250);
            --whatsapp-bg: oklch(0.92 0.08 155);
            --whatsapp-fg: oklch(0.42 0.13 155);
            --email-bg: oklch(0.93 0.07 80);
            --email-fg: oklch(0.48 0.12 70);
            --default-bg: oklch(0.93 0.005 275);
            --default-fg: oklch(0.45 0.01 275);
            --success-soft-bg: oklch(0.94 0.05 155);
            --success-soft-fg: oklch(0.45 0.13 155);
            --danger-soft-bg: oklch(0.95 0.04 30);
            --danger-soft-fg: oklch(0.5 0.17 30);
            --green-bg: oklch(0.92 0.08 155);
            --green-fg: oklch(0.45 0.13 155);
            --red-bg: oklch(0.94 0.05 30);
            --red-fg: oklch(0.5 0.17 30);
            --blue-bg: oklch(0.93 0.04 250);
            --blue-fg: oklch(0.45 0.15 250);
        }

        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        ::-webkit-scrollbar { 
            width: 8px; 
            height: 8px; 
        }
        ::-webkit-scrollbar-thumb { 
            background: oklch(0.85 0.01 275); 
            border-radius: 8px; 
        }
        ::-webkit-scrollbar-track { 
            background: transparent; 
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        .container {
            max-width: 100%;
            padding: 24px 32px;
            margin: 0 auto;
            width: 100%;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--muted);
            text-decoration: none;
            margin-bottom: 20px;
            padding: 8px 16px;
            border-radius: 10px;
            transition: all 0.2s ease;
        }

        .back-link:hover { 
            color: var(--accent);
            background: oklch(0.96 0.003 275);
        }

        /* ===== HEADER ===== */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
            width: 100%;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .header-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            flex-shrink: 0;
        }

        .header-icon.sms { background: var(--sms-bg); color: var(--sms-fg); }
        .header-icon.whatsapp { background: var(--whatsapp-bg); color: var(--whatsapp-fg); }
        .header-icon.email { background: var(--email-bg); color: var(--email-fg); }
        .header-icon.default { background: var(--default-bg); color: var(--default-fg); }

        .header-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }

        .header-name-row {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .header-name {
            font-size: 26px;
            font-weight: 800;
            color: var(--text);
            margin: 0;
            word-break: break-word;
        }

        .statut-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 16px;
            border-radius: 100px;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .statut-badge.actif {
            background: var(--success-soft-bg);
            color: var(--success-soft-fg);
        }

        .statut-badge.inactif {
            background: var(--danger-soft-bg);
            color: var(--danger-soft-fg);
        }

        .header-subtitle {
            color: var(--muted-2);
            font-size: 15px;
            margin: 0;
            word-break: break-word;
        }

        .header-subtitle i {
            margin-right: 8px;
            font-size: 13px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            flex-shrink: 0;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-action:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-action-green {
            background: var(--green-bg);
            color: var(--green-fg);
        }

        .btn-action-green:hover:not(:disabled) {
            background: oklch(0.87 0.08 155);
        }

        .btn-action-red {
            background: var(--red-bg);
            color: var(--red-fg);
        }

        .btn-action-red:hover:not(:disabled) {
            background: oklch(0.89 0.05 30);
        }

        .btn-action-blue {
            background: var(--blue-bg);
            color: var(--blue-fg);
        }

        .btn-action-blue:hover:not(:disabled) {
            background: oklch(0.88 0.04 250);
        }

        /* ===== INFO GRID ===== */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
            width: 100%;
        }

        .info-card {
            background: white;
            border-radius: 14px;
            padding: 20px 24px;
            box-shadow: 0 1px 2px rgba(20,20,50,0.05);
            border: 1px solid var(--border);
            min-width: 0;
        }

        .info-card .label {
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
        }

        .info-card .value {
            font-size: 22px;
            font-weight: 800;
            margin-top: 8px;
            word-break: break-word;
        }

        /* ===== TABLE ===== */
        .table-container {
            background: white;
            border-radius: 14px;
            border: 1px solid var(--border);
            overflow: hidden;
            width: 100%;
        }

        .table-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-light);
            font-weight: 700;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-wrapper {
            overflow-x: auto;
            width: 100%;
        }

        .grid-row {
            display: grid;
            grid-template-columns: 2.2fr 1.4fr 1.2fr 0.8fr;
            gap: 12px;
            align-items: center;
            min-width: 0;
        }

        .grid-head {
            padding: 12px 24px;
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            border-bottom: 1px solid var(--border-light);
        }

        .grid-body-row {
            padding: 14px 24px;
            border-bottom: 1px solid var(--border-light);
            font-size: 14px;
            transition: background 0.15s ease;
        }

        .grid-body-row:last-child { border-bottom: none; }
        .grid-body-row:hover { background: oklch(0.98 0.003 275); }

        .client-name { 
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .client-id { 
            color: var(--muted-2); 
            font-size: 12px; 
            font-family: monospace; 
        }
        
        .client-tarif { 
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-statut {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-statut.actif { background: var(--success-soft-bg); color: var(--success-soft-fg); }
        .badge-statut.inactif { background: var(--danger-soft-bg); color: var(--danger-soft-fg); }

        .btn-voir {
            cursor: pointer;
            text-align: right;
            color: var(--accent);
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
            white-space: nowrap;
            transition: color 0.2s ease;
        }

        .btn-voir:hover { color: var(--accent-dark); }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
        }

        .empty-state i {
            font-size: 56px;
            color: var(--border);
            margin-bottom: 16px;
        }

        .empty-state h3 {
            font-size: 20px;
            color: var(--muted);
            margin-bottom: 8px;
        }

        .empty-state p {
            color: var(--muted-2);
            font-size: 14px;
        }

        /* ===== TOAST ===== */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            padding: 14px 22px;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            font-size: 13px;
            box-shadow: 0 8px 24px rgba(20,20,50,0.18);
            animation: slideInRight 0.35s ease-out;
            min-width: 260px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .toast.success { background: linear-gradient(135deg, oklch(0.62 0.15 155), oklch(0.52 0.13 155)); }
        .toast.error { background: linear-gradient(135deg, oklch(0.62 0.2 30), oklch(0.52 0.19 30)); }
        .toast.info { background: linear-gradient(135deg, var(--accent), var(--accent-dark)); }

        @keyframes slideInRight { from { transform: translateX(110%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOutRight { from { transform: translateX(0); opacity: 1; } to { transform: translateX(110%); opacity: 0; } }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1200px) {
            .container { 
                padding: 20px 24px; 
            }
            .grid-row { 
                grid-template-columns: 2fr 1.2fr 1fr 0.7fr; 
                gap: 10px;
            }
            .info-card .value { 
                font-size: 20px; 
            }
        }

        @media (max-width: 1024px) {
            .container { 
                padding: 20px; 
            }
            .info-grid { 
                grid-template-columns: 1fr 1fr; 
            }
            .grid-row { 
                grid-template-columns: 1.8fr 1.1fr 0.9fr 0.6fr; 
                gap: 8px;
            }
            .grid-head { 
                padding: 10px 16px; 
                font-size: 10px;
            }
            .grid-body-row { 
                padding: 12px 16px; 
                font-size: 13px;
            }
            .header-name { 
                font-size: 22px; 
            }
        }

        @media (max-width: 768px) {
            .container { 
                padding: 16px; 
            }
            .page-header { 
                flex-direction: column; 
                align-items: flex-start; 
            }
            .header-actions { 
                width: 100%; 
            }
            .header-actions .btn-action { 
                flex: 1; 
                justify-content: center; 
            }
            .info-grid { 
                grid-template-columns: 1fr 1fr; 
            }
            .info-card .value { 
                font-size: 18px; 
            }
            .grid-row { 
                grid-template-columns: 1.6fr 1fr 0.8fr 0.5fr; 
                gap: 6px;
                font-size: 12px;
            }
            .grid-head { 
                padding: 8px 12px; 
                font-size: 9px;
            }
            .grid-body-row { 
                padding: 10px 12px; 
                font-size: 12px;
            }
            .client-name { 
                font-size: 12px; 
            }
            .badge-statut { 
                font-size: 10px; 
                padding: 3px 10px;
            }
            .btn-voir { 
                font-size: 11px; 
            }
        }

        @media (max-width: 640px) {
            .container { 
                padding: 12px; 
            }
            .back-link { 
                margin-bottom: 16px; 
            }
            .header-left { 
                flex-direction: column; 
                align-items: flex-start; 
                width: 100%;
            }
            .header-icon { 
                width: 50px; 
                height: 50px; 
                font-size: 22px;
            }
            .header-name { 
                font-size: 20px; 
            }
            .header-name-row { 
                flex-direction: column; 
                align-items: flex-start; 
                gap: 8px;
            }
            .info-grid { 
                grid-template-columns: 1fr; 
                gap: 12px;
            }
            .info-card { 
                padding: 16px 20px; 
            }
            .info-card .value { 
                font-size: 18px; 
            }
            .grid-row { 
                grid-template-columns: repeat(4, minmax(100px, 1fr)); 
                width: max-content; 
                min-width: 100%;
                gap: 8px;
                font-size: 11px;
            }
            .grid-head { 
                padding: 8px 12px; 
                font-size: 9px;
            }
            .grid-body-row { 
                padding: 8px 12px; 
                font-size: 11px;
            }
            .client-name { 
                font-size: 11px; 
            }
            .btn-voir { 
                font-size: 10px; 
            }
            .table-header { 
                padding: 12px 16px; 
                font-size: 13px;
            }
            .statut-badge { 
                font-size: 11px; 
                padding: 4px 12px;
            }
            .btn-action { 
                padding: 8px 16px; 
                font-size: 12px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Retour -->
    <a href="index.php?page=admin/operators" class="back-link">
        <i class="fas fa-arrow-left"></i> Retour aux opérateurs
    </a>

    <!-- ===== HEADER ===== -->
    <div class="page-header">
        <div class="header-left">
            <?php 
            $iconMap = [
                'sms' => 'fa-sms',
                'whatsapp' => 'fa-mobile-alt',
                'email' => 'fa-envelope',
                'default' => 'fa-plug'
            ];
            $icon = $iconMap[$canalClass] ?? 'fa-plug';
            ?>
            <div class="header-icon <?= $canalClass ?>">
                <i class="fas <?= $icon ?>"></i>
            </div>
            <div class="header-info">
                <div class="header-name-row">
                    <h1 class="header-name"><?= htmlspecialchars($provider['nom_providers']) ?></h1>
                    <span class="statut-badge <?= $isActif ? 'actif' : 'inactif' ?>" id="statusBadge">
                        <i class="fas fa-circle" style="font-size: 10px;"></i>
                        <?= $isActif ? 'Actif' : 'Inactif' ?>
                    </span>
                </div>
                <p class="header-subtitle">
                    <i class="fas fa-tag"></i>
                    <?= htmlspecialchars($provider['description']) ?>
                    <span style="margin: 0 8px; color: var(--border);">|</span>
                    <i class="fas fa-channel"></i>
                    <?= htmlspecialchars($canalName) ?>
                </p>
            </div>
        </div>
        
        <div class="header-actions">
            <button onclick="toggleStatus()" id="toggleStatusBtn" 
                    class="btn-action <?= $isActif ? 'btn-action-red' : 'btn-action-green' ?>">
                <i class="fas <?= $isActif ? 'fa-pause' : 'fa-play' ?>"></i>
                <?= $isActif ? 'Désactiver' : 'Activer' ?>
            </button>
        </div>
    </div>

    <!-- ===== INFO CARDS ===== -->
    <div class="info-grid">
        <div class="info-card">
            <div class="label">Tarif par défaut</div>
            <div class="value" style="color: var(--accent);">
                <?= number_format($provider['tarif'], 2, ',', ' ') ?> €
                <span style="font-size: 14px; font-weight: 500; color: var(--muted-2);">/envoi</span>
            </div>
        </div>
        <div class="info-card">
            <div class="label">Clients associés</div>
            <div class="value" style="color: var(--success-soft-fg);"><?= count($clients) ?></div>
        </div>
        <div class="info-card">
            <div class="label">Revenus générés</div>
            <div class="value" style="font-size: 18px; color: var(--muted-2);">
                <i class="fas fa-chart-line" style="font-size: 16px;"></i>
                En développement
            </div>
        </div>
    </div>

    <!-- ===== TABLE ===== -->
    <div class="table-container">
        <div class="table-header">
            <i class="fas fa-users" style="color: var(--muted);"></i>
            Clients utilisant cet opérateur
            <span style="margin-left: auto; font-size: 13px; font-weight: 600; color: var(--muted-2);">
                <?= count($clients) ?> client<?= count($clients) > 1 ? 's' : '' ?>
            </span>
        </div>

        <?php if (empty($clients)): ?>
            <div class="empty-state">
                <i class="fas fa-user-slash"></i>
                <h3>Aucun client connecté</h3>
                <p>Aucun compte n'est actuellement configuré sur cet opérateur</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <div class="grid-row grid-head">
                    <div>Client</div>
                    <div>Tarif client</div>
                    <div>Statut</div>
                    <div style="text-align: right;">Action</div>
                </div>
                <?php foreach ($clients as $client): ?>
                    <div class="grid-row grid-body-row">
                        <div class="client-name" title="<?= htmlspecialchars($client['nom']) ?>">
                            <?= htmlspecialchars($client['nom']) ?>
                        </div>
                        <div class="client-tarif"><?= number_format($client['tarif'], 2, ',', ' ') ?> €</div>
                        <div>
                            <span class="badge-statut <?= $client['actif'] ? 'actif' : 'inactif' ?>">
                                <?= $client['actif'] ? 'Actif' : 'Inactif' ?>
                            </span>
                        </div>
                        <div style="text-align: right;">
                            <a class="btn-voir" href="index.php?page=admin/client-detail&id=<?= urlencode($client['id_compte']) ?>">
                                Voir →
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== TOAST CONTAINER ===== -->
<div id="toastContainer" class="toast-container"></div>

<script>
// ============================================
// TOASTS
// ============================================
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    const icons = {
        success: 'fas fa-check-circle',
        error: 'fas fa-exclamation-circle',
        info: 'fas fa-info-circle'
    };

    toast.innerHTML = `<i class="${icons[type] || icons.info}"></i><span>${message}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.35s ease-in forwards';
        setTimeout(() => toast.remove(), 350);
    }, 4000);
}

// ============================================
// TOGGLE STATUS (Activer / Désactiver)
// ============================================
async function toggleStatus() {
    const btn = document.getElementById('toggleStatusBtn');
    const badge = document.getElementById('statusBadge');
    
    const originalText = btn.innerHTML;
    const isCurrentlyActive = badge.classList.contains('actif');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Chargement...';

    try {
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('id_provider', <?= $idProvider ?>);

        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        if (!response.ok) throw new Error('Erreur serveur (HTTP ' + response.status + ')');

        const text = await response.text();
        if (text.trim().startsWith('<')) {
            console.error('Réponse HTML (erreur PHP):', text);
            const errorMatch = text.match(/Fatal error: ([^<]+)/);
            throw new Error('Erreur PHP: ' + (errorMatch ? errorMatch[1] : 'Erreur PHP inconnue'));
        }

        let result;
        try { result = JSON.parse(text); }
        catch (e) { console.error('Réponse brute:', text); throw new Error("La réponse du serveur n'est pas du JSON valide"); }

        if (result.success) {
            const isActif = result.new_status === 'actif';
            
            // Mettre à jour le badge
            badge.className = `statut-badge ${isActif ? 'actif' : 'inactif'}`;
            badge.innerHTML = `<i class="fas fa-circle" style="font-size: 10px;"></i> ${isActif ? 'Actif' : 'Inactif'}`;
            
            // Mettre à jour le bouton
            btn.className = `btn-action ${isActif ? 'btn-action-red' : 'btn-action-green'}`;
            btn.innerHTML = `<i class="fas ${isActif ? 'fa-pause' : 'fa-play'}"></i> ${isActif ? 'Désactiver' : 'Activer'}`;
            
            showToast(result.message, 'success');
        } else {
            showToast(result.message || 'Une erreur est survenue', 'error');
            btn.innerHTML = originalText;
            btn.className = `btn-action ${isCurrentlyActive ? 'btn-action-red' : 'btn-action-green'}`;
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur: ' + error.message, 'error');
        btn.innerHTML = originalText;
        btn.className = `btn-action ${isCurrentlyActive ? 'btn-action-red' : 'btn-action-green'}`;
    } finally {
        btn.disabled = false;
    }
}

// ============================================
// FLASH MESSAGES
// ============================================
<?php if (isset($_SESSION['flash_success'])): ?>
    showToast('<?= addslashes($_SESSION['flash_success']) ?>', 'success');
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['flash_error'])): ?>
    showToast('<?= addslashes($_SESSION['flash_error']) ?>', 'error');
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
</script>

</body>
</html>