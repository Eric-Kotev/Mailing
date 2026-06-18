<?php
require_once 'includes/functions.php';
require_once 'includes/db.php';
// 🔥 DEBUG - Ajouter en tout début du fichier
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// 🔥 Nettoyer tout buffer de sortie
ob_clean();
ob_start();

// Démarrer la session si elle n'est pas déjà active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $db;

$idCompte = $_SESSION['user_id'];
// Récupérer tous les contacts
$contacts = $db->select('contact', ['id_compte' => $idCompte], '*', 'date_inscription DESC');

// Récupérer les IDs des contacts blacklistés et leurs détails
$blacklistItems = $db->select('blacklist', [], '*');
$blacklistedIds = [];
$blacklistDetails = [];
foreach ($blacklistItems as $bl) {
    $blacklistedIds[] = $bl['id_contact'];
    $blacklistDetails[$bl['id_contact']] = $bl;
}

// Pré-calculer les valeurs des champs personnalisés pour tous les contacts
$contactsCustomValues = [];
foreach ($contacts as $contact) {
    $contactsCustomValues[$contact['id_contact']] = getContactCustomValues($contact['id_contact']);
}

$totalContacts = count($contacts);

// ============================================
// CRÉATION D'UN CHAMP PERSONNALISÉ POUR UN CONTACT SPÉCIFIQUE (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_custom_field']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    ob_clean();
    header('Content-Type: application/json');
    
    $fieldName = trim($_POST['field_name'] ?? '');
    $fieldLabel = trim($_POST['field_label'] ?? '');
    $fieldType = $_POST['field_type'] ?? 'text';
    $fieldOptions = $_POST['field_options'] ?? null;
    $idContact = $_POST['id_contact'] ?? null;
    
    if (empty($fieldName)) {
        echo json_encode(['success' => false, 'error' => 'Le nom du champ est requis']);
        exit;
    }
    if (empty($fieldLabel)) {
        echo json_encode(['success' => false, 'error' => 'Le libellé du champ est requis']);
        exit;
    }
    if (empty($idContact)) {
        echo json_encode(['success' => false, 'error' => 'ID contact manquant']);
        exit;
    }
    
    $result = createCustomFieldForContact($idCompte, $idContact, $fieldName, $fieldLabel, $fieldType, $fieldOptions);
    echo json_encode($result);
    exit;
}

// ============================================
// TRAITEMENT DE L'AJOUT DE CONTACT (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_contact'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $prenom = trim($_POST['prenom'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $dateNaissance = $_POST['date_naissance'] ?? null;
        
        $errors = [];
        if (empty($prenom)) {
            $errors[] = 'Le prénom est requis';
        }
        if (empty($nom)) {
            $errors[] = 'Le nom est requis';
        }
        if (empty($email)) {
            $errors[] = "L'email est requis";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "L'email n'est pas valide";
        }
        
        if (!empty($dateNaissance)) {
            if (!verifierAge($dateNaissance, 18)) {
                $errors[] = "Le contact doit avoir au moins 18 ans et la date ne peut pas être dans le futur";
            }
        }
        
        if (!empty($email)) {
            $existingContact = $db->select('contact', ['id_compte' => $idCompte, 'email' => $email]);
            if (!empty($existingContact)) {
                $errors[] = "Cet email est déjà utilisé par un autre contact";
            }
        }
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
            exit;
        }
        
        // Formater le téléphone
        $telephoneFormatted = null;
        if (!empty($telephone)) {
            if (substr($telephone, 0, 3) === '261') {
                $telephoneFormatted = $telephone;
            } else {
                $telephoneFormatted = formatPhoneNumber($telephone);
            }
        }
        
        $data = [
            'id_compte' => $idCompte,
            'prenom' => $prenom,
            'nom' => $nom,
            'email' => $email,
            'telephone' => $telephoneFormatted,
            'date_naissance' => !empty($dateNaissance) ? $dateNaissance : null,
            'adresse' => !empty($_POST['adresse']) ? $_POST['adresse'] : null,
            'code_postal' => !empty($_POST['code_postal']) ? $_POST['code_postal'] : null,
            'ville' => !empty($_POST['ville']) ? $_POST['ville'] : null,
            'pays' => !empty($_POST['pays']) ? $_POST['pays'] : 'France'
        ];
        
        $contactId = $db->insertAndGetId('contact', $data);
        
        // Sauvegarder les champs personnalisés (incluant les temporaires)
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            saveContactCustomValues($contactId, $_POST['custom_fields']);
        }
        
        // Traiter les champs temporaires (créés pendant l'ajout)
        if (isset($_POST['temp_custom_fields']) && !empty($_POST['temp_custom_fields'])) {
            $tempFields = json_decode($_POST['temp_custom_fields'], true);
            if (is_array($tempFields)) {
                foreach ($tempFields as $field) {
                    // Créer le champ personnalisé pour ce contact
                    createCustomFieldForContact(
                        $idCompte,
                        $contactId,
                        $field['field_name'],
                        $field['field_label'],
                        $field['field_type'],
                        $field['field_options'] ?? null
                    );
                    
                    // Si une valeur a été fournie, la sauvegarder
                    if (isset($field['field_value']) && !empty($field['field_value'])) {
                        // Récupérer l'ID du champ créé
                        $createdField = $db->select('custom_fields', [
                            'id_contact' => $contactId,
                            'field_name' => $field['field_name']
                        ]);
                        if (!empty($createdField)) {
                            $db->insert('contact_custom_values', [
                                'id_custom_field' => $createdField[0]['id_custom_field'],
                                'field_value' => $field['field_value']
                            ]);
                        }
                    }
                }
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Contact ajouté avec succès',
            'id_contact' => $contactId
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR AJOUT: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DE LA MODIFICATION (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_contact'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $id = $_POST['id_contact'] ?? null;
        
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID contact manquant']);
            exit;
        }
        
        $prenom = trim($_POST['prenom'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $dateNaissance = $_POST['date_naissance'] ?? null;
        
        $errors = [];
        if (empty($prenom)) {
            $errors[] = 'Le prénom est requis';
        }
        if (empty($nom)) {
            $errors[] = 'Le nom est requis';
        }
        if (empty($email)) {
            $errors[] = "L'email est requis";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "L'email n'est pas valide";
        }
        
        if (!empty($dateNaissance)) {
            if (!verifierAge($dateNaissance, 18)) {
                $errors[] = "Le contact doit avoir au moins 18 ans et la date ne peut pas être dans le futur";
            }
        }
        
        if (!empty($email)) {
            $existingContact = $db->select('contact', ['id_compte' => $idCompte, 'email' => $email]);
            if (!empty($existingContact) && $existingContact[0]['id_contact'] != $id) {
                $errors[] = "Cet email est déjà utilisé par un autre contact";
            }
        }
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
            exit;
        }
        
        // Formater le téléphone
        $telephoneFormatted = null;
        if (!empty($telephone)) {
            if (substr($telephone, 0, 3) === '261') {
                $telephoneFormatted = $telephone;
            } else {
                $telephoneFormatted = formatPhoneNumber($telephone);
            }
        }
        
        $data = [
            'prenom' => $prenom,
            'nom' => $nom,
            'email' => $email,
            'telephone' => $telephoneFormatted,
            'date_naissance' => !empty($dateNaissance) ? $dateNaissance : null,
            'adresse' => !empty($_POST['adresse']) ? $_POST['adresse'] : null,
            'code_postal' => !empty($_POST['code_postal']) ? $_POST['code_postal'] : null,
            'ville' => !empty($_POST['ville']) ? $_POST['ville'] : null,
            'pays' => !empty($_POST['pays']) ? $_POST['pays'] : 'France'
        ];
        
        $db->update('contact', $data, ['id_contact' => $id]);
        
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            saveContactCustomValues($id, $_POST['custom_fields']);
        }
        
        echo json_encode(['success' => true, 'message' => 'Contact modifié avec succès']);
        
    } catch (Exception $e) {
        error_log("ERREUR MODIFICATION: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// RÉCUPÉRATION D'UN CONTACT POUR L'ÉDITION (AJAX)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_contact' && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $id = $_GET['id'];
        
        // 🔥 Vérifier que l'ID est valide
        if (empty($id)) {
            echo json_encode(['error' => 'ID de contact manquant']);
            exit;
        }
        
        $contact = $db->select('contact', ['id_contact' => $id, 'id_compte' => $idCompte]);
        
        if (empty($contact)) {
            echo json_encode(['error' => 'Contact non trouvé']);
            exit;
        }
        
        $contact = $contact[0];
        $contact['custom_values'] = getContactCustomValues($id);
        
        echo json_encode($contact);
        
    } catch (Exception $e) {
        error_log("ERREUR GET_CONTACT: " . $e->getMessage());
        error_log("TRACE: " . $e->getTraceAsString());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// RÉCUPÉRATION DES CHAMPS PERSONNALISÉS D'UN CONTACT (AJAX)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_contact_fields' && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $id = $_GET['id'];
        
        // Vérifier que le contact appartient au compte
        $contact = $db->select('contact', ['id_contact' => $id, 'id_compte' => $idCompte]);
        if (empty($contact)) {
            echo json_encode(['error' => 'Contact non trouvé']);
            exit;
        }
        
        // Récupérer les champs du contact
        $fields = $db->select('custom_fields', ['id_contact' => $id]);
        $result = [];
        
        foreach ($fields as $field) {
            // Récupérer la valeur
            $value = $db->select('contact_custom_values', ['id_custom_field' => $field['id_custom_field']]);
            $result[] = [
                'id_custom_field' => $field['id_custom_field'],
                'field_name' => $field['field_name'],
                'field_label' => $field['field_label'],
                'field_type' => $field['field_type'],
                'field_options' => $field['field_options'],
                'is_required' => $field['is_required'],
                'value' => !empty($value) ? $value[0]['field_value'] : ''
            ];
        }
        
        echo json_encode(['fields' => $result]);
        
    } catch (Exception $e) {
        error_log("ERREUR GET_FIELDS: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DE L'IMPORT CSV (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fichier']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $file = $_FILES['fichier'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'Erreur lors de l\'upload du fichier']);
            exit;
        }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xls', 'xlsx'])) {
            echo json_encode(['success' => false, 'error' => 'Format non supporté. Utilisez CSV, XLS ou XLSX']);
            exit;
        }
        
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            echo json_encode(['success' => false, 'error' => 'Impossible d\'ouvrir le fichier']);
            exit;
        }
        
        // 🔥 CORRECTION : Détecter automatiquement le séparateur
        $firstLine = fgets($handle);
        rewind($handle);
        
        // Tester différents séparateurs
        $separators = [';', ',', "\t", '|'];
        $separator = ',';
        $maxCount = 0;
        
        foreach ($separators as $testSep) {
            $count = substr_count($firstLine, $testSep);
            if ($count > $maxCount) {
                $maxCount = $count;
                $separator = $testSep;
            }
        }
        
        error_log("Séparateur détecté: " . json_encode($separator));
        
        // Lire les en-têtes
        $headers = fgetcsv($handle, 0, $separator);
        if (!$headers) {
            echo json_encode(['success' => false, 'error' => 'Format CSV invalide']);
            exit;
        }
        
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);
        
        error_log("Headers: " . print_r($headers, true));
        
        // Mapping des colonnes
        $mapping = [
            'prenom' => array_search('prenom', $headers),
            'nom' => array_search('nom', $headers),
            'email' => array_search('email', $headers),
            'telephone' => array_search('telephone', $headers),
            'ville' => array_search('ville', $headers),
            'adresse' => array_search('adresse', $headers),
            'code_postal' => array_search('code_postal', $headers),
            'pays' => array_search('pays', $headers),
            'date_naissance' => array_search('date_naissance', $headers)
        ];
        
        // Vérifier les colonnes requises
        if ($mapping['prenom'] === false || $mapping['nom'] === false || $mapping['email'] === false) {
            echo json_encode(['success' => false, 'error' => 'Colonnes requises manquantes: prenom, nom, email']);
            exit;
        }
        
        $importedCount = 0;
        $existingCount = 0;
        $errorCount = 0;
        $errors = [];
        
        while (($row = fgetcsv($handle, 0, $separator)) !== false) {
            // Nettoyer les lignes
            $row = array_map('trim', $row);
            
            $prenom = $mapping['prenom'] !== false ? trim($row[$mapping['prenom']] ?? '') : '';
            $nom = $mapping['nom'] !== false ? trim($row[$mapping['nom']] ?? '') : '';
            $email = $mapping['email'] !== false ? trim($row[$mapping['email']] ?? '') : '';
            $telephone = $mapping['telephone'] !== false ? trim($row[$mapping['telephone']] ?? '') : '';
            $dateNaissance = $mapping['date_naissance'] !== false ? trim($row[$mapping['date_naissance']] ?? '') : '';
            
            if (empty($prenom) || empty($nom) || empty($email)) {
                $errorCount++;
                continue;
            }
            
            // 🔥 CORRECTION : Convertir la date au format YYYY-MM-DD
            if (!empty($dateNaissance)) {
                // Essayer de convertir différents formats
                $dateFormats = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'm/d/Y'];
                $dateConverted = null;
                
                foreach ($dateFormats as $format) {
                    $dateObj = DateTime::createFromFormat($format, $dateNaissance);
                    if ($dateObj) {
                        $dateConverted = $dateObj->format('Y-m-d');
                        break;
                    }
                }
                
                if ($dateConverted) {
                    $dateNaissance = $dateConverted;
                } else {
                    // Si le format n'est pas reconnu, essayer strtotime
                    $timestamp = strtotime($dateNaissance);
                    if ($timestamp !== false) {
                        $dateNaissance = date('Y-m-d', $timestamp);
                    } else {
                        $dateNaissance = null;
                    }
                }
            }
            
            // Vérifier l'âge (18 ans minimum)
            if (!empty($dateNaissance)) {
                if (!verifierAge($dateNaissance, 18)) {
                    $errorCount++;
                    continue;
                }
            }
            
            // Vérifier si l'email existe déjà
            $existing = $db->select('contact', ['id_compte' => $idCompte, 'email' => $email]);
            if (!empty($existing)) {
                $existingCount++;
                continue;
            }
            
            // Formater le téléphone
            $telephoneFormatted = null;
            if (!empty($telephone)) {
                if (substr($telephone, 0, 3) === '261') {
                    $telephoneFormatted = $telephone;
                } else {
                    $telephoneFormatted = formatPhoneNumber($telephone);
                }
            }
            
            $data = [
                'id_compte' => $idCompte,
                'prenom' => $prenom,
                'nom' => $nom,
                'email' => $email,
                'telephone' => $telephoneFormatted,
                'ville' => $mapping['ville'] !== false ? trim($row[$mapping['ville']] ?? '') : null,
                'adresse' => $mapping['adresse'] !== false ? trim($row[$mapping['adresse']] ?? '') : null,
                'code_postal' => $mapping['code_postal'] !== false ? trim($row[$mapping['code_postal']] ?? '') : null,
                'pays' => $mapping['pays'] !== false ? trim($row[$mapping['pays']] ?? 'France') : 'France',
                'date_naissance' => !empty($dateNaissance) ? $dateNaissance : null
            ];
            
            try {
                $db->insert('contact', $data);
                $importedCount++;
            } catch (Exception $e) {
                $errorCount++;
                $errors[] = $e->getMessage();
            }
        }
        fclose($handle);
        
        if ($importedCount > 0) {
            $message = "$importedCount contact(s) importé(s) avec succès.";
            if ($existingCount > 0) $message .= " $existingCount contact(s) existant(s) ignoré(s).";
            if ($errorCount > 0) $message .= " $errorCount ligne(s) en erreur.";
            if (!empty($errors)) {
                $message .= " Détails: " . implode('; ', array_slice($errors, 0, 3));
            }
            echo json_encode(['success' => true, 'message' => $message]);
        } else {
            $errorMsg = "Aucun contact importé.";
            if ($existingCount > 0) $errorMsg .= " $existingCount contact(s) existant(s) ignoré(s).";
            if ($errorCount > 0) $errorMsg .= " $errorCount ligne(s) en erreur.";
            if (!empty($errors)) {
                $errorMsg .= " Erreurs: " . implode('; ', array_slice($errors, 0, 3));
            }
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
        
    } catch (Exception $e) {
        error_log("ERREUR IMPORT: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// Messages flash
$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
unset($_SESSION['flash_message']);
unset($_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes contacts - <?= APP_NAME ?></title>
    <style>
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
        .toast-notification.warning .toast-content { background: #f59e0b; }
        .toast-notification.info .toast-content { background: #3b82f6; }
        
        .modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        .contact-row.hidden-row { display: none; }
        .modal-add-contact, .modal-import-csv, .modal-edit-contact, .modal-custom-field {
            transition: all 0.3s ease;
            transform: scale(0.95);
            opacity: 0;
        }
        .modal-add-contact.modal-show, .modal-import-csv.modal-show, .modal-edit-contact.modal-show, .modal-custom-field.modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        .custom-field-badge {
            display: inline-block;
            background-color: #f3f4f6;
            border-radius: 9999px;
            padding: 2px 8px;
            font-size: 11px;
            margin: 2px 4px 2px 0;
            white-space: nowrap;
        }
        .custom-field-badge strong {
            font-weight: 600;
            color: #4b5563;
        }
        .phone-hint {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }
        .date-hint {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }
        .add-field-btn {
            transition: all 0.2s ease;
        }
        .add-field-btn:hover {
            transform: scale(1.05);
        }
        .new-field-highlight {
            animation: highlightField 1s ease;
        }
        @keyframes highlightField {
            0% { background-color: #bfdbfe; }
            100% { background-color: transparent; }
        }
        .temp-field-badge {
            display: inline-block;
            background-color: #dbeafe;
            color: #1e40af;
            border-radius: 9999px;
            padding: 2px 10px;
            font-size: 11px;
            margin: 2px 4px 2px 0;
            border: 1px dashed #60a5fa;
        }
        .remove-temp-field {
            cursor: pointer;
            color: #ef4444;
            margin-left: 4px;
            font-weight: bold;
        }
        .remove-temp-field:hover {
            color: #dc2626;
        }
    </style>
</head>
<body>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Mes contacts</h1>
            <p class="text-gray-500">Gérez votre base de contacts</p>
        </div>
        <div class="space-x-2">
            <button type="button" onclick="openAddContactModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-plus mr-2"></i>Ajouter un contact
            </button>
            <button type="button" onclick="openImportModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-upload mr-2"></i>Importer CSV
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div><span class="text-gray-500">Total des contacts</span><span class="text-2xl font-bold text-gray-800 ml-2" id="totalCount"><?= $totalContacts ?></span></div>
                <div class="text-gray-400"><i class="fas fa-users text-2xl"></i></div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 md:col-span-2">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="searchInput" placeholder="Rechercher par nom, email, téléphone ou ville..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
            </div>
            <div class="flex justify-between items-center mt-2">
                <div class="flex space-x-2 flex-wrap gap-2">
                    <button class="filter-btn active px-3 py-1 text-xs rounded-full bg-blue-600 text-white" data-filter="all">Tous</button>
                    <button class="filter-btn px-3 py-1 text-xs rounded-full bg-gray-200 text-gray-700 hover:bg-gray-300 transition" data-filter="email">Avec email</button>
                    <button class="filter-btn px-3 py-1 text-xs rounded-full bg-gray-200 text-gray-700 hover:bg-gray-300 transition" data-filter="phone">Avec téléphone</button>
                    <button class="filter-btn px-3 py-1 text-xs rounded-full bg-red-100 text-red-700 hover:bg-red-200 transition" data-filter="blacklisted">
                        <i class="fas fa-ban mr-1"></i>Blacklistés
                    </button>
                </div>
                <span id="filteredCount" class="text-xs text-gray-500"></span>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Téléphone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ville</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Infos</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date d'inscription</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody id="contactsTableBody">
                    <?php if (empty($contacts)): ?>
                        <tr id="noContactsRow">
                            <td colspan="8" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-address-book text-4xl mb-2 block"></i>
                                Aucun contact pour le moment.
                                <button type="button" onclick="openAddContactModal()" class="text-blue-600 block mt-2">Ajouter votre premier contact →</button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($contacts as $contact): 
                            $isBlacklisted = in_array($contact['id_contact'], $blacklistedIds);
                            $customVals = $contactsCustomValues[$contact['id_contact']] ?? [];
                        ?>
                            <tr class="contact-row hover:bg-gray-50 transition <?= $isBlacklisted ? 'bg-red-50' : '' ?>" 
                                data-name="<?= strtolower(htmlspecialchars($contact['prenom'] . ' ' . $contact['nom'])) ?>"
                                data-email="<?= strtolower(htmlspecialchars($contact['email'] ?? '')) ?>"
                                data-phone="<?= strtolower(htmlspecialchars($contact['telephone'] ?? '')) ?>"
                                data-city="<?= strtolower(htmlspecialchars($contact['ville'] ?? '')) ?>"
                                data-has-email="<?= !empty($contact['email']) ? 'true' : 'false' ?>"
                                data-has-phone="<?= !empty($contact['telephone']) ? 'true' : 'false' ?>"
                                data-blacklisted="<?= $isBlacklisted ? 'true' : 'false' ?>"
                                data-contact-id="<?= $contact['id_contact'] ?>">
                                <td class="px-6 py-4"><div class="font-medium text-gray-800"><?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?></div></td>
                                <td class="px-6 py-4"><?= htmlspecialchars($contact['email'] ?? '-') ?></td>
                                <td class="px-6 py-4"><?= htmlspecialchars($contact['telephone'] ?? '-') ?></td>
                                <td class="px-6 py-4"><?= htmlspecialchars($contact['ville'] ?? '-') ?></td>
                                <td class="px-6 py-4">
                                    <?php if (!empty($customVals)): ?>
                                        <?php foreach ($customVals as $field): ?>
                                            <span class="custom-field-badge">
                                                <strong><?= htmlspecialchars($field['label']) ?>:</strong> <?= htmlspecialchars(substr($field['value'], 0, 30)) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4"><?= date('d/m/Y', strtotime($contact['date_inscription'])) ?></td>
                                <td class="px-6 py-4">
                                    <?php if ($isBlacklisted): ?>
                                        <button onclick="openUnblacklistModal('<?= $contact['id_contact'] ?>')" class="px-2 py-1 rounded text-xs bg-red-100 text-red-700 hover:bg-red-200 transition cursor-pointer flex items-center gap-1">
                                            <i class="fas fa-ban mr-1"></i> Blacklisté
                                        </button>
                                    <?php else: ?>
                                        <span class="px-2 py-1 rounded text-xs bg-green-100 text-green-700"><i class="fas fa-check-circle mr-1"></i> Normal</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 space-x-2">
                                    <button type="button" onclick="openEditContactModal('<?= $contact['id_contact'] ?>')" class="text-blue-600 hover:text-blue-800 transition" title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" onclick="showDeleteModal('<?= $contact['id_contact'] ?>')" class="text-red-600 hover:text-red-800 transition" title="Supprimer">
                                        <i class="fas fa-trash"></i>
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

<!-- ============================================ -->
<!-- MODAL D'AJOUT DE CONTACT -->
<!-- ============================================ -->
<div id="addContactModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 modal-add-contact">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center"><div class="bg-blue-100 p-2 rounded-full mr-3"><i class="fas fa-user-plus text-blue-600 text-xl"></i></div><h3 class="text-xl font-bold text-gray-800">Ajouter un contact</h3></div>
                <button type="button" onclick="closeAddContactModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
            </div>
            <form id="addContactForm" method="POST">
                <input type="hidden" name="action_add_contact" value="1">
                <input type="hidden" id="tempCustomFields" name="temp_custom_fields" value="">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label><input type="text" name="prenom" id="add_prenom" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label><input type="text" name="nom" id="add_nom" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Email *</label><input type="email" name="email" id="add_email" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                        <input type="tel" name="telephone" id="add_telephone" placeholder="ex: 0612345678 ou 261341234567" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                        <p class="phone-hint">Format accepté : 0612345678 (France) ou 261341234567 (International)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label>
                        <input type="date" name="date_naissance" id="add_date_naissance" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500" max="<?= date('Y-m-d', strtotime('-18 years')) ?>">
                        <p class="date-hint">📅 Le contact doit avoir au moins 18 ans. Les dates futures sont interdites.</p>
                    </div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Ville</label><input type="text" name="ville" id="add_ville" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Code postal</label><input type="text" name="code_postal" id="add_code_postal" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Pays</label><input type="text" name="pays" id="add_pays" value="France" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                </div>
                <div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label><textarea name="adresse" id="add_adresse" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea></div>
                
                <!-- Champs personnalisés pour l'ajout -->
                <div class="mt-6 pt-4 border-t border-gray-200">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-md font-semibold text-gray-700">
                            <i class="fas fa-cog mr-2"></i>Champs personnalisés
                        </h3>
                        <button type="button" onclick="openAddCustomFieldModalFromAddTemp()" 
                                class="text-sm text-blue-600 hover:text-blue-800 transition flex items-center gap-1 add-field-btn">
                            <i class="fas fa-plus-circle"></i> Ajouter un champ
                        </button>
                    </div>
                    <div id="addCustomFieldsContainer" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="col-span-2 text-center py-3 text-gray-400 text-sm" id="noCustomFieldsMessage">
                            <i class="fas fa-info-circle mr-1"></i>
                            Aucun champ personnalisé.
                            <button type="button" onclick="openAddCustomFieldModalFromAddTemp()" 
                                    class="text-blue-600 hover:underline">
                                Ajouter votre premier champ
                            </button>
                        </div>
                    </div>
                    <!-- Liste des champs temporaires -->
                    <div id="tempFieldsList" class="mt-3 flex flex-wrap gap-2"></div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeAddContactModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Annuler</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL DE MODIFICATION DE CONTACT -->
<!-- ============================================ -->
<div id="editContactModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 modal-edit-contact">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center"><div class="bg-yellow-100 p-2 rounded-full mr-3"><i class="fas fa-edit text-yellow-600 text-xl"></i></div><h3 class="text-xl font-bold text-gray-800">Modifier le contact</h3></div>
                <button type="button" onclick="closeEditContactModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
            </div>
            <form id="editContactForm" method="POST">
                <input type="hidden" name="action_edit_contact" value="1">
                <input type="hidden" name="id_contact" id="edit_id_contact">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label><input type="text" name="prenom" id="edit_prenom" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label><input type="text" name="nom" id="edit_nom" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Email *</label><input type="email" name="email" id="edit_email" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                        <input type="tel" name="telephone" id="edit_telephone" placeholder="ex: 0612345678 ou 261341234567" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                        <p class="phone-hint">Format accepté : 0612345678 (France) ou 261341234567 (International)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label>
                        <input type="date" name="date_naissance" id="edit_date_naissance" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500" max="<?= date('Y-m-d', strtotime('-18 years')) ?>">
                        <p class="date-hint">📅 Le contact doit avoir au moins 18 ans. Les dates futures sont interdites.</p>
                    </div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Ville</label><input type="text" name="ville" id="edit_ville" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Code postal</label><input type="text" name="code_postal" id="edit_code_postal" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Pays</label><input type="text" name="pays" id="edit_pays" value="France" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                </div>
                <div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label><textarea name="adresse" id="edit_adresse" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea></div>
                
                <!-- Champs personnalisés dynamiques -->
                <div class="mt-6 pt-4 border-t border-gray-200" id="customFieldsSection">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-md font-semibold text-gray-700">
                            <i class="fas fa-cog mr-2"></i>Informations supplémentaires
                        </h3>
                        <button type="button" onclick="openAddCustomFieldModalFromEdit()" 
                                class="text-sm text-blue-600 hover:text-blue-800 transition flex items-center gap-1 add-field-btn">
                            <i class="fas fa-plus-circle"></i> Ajouter un champ
                        </button>
                    </div>
                    <div id="editCustomFieldsContainer" class="grid grid-cols-1 md:grid-cols-2 gap-4"></div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeEditContactModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Annuler</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE DE CRÉATION DE CHAMP PERSONNALISÉ -->
<!-- ============================================ -->
<div id="addCustomFieldModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-[60] transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-custom-field">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                        <i class="fas fa-plus-circle text-blue-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Ajouter un champ personnalisé</h3>
                </div>
                <button type="button" onclick="closeAddCustomFieldModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="addCustomFieldForm">
                <input type="hidden" id="custom_field_contact_id" value="">
                <input type="hidden" id="custom_field_mode" value="temp">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Nom technique <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="new_field_name" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                               placeholder="ex: societe, fonction">
                        <p class="text-xs text-gray-500 mt-1">Sans accent, sans espace (utilisez _ )</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Libellé <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="new_field_label" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                               placeholder="ex: Société, Fonction">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Type de champ</label>
                        <select id="new_field_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                            <option value="text">Texte court</option>
                            <option value="textarea">Zone texte</option>
                            <option value="number">Nombre</option>
                            <option value="date">Date</option>
                            <option value="email">Email</option>
                            <option value="tel">Téléphone</option>
                            <option value="select">Liste déroulante</option>
                        </select>
                    </div>
                    <div id="new_field_options_div" style="display:none">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Options <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="new_field_options" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                               placeholder="ex: Option 1|Option 2|Option 3">
                        <p class="text-xs text-gray-500 mt-1">Séparez les options par <strong>|</strong></p>
                    </div>
                    <div id="new_field_value_div">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Valeur (optionnel)
                        </label>
                        <input type="text" id="new_field_value" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                               placeholder="Valeur du champ">
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeAddCustomFieldModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" id="createFieldBtn" 
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                        <i class="fas fa-plus mr-2"></i>Ajouter le champ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL D'IMPORT CSV -->
<div id="importModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 modal-import-csv">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center"><div class="bg-green-100 p-2 rounded-full mr-3"><i class="fas fa-file-import text-green-600 text-xl"></i></div><h3 class="text-xl font-bold text-gray-800">Importer des contacts</h3></div>
                <button type="button" onclick="closeImportModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="bg-blue-50 p-4 rounded mb-4">
                <h4 class="font-medium text-blue-800 mb-2">Format du fichier</h4>
                <ul class="text-sm text-blue-700 space-y-1">
                    <li><i class="fas fa-check-circle mr-2"></i> Colonnes requises : <strong>prenom, nom, email</strong></li>
                    <li><i class="fas fa-check-circle mr-2"></i> Colonnes optionnelles : telephone, ville, adresse, code_postal, pays, date_naissance</li>
                    <li><i class="fas fa-check-circle mr-2"></i> Séparateur : point-virgule (;) ou virgule (,)</li>
                    <li><i class="fas fa-check-circle mr-2"></i> Les contacts déjà existants (même email) sont ignorés</li>
                    <li><i class="fas fa-info-circle mr-2"></i> La date de naissance doit être au format YYYY-MM-DD et le contact doit avoir au moins 18 ans</li>
                </ul>
            </div>
            <form id="importForm" method="POST" enctype="multipart/form-data">
                <div class="mb-4"><label class="block text-sm font-medium text-gray-700 mb-2">Fichier CSV/Excel</label><input type="file" name="fichier" id="importFile" accept=".csv,.xls,.xlsx" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500"><p class="text-xs text-gray-500 mt-1">Formats acceptés : CSV, XLS, XLSX (Taille max : 10MB)</p></div>
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeImportModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Annuler</button>
                    <button type="submit" id="importSubmitBtn" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition">Importer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE POUR DÉBLACKLISTER -->
<div id="unblacklistModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6 text-center">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-4"><i class="fas fa-unlock-alt text-green-600 text-3xl"></i></div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">Débloquer le contact</h3>
            <p class="text-gray-500 mb-6">Êtes-vous sûr de vouloir retirer ce contact de la blacklist ?</p>
            <form method="POST" action="?page=contacts/unblacklist"><input type="hidden" name="id_contact" id="unblacklistContactId"><div class="flex space-x-3"><button type="button" onclick="closeUnblacklistModal()" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Annuler</button><button type="submit" class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition">Débloquer</button></div></form>
        </div>
    </div>
</div>

<!-- MODALE DE CONFIRMATION SUPPRESSION -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6 text-center">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-4"><i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i></div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">Confirmer la suppression</h3>
            <p class="text-gray-500 mb-6">Êtes-vous sûr de vouloir supprimer ce contact ?</p>
            <p class="text-sm text-gray-400 mb-6">Cette action est irréversible.</p>
            <div class="flex space-x-3"><button type="button" onclick="closeModal()" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Annuler</button><a href="#" id="confirmDeleteBtn" class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition text-center">Supprimer</a></div>
        </div>
    </div>
</div>

<script>
// ============================================
// TOAST NOTIFICATION
// ============================================
function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const colors = { success: '#10b981', error: '#ef4444', warning: '#f59e0b', info: '#3b82f6' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Messages flash
<?php if ($flashMessage): ?> showToast('<?= addslashes($flashMessage) ?>', 'success'); <?php endif; ?>
<?php if ($flashError): ?> showToast('<?= addslashes($flashError) ?>', 'error'); <?php endif; ?>

// ============================================
// VARIABLES GLOBALES
// ============================================
let currentContactIdForField = null;
let currentContactIdForEdit = null;
let tempCustomFields = [];
let isTempMode = false;

// ============================================
// GESTION DES CHAMPS TEMPORAIRES
// ============================================

function addTempField(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue) {
    // Vérifier si le champ existe déjà
    const exists = tempCustomFields.some(f => f.field_name === fieldName);
    if (exists) {
        showToast('Un champ avec ce nom existe déjà', 'warning');
        return false;
    }
    
    tempCustomFields.push({
        field_name: fieldName,
        field_label: fieldLabel,
        field_type: fieldType,
        field_options: fieldOptions || null,
        field_value: fieldValue || ''
    });
    
    updateTempFieldsDisplay();
    updateTempFieldsInput();
    return true;
}

function removeTempField(index) {
    tempCustomFields.splice(index, 1);
    updateTempFieldsDisplay();
    updateTempFieldsInput();
}

function updateTempFieldsDisplay() {
    const container = document.getElementById('tempFieldsList');
    if (!container) return;
    
    if (tempCustomFields.length === 0) {
        container.innerHTML = '';
        return;
    }
    
    container.innerHTML = tempCustomFields.map((field, index) => {
        const label = field.field_label || field.field_name;
        const value = field.field_value ? `: ${field.field_value}` : '';
        return `<span class="temp-field-badge">
            <i class="fas fa-tag mr-1"></i>
            ${escapeHtml(label)}${escapeHtml(value)}
            <span class="remove-temp-field" onclick="removeTempField(${index})" title="Supprimer ce champ">×</span>
        </span>`;
    }).join('');
}

function updateTempFieldsInput() {
    const input = document.getElementById('tempCustomFields');
    if (input) {
        input.value = JSON.stringify(tempCustomFields);
    }
}

// ============================================
// FONCTION POUR AJOUTER UN CHAMP DYNAMIQUEMENT DANS LE FORMULAIRE D'AJOUT
// ============================================
function ajouterChampDynamiquement(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue) {
    const noFieldsMsg = document.getElementById('noCustomFieldsMessage');
    if (noFieldsMsg) {
        noFieldsMsg.remove();
    }
    
    const container = document.getElementById('addCustomFieldsContainer');
    if (!container) return;
    
    let fieldHtml = '';
    const fieldNameEscaped = escapeHtml(fieldName);
    const fieldLabelEscaped = escapeHtml(fieldLabel);
    const fieldValueEscaped = escapeHtml(fieldValue || '');
    
    if (fieldType === 'textarea') {
        fieldHtml = `
            <div class="custom-field-wrapper new-field-highlight">
                <label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label>
                <textarea name="custom_fields[${fieldNameEscaped}]" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">${fieldValueEscaped}</textarea>
            </div>
        `;
    } else if (fieldType === 'select' && fieldOptions) {
        const options = fieldOptions.split('|');
        let optionsHtml = '<option value="">-- Sélectionner --</option>';
        options.forEach(opt => {
            const optTrimmed = opt.trim();
            const selected = fieldValue === optTrimmed ? 'selected' : '';
            optionsHtml += `<option value="${escapeHtml(optTrimmed)}" ${selected}>${escapeHtml(optTrimmed)}</option>`;
        });
        fieldHtml = `
            <div class="custom-field-wrapper new-field-highlight">
                <label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label>
                <select name="custom_fields[${fieldNameEscaped}]" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    ${optionsHtml}
                </select>
            </div>
        `;
    } else if (fieldType === 'date') {
        fieldHtml = `
            <div class="custom-field-wrapper new-field-highlight">
                <label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label>
                <input type="date" name="custom_fields[${fieldNameEscaped}]" value="${fieldValueEscaped}" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
            </div>
        `;
    } else if (fieldType === 'number') {
        fieldHtml = `
            <div class="custom-field-wrapper new-field-highlight">
                <label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label>
                <input type="number" name="custom_fields[${fieldNameEscaped}]" value="${fieldValueEscaped}" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
            </div>
        `;
    } else {
        fieldHtml = `
            <div class="custom-field-wrapper new-field-highlight">
                <label class="block text-sm font-medium text-gray-700 mb-1">${fieldLabelEscaped}</label>
                <input type="text" name="custom_fields[${fieldNameEscaped}]" value="${fieldValueEscaped}" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500" placeholder="${fieldLabelEscaped}">
            </div>
        `;
    }
    
    container.insertAdjacentHTML('beforeend', fieldHtml);
    
    setTimeout(() => {
        document.querySelectorAll('.new-field-highlight').forEach(el => {
            el.classList.remove('new-field-highlight');
        });
    }, 2000);
}

// ============================================
// MODALE D'AJOUT DE CHAMP PERSONNALISÉ (MODE TEMPORAIRE)
// ============================================
function openAddCustomFieldModalFromAddTemp() {
    isTempMode = true;
    document.getElementById('custom_field_contact_id').value = 'temp';
    document.getElementById('custom_field_mode').value = 'temp';
    document.getElementById('new_field_value_div').style.display = 'block';
    openAddCustomFieldModal();
}

function openAddCustomFieldModalFromEdit() {
    if (!currentContactIdForEdit) {
        showToast('Contact non identifié', 'error');
        return;
    }
    isTempMode = false;
    document.getElementById('custom_field_contact_id').value = currentContactIdForEdit;
    document.getElementById('custom_field_mode').value = 'edit';
    document.getElementById('new_field_value_div').style.display = 'none';
    openAddCustomFieldModal();
}

function openAddCustomFieldModal() {
    const modal = document.getElementById('addCustomFieldModal');
    const modalContent = modal.querySelector('.modal-custom-field');
    document.getElementById('addCustomFieldForm').reset();
    document.getElementById('new_field_options_div').style.display = 'none';
    document.getElementById('new_field_value').value = '';
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeAddCustomFieldModal() {
    const modal = document.getElementById('addCustomFieldModal');
    const modalContent = modal.querySelector('.modal-custom-field');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// Afficher/masquer les options pour les listes
document.getElementById('new_field_type')?.addEventListener('change', function() {
    document.getElementById('new_field_options_div').style.display = this.value === 'select' ? 'block' : 'none';
});

// ============================================
// SOUMISSION DU FORMULAIRE DE CRÉATION DE CHAMP
// ============================================
document.getElementById('addCustomFieldForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const fieldName = document.getElementById('new_field_name').value.trim();
    const fieldLabel = document.getElementById('new_field_label').value.trim();
    const fieldType = document.getElementById('new_field_type').value;
    const fieldOptions = document.getElementById('new_field_options').value.trim();
    const fieldValue = document.getElementById('new_field_value').value.trim();
    const mode = document.getElementById('custom_field_mode').value;
    const contactId = document.getElementById('custom_field_contact_id').value;
    
    if (!fieldName || !fieldLabel) {
        showToast('Veuillez remplir tous les champs obligatoires', 'warning');
        return;
    }
    
    const btn = document.getElementById('createFieldBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Ajout...';
    btn.disabled = true;
    
    try {
        if (mode === 'temp') {
            // Mode temporaire : ajouter le champ à la liste temporaire
            const success = addTempField(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue);
            if (success) {
                ajouterChampDynamiquement(fieldName, fieldLabel, fieldType, fieldOptions, fieldValue);
                showToast('Champ ajouté temporairement', 'success');
                closeAddCustomFieldModal();
            }
        } else if (mode === 'edit') {
            // Mode édition : créer le champ directement dans la base
            if (!contactId || contactId === 'temp') {
                showToast('Contact non identifié', 'error');
                btn.innerHTML = originalText;
                btn.disabled = false;
                return;
            }
            
            const formData = new FormData();
            formData.append('action_create_custom_field', '1');
            formData.append('field_name', fieldName);
            formData.append('field_label', fieldLabel);
            formData.append('field_type', fieldType);
            if (fieldOptions) {
                formData.append('field_options', fieldOptions);
            }
            formData.append('id_contact', contactId);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                showToast(result.message, 'success');
                closeAddCustomFieldModal();
                // Recharger le contact pour afficher le nouveau champ
                if (currentContactIdForEdit) {
                    openEditContactModal(currentContactIdForEdit);
                }
            } else {
                showToast(result.error, 'error');
            }
        }
    } catch (error) {
        showToast('Erreur réseau', 'error');
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
});

// ============================================
// MODAL D'AJOUT DE CONTACT
// ============================================
function openAddContactModal() {
    const modal = document.getElementById('addContactModal');
    const modalContent = modal.querySelector('.modal-add-contact');
    document.getElementById('addContactForm').reset();
    
    // Réinitialiser les champs temporaires
    tempCustomFields = [];
    document.getElementById('tempCustomFields').value = '';
    document.getElementById('tempFieldsList').innerHTML = '';
    
    // Réinitialiser l'affichage des champs personnalisés
    const container = document.getElementById('addCustomFieldsContainer');
    container.innerHTML = `
        <div class="col-span-2 text-center py-3 text-gray-400 text-sm" id="noCustomFieldsMessage">
            <i class="fas fa-info-circle mr-1"></i>
            Aucun champ personnalisé.
            <button type="button" onclick="openAddCustomFieldModalFromAddTemp()" 
                    class="text-blue-600 hover:underline">
                Ajouter votre premier champ
            </button>
        </div>
    `;
    
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeAddContactModal() {
    const modal = document.getElementById('addContactModal');
    const modalContent = modal.querySelector('.modal-add-contact');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// ============================================
// MODAL DE MODIFICATION DE CONTACT
// ============================================
async function openEditContactModal(contactId) {
    currentContactIdForEdit = contactId;
    const modal = document.getElementById('editContactModal');
    const modalContent = modal.querySelector('.modal-edit-contact');
    
    try {
        const url = `index.php?page=contacts/index&action=get_contact&id=${contactId}`;
        console.log('Chargement du contact:', url);
        
        const response = await fetch(url);
        
        if (!response.ok) {
            const text = await response.text();
            console.error('Erreur HTTP:', response.status, text);
            showToast('Erreur serveur: ' + response.status, 'error');
            return;
        }
        
        const textResponse = await response.text();
        console.log('Réponse brute:', textResponse);
        
        let contact;
        try {
            contact = JSON.parse(textResponse);
        } catch (e) {
            console.error('Erreur de parsing JSON:', e);
            showToast('Erreur de parsing: ' + textResponse.substring(0, 200), 'error');
            return;
        }
        
        if (contact.error) {
            showToast(contact.error, 'error');
            return;
        }
        
        // Remplir les champs du formulaire
        document.getElementById('edit_id_contact').value = contact.id_contact;
        document.getElementById('edit_prenom').value = contact.prenom || '';
        document.getElementById('edit_nom').value = contact.nom || '';
        document.getElementById('edit_email').value = contact.email || '';
        document.getElementById('edit_telephone').value = contact.telephone || '';
        document.getElementById('edit_date_naissance').value = contact.date_naissance || '';
        document.getElementById('edit_ville').value = contact.ville || '';
        document.getElementById('edit_code_postal').value = contact.code_postal || '';
        document.getElementById('edit_pays').value = contact.pays || 'France';
        document.getElementById('edit_adresse').value = contact.adresse || '';
        
        const container = document.getElementById('editCustomFieldsContainer');
        container.innerHTML = '';
        
        // Récupérer les champs personnalisés du contact
        const fieldsUrl = `index.php?page=contacts/index&action=get_contact_fields&id=${contactId}`;
        const fieldsResponse = await fetch(fieldsUrl);
        const fieldsData = await fieldsResponse.json();
        
        if (fieldsData.fields && fieldsData.fields.length > 0) {
            document.getElementById('customFieldsSection').style.display = 'block';
            
            window.currentContactFields = fieldsData.fields;
            
            for (const field of fieldsData.fields) {
                const currentValue = field.value || '';
                const required = field.is_required ? '<span class="text-red-500">*</span>' : '';
                
                let fieldHtml = '<div class="custom-field-wrapper" data-field-name="' + escapeHtml(field.field_name) + '">';
                fieldHtml += '<label class="block text-sm font-medium text-gray-700 mb-1">' + escapeHtml(field.field_label) + ' ' + required + '</label>';
                
                if (field.field_type === 'textarea') {
                    fieldHtml += '<textarea name="custom_fields[' + escapeHtml(field.field_name) + ']" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">' + escapeHtml(currentValue) + '</textarea>';
                } 
                else if (field.field_type === 'select' && field.field_options) {
                    const options = field.field_options.split('|');
                    fieldHtml += '<select name="custom_fields[' + escapeHtml(field.field_name) + ']" class="w-full border border-gray-300 rounded-lg px-3 py-2">';
                    fieldHtml += '<option value="">-- Sélectionner --</option>';
                    for (const opt of options) {
                        const optTrimmed = opt.trim();
                        const selected = currentValue === optTrimmed ? 'selected' : '';
                        fieldHtml += '<option value="' + escapeHtml(optTrimmed) + '" ' + selected + '>' + escapeHtml(optTrimmed) + '</option>';
                    }
                    fieldHtml += '</select>';
                }
                else if (field.field_type === 'date') {
                    fieldHtml += '<input type="date" name="custom_fields[' + escapeHtml(field.field_name) + ']" value="' + escapeHtml(currentValue) + '" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">';
                }
                else if (field.field_type === 'number') {
                    fieldHtml += '<input type="number" name="custom_fields[' + escapeHtml(field.field_name) + ']" value="' + escapeHtml(currentValue) + '" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">';
                }
                else {
                    fieldHtml += '<input type="text" name="custom_fields[' + escapeHtml(field.field_name) + ']" value="' + escapeHtml(currentValue) + '" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">';
                }
                
                fieldHtml += '</div>';
                container.innerHTML += fieldHtml;
            }
        } else {
            document.getElementById('customFieldsSection').style.display = 'block';
            container.innerHTML = `
                <div class="col-span-2 text-center py-3 text-gray-400 text-sm">
                    <i class="fas fa-info-circle mr-1"></i>
                    Aucun champ personnalisé pour ce contact.
                    <button type="button" onclick="openAddCustomFieldModalFromEdit()" 
                            class="text-blue-600 hover:underline">
                        Ajouter un champ
                    </button>
                </div>
            `;
        }
        
        modal.style.display = 'flex';
        setTimeout(() => modalContent.classList.add('modal-show'), 10);
        
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur lors du chargement du contact: ' + error.message, 'error');
    }
}

function closeEditContactModal() {
    const modal = document.getElementById('editContactModal');
    const modalContent = modal.querySelector('.modal-edit-contact');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// ============================================
// MODAL D'IMPORT
// ============================================
function openImportModal() {
    const modal = document.getElementById('importModal');
    const modalContent = modal.querySelector('.modal-import-csv');
    document.getElementById('importForm').reset();
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeImportModal() {
    const modal = document.getElementById('importModal');
    const modalContent = modal.querySelector('.modal-import-csv');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// ============================================
// MODALE BLACKLIST
// ============================================
function openUnblacklistModal(contactId) {
    document.getElementById('unblacklistContactId').value = contactId;
    document.getElementById('unblacklistModal').style.display = 'flex';
}

function closeUnblacklistModal() {
    document.getElementById('unblacklistModal').style.display = 'none';
}

// ============================================
// MODALE SUPPRESSION
// ============================================
function showDeleteModal(contactId) {
    const modal = document.getElementById('deleteModal');
    document.getElementById('confirmDeleteBtn').href = 'index.php?page=contacts/supprimer&id=' + contactId;
    modal.style.display = 'flex';
}

function closeModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// ============================================
// SOUMISSION DES FORMULAIRES AJAX
// ============================================

// Ajout contact
document.getElementById('addContactForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Envoi...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }, 
            body: formData 
        });
        
        const textResponse = await response.text();
        console.log('Réponse brute:', textResponse);
        
        let result;
        try {
            result = JSON.parse(textResponse);
        } catch (e) {
            console.error('Erreur de parsing JSON:', e);
            showToast('Erreur de parsing: ' + textResponse.substring(0, 200), 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        if (result.success) {
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 2000);
        } else {
            showToast(result.error || 'Erreur inconnue', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Erreur réseau:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// Modification contact
document.getElementById('editContactForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Envoi...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }, 
            body: formData 
        });
        
        const textResponse = await response.text();
        console.log('Réponse brute modification:', textResponse);
        
        let result;
        try {
            result = JSON.parse(textResponse);
        } catch (e) {
            console.error('Erreur de parsing JSON modification:', e);
            showToast('Erreur de parsing: ' + textResponse.substring(0, 200), 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        if (result.success) {
            showToast(result.message, 'success');
            closeEditContactModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error || 'Erreur inconnue', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Erreur réseau modification:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// Import CSV
document.getElementById('importForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fileInput = document.getElementById('importFile');
    if (!fileInput.files.length) { showToast('Veuillez sélectionner un fichier', 'warning'); return; }
    const formData = new FormData(this);
    const submitBtn = document.getElementById('importSubmitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Import en cours...';
    submitBtn.disabled = true;
    try {
        const response = await fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            closeImportModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error, 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Erreur réseau:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// ============================================
// FILTRES ET RECHERCHE
// ============================================
const searchInput = document.getElementById('searchInput');
const filterBtns = document.querySelectorAll('.filter-btn');
const contactsRows = document.querySelectorAll('.contact-row');
const filteredCountSpan = document.getElementById('filteredCount');
let currentFilter = 'all';

function filterContacts() {
    const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
    let visibleCount = 0;
    contactsRows.forEach(row => {
        const name = row.getAttribute('data-name') || '';
        const email = row.getAttribute('data-email') || '';
        const phone = row.getAttribute('data-phone') || '';
        const city = row.getAttribute('data-city') || '';
        const hasEmail = row.getAttribute('data-has-email') === 'true';
        const hasPhone = row.getAttribute('data-has-phone') === 'true';
        const isBlacklisted = row.getAttribute('data-blacklisted') === 'true';
        
        let filterMatch = true;
        if (currentFilter === 'email') filterMatch = hasEmail;
        else if (currentFilter === 'phone') filterMatch = hasPhone;
        else if (currentFilter === 'blacklisted') filterMatch = isBlacklisted;
        
        let searchMatch = true;
        if (searchTerm !== '') {
            searchMatch = name.includes(searchTerm) || email.includes(searchTerm) || phone.includes(searchTerm) || city.includes(searchTerm);
        }
        
        if (filterMatch && searchMatch) { row.classList.remove('hidden-row'); visibleCount++; }
        else { row.classList.add('hidden-row'); }
    });
    if (filteredCountSpan) filteredCountSpan.textContent = `${visibleCount} contact(s) affiché(s)`;
    
    let noResultRow = document.getElementById('noResultRow');
    if (visibleCount === 0 && contactsRows.length > 0) {
        if (!noResultRow) {
            const tbody = document.getElementById('contactsTableBody');
            if (tbody) {
                noResultRow = document.createElement('tr');
                noResultRow.id = 'noResultRow';
                noResultRow.innerHTML = '<td colspan="8" class="px-6 py-8 text-center text-gray-500"><i class="fas fa-search text-4xl mb-2 block"></i>Aucun contact ne correspond à votre recherche.</td>';
                tbody.appendChild(noResultRow);
            }
        }
        if (noResultRow) noResultRow.style.display = '';
    } else if (noResultRow) { noResultRow.style.display = 'none'; }
}

if (searchInput) searchInput.addEventListener('input', filterContacts);

filterBtns.forEach(btn => {
    btn.addEventListener('click', function() {
        filterBtns.forEach(b => {
            b.classList.remove('bg-blue-600', 'text-white');
            b.classList.add('bg-gray-200', 'text-gray-700');
        });
        this.classList.remove('bg-gray-200', 'text-gray-700');
        this.classList.add('bg-blue-600', 'text-white');
        currentFilter = this.getAttribute('data-filter');
        filterContacts();
    });
});

// ============================================
// FONCTIONS UTILITAIRES
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Fermeture des modales
document.getElementById('addContactModal')?.addEventListener('click', function(e) { if (e.target === this) closeAddContactModal(); });
document.getElementById('editContactModal')?.addEventListener('click', function(e) { if (e.target === this) closeEditContactModal(); });
document.getElementById('importModal')?.addEventListener('click', function(e) { if (e.target === this) closeImportModal(); });
document.getElementById('unblacklistModal')?.addEventListener('click', function(e) { if (e.target === this) closeUnblacklistModal(); });
document.getElementById('deleteModal')?.addEventListener('click', function(e) { if (e.target === this) closeModal(); });
document.getElementById('addCustomFieldModal')?.addEventListener('click', function(e) { if (e.target === this) closeAddCustomFieldModal(); });

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddContactModal();
        closeEditContactModal();
        closeImportModal();
        closeUnblacklistModal();
        closeModal();
        closeAddCustomFieldModal();
    }
});
</script>

</body>
</html>