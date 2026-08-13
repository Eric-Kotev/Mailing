<?php
// operators.php - Gestion des opérateurs/providers
global $db;

// Vérifier la session
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?page=login');
    exit;
}

$idCompte = $_SESSION['user_id'];

// ============================================
// MAPPING FOURNISSEUR -> TABLE DE SESSIONS
// ============================================
// Chaque fournisseur stocke ses sessions/connexions clients dans une table
// dédiée. La colonne id_compte de cette table indique quel client (compte)
// utilise cet opérateur. On s'en sert pour compter les clients associés.
function getFournisseurTableMap()
{
    return [
        'octopush'         => 'octopush_config',
        'waha'             => 'whatsapp_sessions',
        'sms api gateway'  => 'sms_appareils',
        'listmonk'          => 'email_accounts',
    ];
}

// Liste des fournisseurs proposés dans le formulaire (clé => libellé affiché)
function getFournisseursDisponibles()
{
    return [
        'Octopush'        => 'Octopush (SMS)',
        'WAHA'            => 'WAHA (WhatsApp)',
        'SMS API Gateway' => 'SMS API Gateway (SMS)',
        'Listmonk'         => 'Listmonk (Email)',
    ];
}

// Résout le nom de table de sessions correspondant à un fournisseur donné
function resolveFournisseurTable($fournisseur)
{
    $map = getFournisseurTableMap();
    $key = mb_strtolower(trim($fournisseur));
    return $map[$key] ?? null;
}

// Compte le nombre de comptes clients (id_compte) distincts présents
// dans la table de sessions correspondant au fournisseur de l'opérateur.
function countClientsForProvider($db, $fournisseur)
{
    $table = resolveFournisseurTable($fournisseur);
    if (!$table) {
        return 0;
    }

    try {
        $rows = $db->select($table, [], 'id_compte');
    } catch (Exception $e) {
        return 0;
    }

    if (empty($rows)) {
        return 0;
    }

    $ids = array_unique(array_filter(array_map(function ($row) {
        return $row['id_compte'] ?? null;
    }, $rows)));

    return count($ids);
}

// ============================================
// TRAITEMENT DES ACTIONS
// ============================================

// Suppression d'un provider (via GET) - Gardé pour compatibilité
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $providerId = (int)$_GET['id'];

    // Vérifier que le provider appartient au compte
    $provider = $db->select('provider', [
        'id_provider' => $providerId,
        'id_compte' => $idCompte
    ]);

    if (!empty($provider)) {
        $result = $db->delete('provider', $providerId, 'id_provider');

        if ($result !== false) {
            $_SESSION['flash_success'] = "Opérateur supprimé avec succès";
        } else {
            $_SESSION['flash_error'] = "Erreur lors de la suppression";
        }
    } else {
        $_SESSION['flash_error'] = "Opérateur non trouvé";
    }

    header('Location: index.php?page=admin/operators');
    exit;
}

// Suppression d'un provider (via AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_provider') {
    header('Content-Type: application/json');

    try {
        $id_provider = isset($_POST['id_provider']) ? intval($_POST['id_provider']) : 0;

        if ($id_provider <= 0) {
            throw new Exception('ID invalide');
        }

        $provider = $db->select('provider', [
            'id_provider' => $id_provider,
            'id_compte' => $idCompte
        ]);

        if (empty($provider)) {
            throw new Exception('Opérateur non trouvé');
        }

        $result = $db->delete('provider', $id_provider, 'id_provider');

        if ($result !== false) {
            echo json_encode([
                'success' => true,
                'message' => 'Opérateur supprimé avec succès'
            ]);
        } else {
            throw new Exception('Erreur lors de la suppression');
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Création d'un nouveau provider (via AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_provider') {
    header('Content-Type: application/json');

    try {
        $nom = trim($_POST['nom'] ?? '');
        $canal = trim($_POST['canal'] ?? '');
        $fournisseur = trim($_POST['fournisseur'] ?? '');
        $tarif = floatval($_POST['tarif'] ?? 0);
        $idCompte = $_SESSION['user_id'];

        // Validation
        if (empty($nom)) {
            throw new Exception('Le nom est requis');
        }
        if (empty($canal)) {
            throw new Exception('Le canal est requis');
        }
        if (empty($fournisseur)) {
            throw new Exception('Le fournisseur est requis');
        }
        if (!array_key_exists($fournisseur, getFournisseursDisponibles())) {
            throw new Exception('Le fournisseur sélectionné n\'est pas valide');
        }
        if ($tarif < 0) {
            throw new Exception('Le tarif doit être positif');
        }

        // Vérifier que le type_message existe
        $typeMessage = $db->select('type_message', ['id_type_message' => $canal]);
        if (empty($typeMessage)) {
            throw new Exception('Le canal sélectionné n\'existe pas');
        }

        // Créer le provider avec statut par défaut 'actif'
        $providerData = [
            'nom_providers' => $nom,
            'description' => $fournisseur,
            'id_type_message' => $canal,
            'id_compte' => $idCompte,
            'tarif' => $tarif,
            'statut' => 'actif',
            'created_at' => date('Y-m-d H:i:s')
        ];

        $result = $db->insert('provider', $providerData);

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Opérateur créé avec succès',
                'provider' => array_merge(['id_provider' => $result], $providerData)
            ]);
        } else {
            throw new Exception('Erreur lors de la création');
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Mise à jour d'un provider (via AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_provider') {
    header('Content-Type: application/json');

    try {
        $id_provider = isset($_POST['id_provider']) ? intval($_POST['id_provider']) : 0;

        if ($id_provider <= 0) {
            throw new Exception('ID invalide');
        }

        $nom = trim($_POST['nom'] ?? '');
        $canal = trim($_POST['canal'] ?? '');
        $fournisseur = trim($_POST['fournisseur'] ?? '');
        $tarif = floatval($_POST['tarif'] ?? 0);
        $idCompte = $_SESSION['user_id'];

        // Validation
        if (empty($nom)) {
            throw new Exception('Le nom est requis');
        }
        if (empty($canal)) {
            throw new Exception('Le canal est requis');
        }
        if (empty($fournisseur)) {
            throw new Exception('Le fournisseur est requis');
        }
        if (!array_key_exists($fournisseur, getFournisseursDisponibles())) {
            throw new Exception('Le fournisseur sélectionné n\'est pas valide');
        }
        if ($tarif < 0) {
            throw new Exception('Le tarif doit être positif');
        }

        // Vérifier que le provider appartient au compte
        $existing = $db->select('provider', [
            'id_provider' => $id_provider,
            'id_compte' => $idCompte
        ]);

        if (empty($existing)) {
            throw new Exception('Opérateur non trouvé');
        }

        // Vérifier que le type_message existe
        $typeMessage = $db->select('type_message', ['id_type_message' => $canal]);
        if (empty($typeMessage)) {
            throw new Exception('Le canal sélectionné n\'existe pas');
        }

        // Mettre à jour le provider
        $providerData = [
            'nom_providers' => $nom,
            'description' => $fournisseur,
            'id_type_message' => $canal,
            'tarif' => $tarif
        ];

        $result = $db->update('provider', $providerData, [
            'id_provider' => $id_provider,
            'id_compte' => $idCompte
        ]);

        if ($result !== false) {
            echo json_encode([
                'success' => true,
                'message' => 'Opérateur mis à jour avec succès'
            ]);
        } else {
            throw new Exception('Erreur lors de la mise à jour');
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
// RÉCUPÉRATION DES DONNÉES
// ============================================

// Récupérer tous les providers du compte
$providers = $db->select('provider', ['id_compte' => $idCompte], '*', 'created_at DESC');

// Récupérer tous les types de messages (canaux) avec libelle_type
$typeMessages = $db->select('type_message', [], '*', 'libelle_type ASC');

// Pour chaque provider, calculer le nombre de clients connectés
// (comptes distincts trouvés dans la table de sessions du fournisseur)
foreach ($providers as &$provider) {
    $provider['nb_clients'] = countClientsForProvider($db, $provider['description']);
}
unset($provider);

$totalClientsConnectes = array_sum(array_column($providers, 'nb_clients'));
$fournisseursDisponibles = getFournisseursDisponibles();

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des opérateurs - <?= APP_NAME ?></title>
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
            --input-border: oklch(0.88 0.005 275);
            --accent: oklch(0.55 0.18 275);
            --accent-dark: oklch(0.48 0.18 275);
            --accent-soft-bg: oklch(0.94 0.04 275);
            --accent-soft-fg: oklch(0.45 0.16 275);
            --danger: oklch(0.6 0.19 30);
            --danger-soft-bg: oklch(0.95 0.04 30);
            --success: oklch(0.6 0.14 155);
            --success-soft-bg: oklch(0.94 0.05 155);
            --success-soft-fg: oklch(0.45 0.13 155);
            --sms-bg: oklch(0.92 0.05 250);
            --sms-fg: oklch(0.45 0.15 250);
            --whatsapp-bg: oklch(0.92 0.08 155);
            --whatsapp-fg: oklch(0.42 0.13 155);
            --email-bg: oklch(0.93 0.07 80);
            --email-fg: oklch(0.48 0.12 70);
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
            padding: 16px 26px;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            font-size: 14px;
            box-shadow: 0 8px 24px rgba(20,20,50,0.18);
            animation: slideInRight 0.35s ease-out;
            min-width: 300px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .toast.success { background: linear-gradient(135deg, oklch(0.62 0.15 155), oklch(0.52 0.13 155)); }
        .toast.error { background: linear-gradient(135deg, oklch(0.62 0.2 30), oklch(0.52 0.19 30)); }
        .toast.info { background: linear-gradient(135deg, var(--accent), var(--accent-dark)); }

        @keyframes slideInRight { from { transform: translateX(110%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOutRight { from { transform: translateX(0); opacity: 1; } to { transform: translateX(110%); opacity: 0; } }

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

        .header-actions {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .search-input {
            width: 320px;
            padding: 12px 18px;
            border-radius: 12px;
            border: 1px solid var(--input-border);
            font-size: 14px;
            font-family: inherit;
            outline: none;
            background: white;
            transition: border-color 0.2s ease;
        }

        .search-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px oklch(0.55 0.18 275 / 0.12);
        }

        .btn-primary {
            padding: 12px 22px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            background: var(--accent);
            color: white;
            border: none;
            cursor: pointer;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: background 0.2s ease;
        }

        .btn-primary:hover { background: var(--accent-dark); }

        /* ===== STATS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
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
            min-width: 0;
        }

        .stat-card .stat-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
        }

        .stat-card .stat-number {
            font-size: 30px;
            font-weight: 800;
            margin-top: 6px;
        }

        .stat-card .stat-hint {
            font-size: 13px;
            color: var(--muted-2);
            margin-top: 4px;
        }

        /* ===== TABLE (grid) ===== */
        .table-container {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--border);
            overflow: hidden;
            width: 100%;
        }

        .table-wrapper {
            overflow-x: auto;
            width: 100%;
        }

        .grid-row {
            display: grid;
            grid-template-columns: 1.8fr 1fr 1.4fr 1fr 0.8fr 0.8fr 0.6fr 0.6fr;
            gap: 12px;
            align-items: center;
            min-width: 0;
        }

        .grid-head {
            padding: 14px 24px;
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid var(--border-light);
            background: oklch(0.98 0.003 275);
        }

        .grid-body-row {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-light);
            font-size: 14px;
            transition: background 0.15s ease;
        }

        .grid-body-row:last-child { border-bottom: none; }
        .grid-body-row:hover { background: oklch(0.98 0.003 275); }

        .op-name {
            font-weight: 700;
            color: var(--text);
            font-size: 15px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .op-fournisseur {
            color: var(--muted-2);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .badge-canal {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
        }

        .badge-canal.sms { background: var(--sms-bg); color: var(--sms-fg); }
        .badge-canal.whatsapp { background: var(--whatsapp-bg); color: var(--whatsapp-fg); }
        .badge-canal.email { background: var(--email-bg); color: var(--email-fg); }
        .badge-canal.default { background: var(--default-bg); color: var(--default-fg); }

        .tarif-value {
            font-weight: 700;
            font-size: 15px;
            white-space: nowrap;
        }

        .client-count {
            font-weight: 600;
            font-size: 15px;
            text-align: center;
        }

        .badge-statut {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-statut.actif { background: var(--success-soft-bg); color: var(--success-soft-fg); }
        .badge-statut.inactif { background: var(--default-bg); color: var(--default-fg); }

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

        .btn-remove {
            cursor: pointer;
            text-align: right;
            color: var(--danger);
            font-weight: 700;
            font-size: 18px;
            background: none;
            border: none;
            padding: 0;
            transition: color 0.2s ease;
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }

        .btn-remove:hover { 
            color: oklch(0.45 0.19 30);
            background: var(--danger-soft-bg);
        }

        .btn-remove.edit-btn {
            color: var(--accent);
        }

        .btn-remove.edit-btn:hover {
            background: var(--accent-soft-bg);
            color: var(--accent-dark);
        }

        .btn-group {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .empty-state {
            text-align: center;
            padding: 90px 20px;
        }

        .empty-state i {
            font-size: 64px;
            color: var(--border);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 24px;
            color: var(--muted);
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--muted-2);
            font-size: 15px;
        }

        /* ===== MODAL ===== */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(20, 20, 40, 0.45);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .modal-overlay.active { display: flex; }

        .modal {
            background: white;
            border-radius: 20px;
            width: 100%;
            max-width: 620px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 24px 64px rgba(20,20,50,0.25);
            transform: scale(0.96) translateY(8px);
            opacity: 0;
            transition: all 0.25s ease;
        }

        .modal-overlay.active .modal { transform: scale(1) translateY(0); opacity: 1; }

        .modal-header {
            padding: 28px 32px 20px 32px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title { display: flex; align-items: center; gap: 14px; }

        .modal-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .modal-icon.warning { background: var(--danger-soft-bg); color: var(--danger); }
        .modal-icon.success { background: var(--accent-soft-bg); color: var(--accent-soft-fg); }

        .modal-header h3 { font-size: 20px; font-weight: 800; }

        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: var(--muted);
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .modal-close:hover { color: var(--text); }

        .modal-body { padding: 28px 32px; }
        .modal-footer {
            padding: 20px 32px 28px 32px;
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: flex-end;
            gap: 14px;
        }

        .confirmation-text { font-size: 15px; color: var(--muted-2); line-height: 1.6; text-align: center; }
        .confirmation-text strong { color: var(--text); }
        .warning-icon { color: var(--danger); font-size: 36px; display: block; text-align: center; margin-bottom: 12px; }
        .warning-message { margin-top: 14px; color: var(--danger); font-weight: 600; font-size: 13px; text-align: center; }

        .form-group { margin-bottom: 22px; }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }

        .form-group label .required { color: var(--danger); }

        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid var(--input-border);
            border-radius: 12px;
            font-size: 15px;
            font-family: inherit;
            background: white;
            transition: all 0.15s ease;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px oklch(0.55 0.18 275 / 0.12);
        }

        .form-group .helper { font-size: 13px; color: var(--muted-2); margin-top: 6px; }

        .btn-secondary {
            padding: 12px 26px;
            border: 1.5px solid var(--input-border);
            background: white;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            color: var(--muted-2);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover { 
            background: oklch(0.98 0.003 275);
            border-color: var(--muted-2);
        }

        .btn-success {
            background: linear-gradient(135deg, oklch(0.62 0.15 155), oklch(0.52 0.13 155));
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: opacity 0.2s ease;
        }

        .btn-success:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-success:hover:not(:disabled) { opacity: 0.9; }

        .btn-danger {
            background: linear-gradient(135deg, oklch(0.62 0.2 30), oklch(0.52 0.19 30));
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: opacity 0.2s ease;
        }

        .btn-danger:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-danger:hover:not(:disabled) { opacity: 0.9; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1400px) {
            .container { 
                padding: 20px 24px; 
            }
            .grid-row { 
                grid-template-columns: 1.6fr 0.9fr 1.2fr 0.9fr 0.7fr 0.7fr 0.5fr 0.5fr; 
                gap: 10px;
            }
        }

        @media (max-width: 1200px) {
            .container { 
                padding: 20px; 
            }
            .grid-row { 
                grid-template-columns: 1.4fr 0.8fr 1fr 0.8fr 0.6fr 0.6fr 0.4fr 0.4fr; 
                gap: 8px;
            }
            .grid-head { 
                padding: 12px 16px; 
                font-size: 10px;
            }
            .grid-body-row { 
                padding: 14px 16px; 
                font-size: 13px;
            }
            .op-name { 
                font-size: 14px; 
            }
            .search-input { 
                width: 260px; 
            }
        }

        @media (max-width: 900px) {
            .container { 
                padding: 16px; 
            }
            .stats-grid { 
                grid-template-columns: 1fr 1fr; 
            }
            .grid-row { 
                grid-template-columns: 1.2fr 0.7fr 0.9fr 0.7fr 0.5fr 0.5fr 0.3fr 0.3fr; 
                gap: 6px;
                font-size: 12px;
            }
            .grid-head { 
                padding: 10px 12px; 
                font-size: 9px;
            }
            .grid-body-row { 
                padding: 12px 12px; 
                font-size: 12px;
            }
            .op-name { 
                font-size: 13px; 
            }
            .search-input { 
                width: 200px; 
            }
            .header-actions { 
                width: 100%; 
            }
            .btn-primary { 
                flex: 1; 
                justify-content: center;
            }
            .badge-canal { 
                font-size: 10px; 
                padding: 3px 10px;
            }
            .badge-statut { 
                font-size: 10px; 
                padding: 3px 10px;
            }
            .btn-remove { 
                font-size: 16px; 
                width: 28px;
                height: 28px;
            }
        }

        @media (max-width: 640px) {
            .container { 
                padding: 12px; 
            }
            .page-header { 
                flex-direction: column; 
                align-items: flex-start; 
            }
            .header-actions { 
                flex-direction: column; 
                width: 100%;
            }
            .search-input { 
                width: 100%; 
                min-width: 0;
            }
            .btn-primary { 
                width: 100%; 
                justify-content: center;
            }
            .stats-grid { 
                grid-template-columns: 1fr; 
            }
            .stat-card .stat-number { 
                font-size: 26px; 
            }
            .grid-row { 
                grid-template-columns: repeat(8, minmax(90px, 1fr)); 
                width: max-content; 
                min-width: 100%;
                gap: 8px;
                font-size: 12px;
            }
            .grid-head { 
                padding: 10px 12px; 
                font-size: 9px;
            }
            .grid-body-row { 
                padding: 10px 12px; 
                font-size: 11px;
            }
            .op-name { 
                font-size: 12px; 
            }
            .btn-voir { 
                font-size: 11px; 
            }
            .modal { 
                max-width: 100%; 
                margin: 10px; 
            }
            .modal-header, .modal-body, .modal-footer { 
                padding: 16px 20px; 
            }
            .modal-header h3 { 
                font-size: 17px; 
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- ===== HEADER ===== -->
    <div class="page-header">
        <div>
            <div class="title"> Opérateurs</div>
            <div class="subtitle">Canaux d'envoi SMS, WhatsApp &amp; Email</div>
        </div>
        <div class="header-actions">
            <input type="text" id="operatorSearch" class="search-input" placeholder=" Rechercher un opérateur…" oninput="filterOperators()">
            <button class="btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> Nouvel opérateur
            </button>
        </div>
    </div>

    <!-- ===== TABLE ===== -->
    <div class="table-container">
        <?php if (empty($providers)): ?>
            <div class="empty-state">
                <i class="fas fa-users-slash"></i>
                <h3>Aucun opérateur</h3>
                <p>Commencez par créer votre premier opérateur</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <div class="grid-row grid-head">
                    <div>Opérateur</div>
                    <div>Canal</div>
                    <div>Fournisseur</div>
                    <div>Tarif défaut</div>
                    <div style="text-align:center;">Clients</div>
                    <div>Statut</div>
                    <div></div>
                    <div></div>
                </div>

                <div id="operatorsList">
                <?php foreach ($providers as $provider): ?>
                    <?php
                    $canal = array_filter($typeMessages, function ($tm) use ($provider) {
                        return $tm['id_type_message'] == $provider['id_type_message'];
                    });
                    $canal = !empty($canal) ? array_values($canal)[0] : null;
                    $canalName = $canal ? $canal['libelle_type'] : 'Inconnu';
                    $canalClass = strtolower($canalName);
                    $canalClass = in_array($canalClass, ['whatsapp', 'sms', 'email']) ? $canalClass : 'default';

                    $nbClients = (int)$provider['nb_clients'];
                    
                    // Récupérer le statut depuis la base, avec fallback 'actif'
                    $statut = isset($provider['statut']) ? $provider['statut'] : 'actif';
                    $isActif = ($statut === 'actif');

                    $searchBlob = mb_strtolower($provider['nom_providers'] . ' ' . $provider['description'] . ' ' . $canalName);
                    ?>
                    <div class="grid-row grid-body-row" data-search="<?= htmlspecialchars($searchBlob) ?>">
                        <div class="op-name" title="<?= htmlspecialchars($provider['nom_providers']) ?>">
                            <?= htmlspecialchars($provider['nom_providers']) ?>
                        </div>
                        <div>
                            <span class="badge-canal <?= $canalClass ?>"><?= htmlspecialchars($canalName) ?></span>
                        </div>
                        <div class="op-fournisseur" title="<?= htmlspecialchars($provider['description']) ?>">
                            <?= htmlspecialchars($provider['description']) ?>
                        </div>
                        <div class="tarif-value"><?= number_format($provider['tarif'], 2, ',', ' ') ?> €</div>
                        <div class="client-count"><?= $nbClients ?></div>
                        <div>
                            <span class="badge-statut <?= $isActif ? 'actif' : 'inactif' ?>">
                                <?= $isActif ? 'Actif' : 'Inactif' ?>
                            </span>
                        </div>
                        <div>
                            <a class="btn-voir" href="index.php?page=admin/operator_details&id=<?= (int)$provider['id_provider'] ?>">Voir →</a>
                        </div>
                        <div class="btn-group">
                            <button class="btn-remove edit-btn" onclick='editProvider(<?= json_encode($provider) ?>)' title="Modifier">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button class="btn-remove" onclick="openDeleteModal(<?= (int)$provider['id_provider'] ?>, '<?= htmlspecialchars($provider['nom_providers'], ENT_QUOTES) ?>')" title="Supprimer">
                                ✕
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== MODAL DE CRÉATION / ÉDITION ===== -->
<div id="providerModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">
                <span class="modal-icon success"><i class="fas fa-user-plus"></i></span>
                <h3 id="modalTitle">Nouvel opérateur</h3>
            </div>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>

        <form id="providerForm" onsubmit="submitProvider(event)">
            <div class="modal-body">
                <input type="hidden" id="providerId" name="id_provider" value="">
                <input type="hidden" name="action" id="formAction" value="create_provider">

                <div class="form-group">
                    <label for="nom">Nom de l'opérateur <span class="required">*</span></label>
                    <input type="text" id="nom" name="nom" placeholder="Ex: Opérateur SMS FR" required>
                    <div class="helper">Nom unique pour identifier cet opérateur</div>
                </div>

                <div class="form-group">
                    <label for="canal">Canal <span class="required">*</span></label>
                    <select id="canal" name="canal" required>
                        <option value="">Sélectionnez un canal...</option>
                        <?php foreach ($typeMessages as $tm): ?>
                            <option value="<?= $tm['id_type_message'] ?>">
                                <?= htmlspecialchars($tm['libelle_type']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="helper">Le type de message que cet opérateur peut envoyer</div>
                </div>

                <div class="form-group">
                    <label for="fournisseur">Fournisseur <span class="required">*</span></label>
                    <select id="fournisseur" name="fournisseur" required>
                        <option value="">Sélectionnez un fournisseur...</option>
                        <?php foreach ($fournisseursDisponibles as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="helper">Détermine la table de sessions utilisée pour compter les clients connectés</div>
                </div>

                <div class="form-group">
                    <label for="tarif">Tarif <span class="required">*</span></label>
                    <input type="number" id="tarif" name="tarif" placeholder="0.00" step="0.01" min="0" required>
                    <div class="helper">Coût par message (en euros)</div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal()">Annuler</button>
                <button type="submit" class="btn-success" id="submitBtn">
                    <i class="fas fa-save"></i>
                    <span id="submitBtnText">Créer l'opérateur</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ===== MODAL DE CONFIRMATION DE SUPPRESSION ===== -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <div class="modal-title">
                <span class="modal-icon warning"><i class="fas fa-exclamation-triangle"></i></span>
                <h3>Confirmer la suppression</h3>
            </div>
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>

        <div class="modal-body">
            <div class="confirmation-text">
                <i class="fas fa-exclamation-circle warning-icon"></i>
                <p>Supprimer l'opérateur <strong id="deleteProviderName"></strong> ?</p>
                <div class="warning-message">
                    <i class="fas fa-info-circle"></i> Cette action est irréversible.
                </div>
            </div>
            <input type="hidden" id="deleteProviderId" value="">
        </div>

        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Annuler</button>
            <button type="button" class="btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()">
                <i class="fas fa-trash-alt"></i> Supprimer
            </button>
        </div>
    </div>
</div>

<div id="toastContainer" class="toast-container"></div>

<script>
// ============================================
// RECHERCHE
// ============================================
function filterOperators() {
    const q = document.getElementById('operatorSearch').value.trim().toLowerCase();
    document.querySelectorAll('#operatorsList .grid-body-row').forEach(row => {
        const blob = row.getAttribute('data-search') || '';
        row.style.display = blob.includes(q) ? '' : 'none';
    });
}

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
// MODAL CRÉATION / ÉDITION
// ============================================
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Nouvel opérateur';
    document.getElementById('submitBtnText').textContent = "Créer l'opérateur";
    document.getElementById('providerId').value = '';
    document.getElementById('formAction').value = 'create_provider';
    document.getElementById('providerForm').reset();
    document.getElementById('providerModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('nom').focus(), 100);
}

function editProvider(provider) {
    document.getElementById('modalTitle').textContent = "Modifier l'opérateur";
    document.getElementById('submitBtnText').textContent = 'Mettre à jour';
    document.getElementById('providerId').value = provider.id_provider;
    document.getElementById('formAction').value = 'update_provider';
    document.getElementById('nom').value = provider.nom_providers;
    document.getElementById('canal').value = provider.id_type_message;
    document.getElementById('fournisseur').value = provider.description;
    document.getElementById('tarif').value = provider.tarif;
    document.getElementById('providerModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('providerModal').classList.remove('active');
    document.body.style.overflow = '';
}

// ============================================
// MODAL SUPPRESSION
// ============================================
function openDeleteModal(id, name) {
    document.getElementById('deleteProviderId').value = id;
    document.getElementById('deleteProviderName').textContent = name;
    document.getElementById('deleteModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
    document.body.style.overflow = '';
}

async function confirmDelete() {
    const id = document.getElementById('deleteProviderId').value;
    const btn = document.getElementById('confirmDeleteBtn');
    const originalText = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Suppression...';

    try {
        const formData = new FormData();
        formData.append('action', 'delete_provider');
        formData.append('id_provider', id);

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
            showToast(result.message, 'success');
            closeDeleteModal();
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(result.message || 'Une erreur est survenue', 'error');
        }
    } catch (error) {
        console.error('Erreur détaillée:', error);
        showToast('Erreur: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

// ============================================
// SOUMISSION DU FORMULAIRE
// ============================================
async function submitProvider(event) {
    event.preventDefault();

    const form = document.getElementById('providerForm');
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement...';

    try {
        const formData = new FormData(form);
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
            showToast(result.message, 'success');
            closeModal();
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(result.message || 'Une erreur est survenue', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur: ' + error.message, 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
}

// ============================================
// FERMETURE DES MODALS
// ============================================
document.getElementById('providerModal').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
});

document.getElementById('deleteModal').addEventListener('click', function (e) {
    if (e.target === this) closeDeleteModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeModal(); closeDeleteModal(); }
});

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