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
        // Utiliser la méthode delete avec les bons paramètres
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
        
        // Vérifier que le provider appartient au compte
        $provider = $db->select('provider', [
            'id_provider' => $id_provider,
            'id_compte' => $idCompte
        ]);
        
        if (empty($provider)) {
            throw new Exception('Opérateur non trouvé');
        }
        
        // Supprimer le provider - Utilisation correcte de la méthode delete
        // delete($table, $id, $idField)
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
        if ($tarif < 0) {
            throw new Exception('Le tarif doit être positif');
        }
        
        // Vérifier que le type_message existe
        $typeMessage = $db->select('type_message', ['id_type_message' => $canal]);
        if (empty($typeMessage)) {
            throw new Exception('Le canal sélectionné n\'existe pas');
        }
        
        // Créer le provider
        $providerData = [
            'nom_providers' => $nom,
            'description' => $fournisseur,
            'id_type_message' => $canal,
            'id_compte' => $idCompte,
            'tarif' => $tarif,
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

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des opérateurs - <?= APP_NAME ?></title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===== STYLES GÉNÉRAUX ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f3f4f6;
            color: #1f2937;
        }
        
        /* ===== CONTAINER ===== */
        .container {
            max-width: 100%;
            padding: 20px 30px;
            margin: 0 auto;
        }
        
        /* ===== TOAST NOTIFICATIONS ===== */
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
            padding: 14px 24px;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            font-size: 14px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            animation: slideInRight 0.4s ease-out;
            min-width: 280px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .toast.success { background: linear-gradient(135deg, #10b981, #059669); }
        .toast.error { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .toast.info { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        
        .toast i {
            font-size: 20px;
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        /* ===== HEADER ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 16px;
            padding: 20px 0;
        }
        
        .page-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .page-header-left .icon-wrapper {
            background: #8b5cf6;
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
        }
        
        .page-header h1 {
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
        }
        
        .page-header p {
            color: #6b7280;
            font-size: 16px;
            margin-top: 4px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 14px rgba(139, 92, 246, 0.35);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.45);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        /* ===== STATS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: white;
            border-radius: 14px;
            padding: 22px 26px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .stat-card .stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-card .stat-icon.purple { background: #ede9fe; color: #7c3aed; }
        .stat-card .stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .stat-card .stat-icon.green { background: #d1fae5; color: #059669; }
        
        .stat-card .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
        }
        
        .stat-card .stat-label {
            font-size: 14px;
            color: #6b7280;
        }
        
        /* ===== TABLEAU ===== */
        .table-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        
        .table-header {
            padding: 22px 28px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .table-header h2 {
            font-size: 20px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .table-header .badge {
            background: #ede9fe;
            color: #7c3aed;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .table-wrapper {
            overflow-x: auto;
            padding: 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        thead {
            background: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
        }
        
        th {
            text-align: left;
            padding: 16px 24px;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            white-space: nowrap;
        }
        
        td {
            padding: 16px 24px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 15px;
            vertical-align: middle;
        }
        
        tr:hover td {
            background: #fafafa;
        }
        
        .provider-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 15px;
        }
        
        .provider-description {
            color: #6b7280;
            font-size: 14px;
        }
        
        .badge-canal {
            display: inline-block;
            padding: 5px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .badge-canal.whatsapp {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-canal.sms {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .badge-canal.email {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-canal.default {
            background: #f3f4f6;
            color: #4b5563;
        }
        
        .tarif {
            font-weight: 600;
            color: #1f2937;
            font-size: 15px;
        }
        
        .date-creation {
            color: #6b7280;
            font-size: 14px;
            white-space: nowrap;
        }
        
        .actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .btn-icon {
            width: 38px;
            height: 38px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        
        .btn-icon.edit {
            background: #dbeafe;
            color: #2563eb;
        }
        
        .btn-icon.edit:hover {
            background: #bfdbfe;
            transform: scale(1.05);
        }
        
        .btn-icon.delete {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .btn-icon.delete:hover {
            background: #fecaca;
            transform: scale(1.05);
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 20px;
        }
        
        .empty-state i {
            font-size: 64px;
            color: #d1d5db;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-size: 24px;
            color: #4b5563;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #9ca3af;
            font-size: 16px;
        }
        
        /* ===== MODAL ===== */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: white;
            border-radius: 20px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 24px 64px rgba(0,0,0,0.2);
            transform: scale(0.95) translateY(10px);
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active .modal {
            transform: scale(1) translateY(0);
            opacity: 1;
        }
        
        /* ===== MODAL DE CRÉATION/ÉDITION ===== */
        .modal-header {
            padding: 28px 32px 20px 32px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header .modal-title {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        
        .modal-header .modal-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .modal-header .modal-icon.warning {
            background: #fef3c7;
            color: #d97706;
        }
        
        .modal-header .modal-icon.success {
            background: #ede9fe;
            color: #7c3aed;
        }
        
        .modal-header h3 {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: #9ca3af;
            cursor: pointer;
            transition: color 0.2s;
            padding: 4px;
        }
        
        .modal-close:hover {
            color: #4b5563;
        }
        
        .modal-body {
            padding: 28px 32px 32px 32px;
        }
        
        .modal-footer {
            padding: 20px 32px 28px 32px;
            border-top: 1px solid #f3f4f6;
            display: flex;
            justify-content: flex-end;
            gap: 14px;
        }
        
        /* ===== MODAL DE SUPPRESSION (plus compact) ===== */
        .modal-delete .modal-header {
            padding: 18px 24px 14px 24px;
        }
        
        .modal-delete .modal-header .modal-icon {
            width: 40px;
            height: 40px;
            font-size: 18px;
        }
        
        .modal-delete .modal-header h3 {
            font-size: 18px;
        }
        
        .modal-delete .modal-body {
            padding: 16px 24px 20px 24px;
        }
        
        .modal-delete .modal-body .confirmation-text {
            font-size: 15px;
            color: #4b5563;
            line-height: 1.5;
        }
        
        .modal-delete .modal-body .confirmation-text strong {
            color: #1f2937;
        }
        
        .modal-delete .modal-body .confirmation-text .warning-icon {
            color: #d97706;
            font-size: 32px;
            display: block;
            text-align: center;
            margin-bottom: 10px;
        }
        
        .modal-delete .modal-body .warning-message {
            margin-top: 8px;
            color: #dc2626;
            font-weight: 500;
            font-size: 13px;
            text-align: center;
        }
        
        .modal-delete .modal-footer {
            padding: 12px 24px 18px 24px;
        }
        
        .modal-delete .modal-footer .btn-secondary {
            padding: 8px 20px;
            font-size: 14px;
        }
        
        .modal-delete .modal-footer .btn-danger {
            padding: 8px 20px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 22px;
        }
        
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        
        .form-group label .required {
            color: #ef4444;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.2s ease;
            background: white;
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.1);
        }
        
        .form-group input::placeholder {
            color: #9ca3af;
        }
        
        .form-group .helper {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 6px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .btn-secondary {
            padding: 12px 26px;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            color: #4b5563;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-secondary:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
        }
        
        .btn-success:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.35);
        }
        
        .btn-danger:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 1024px) {
            .container {
                padding: 15px 20px;
            }
            
            .page-header h1 {
                font-size: 28px;
            }
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px 15px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .page-header .btn-primary {
                width: 100%;
                justify-content: center;
            }
            
            .page-header h1 {
                font-size: 24px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .modal {
                max-width: 100%;
                margin: 10px;
                border-radius: 16px;
            }
            
            .actions {
                justify-content: flex-start;
            }
            
            .stat-card .stat-number {
                font-size: 22px;
            }
            
            .modal-delete .modal-header h3 {
                font-size: 16px;
            }
            
            .modal-delete .modal-body .confirmation-text {
                font-size: 14px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            td, th {
                padding: 12px 16px;
                font-size: 14px;
            }
            
            .page-header-left .icon-wrapper {
                width: 48px;
                height: 48px;
                font-size: 22px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- ===== HEADER ===== -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="icon-wrapper">
                <i class="fas fa-users-cog"></i>
            </div>
            <div>
                <h1>Gestion des opérateurs</h1>
                <p>Gérez les opérateurs disponibles pour vos campagnes</p>
            </div>
        </div>
        <button class="btn-primary" onclick="openCreateModal()">
            <i class="fas fa-plus-circle"></i>
            Créer un opérateur
        </button>
    </div>

    <!-- ===== STATS ===== -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-number"><?= count($providers) ?></div>
                <div class="stat-label">Opérateurs</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-filter"></i></div>
            <div>
                <div class="stat-number"><?= count($typeMessages) ?></div>
                <div class="stat-label">Canaux disponibles</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="stat-number"><?= count(array_filter($providers, function($p) { return $p['tarif'] > 0; })) ?></div>
                <div class="stat-label">Opérateurs avec tarif</div>
            </div>
        </div>
    </div>

    <!-- ===== TABLEAU ===== -->
    <div class="table-container">
        <div class="table-header">
            <h2>Liste des opérateurs</h2>
            <span class="badge"><?= count($providers) ?> opérateur(s)</span>
        </div>
        
        <div class="table-wrapper">
            <?php if (empty($providers)): ?>
                <div class="empty-state">
                    <i class="fas fa-users-slash"></i>
                    <h3>Aucun opérateur</h3>
                    <p>Commencez par créer votre premier opérateur</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Fournisseur</th>
                            <th>Canal</th>
                            <th>Tarif</th>
                            <th>Créé le</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($providers as $provider): ?>
                            <?php
                            // Trouver le type de message associé
                            $canal = array_filter($typeMessages, function($tm) use ($provider) {
                                return $tm['id_type_message'] == $provider['id_type_message'];
                            });
                            $canal = !empty($canal) ? array_values($canal)[0] : null;
                            $canalName = $canal ? $canal['libelle_type'] : 'Inconnu';
                            $canalClass = strtolower($canalName);
                            ?>
                            <tr>
                                <td>
                                    <div class="provider-name"><?= htmlspecialchars($provider['nom_providers']) ?></div>
                                </td>
                                <td>
                                    <div class="provider-description"><?= htmlspecialchars($provider['description']) ?></div>
                                </td>
                                <td>
                                    <span class="badge-canal <?= in_array($canalClass, ['whatsapp', 'sms', 'email']) ? $canalClass : 'default' ?>">
                                        <?= htmlspecialchars($canalName) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="tarif"><?= number_format($provider['tarif'], 2, ',', ' ') ?> €</span>
                                </td>
                                <td>
                                    <span class="date-creation"><?= date('d/m/Y H:i', strtotime($provider['created_at'])) ?></span>
                                </td>
                                <td style="text-align: right;">
                                    <div class="actions">
                                        <button class="btn-icon edit" onclick='editProvider(<?= json_encode($provider) ?>)' title="Modifier">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button class="btn-icon delete" onclick="openDeleteModal(<?= $provider['id_provider'] ?>, '<?= htmlspecialchars($provider['nom_providers']) ?>')" title="Supprimer">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
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
                <!-- ID caché pour l'édition -->
                <input type="hidden" id="providerId" name="id_provider" value="">
                <input type="hidden" name="action" id="formAction" value="create_provider">
                
                <div class="form-group">
                    <label for="nom">
                        Nom de l'opérateur <span class="required">*</span>
                    </label>
                    <input type="text" id="nom" name="nom" placeholder="Ex: Opérateur A" required>
                    <div class="helper">Nom unique pour identifier cet opérateur</div>
                </div>
                
                <div class="form-group">
                    <label for="canal">
                        Canal <span class="required">*</span>
                    </label>
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
                    <label for="fournisseur">
                        Fournisseur <span class="required">*</span>
                    </label>
                    <input type="text" id="fournisseur" name="fournisseur" placeholder="Ex: Twilio, Vonage..." required>
                    <div class="helper">Nom du fournisseur de service</div>
                </div>
                
                <div class="form-group">
                    <label for="tarif">
                        Tarif <span class="required">*</span>
                    </label>
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

<!-- ===== MODAL DE CONFIRMATION DE SUPPRESSION (compact) ===== -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal modal-delete">
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
                <i class="fas fa-trash-alt"></i>
                Supprimer
            </button>
        </div>
    </div>
</div>

<!-- ===== TOAST CONTAINER ===== -->
<div id="toastContainer" class="toast-container"></div>

<!-- ===== SCRIPTS ===== -->
<script>
// ============================================
// GESTION DES TOASTS
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
    
    toast.innerHTML = `
        <i class="${icons[type] || icons.info}"></i>
        <span>${message}</span>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.4s ease-in forwards';
        setTimeout(() => toast.remove(), 400);
    }, 4000);
}

// ============================================
// GESTION DU MODAL DE CRÉATION/ÉDITION
// ============================================
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Nouvel opérateur';
    document.getElementById('submitBtnText').textContent = 'Créer l\'opérateur';
    document.getElementById('providerId').value = '';
    document.getElementById('formAction').value = 'create_provider';
    document.getElementById('providerForm').reset();
    document.getElementById('providerModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    
    setTimeout(() => document.getElementById('nom').focus(), 100);
}

function editProvider(provider) {
    document.getElementById('modalTitle').textContent = 'Modifier l\'opérateur';
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
// GESTION DU MODAL DE SUPPRESSION
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
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Erreur serveur (HTTP ' + response.status + ')');
        }
        
        const text = await response.text();
        
        // Vérifier si la réponse contient du HTML (erreur PHP)
        if (text.trim().startsWith('<')) {
            console.error('Réponse HTML (erreur PHP):', text);
            const errorMatch = text.match(/Fatal error: ([^<]+)/);
            const errorMsg = errorMatch ? errorMatch[1] : 'Erreur PHP inconnue';
            throw new Error('Erreur PHP: ' + errorMsg);
        }
        
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            console.error('Réponse brute:', text);
            throw new Error('La réponse du serveur n\'est pas du JSON valide');
        }
        
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
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Erreur serveur (HTTP ' + response.status + ')');
        }
        
        const text = await response.text();
        
        // Vérifier si la réponse contient du HTML (erreur PHP)
        if (text.trim().startsWith('<')) {
            console.error('Réponse HTML (erreur PHP):', text);
            const errorMatch = text.match(/Fatal error: ([^<]+)/);
            const errorMsg = errorMatch ? errorMatch[1] : 'Erreur PHP inconnue';
            throw new Error('Erreur PHP: ' + errorMsg);
        }
        
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            console.error('Réponse brute:', text);
            throw new Error('La réponse du serveur n\'est pas du JSON valide');
        }
        
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
document.getElementById('providerModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        closeDeleteModal();
    }
});

// ============================================
// AFFICHAGE DES FLASH MESSAGES
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