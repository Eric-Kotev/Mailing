<?php
// Vérification que l'utilisateur est admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit;
}
global $db;

// ============================================
// STATISTIQUES
// ============================================

// 1. Total des comptes clients
$totalUsers = count($db->select('compte', ['role'=>'client']));

// 2. Comptes actifs
$activeUsers = count($db->select('compte', ['actif' => 1, 'role' =>'client']));

// 3. Crédits total
$totalCredits = array_sum(array_column($db->select('compte'), 'credits_total'));

// 4. Nombre d'administrateurs
$adminCount = count($db->select('compte', ['role' => 'admin']));

// 5. Opérateurs actifs (statut = 'actif')
$activeOperators = count($db->select('provider', ['statut' => 'actif']));

// 6. Nombre total de messages envoyés ce mois
// Récupérer toutes les campagnes
$campaigns = $db->select('campagne', [], '*', 'updated_at DESC');

$totalMessagesSent = 0;
$currentMonth = date('Y-m');
$currentMonthStart = date('Y-m-01 00:00:00');
$currentMonthEnd = date('Y-m-t 23:59:59');

foreach ($campaigns as $campaign) {
    // Vérifier si la campagne a été envoyée ce mois
    // La colonne updated_at contient la date de dernier envoi (statut final)
    if (isset($campaign['updated_at'])) {
        // Extraire la date de updated_at (format: 2026-08-10 09:35:57.17304+00)
        $campaignDate = $campaign['updated_at'];
        
        // Si la date contient un format avec timezone, on prend les 10 premiers caractères "YYYY-MM-DD"
        // Sinon on prend la date complète
        if (strpos($campaignDate, ' ') !== false) {
            // Format: 2026-08-10 09:35:57.17304+00
            $campaignDateOnly = substr($campaignDate, 0, 10);
            $campaignMonth = substr($campaignDate, 0, 7);
        } else {
            // Format: 2026-08-10
            $campaignDateOnly = $campaignDate;
            $campaignMonth = substr($campaignDate, 0, 7);
        }
        
        // Vérifier si la campagne est du mois en cours
        if ($campaignMonth === $currentMonth) {
            // Compter les messages de cette campagne
            // Soit via le statut "envoyé" ou "terminé" selon votre logique
            // Soit en comptant toutes les campagnes du mois comme 1 message (si une campagne = 1 envoi)
            
            // Option 1: Si une campagne = 1 envoi groupé
            $totalMessagesSent += 1;
            
            // Option 2: Si vous avez une colonne pour le nombre de destinataires
            // if (isset($campaign['nb_destinataires'])) {
            //     $totalMessagesSent += (int)$campaign['nb_destinataires'];
            // }
            
            // Option 3: Si vous avez une table campagne_messages
            // $messages = $db->select('campagne_messages', ['id_campagne' => $campaign['id_campagne']]);
            // $totalMessagesSent += count($messages);
        }
    }
}

// Alternative : Utiliser une requête SQL directe pour plus de précision
// $campaignsThisMonth = $db->select('campagne', [
//     'updated_at >=' => $currentMonthStart,
//     'updated_at <=' => $currentMonthEnd
// ]);
// $totalMessagesSent = count($campaignsThisMonth);

// Derniers comptes créés
$recentUsers = $db->select('compte', [], '*', 'date_creation DESC', 5);

// ============================================
// NOUVEAU: Clients avec crédit faible
// ============================================

// Récupérer tous les clients avec leurs crédits
$allClients = $db->select('compte', ['role' => 'client'], '*', 'credits_total ASC');

// Définir un seuil de crédit faible (par exemple 10 €)
$creditThreshold = 10;

// Filtrer les clients avec un crédit inférieur au seuil
$lowCreditClients = array_filter($allClients, function($client) use ($creditThreshold) {
    return ($client['credits_total'] ?? 0) < $creditThreshold;
});

// Si vous voulez limiter l'affichage aux 10 premiers clients avec le plus faible crédit
$lowCreditClients = array_slice($lowCreditClients, 0, 10);

// Calculer le nombre total de clients avec crédit faible
$totalLowCreditClients = count(array_filter($allClients, function($client) use ($creditThreshold) {
    return ($client['credits_total'] ?? 0) < $creditThreshold;
}));

// ===============================
// SOLUTION: Transactions récentes 
// ===============================

// Récupérer toutes les transactions via la méthode select()

// ÉTAPE 1: Récupérer uniquement les IDs des transactions triées par date
// On utilise select avec seulement l'ID pour éviter les problèmes de jointure
$idsResult = $db->select('transactions', [], 'id_transaction', 'created_at DESC', 10);

// Si select ne supporte pas la sélection de colonnes spécifiques, on récupère tout
if (empty($idsResult) || !isset($idsResult[0]['id_transaction'])) {
    // Fallback: récupérer toutes les transactions
    $allTransactions = $db->select('transactions', [], '*', 'created_at DESC');
    
    // Dédoublonner par id_transaction
    $seenIds = [];
    $recentTransactions = [];
    foreach ($allTransactions as $t) {
        $id = $t['id_transaction'] ?? null;
        if ($id !== null && !in_array($id, $seenIds)) {
            $seenIds[] = $id;
            $recentTransactions[] = $t;
        }
    }
    $recentTransactions = array_slice($recentTransactions, 0, 10);
} else {
    // Récupérer les IDs
    $transactionIds = array_column($idsResult, 'id_transaction');
    
    // Récupérer chaque transaction individuellement par son ID
    $recentTransactions = [];
    foreach ($transactionIds as $id) {
        $transaction = $db->select('transactions', ['id_transaction' => $id]);
        if (!empty($transaction)) {
            $recentTransactions[] = $transaction[0];
        }
    }
}

// Enrichir les transactions avec le nom du client et du provider
foreach ($recentTransactions as &$transaction) {
    // Récupérer le nom du client
    if (!empty($transaction['id_compte'])) {
        $client = $db->select('compte', ['id_compte' => $transaction['id_compte']]);
        $transaction['client_entreprise'] = !empty($client) ? ($client[0]['entreprise'] ?? '-') : '-';
        $transaction['client_prenom'] = !empty($client) ? ($client[0]['prenom'] ?? '') : '';
        $transaction['client_nom'] = !empty($client) ? ($client[0]['nom'] ?? '') : '';
    } else {
        $transaction['client_entreprise'] = '-';
        $transaction['client_prenom'] = '';
        $transaction['client_nom'] = '';
    }
    
    // Récupérer le nom du provider
    if (!empty($transaction['id_provider'])) {
        $provider = $db->select('provider', ['id_provider' => $transaction['id_provider']]);
        $transaction['provider_nom'] = !empty($provider) ? $provider[0]['nom_providers'] : 'N/A';
    } else {
        $transaction['provider_nom'] = 'N/A';
    }
    
    // Formater le type de transaction
    $transaction['type_label'] = $transaction['type_transaction'] === 'credit' ? 'Crédit' : 'Débit';
    $transaction['type_icon'] = $transaction['type_transaction'] === 'credit' ? 'fa-arrow-up' : 'fa-arrow-down';
    $transaction['type_color'] = $transaction['type_transaction'] === 'credit' ? 'text-green-600' : 'text-red-600';
}

// Calculer les statistiques des transactions
$totalCreditsTransactions = 0;
$totalDebitsTransactions = 0;
foreach ($recentTransactions as $t) {
    if ($t['type_transaction'] === 'credit') {
        $totalCreditsTransactions += $t['montant'];
    } else {
        $totalDebitsTransactions += $t['montant'];
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - <?= APP_NAME ?></title>
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
            --success: oklch(0.6 0.14 155);
            --success-soft-bg: oklch(0.94 0.05 155);
            --success-soft-fg: oklch(0.45 0.13 155);
            --danger: oklch(0.6 0.19 30);
            --danger-soft-bg: oklch(0.95 0.04 30);
            --danger-soft-fg: oklch(0.5 0.17 30);
            --warning: oklch(0.7 0.16 85);
            --warning-soft-bg: oklch(0.95 0.06 85);
            --warning-soft-fg: oklch(0.55 0.15 85);
            --info: oklch(0.55 0.18 275);
            --info-soft-bg: oklch(0.94 0.04 275);
            --info-soft-fg: oklch(0.45 0.16 275);
            --purple: oklch(0.55 0.2 310);
            --purple-soft-bg: oklch(0.94 0.05 310);
            --purple-soft-fg: oklch(0.45 0.18 310);
            --default-bg: oklch(0.93 0.005 275);
            --default-fg: oklch(0.45 0.01 275);
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

        /* ===== HEADER ===== */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
            width: 100%;
        }

        .page-header .title {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.01em;
        }

        .page-header .subtitle {
            font-size: 15px;
            color: var(--muted);
            margin-top: 4px;
        }

        .page-header .date {
            font-size: 14px;
            color: var(--muted-2);
            padding: 10px 20px;
            background: white;
            border-radius: 12px;
            border: 1px solid var(--border);
            white-space: nowrap;
        }

        .page-header .date i {
            margin-right: 8px;
        }

        /* ===== STATS GRID ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
            width: 100%;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: 0 1px 3px rgba(20,20,50,0.06);
            border: 1px solid var(--border);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            min-width: 0;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(20,20,50,0.08);
        }

        .stat-card .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .stat-card .stat-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
        }

        .stat-card .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-card .stat-icon.blue { background: var(--info-soft-bg); color: var(--info-soft-fg); }
        .stat-card .stat-icon.green { background: var(--success-soft-bg); color: var(--success-soft-fg); }
        .stat-card .stat-icon.yellow { background: var(--warning-soft-bg); color: var(--warning-soft-fg); }
        .stat-card .stat-icon.purple { background: var(--purple-soft-bg); color: var(--purple-soft-fg); }
        .stat-card .stat-icon.red { background: var(--danger-soft-bg); color: var(--danger-soft-fg); }
        .stat-card .stat-icon.orange { background: oklch(0.95 0.06 50); color: oklch(0.55 0.15 50); }

        .stat-card .stat-number {
            font-size: 30px;
            font-weight: 800;
            line-height: 1.2;
        }

        .stat-card .stat-number.blue { color: var(--info); }
        .stat-card .stat-number.green { color: var(--success); }
        .stat-card .stat-number.yellow { color: var(--warning); }
        .stat-card .stat-number.purple { color: var(--purple); }
        .stat-card .stat-number.red { color: var(--danger); }
        .stat-card .stat-number.orange { color: oklch(0.55 0.15 50); }

        .stat-card .stat-hint {
            font-size: 12px;
            color: var(--muted-2);
            margin-top: 4px;
        }

        /* ===== TWO COLUMNS LAYOUT ===== */
        .two-col-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
            width: 100%;
        }

        /* ===== TABLES ===== */
        .table-container {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--border);
            overflow: hidden;
            width: 100%;
            height: fit-content;
        }

        .table-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .table-header h3 {
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-header h3 i {
            color: var(--muted);
        }

        .table-header h3 .badge-count {
            background: var(--danger-soft-bg);
            color: var(--danger-soft-fg);
            font-size: 11px;
            padding: 2px 10px;
            border-radius: 100px;
            margin-left: 6px;
        }

        .table-header h3 .badge-transactions {
            background: var(--info-soft-bg);
            color: var(--info-soft-fg);
            font-size: 11px;
            padding: 2px 10px;
            border-radius: 100px;
            margin-left: 6px;
        }

        .table-header a {
            font-size: 13px;
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s ease;
        }

        .table-header a:hover { 
            color: var(--accent-dark); 
        }

        .table-wrapper {
            overflow-x: auto;
            width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        thead {
            background: oklch(0.98 0.003 275);
        }

        th {
            padding: 10px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid var(--border-light);
        }

        td {
            padding: 10px 16px;
            font-size: 13px;
            border-bottom: 1px solid var(--border-light);
            word-wrap: break-word;
        }

        tr:last-child td { 
            border-bottom: none; 
        }
        tr:hover td { 
            background: oklch(0.98 0.003 275); 
        }

        .badge-role {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
        }

        .badge-role.admin { 
            background: var(--purple-soft-bg); 
            color: var(--purple-soft-fg); 
        }
        .badge-role.user { 
            background: var(--default-bg); 
            color: var(--default-fg); 
        }

        .badge-statut {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
        }

        .badge-statut.actif { 
            background: var(--success-soft-bg); 
            color: var(--success-soft-fg); 
        }
        .badge-statut.inactif { 
            background: var(--danger-soft-bg); 
            color: var(--danger-soft-fg); 
        }

        .badge-credit-low {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
            background: var(--danger-soft-bg);
            color: var(--danger-soft-fg);
        }

        .badge-credit-medium {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
            background: var(--warning-soft-bg);
            color: var(--warning-soft-fg);
        }

        .badge-credit-high {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
            background: var(--success-soft-bg);
            color: var(--success-soft-fg);
        }

        .badge-transaction {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
        }

        .badge-transaction.credit { 
            background: var(--success-soft-bg); 
            color: var(--success-soft-fg); 
        }
        .badge-transaction.debit { 
            background: var(--danger-soft-bg); 
            color: var(--danger-soft-fg); 
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted-2);
        }

        .empty-state i {
            font-size: 40px;
            color: var(--border);
            margin-bottom: 10px;
        }

        .credit-warning {
            color: var(--danger);
            font-weight: 700;
        }

        .credit-warning i {
            margin-right: 4px;
        }

        .transaction-amount-credit {
            color: var(--success);
            font-weight: 700;
        }

        .transaction-amount-debit {
            color: var(--danger);
            font-weight: 700;
        }

        .transaction-type-icon {
            margin-right: 4px;
        }

        .text-truncate {
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: inline-block;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1400px) {
            .container { 
                padding: 24px; 
            }
            .stats-grid { 
                gap: 14px; 
            }
            .stat-card .stat-number { 
                font-size: 26px; 
            }
        }

        @media (max-width: 1200px) {
            .container { 
                padding: 20px; 
            }
            .stats-grid { 
                grid-template-columns: repeat(2, 1fr); 
                gap: 14px;
            }
            .stat-card { 
                padding: 18px 20px; 
            }
            .stat-card .stat-number { 
                font-size: 24px; 
            }
            .two-col-grid {
                grid-template-columns: 1fr;
                gap: 16px;
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
            .page-header .date { 
                width: 100%; 
                text-align: center; 
                white-space: normal;
            }
            .stats-grid { 
                grid-template-columns: 1fr; 
            }
            .two-col-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            .table-header { 
                flex-direction: column; 
                align-items: flex-start; 
            }
            .table-header h3 { 
                font-size: 14px; 
            }
            
            th, td { 
                padding: 8px 12px; 
                font-size: 12px; 
            }
        }

        @media (max-width: 480px) {
            .container { 
                padding: 12px; 
            }
            .stat-card { 
                padding: 16px; 
            }
            .stat-card .stat-number { 
                font-size: 22px; 
            }
            .page-header .title { 
                font-size: 22px; 
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- ===== HEADER ===== -->
    <div class="page-header">
        <div>
            <div class="title">Tableau de bord</div>
            <div class="subtitle">Vue d'ensemble de l'activité clients & opérateurs</div>
        </div>
        <div class="date">
            <i class="fas fa-calendar-alt"></i>
            <?= date('d/m/Y') ?>
        </div>
    </div>

    <!-- ===== STATISTIQUES ===== -->
    <div class="stats-grid">

        <!-- Comptes actifs -->
        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Clients actifs</span>
            </div>
            <div class="stat-number green"><?= $activeUsers ?></div>
            <div class="stat-hint"><?= round(($activeUsers / max($totalUsers, 1)) * 100) ?>% des clients</div>
        </div>

        <!-- Crédits total -->
        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Crédits en circulation</span>
            </div>
            <div class="stat-number yellow"><?= number_format($totalCredits, 0, ',', ' ') ?> €</div>
            <div class="stat-hint">Crédits disponibles sur la plateforme</div>
        </div>

        <!-- Opérateurs actifs -->
        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Opérateurs actifs</span>
            </div>
            <div class="stat-number blue"><?= $activeOperators ?></div>
            <div class="stat-hint">Opérateurs configurés et actifs</div>
        </div>

        <!-- Messages envoyés ce mois -->
        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Envois ce mois</span>
            </div>
            <div class="stat-number orange"><?= number_format($totalMessagesSent, 0, ',', ' ') ?></div>
            <div class="stat-hint">SMS + EMAIL + WHATSAPP</div>
        </div>
    </div>

    <!-- ===== Crédits faibles + Transactions récentes ===== -->
    <div class="two-col-grid">

        <!-- ===== TRANSACTIONS RÉCENTES ===== -->
        <div class="table-container">
            <div class="table-header">
                <h3>
                    <i class="fas fa-exchange-alt" style="color: var(--info);"></i>
                    Transactions récentes
                    <span class="badge-transactions"><?= count($recentTransactions) ?></span>
                </h3>
                <a href="?page=admin/transactions" style="display: none;">Voir toutes →</a>
            </div>

            <?php if (empty($recentTransactions)): ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <p>Aucune transaction récente</p>
                    <p style="font-size: 13px; margin-top: 4px;">Les transactions apparaîtront ici</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 15%;">Date</th>
                                <th style="width: 20%;">Client</th>
                                <th style="width: 12%;">Type</th>
                                <th style="width: 15%;">Montant</th>
                                <th style="width: 38%;">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTransactions as $transaction): ?>
                            <tr>
                                <td style="font-size: 12px; color: var(--muted-2);">
                                    <?= date('d/m/Y H:i', strtotime($transaction['created_at'])) ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($transaction['client_entreprise'] ?? '-') ?>
                                </td>
                                <td>
                                    <span class="badge-transaction <?= $transaction['type_transaction'] ?>">
                                        <i class="fas <?= $transaction['type_icon'] ?>"></i>
                                        <?= $transaction['type_label'] ?>
                                    </span>
                                </td>
                                <td class="<?= $transaction['type_transaction'] === 'credit' ? 'transaction-amount-credit' : 'transaction-amount-debit' ?>">
                                    <?= ($transaction['type_transaction'] === 'credit' ? '+' : '-') ?>
                                    <?= number_format($transaction['montant'], 2) ?> €
                                </td>
                                <td style="font-size: 12px; color: var(--muted);">
                                    <?= htmlspecialchars($transaction['description'] ?? '-') ?>
                                    <?php if (!empty($transaction['provider_nom']) && $transaction['provider_nom'] !== 'N/A'): ?>
                                        <br><span style="font-size: 10px; color: var(--muted-2);">Via: <?= htmlspecialchars($transaction['provider_nom']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- ===== CLIENTS AVEC CRÉDIT FAIBLE ===== -->
        <div class="table-container">
            <div class="table-header">
                <h3>
                    <i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i>
                    Clients avec crédit faible
                    <span class="badge-count"><?= $totalLowCreditClients ?></span>
                </h3>
                <a href="?page=admin/clients">Voir tous →</a>
            </div>

            <?php if (empty($lowCreditClients)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle" style="color: var(--success);"></i>
                    <p>Aucun client avec un crédit faible</p>
                    <p style="font-size: 13px; margin-top: 4px;">Tous les clients ont un crédit suffisant</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 50%;">Entreprise</th>
                                <th style="width: 25%;">Crédits</th>
                                <th style="width: 25%;">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowCreditClients as $client): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($client['entreprise'] ?? '-') ?></strong>
                                    <br>
                                    <span style="font-size: 11px; color: var(--muted);">
                                        <?= htmlspecialchars($client['prenom'] ?? '') ?> <?= htmlspecialchars($client['nom'] ?? '') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="credit-warning">
                                        <i class="fas fa-coins"></i>
                                        <?= number_format($client['credits_total'] ?? 0, 0, ',', ' ') ?> €
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-statut <?= ($client['actif'] ?? 1) ? 'actif' : 'inactif' ?>">
                                        <?= ($client['actif'] ?? 1) ? 'Actif' : 'Suspendu' ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
    </div>

    <!-- ===== DERNIERS COMPTES ===== -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-user-plus"></i>Derniers comptes créés</h3>
            <a href="?page=admin/users">Voir tous →</a>
        </div>

        <?php if (empty($recentUsers)): ?>
            <div class="empty-state">
                <i class="fas fa-users-slash"></i>
                <p>Aucun compte créé pour le moment</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 22%;">Entreprise</th>
                            <th style="width: 20%;">Utilisateur</th>
                            <th style="width: 13%;">Crédits</th>
                            <th style="width: 12%;">Rôle</th>
                            <th style="width: 13%;">Statut</th>
                            <th style="width: 20%;">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentUsers as $user): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($user['entreprise'] ?? '-') ?></strong>
                            </td>
                            <td>
                                <?= htmlspecialchars($user['prenom'] ?? '') ?> 
                                <?= htmlspecialchars($user['nom'] ?? '') ?>
                            </td>
                            <td>
                                <?= number_format($user['credits_total'] ?? 0, 0, ',', ' ') ?> €
                            </td>
                            <td>
                                <span class="badge-role <?= ($user['role'] ?? 'user') === 'admin' ? 'admin' : 'user' ?>">
                                    <?= ucfirst($user['role'] ?? 'user') ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge-statut <?= ($user['actif'] ?? 1) ? 'actif' : 'inactif' ?>">
                                    <?= ($user['actif'] ?? 1) ? 'Actif' : 'Suspendu' ?>
                                </span>
                            </td>
                            <td style="color: var(--muted-2); font-size: 13px;">
                                <?= date('d/m/Y H:i', strtotime($user['date_creation'] ?? 'now')) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>