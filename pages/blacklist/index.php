<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer les types de messages
$typeMessages = $db->select('type_message');
if (empty($typeMessages)) {
    try {
        $db->insert('type_message', ['libelle_type' => 'SMS']);
        $db->insert('type_message', ['libelle_type' => 'Email']);
        $db->insert('type_message', ['libelle_type' => 'WhatsApp']);
        $typeMessages = $db->select('type_message');
    } catch (Exception $e) {
        // Ignorer
    }
}

// Récupérer la blacklist avec les infos contact
$blacklist = $db->select('blacklist', [], '*', 'date_ajout DESC');
$blacklistWithContact = [];

// Organiser les blocages par contact
$blocagesParContact = [];
foreach ($blacklist as $bl) {
    $contact = $db->select('contact', ['id_contact' => $bl['id_contact'], 'id_compte' => $idCompte]);
    if ($contact && !empty($contact)) {
        $bl['contact'] = $contact[0];
        $blacklistWithContact[] = $bl;
        
        if (!isset($blocagesParContact[$bl['id_contact']])) {
            $blocagesParContact[$bl['id_contact']] = [];
        }
        $blocagesParContact[$bl['id_contact']][] = $bl['id_type_message'];
    }
}

// Récupérer tous les contacts (même ceux déjà blacklistés)
$tousContacts = $db->select('contact', ['id_compte' => $idCompte], '*', 'nom ASC');

// Pour chaque contact, déterminer quels types sont déjà bloqués
$contactsAvecBlocages = [];
foreach ($tousContacts as $contact) {
    $dejaBloques = $blocagesParContact[$contact['id_contact']] ?? [];
    $contactsAvecBlocages[] = [
        'contact' => $contact,
        'deja_bloques' => $dejaBloques,
        'nb_bloques' => count($dejaBloques),
        'total_types' => count($typeMessages)
    ];
}

// ============================================
// AJOUT À LA BLACKLIST (SIMPLE OU MULTIPLE CONTACTS)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_blacklist'])) {
    $id_contacts = $_POST['id_contacts'] ?? [];
    $types_selectionnes = $_POST['id_type_message'] ?? [];
    $motif = $_POST['motif'] ?? null;
    
    // Si c'est un formulaire avec un seul contact (pour compatibilité)
    if (isset($_POST['id_contact']) && !empty($_POST['id_contact']) && empty($id_contacts)) {
        $id_contacts = [$_POST['id_contact']];
    }
    
    if (empty($id_contacts)) {
        $error = "Veuillez sélectionner au moins un contact";
    } elseif (empty($types_selectionnes)) {
        $error = "Veuillez sélectionner au moins un type de message";
    } else {
        $addedCount = 0;
        $alreadyExists = 0;
        $contactsTraites = [];
        
        foreach ($id_contacts as $id_contact) {
            $contactInfo = $db->select('contact', ['id_contact' => $id_contact, 'id_compte' => $idCompte]);
            if (!empty($contactInfo)) {
                $contactNom = $contactInfo[0]['prenom'] . ' ' . $contactInfo[0]['nom'];
                $contactsTraites[] = $contactNom;
                
                foreach ($types_selectionnes as $id_type_message) {
                    $existing = $db->select('blacklist', [
                        'id_contact' => $id_contact,
                        'id_type_message' => $id_type_message
                    ]);
                    
                    if (empty($existing)) {
                        $data = [
                            'id_type_message' => intval($id_type_message),
                            'id_contact' => $id_contact,
                            'motif' => $motif
                        ];
                        $db->insert('blacklist', $data);
                        $addedCount++;
                    } else {
                        $alreadyExists++;
                    }
                }
            }
        }
        
        if ($addedCount > 0) {
            $message = "$addedCount blocage(s) ajouté(s) pour " . count($id_contacts) . " contact(s)";
            if ($alreadyExists > 0) {
                $message .= " ($alreadyExists déjà existant(s))";
            }
            header("Location: index.php?page=blacklist/index&toast=" . urlencode($message) . "&type=success");
            exit;
        } else {
            $error = "Tous les contacts sont déjà blacklistés pour les types sélectionnés";
            header("Location: index.php?page=blacklist/index&toast=" . urlencode($error) . "&type=error");
            exit;
        }
    }
}

// Retirer plusieurs contacts de la blacklist (action groupée)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_bulk_unblacklist'])) {
    $selectedIds = $_POST['selected_ids'] ?? [];
    
    if (!empty($selectedIds)) {
        $removedCount = 0;
        foreach ($selectedIds as $id) {
            try {
                $db->delete('blacklist', $id, 'id_blacklist');
                $removedCount++;
            } catch (Exception $e) {
                // Erreur silencieuse
            }
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'count' => $removedCount]);
            exit;
        } else {
            $message = "$removedCount contact(s) retiré(s) de la blacklist avec succès !";
            header("Location: index.php?page=blacklist/index&toast=" . urlencode($message) . "&type=success");
            exit;
        }
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Aucun contact sélectionné']);
            exit;
        } else {
            header("Location: index.php?page=blacklist/index&toast=" . urlencode('Aucun contact sélectionné') . "&type=error");
            exit;
        }
    }
}

// Retirer un seul contact de la blacklist
if (isset($_GET['retirer'])) {
    $id_blacklist = $_GET['retirer'];
    try {
        $db->delete('blacklist', $id_blacklist, 'id_blacklist');
        $message = "Contact retiré de la blacklist";
        header("Location: index.php?page=blacklist/index&toast=" . urlencode($message) . "&type=success");
        exit;
    } catch (Exception $e) {
        $error = "Erreur : " . $e->getMessage();
        header("Location: index.php?page=blacklist/index&toast=" . urlencode($error) . "&type=error");
        exit;
    }
}

$totalBlacklisted = count($blacklistWithContact);

// Récupérer les paramètres toast
$toastMessage = isset($_GET['toast']) ? urldecode($_GET['toast']) : null;
$toastType = isset($_GET['type']) ? $_GET['type'] : 'success';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Blacklist - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================
           STYLES PRINCIPAUX
        ============================================ */
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            padding: 0;
        }
        
        .space-y-6 > :not([hidden]) ~ :not([hidden]) {
            margin-top: 1.5rem;
        }
        
        /* ============================================
           STYLES SELECT2 - TAILLE RÉDUITE
        ============================================ */
        
        /* Cacher les selects natifs */
        #contactsSearch, #typeMessageSelect {
            display: none !important;
        }
        
        .select2-container {
            width: 100% !important;
            display: block !important;
        }
        
        /* Conteneur principal - TAILLE RÉDUITE */
        .select2-container--default .select2-selection--multiple {
            min-height: 38px !important;
            max-height: 80px !important;
            overflow-y: auto !important;
            border: 2px solid #e5e7eb !important;
            border-radius: 8px !important;
            padding: 4px 10px !important;
            background: white !important;
            transition: border-color 0.2s ease;
        }
        
        .select2-container--default .select2-selection--multiple:hover {
            border-color: #ef4444 !important;
        }
        
        .select2-container--default .select2-selection--multiple:focus-within {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
        }
        
        /* Placeholder */
        .select2-container--default .select2-selection--multiple .select2-selection__placeholder {
            font-size: 14px !important;
            line-height: 28px !important;
            color: #9ca3af !important;
            font-weight: 400 !important;
            padding: 0 !important;
            display: block !important;
        }
        
        /* Le conteneur des éléments sélectionnés */
        .select2-container--default .select2-selection--multiple .select2-selection__rendered {
            display: block !important;
            padding: 0 !important;
            min-height: 24px !important;
        }
        
        /* Les badges des éléments sélectionnés - PLUS COMPACTS */
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: #fee2e2 !important;
            border: none !important;
            border-radius: 16px !important;
            padding: 2px 10px !important;
            margin: 2px 3px !important;
            font-size: 12px !important;
            color: #991b1b !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 3px !important;
            line-height: 1.4 !important;
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: #991b1b !important;
            margin-right: 3px !important;
            font-size: 12px !important;
            font-weight: bold !important;
            padding: 0 2px !important;
        }
        
        /* Le champ de recherche à l'intérieur */
        .select2-container--default .select2-selection--multiple .select2-search--inline {
            margin: 0 !important;
            padding: 0 !important;
        }
        
        .select2-container--default .select2-selection--multiple .select2-search--inline .select2-search__field {
            margin: 0 !important;
            padding: 2px 0 !important;
            font-size: 13px !important;
            min-width: 100px !important;
            height: 26px !important;
            border: none !important;
            outline: none !important;
            background: transparent !important;
        }
        
        /* Dropdown */
        .select2-dropdown {
            border: 2px solid #e5e7eb !important;
            border-radius: 8px !important;
            z-index: 1050 !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1) !important;
        }
        
        /* Champ de recherche dans le dropdown */
        .select2-search--dropdown {
            padding: 8px !important;
            border-bottom: 1px solid #e5e7eb !important;
        }
        
        .select2-search__field {
            border: 2px solid #e5e7eb !important;
            border-radius: 6px !important;
            padding: 6px 12px !important;
            font-size: 13px !important;
            width: 100% !important;
            transition: all 0.2s ease;
        }
        
        .select2-search__field:focus {
            border-color: #ef4444 !important;
            outline: none !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
        }
        
        /* Résultats */
        .select2-results {
            max-height: 300px !important;
        }
        
        .select2-results__options {
            max-height: 250px !important;
            overflow-y: auto !important;
        }
        
        .select2-results__option {
            padding: 8px 14px !important;
            font-size: 13px !important;
            line-height: 1.4 !important;
        }
        
        .select2-results__option--highlighted {
            background-color: #ef4444 !important;
            color: white !important;
        }
        
        .select2-results__option[aria-disabled="true"] {
            opacity: 0.5 !important;
            background-color: #f3f4f6 !important;
            cursor: not-allowed !important;
        }
        
        /* Scrollbar personnalisée */
        .select2-results__options::-webkit-scrollbar {
            width: 5px;
        }
        
        .select2-results__options::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .select2-results__options::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        /* VERSION MOBILE */
        @media (max-width: 768px) {
            .select2-container--default .select2-selection--multiple {
                min-height: 34px !important;
                max-height: 70px !important;
                padding: 3px 8px !important;
            }
            
            .select2-container--default .select2-selection--multiple .select2-selection__placeholder {
                font-size: 13px !important;
                line-height: 24px !important;
            }
            
            .select2-container--default .select2-selection--multiple .select2-search--inline .select2-search__field {
                font-size: 12px !important;
                min-width: 80px !important;
                height: 22px !important;
            }
            
            .select2-dropdown {
                position: fixed !important;
                bottom: 0 !important;
                top: auto !important;
                left: 0 !important;
                right: 0 !important;
                width: 100% !important;
                border-radius: 20px 20px 0 0 !important;
                max-height: 70% !important;
            }
        }
        
        /* ============================================
           STYLES DES MODALES
        ============================================ */
        .confirm-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
            backdrop-filter: blur(4px);
        }
        
        .confirm-modal-overlay.show {
            visibility: visible;
            opacity: 1;
        }
        
        .confirm-modal-box {
            background: white;
            border-radius: 20px;
            max-width: 480px;
            width: 92%;
            overflow: hidden;
            transform: scale(0.9) translateY(20px);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .confirm-modal-overlay.show .confirm-modal-box {
            transform: scale(1) translateY(0);
        }
        
        .confirm-modal-header {
            padding: 24px 28px 16px 28px;
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .confirm-modal-header .icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .confirm-modal-header .icon-wrapper.warning {
            background: #fef3c7;
            color: #d97706;
        }
        .confirm-modal-header .icon-wrapper.danger {
            background: #fee2e2;
            color: #dc2626;
        }
        .confirm-modal-header .icon-wrapper.success {
            background: #dcfce7;
            color: #16a34a;
        }
        .confirm-modal-header .icon-wrapper i {
            font-size: 24px;
        }
        
        .confirm-modal-header .modal-title {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }
        
        .confirm-modal-header .modal-subtitle {
            font-size: 13px;
            color: #6b7280;
            margin: 2px 0 0 0;
        }
        
        .confirm-modal-body {
            padding: 24px 28px;
        }
        
        .confirm-modal-body .modal-message {
            font-size: 15px;
            color: #374151;
            line-height: 1.6;
            margin: 0;
        }
        
        .confirm-modal-body .modal-warning-text {
            margin-top: 16px;
            padding: 12px 16px;
            background: #fef2f2;
            border-radius: 10px;
            font-size: 13px;
            color: #991b1b;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        
        .confirm-modal-footer {
            padding: 16px 28px 24px 28px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-top: 1px solid #f3f4f6;
            flex-wrap: wrap;
        }
        
        .confirm-modal-footer .btn {
            padding: 10px 24px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .confirm-modal-footer .btn-cancel {
            background: #f3f4f6;
            color: #4b5563;
        }
        .confirm-modal-footer .btn-cancel:hover {
            background: #e5e7eb;
        }
        .confirm-modal-footer .btn-danger {
            background: #dc2626;
            color: white;
        }
        .confirm-modal-footer .btn-danger:hover {
            background: #b91c1c;
        }
        .confirm-modal-footer .btn-success {
            background: #16a34a;
            color: white;
        }
        .confirm-modal-footer .btn-success:hover {
            background: #15803d;
        }
        
        /* ============================================
           TOAST NOTIFICATION
        ============================================ */
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
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 500;
        }
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        .toast-notification.warning .toast-content { background: #f59e0b; }
        .toast-notification.info .toast-content { background: #3b82f6; }
        
        /* ============================================
           STYLES EXISTANTS
        ============================================ */
        .checkbox-column {
            width: 40px;
            text-align: center;
        }
        .checkbox-column input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .modal-content-unblacklist {
            transition: all 0.3s ease;
            transform: scale(0.95);
            opacity: 0;
        }
        .modal-content-unblacklist.modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        .blacklist-row.hidden-row {
            display: none;
        }
        .type-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            margin: 2px;
        }
        .type-badge-sms { background: #dbeafe; color: #1e40af; }
        .type-badge-whatsapp { background: #dcfce7; color: #166534; }
        .type-badge-email { background: #fef3c7; color: #92400e; }
        
        .table-container {
            overflow-x: auto;
        }
        .blacklist-table {
            min-width: 800px;
            width: 100%;
            border-collapse: collapse;
        }
        .blacklist-table th,
        .blacklist-table td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #e5e7eb;
        }
        .blacklist-table th {
            background-color: #f9fafb;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            color: #6b7280;
        }
        .blacklist-table tr:hover {
            background-color: #f9fafb;
        }
        .contact-info {
            font-weight: 500;
            color: #1f2937;
        }
        .contact-detail {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }
        .motif-text {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 13px;
            color: #10b981;
        }
        .action-btn:hover {
            transform: scale(1.05);
            color: #059669;
        }
        .action-btn i {
            font-size: 16px;
        }
        .btn-retirer {
            color: #10b981;
        }
        .btn-retirer:hover {
            color: #059669;
        }
        .stats-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 16px;
        }
        .stats-number {
            font-size: 28px;
            font-weight: bold;
        }
        .stats-label {
            font-size: 13px;
            color: #6b7280;
        }
        .search-input-wrapper {
            position: relative;
        }
        .search-input-wrapper i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        .search-input-wrapper input {
            width: 100%;
            padding: 10px 12px 10px 36px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }
        .search-input-wrapper input:focus {
            outline: none;
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        
        .selected-contacts-info {
            background: #e0f2fe;
            border-radius: 8px;
            padding: 8px 12px;
            margin-top: 8px;
            font-size: 13px;
        }
        .selected-contacts-info i {
            color: #0284c7;
        }
        
        .bulk-actions-bar {
            transition: all 0.3s ease;
        }
        
        /* Labels des champs */
        .form-label {
            font-size: 14px !important;
            font-weight: 600 !important;
            color: #1f2937 !important;
            margin-bottom: 4px !important;
            display: block !important;
        }
        
        .form-label i {
            margin-right: 6px;
        }
        
        .motif-input {
            padding: 8px 14px !important;
            font-size: 14px !important;
            border-radius: 8px !important;
        }
    </style>
</head>
<body>

<!-- ============================================
     MODALE DE CONFIRMATION GÉNÉRIQUE
============================================ -->
<div id="confirmModal" class="confirm-modal-overlay">
    <div class="confirm-modal-box">
        <div class="confirm-modal-header">
            <div class="icon-wrapper" id="confirmModalIcon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div>
                <h3 class="modal-title" id="confirmModalTitle">Confirmation</h3>
                <p class="modal-subtitle" id="confirmModalSubtitle">Veuillez confirmer votre action</p>
            </div>
        </div>
        <div class="confirm-modal-body">
            <p class="modal-message" id="confirmModalMessage">Êtes-vous sûr de vouloir effectuer cette action ?</p>
            <div id="confirmModalWarning" class="modal-warning-text" style="display: none;">
                <i class="fas fa-info-circle"></i>
                <span id="confirmModalWarningText">Cette action est irréversible.</span>
            </div>
        </div>
        <div class="confirm-modal-footer">
            <button class="btn btn-cancel" id="confirmModalCancelBtn">
                <i class="fas fa-times"></i> Annuler
            </button>
            <button class="btn btn-danger" id="confirmModalConfirmBtn">
                <i class="fas fa-check"></i> Confirmer
            </button>
        </div>
    </div>
</div>

<!-- ============================================
     MODALE POUR RETIRER UN SEUL CONTACT
============================================ -->
<div id="unblacklistModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-content-unblacklist">
        <div class="p-6 text-center">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-4">
                <i class="fas fa-unlock-alt text-green-600 text-3xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">Retirer de la blacklist</h3>
            <p class="text-gray-500 mb-6">
                Êtes-vous sûr de vouloir retirer <br>
                <strong id="unblacklistContactName" class="text-gray-700"></strong> de la blacklist ?
            </p>
            <div class="flex flex-col gap-3">
                <button onclick="confirmUnblacklist()" 
                        class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-check mr-2"></i>Retirer
                </button>
                <button type="button" onclick="closeUnblacklistModal()" 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Annuler
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
     CONTENU PRINCIPAL
============================================ -->
<div class="space-y-6" style="max-width: 1280px; margin: 0 auto; padding: 20px;">
    <!-- En-tête -->
    <div class="flex justify-between items-center flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Blacklist</h1>
            <p class="text-gray-500 text-sm mt-1">Gérez les contacts exclus par type de message</p>
        </div>
    </div>

    <!-- Section Ajouter -->
    <div id="addSection" class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-bold mb-4 flex items-center gap-2">
            <i class="fas fa-plus-circle text-red-600"></i>
            Ajouter des contacts à la blacklist
        </h2>
        <form method="POST" class="space-y-4" id="blacklistForm">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">
                        <i class="fas fa-users text-gray-400"></i> Contacts * (sélection multiple possible)
                    </label>
                    <select name="id_contacts[]" id="contactsSearch" multiple="multiple">
                        <?php foreach ($contactsAvecBlocages as $item): 
                            $contact = $item['contact'];
                            $nbBloques = $item['nb_bloques'];
                            $totalTypes = $item['total_types'];
                        ?>
                            <option value="<?= $contact['id_contact'] ?>">
                                <?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?> 
                                (<?= htmlspecialchars($contact['email'] ?? $contact['telephone'] ?? 'aucun contact') ?>)
                                <?php if ($nbBloques > 0): ?>
                                    - <?= $nbBloques ?>/<?= $totalTypes ?> type(s) bloqué(s)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="selected-contacts-info hidden" id="selectedContactsInfo">
                        <i class="fas fa-info-circle"></i>
                        <span id="selectedContactsCount">0</span> contact(s) sélectionné(s)
                    </div>
                </div>
                <div>
                    <label class="form-label">
                        <i class="fas fa-envelope text-gray-400"></i> Types de message à bloquer * <span class="text-red-500">(plusieurs choix possibles)</span>
                    </label>
                    <select name="id_type_message[]" id="typeMessageSelect" multiple="multiple">
                        <?php if (!empty($typeMessages)): ?>
                            <?php foreach ($typeMessages as $type): ?>
                                <option value="<?= $type['id_type_message'] ?>">
                                    <?= htmlspecialchars($type['libelle_type']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="text-xs text-gray-400 mt-2" id="selectedTypesHelp">
                        <i class="fas fa-info-circle"></i> Maintenez Ctrl/Cmd pour sélectionner plusieurs types
                    </p>
                </div>
            </div>
            <div>
                <label class="form-label">
                    <i class="fas fa-pencil-alt text-gray-400"></i> Motif (optionnel)
                </label>
                <input type="text" name="motif" id="motifInput" placeholder="Pourquoi ces contacts sont bloqués ?" 
                       class="motif-input w-full border border-gray-300 rounded-lg focus:outline-none focus:border-red-500 focus:ring-2 focus:ring-red-200">
            </div>
            <div class="flex justify-end space-x-3">
                <button type="button" id="clearSelectionBtn" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-lg transition">
                    <i class="fas fa-times mr-2"></i>Effacer la sélection
                </button>
                <button type="submit" name="ajouter_blacklist" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-ban mr-2"></i>Ajouter à la blacklist
                </button>
            </div>
        </form>
    </div>

    <!-- Statistiques -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="stats-card">
            <div class="flex justify-between items-center">
                <div>
                    <div class="stats-number"><?= $totalBlacklisted ?></div>
                    <div class="stats-label">Total blocages</div>
                </div>
                <i class="fas fa-ban text-2xl text-gray-400"></i>
            </div>
        </div>
        <div class="stats-card">
            <div class="flex justify-between items-center">
                <div>
                    <div class="stats-number"><?= count($contactsAvecBlocages) ?></div>
                    <div class="stats-label">Contacts dans la base</div>
                </div>
                <i class="fas fa-users text-2xl text-gray-400"></i>
            </div>
        </div>
        <div class="stats-card">
            <div class="flex justify-between items-center">
                <div>
                    <div class="stats-number"><?= count($blocagesParContact) ?></div>
                    <div class="stats-label">Contacts blacklistés</div>
                </div>
                <i class="fas fa-user-slash text-2xl text-gray-400"></i>
            </div>
        </div>
        <div class="stats-card">
            <div class="flex justify-between items-center">
                <div>
                    <div class="stats-number"><?= count($typeMessages) ?></div>
                    <div class="stats-label">Types de messages</div>
                </div>
                <i class="fas fa-envelope text-2xl text-gray-400"></i>
            </div>
        </div>
    </div>

    <!-- Barre de recherche -->
    <div class="bg-white rounded-lg shadow p-4">
        <div class="search-input-wrapper">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" 
                   placeholder="Rechercher par nom, email, téléphone, type ou motif...">
        </div>
        <div class="mt-2 text-right">
            <span id="filteredCount" class="text-xs text-gray-500"></span>
        </div>
    </div>

    <!-- Barre d'actions groupées -->
    <div id="bulkActionsBar" class="hidden bg-blue-50 rounded-lg p-4 flex justify-between items-center bulk-actions-bar flex-wrap gap-3">
        <div>
            <span id="selectedCount" class="text-sm font-semibold text-blue-700">0</span>
            <span class="text-sm text-blue-600">blocage(s) sélectionné(s)</span>
        </div>
        <button id="bulkUnblacklistBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition flex items-center gap-2">
            <i class="fas fa-check-double"></i> Retirer les sélectionnés
        </button>
    </div>

    <!-- Liste de la blacklist -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="p-4 border-b bg-gray-50 flex justify-between items-center flex-wrap gap-3">
            <h2 class="text-lg font-bold flex items-center gap-2">
                <i class="fas fa-list text-red-600"></i>
                Liste des blocages
            </h2>
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-600 flex items-center gap-1 cursor-pointer">
                    <input type="checkbox" id="selectAllCheckbox" class="rounded">
                    <span>Tout sélectionner</span>
                </label>
            </div>
        </div>
        <div class="table-container">
            <table class="blacklist-table">
                <thead>
                    <tr>
                        <th class="checkbox-column">
                            <input type="checkbox" id="selectAllHeader" class="rounded">
                        </th>
                        <th>Contact</th>
                        <th>Type bloqué</th>
                        <th>Motif</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="blacklistTableBody">
                    <?php if (empty($blacklistWithContact)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-12 text-gray-500">
                                <i class="fas fa-check-circle text-4xl mb-2 block text-gray-300"></i>
                                Aucun contact blacklisté
                                <div class="text-sm mt-1">Utilisez le formulaire ci-dessus pour ajouter un blocage</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($blacklistWithContact as $bl): 
                            $typeLabel = '';
                            $typeClass = '';
                            foreach ($typeMessages as $t) {
                                if ($t['id_type_message'] == $bl['id_type_message']) {
                                    $typeLabel = $t['libelle_type'];
                                    switch(strtolower($typeLabel)) {
                                        case 'sms': $typeClass = 'type-badge-sms'; break;
                                        case 'whatsapp': $typeClass = 'type-badge-whatsapp'; break;
                                        case 'email': $typeClass = 'type-badge-email'; break;
                                        default: $typeClass = 'type-badge-sms';
                                    }
                                    break;
                                }
                            }
                        ?>
                            <tr class="blacklist-row" 
                                data-id="<?= $bl['id_blacklist'] ?>"
                                data-name="<?= strtolower(htmlspecialchars($bl['contact']['prenom'] . ' ' . $bl['contact']['nom'])) ?>"
                                data-email="<?= strtolower(htmlspecialchars($bl['contact']['email'] ?? '')) ?>"
                                data-phone="<?= strtolower(htmlspecialchars($bl['contact']['telephone'] ?? '')) ?>"
                                data-type="<?= strtolower($typeLabel) ?>"
                                data-motif="<?= strtolower(htmlspecialchars($bl['motif'] ?? '')) ?>">
                                <td class="checkbox-column">
                                    <input type="checkbox" value="<?= $bl['id_blacklist'] ?>" class="contact-checkbox rounded">
                                </td>
                                <td>
                                    <div class="contact-info"><?= htmlspecialchars($bl['contact']['prenom'] . ' ' . $bl['contact']['nom']) ?></div>
                                    <div class="contact-detail">
                                        <?php if (!empty($bl['contact']['email'])): ?>
                                            <i class="fas fa-envelope text-gray-400 text-xs mr-1"></i><?= htmlspecialchars($bl['contact']['email']) ?>
                                        <?php elseif (!empty($bl['contact']['telephone'])): ?>
                                            <i class="fas fa-phone text-gray-400 text-xs mr-1"></i><?= htmlspecialchars($bl['contact']['telephone']) ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">Aucun contact</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="type-badge <?= $typeClass ?>">
                                        <i class="fas <?= $typeLabel == 'WhatsApp' ? 'fa-brands fa-whatsapp' : ($typeLabel == 'SMS' ? 'fa-comment-dots' : 'fa-envelope') ?> text-xs mr-1"></i>
                                        <?= htmlspecialchars($typeLabel) ?>
                                    </span>
                                </td>
                                <td class="motif-text" title="<?= htmlspecialchars($bl['motif'] ?? '') ?>">
                                    <?= htmlspecialchars($bl['motif'] ?? '-') ?>
                                </td>
                                <td class="whitespace-nowrap text-sm text-gray-500">
                                    <?= date('d/m/Y', strtotime($bl['date_ajout'])) ?>
                                </td>
                                <td class="whitespace-nowrap">
                                    <button onclick="openUnblacklistModal('<?= $bl['id_blacklist'] ?>', '<?= addslashes($bl['contact']['prenom'] . ' ' . $bl['contact']['nom']) ?>', '<?= addslashes($typeLabel) ?>')"
                                            class="action-btn btn-retirer" title="Retirer de la blacklist">
                                        <i class="fas fa-check-circle"></i> Retirer
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/fr.js"></script>
<script>
    let blocagesParContact = <?= json_encode($blocagesParContact) ?>;
    let unblacklistData = null;

    // ============================================
    // TOAST NOTIFICATIONS
    // ============================================
    function showToast(message, type = 'success') {
        const existingToasts = document.querySelectorAll('.toast-notification');
        existingToasts.forEach(toast => toast.remove());
        
        const toast = document.createElement('div');
        toast.className = `toast-notification ${type}`;
        const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
        toast.innerHTML = `<div class="toast-content"><i class="fas ${icons[type] || icons.success}"></i><span>${message}</span></div>`;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }

    // ============================================
    // AFFICHAGE DES TOASTS DEPUIS L'URL
    // ============================================
    <?php if ($toastMessage): ?>
    showToast('<?= addslashes($toastMessage) ?>', '<?= $toastType ?>');
    <?php endif; ?>

    // ============================================
    // MODALE DE CONFIRMATION GÉNÉRIQUE
    // ============================================
    let confirmCallback = null;

    function showConfirmModal(options) {
        const modal = document.getElementById('confirmModal');
        const iconWrapper = document.getElementById('confirmModalIcon');
        const title = document.getElementById('confirmModalTitle');
        const subtitle = document.getElementById('confirmModalSubtitle');
        const message = document.getElementById('confirmModalMessage');
        const warning = document.getElementById('confirmModalWarning');
        const warningText = document.getElementById('confirmModalWarningText');
        const confirmBtn = document.getElementById('confirmModalConfirmBtn');
        const cancelBtn = document.getElementById('confirmModalCancelBtn');
        
        const iconMap = {
            warning: { class: 'warning', icon: 'fa-exclamation-triangle' },
            danger: { class: 'danger', icon: 'fa-exclamation-circle' },
            success: { class: 'success', icon: 'fa-check-circle' },
            info: { class: 'warning', icon: 'fa-info-circle' }
        };
        
        const iconConfig = iconMap[options.type] || iconMap.warning;
        iconWrapper.className = 'icon-wrapper ' + iconConfig.class;
        iconWrapper.querySelector('i').className = 'fas ' + iconConfig.icon;
        
        title.textContent = options.title || 'Confirmation';
        subtitle.textContent = options.subtitle || 'Veuillez confirmer votre action';
        message.innerHTML = options.message || 'Êtes-vous sûr de vouloir effectuer cette action ?';
        
        if (options.warning) {
            warning.style.display = 'flex';
            warningText.textContent = options.warning;
        } else {
            warning.style.display = 'none';
        }
        
        confirmBtn.textContent = options.confirmText || 'Confirmer';
        confirmBtn.className = 'btn ' + (options.confirmClass || 'btn-danger');
        confirmBtn.innerHTML = '<i class="fas fa-check"></i> ' + (options.confirmText || 'Confirmer');
        
        cancelBtn.textContent = options.cancelText || 'Annuler';
        cancelBtn.innerHTML = '<i class="fas fa-times"></i> ' + (options.cancelText || 'Annuler');
        
        confirmCallback = options.onConfirm || null;
        modal.classList.add('show');
    }

    function closeConfirmModal() {
        document.getElementById('confirmModal').classList.remove('show');
        confirmCallback = null;
    }

    document.getElementById('confirmModalConfirmBtn').addEventListener('click', function() {
        if (typeof confirmCallback === 'function') {
            confirmCallback();
        }
        closeConfirmModal();
    });

    document.getElementById('confirmModalCancelBtn').addEventListener('click', closeConfirmModal);
    document.getElementById('confirmModal').addEventListener('click', function(e) {
        if (e.target === this) closeConfirmModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeConfirmModal();
    });

    // ============================================
    // MODALE POUR RETIRER UN CONTACT
    // ============================================
    function openUnblacklistModal(blacklistId, contactName, typeLabel) {
        unblacklistData = { id: blacklistId, name: contactName, type: typeLabel };
        const modal = document.getElementById('unblacklistModal');
        const modalContent = modal.querySelector('.modal-content-unblacklist');
        
        document.getElementById('unblacklistContactName').innerHTML = `${contactName} (${typeLabel})`;
        
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => modalContent.classList.add('modal-show'), 10);
    }

    function closeUnblacklistModal() {
        const modal = document.getElementById('unblacklistModal');
        const modalContent = modal.querySelector('.modal-content-unblacklist');
        modalContent.classList.remove('modal-show');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            unblacklistData = null;
        }, 200);
    }

    function confirmUnblacklist() {
        if (!unblacklistData) return;
        
        // Rediriger directement vers la page avec le paramètre retirer
        window.location.href = '?page=blacklist/index&retirer=' + unblacklistData.id;
    }

    document.getElementById('unblacklistModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeUnblacklistModal();
    });

    // ============================================
    // INITIALISATION SELECT2
    // ============================================
    $(document).ready(function() {
        // Initialisation Select2 pour les contacts
        $('#contactsSearch').select2({
            placeholder: "Tapez le nom, prénom ou email...",
            allowClear: true,
            width: '100%',
            language: 'fr',
            closeOnSelect: false,
            dropdownParent: $('#addSection')
        });
        
        // Initialisation Select2 pour les types
        $('#typeMessageSelect').select2({
            placeholder: "Sélectionnez les types à bloquer",
            allowClear: true,
            width: '100%',
            language: 'fr',
            closeOnSelect: false,
            dropdownParent: $('#addSection')
        });
        
        // Afficher le nombre de contacts sélectionnés
        $('#contactsSearch').on('change', function() {
            const selectedCount = $(this).val() ? $(this).val().length : 0;
            if (selectedCount > 0) {
                $('#selectedContactsInfo').removeClass('hidden');
                $('#selectedContactsCount').text(selectedCount);
            } else {
                $('#selectedContactsInfo').addClass('hidden');
            }
            
            const selectedContacts = $(this).val();
            if (selectedContacts && selectedContacts.length > 0) {
                updateAvailableTypes(selectedContacts);
            } else {
                $('#typeMessageSelect option').prop('disabled', false).css('opacity', '1');
                $('#typeMessageSelect').trigger('change');
                $('#selectedTypesHelp').html('<i class="fas fa-info-circle"></i> Maintenez Ctrl/Cmd pour sélectionner plusieurs types');
            }
        });
        
        function updateAvailableTypes(contactIds) {
            let allBloquedTypes = [];
            contactIds.forEach(contactId => {
                const typesBloques = blocagesParContact[contactId] || [];
                allBloquedTypes = [...allBloquedTypes, ...typesBloques];
            });
            
            const uniqueBloquedTypes = [...new Set(allBloquedTypes)];
            
            if (uniqueBloquedTypes.length > 0) {
                $('#typeMessageSelect option').each(function() {
                    const typeId = parseInt($(this).val());
                    if (uniqueBloquedTypes.includes(typeId)) {
                        $(this).prop('disabled', true).css('opacity', '0.5');
                    } else {
                        $(this).prop('disabled', false).css('opacity', '1');
                    }
                });
                
                const typesBloquesLibelles = [];
                $('#typeMessageSelect option:disabled').each(function() {
                    typesBloquesLibelles.push($(this).text());
                });
                
                if (typesBloquesLibelles.length > 0) {
                    $('#selectedTypesHelp').html(`<i class="fas fa-exclamation-triangle text-orange-500"></i> Types déjà bloqués pour au moins un contact : ${typesBloquesLibelles.join(', ')}<br><small class="text-gray-400">Ces types ne peuvent pas être sélectionnés</small>`);
                }
            } else {
                $('#selectedTypesHelp').html('<i class="fas fa-info-circle"></i> Maintenez Ctrl/Cmd pour sélectionner plusieurs types');
            }
            
            $('#typeMessageSelect').trigger('change');
        }
    });

    // ============================================
    // EFFACER LA SÉLECTION
    // ============================================
    document.getElementById('clearSelectionBtn')?.addEventListener('click', function() {
        $('#contactsSearch').val(null).trigger('change');
        $('#typeMessageSelect').val(null).trigger('change');
        document.getElementById('motifInput').value = '';
        $('#selectedContactsInfo').addClass('hidden');
        showToast('Sélection effacée', 'info');
    });

    // ============================================
    // GESTION DES CASES À COCHER
    // ============================================
    const selectAllHeader = document.getElementById('selectAllHeader');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCountSpan = document.getElementById('selectedCount');
    const bulkUnblacklistBtn = document.getElementById('bulkUnblacklistBtn');

    function updateBulkActionsBar() {
        const checked = document.querySelectorAll('.contact-checkbox:checked');
        const count = checked.length;
        
        if (count > 0) {
            bulkActionsBar.classList.remove('hidden');
            selectedCountSpan.textContent = count;
        } else {
            bulkActionsBar.classList.add('hidden');
        }
        
        const visibleRows = document.querySelectorAll('.blacklist-row:not(.hidden-row)');
        const allCount = visibleRows.length;
        const allChecked = count === allCount && allCount > 0;
        if (selectAllHeader) selectAllHeader.checked = allChecked;
        if (selectAllCheckbox) selectAllCheckbox.checked = allChecked;
    }

    function toggleAllCheckboxes(checked) {
        document.querySelectorAll('.blacklist-row:not(.hidden-row) .contact-checkbox').forEach(cb => {
            cb.checked = checked;
        });
        updateBulkActionsBar();
    }

    if (selectAllHeader) {
        selectAllHeader.addEventListener('change', function() {
            toggleAllCheckboxes(this.checked);
        });
    }
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            toggleAllCheckboxes(this.checked);
        });
    }
    
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('contact-checkbox')) {
            updateBulkActionsBar();
        }
    });
    
    updateBulkActionsBar();

    // ============================================
    // SUPPRESSION GROUPÉE AVEC MODALE
    // ============================================
    if (bulkUnblacklistBtn) {
        bulkUnblacklistBtn.addEventListener('click', function() {
            const checked = document.querySelectorAll('.contact-checkbox:checked');
            if (checked.length === 0) {
                showToast('Veuillez sélectionner au moins un contact', 'warning');
                return;
            }
            
            const count = checked.length;
            
            showConfirmModal({
                type: 'warning',
                title: 'Retrait groupé de la blacklist',
                subtitle: `${count} blocage(s) à retirer`,
                message: `Êtes-vous sûr de vouloir retirer <strong>${count}</strong> blocage(s) de la blacklist ?`,
                warning: 'Cette action est réversible. Vous pourrez ajouter ces contacts à nouveau si nécessaire.',
                confirmText: `Retirer ${count} blocage(s)`,
                confirmClass: 'btn-success',
                cancelText: 'Annuler',
                onConfirm: function() {
                    const selectedIds = Array.from(checked).map(cb => cb.value);
                    showToast(`Retrait de ${count} blocage(s) en cours...`, 'info');
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: 'action_bulk_unblacklist=1&selected_ids[]=' + selectedIds.join('&selected_ids[]=')
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast(`${data.count} blocage(s) retiré(s) avec succès !`, 'success');
                            setTimeout(() => window.location.reload(), 1500);
                        } else {
                            showToast(data.error || 'Erreur lors du retrait', 'error');
                        }
                    })
                    .catch(error => {
                        showToast('Erreur réseau', 'error');
                    });
                }
            });
        });
    }

    // ============================================
    // RECHERCHE
    // ============================================
    const searchInput = document.getElementById('searchInput');
    const blacklistRows = document.querySelectorAll('.blacklist-row');
    const totalCount = blacklistRows.length;
    const filteredCountSpan = document.getElementById('filteredCount');
    
    function filterBlacklist() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        let visibleCount = 0;
        
        blacklistRows.forEach(row => {
            const name = row.getAttribute('data-name') || '';
            const email = row.getAttribute('data-email') || '';
            const phone = row.getAttribute('data-phone') || '';
            const type = row.getAttribute('data-type') || '';
            const motif = row.getAttribute('data-motif') || '';
            
            let searchMatch = true;
            if (searchTerm !== '') {
                searchMatch = name.includes(searchTerm) || email.includes(searchTerm) || phone.includes(searchTerm) || type.includes(searchTerm) || motif.includes(searchTerm);
            }
            
            if (searchMatch) {
                row.classList.remove('hidden-row');
                visibleCount++;
            } else {
                row.classList.add('hidden-row');
            }
        });
        
        if (filteredCountSpan) {
            filteredCountSpan.textContent = `${visibleCount} / ${totalCount} blocage(s) affiché(s)`;
        }
        
        let noResultRow = document.getElementById('noResultRow');
        if (visibleCount === 0 && blacklistRows.length > 0) {
            if (!noResultRow) {
                const tbody = document.getElementById('blacklistTableBody');
                noResultRow = document.createElement('tr');
                noResultRow.id = 'noResultRow';
                noResultRow.innerHTML = '<td colspan="6" class="text-center py-8 text-gray-500">' +
                    '<i class="fas fa-search text-4xl mb-2 block"></i>' +
                    'Aucun blocage ne correspond à votre recherche.' +
                    '</td>';
                tbody.appendChild(noResultRow);
            }
            noResultRow.style.display = '';
        } else if (noResultRow) {
            noResultRow.style.display = 'none';
        }
        updateBulkActionsBar();
    }
    
    if (searchInput) {
        searchInput.addEventListener('input', filterBlacklist);
    }

    // ============================================
    // FERMETURE DES MODALES (TOUCHE ESC)
    // ============================================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeConfirmModal();
            closeUnblacklistModal();
        }
    });
</script>

</body>
</html>