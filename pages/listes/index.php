<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);


ini_set('log_errors', 1);
ini_set('error_log', '/var/log/php_errors.log');

// Désactiver le cache pour cette page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

global $db;

$idCompte = $_SESSION['user_id'];

// ============================================
// TRAITEMENT DE L'AJOUT DE LISTE (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_liste']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    ob_clean();
    header('Content-Type: application/json');
    
    $nom_liste = trim($_POST['nom_liste'] ?? '');
    
    if (empty($nom_liste)) {
        echo json_encode(['success' => false, 'error' => 'Veuillez saisir un nom de liste']);
        exit;
    }
    
    try {
        $data = [
            'id_compte' => $idCompte,
            'nom_liste' => $nom_liste
        ];
        $db->insert('liste', $data);
        echo json_encode(['success' => true, 'message' => 'Liste créée avec succès']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DE L'AJOUT DE CONTACT À UNE LISTE (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_contacts_to_list']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    ob_clean();
    header('Content-Type: application/json');
    
    $id_liste = $_POST['id_liste'] ?? null;
    $selectedContacts = $_POST['selected_contacts'] ?? [];
    
    if (!$id_liste) {
        echo json_encode(['success' => false, 'error' => 'Liste invalide']);
        exit;
    }
    
    if (empty($selectedContacts)) {
        echo json_encode(['success' => false, 'error' => 'Veuillez sélectionner au moins un contact']);
        exit;
    }
    
    $listeExists = $db->select('liste', ['id_liste' => $id_liste, 'id_compte' => $idCompte]);
    if (empty($listeExists)) {
        echo json_encode(['success' => false, 'error' => 'Liste invalide']);
        exit;
    }
    
    $existingContacts = $db->select('liste_contact', ['id_liste' => $id_liste]);
    $existingIds = array_column($existingContacts, 'id_contact');
    
    $addedCount = 0;
    $alreadyExists = 0;
    
    foreach ($selectedContacts as $id_contact) {
        if (!in_array($id_contact, $existingIds)) {
            try {
                $db->insert('liste_contact', [
                    'id_liste' => $id_liste,
                    'id_contact' => $id_contact
                ]);
                $addedCount++;
            } catch (Exception $e) {
                // Erreur silencieuse
            }
        } else {
            $alreadyExists++;
        }
    }
    
    if ($addedCount > 0) {
        $message = "$addedCount contact(s) ajouté(s) à la liste";
        if ($alreadyExists > 0) {
            $message .= " ($alreadyExists déjà présent(s))";
        }
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Aucun contact ajouté (ils sont peut-être déjà dans la liste)']);
    }
    exit;
}

// ============================================
// TRAITEMENT DE L'IMPORT CSV (AJAX) - VERSION CORRIGÉE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // Vider le buffer et définir le header JSON
    ob_clean();
    header('Content-Type: application/json');
    
    // Log pour debug
    error_log("=== IMPORT CSV DEMARRÉ ===");
    error_log("POST: " . print_r($_POST, true));
    error_log("FILES: " . print_r($_FILES, true));
    
    try {
        $id_liste = isset($_POST['id_liste']) ? $_POST['id_liste'] : null;
        $separator = isset($_POST['separator']) ? $_POST['separator'] : ';';
        
        if (!$id_liste) {
            echo json_encode(['success' => false, 'error' => 'Veuillez sélectionner une liste']);
            exit;
        }
        
        $listeExists = $db->select('liste', ['id_liste' => $id_liste, 'id_compte' => $idCompte]);
        if (empty($listeExists)) {
            echo json_encode(['success' => false, 'error' => 'Liste invalide']);
            exit;
        }
        
        // Vérifier le fichier
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['csv_file']['error'] ?? 'Aucun fichier';
            error_log("Erreur upload: code " . $errorCode);
            echo json_encode(['success' => false, 'error' => 'Erreur lors du téléchargement du fichier (code: ' . $errorCode . ')']);
            exit;
        }
        
        $file = $_FILES['csv_file'];
        error_log("Fichier: " . $file['name'] . ", taille: " . $file['size'] . " bytes");
        
        // Vérifier la taille (5MB max)
        $maxSize = 5 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            echo json_encode(['success' => false, 'error' => 'Fichier trop volumineux (max 5MB)']);
            exit;
        }
        
        // Vérifier l'extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            echo json_encode(['success' => false, 'error' => 'Format non supporté. Utilisez un fichier CSV']);
            exit;
        }
        
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            echo json_encode(['success' => false, 'error' => 'Impossible d\'ouvrir le fichier']);
            exit;
        }
        
        // Détection automatique du séparateur
        $firstLine = fgets($handle);
        rewind($handle);
        error_log("Première ligne: " . $firstLine);
        
        $separators = [';', ',', "\t", '|'];
        $separator = ';';
        $maxCount = 0;
        
        foreach ($separators as $testSep) {
            $count = substr_count($firstLine, $testSep);
            error_log("Séparateur '$testSep' trouvé $count fois");
            if ($count > $maxCount) {
                $maxCount = $count;
                $separator = $testSep;
            }
        }
        error_log("Séparateur détecté: '" . $separator . "'");
        
        // Lire les en-têtes
        $headers = fgetcsv($handle, 0, $separator);
        if (!$headers) {
            echo json_encode(['success' => false, 'error' => 'Format CSV invalide: impossible de lire les en-têtes']);
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
        error_log("Mapping: " . print_r($mapping, true));
        
        // Vérifier les colonnes requises
        if ($mapping['nom'] === false && $mapping['prenom'] === false) {
            echo json_encode(['success' => false, 'error' => 'Colonnes requises manquantes: nom ou prenom']);
            exit;
        }
        
        $importCount = 0;
        $createdCount = 0;
        $existingCount = 0;
        $errors = [];
        $rowNumber = 1;
        
        while (($data = fgetcsv($handle, 0, $separator)) !== false) {
            $rowNumber++;
            
            // Nettoyer la ligne
            $data = array_map('trim', $data);
            
            // Extraire les données
            $prenom = $mapping['prenom'] !== false ? trim($data[$mapping['prenom']] ?? '') : '';
            $nom = $mapping['nom'] !== false ? trim($data[$mapping['nom']] ?? '') : '';
            $email = $mapping['email'] !== false ? trim($data[$mapping['email']] ?? '') : '';
            $telephone = $mapping['telephone'] !== false ? trim($data[$mapping['telephone']] ?? '') : '';
            $dateNaissance = $mapping['date_naissance'] !== false ? trim($data[$mapping['date_naissance']] ?? '') : '';
            $ville = $mapping['ville'] !== false ? trim($data[$mapping['ville']] ?? '') : '';
            $adresse = $mapping['adresse'] !== false ? trim($data[$mapping['adresse']] ?? '') : '';
            $code_postal = $mapping['code_postal'] !== false ? trim($data[$mapping['code_postal']] ?? '') : '';
            $pays = $mapping['pays'] !== false ? trim($data[$mapping['pays']] ?? 'France') : 'France';
            
            if (empty($nom) && empty($prenom) && empty($email)) {
                continue;
            }
            
            // Convertir la date si présente
            if (!empty($dateNaissance)) {
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
                    $timestamp = strtotime($dateNaissance);
                    if ($timestamp !== false) {
                        $dateNaissance = date('Y-m-d', $timestamp);
                    } else {
                        $dateNaissance = null;
                    }
                }
            }
            
            // Vérifier l'âge
            if (!empty($dateNaissance)) {
                if (!verifierAge($dateNaissance, 18)) {
                    $errors[] = "Ligne $rowNumber: Âge minimum 18 ans requis";
                    continue;
                }
            }
            
            $contactId = null;
            $isExisting = false;
            
            // Vérifier si le contact existe déjà par email
            if (!empty($email)) {
                $existingContacts = $db->select('contact', [
                    'id_compte' => $idCompte,
                    'email' => $email
                ]);
                if (!empty($existingContacts)) {
                    $contactId = $existingContacts[0]['id_contact'];
                    $isExisting = true;
                }
            }
            
            // Si pas trouvé par email, essayer par nom + prénom
            if (!$contactId && !empty($nom) && !empty($prenom)) {
                $existingContacts = $db->select('contact', [
                    'id_compte' => $idCompte,
                    'nom' => $nom,
                    'prenom' => $prenom
                ]);
                if (!empty($existingContacts)) {
                    $contactId = $existingContacts[0]['id_contact'];
                    $isExisting = true;
                }
            }
            
            // Si le contact n'existe pas, le créer
            if (!$contactId) {
                $telephoneFormatted = null;
                if (!empty($telephone)) {
                    if (substr($telephone, 0, 3) === '261') {
                        $telephoneFormatted = $telephone;
                    } else {
                        $telephoneFormatted = formatPhoneNumber($telephone);
                    }
                }
                
                $contactData = [
                    'id_compte' => $idCompte,
                    'nom' => $nom,
                    'prenom' => $prenom,
                    'email' => !empty($email) ? $email : null,
                    'telephone' => $telephoneFormatted,
                    'adresse' => !empty($adresse) ? $adresse : null,
                    'ville' => !empty($ville) ? $ville : null,
                    'code_postal' => !empty($code_postal) ? $code_postal : null,
                    'pays' => !empty($pays) ? $pays : 'France',
                    'date_naissance' => !empty($dateNaissance) ? $dateNaissance : null,
                    'date_inscription' => date('Y-m-d H:i:s')
                ];
                
                try {
                    $contactId = $db->insertAndGetId('contact', $contactData);
                    if ($contactId) {
                        $createdCount++;
                    } else {
                        $errors[] = "Ligne $rowNumber: Impossible de créer le contact";
                        continue;
                    }
                } catch (Exception $e) {
                    error_log("Erreur création contact ligne $rowNumber: " . $e->getMessage());
                    $errors[] = "Ligne $rowNumber: Erreur création contact";
                    continue;
                }
            }
            
            // Ajouter le contact à la liste
            if ($contactId) {
                $existingInList = $db->select('liste_contact', [
                    'id_liste' => $id_liste,
                    'id_contact' => $contactId
                ]);
                
                if (empty($existingInList)) {
                    try {
                        $db->insert('liste_contact', [
                            'id_liste' => $id_liste,
                            'id_contact' => $contactId
                        ]);
                        $importCount++;
                        
                        if ($isExisting) {
                            $existingCount++;
                        }
                    } catch (Exception $e) {
                        error_log("Erreur ajout liste ligne $rowNumber: " . $e->getMessage());
                        $errors[] = "Ligne $rowNumber: Erreur ajout liste";
                    }
                } else {
                    $errors[] = "Ligne $rowNumber: Contact déjà dans la liste";
                }
            }
        }
        
        fclose($handle);
        
        error_log("Import terminé: $importCount importés, $createdCount créés, $existingCount existants, " . count($errors) . " erreurs");
        
        // Construire le message de retour
        if ($importCount > 0) {
            $message = "$importCount contact(s) importé(s) dans la liste";
            if ($createdCount > 0) {
                $message .= " ($createdCount nouveau(x) créé(s))";
            }
            if ($existingCount > 0) {
                $message .= " ($existingCount existant(s) ajouté(s))";
            }
            if (!empty($errors)) {
                $message .= count($errors) . " non importé (s)";
            }
            echo json_encode(['success' => true, 'message' => $message, 'imported' => $importCount]);
        } else {
            $errorMsg = "Aucun contact importé.";
            if (!empty($errors)) {
                $errorMsg .= " Erreurs: " . implode('; ', array_slice($errors, 0, 5));
            } else {
                $errorMsg .= " Vérifiez le format de votre fichier CSV.";
            }
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
        
    } catch (Exception $e) {
        error_log("ERREUR IMPORT: " . $e->getMessage());
        error_log("TRACE: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DU RENOMMAGE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_rename'])) {
    $id_liste = $_POST['id_liste'];
    $nouveau_nom = trim($_POST['nom_liste']);
    
    if (!empty($id_liste) && !empty($nouveau_nom)) {
        try {
            $db->update('liste', ['nom_liste' => $nouveau_nom], ['id_liste' => $id_liste, 'id_compte' => $idCompte]);
            $_SESSION['flash_message'] = "Liste renommée avec succès";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Erreur lors du renommage";
        }
    } else {
        $_SESSION['flash_error'] = "Le nom ne peut pas être vide";
    }
    header('Location: index.php?page=listes/index');
    exit();
}

// ============================================
// TRAITEMENT DU VIDAGE DE LISTE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_clear'])) {
    $id_liste = $_POST['id_liste'];
    
    if (!empty($id_liste)) {
        try {
            $db->deleteWithConditions('liste_contact', ['id_liste' => $id_liste]);
            $_SESSION['flash_message'] = "La liste a été vidée avec succès";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Erreur lors du vidage";
        }
    } else {
        $_SESSION['flash_error'] = "ID de liste invalide";
    }
    header('Location: index.php?page=listes/index');
    exit();
}

// ============================================
// TRAITEMENT DE LA SUPPRESSION DE LISTE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete'])) {
    $id_liste = $_POST['id_liste'];
    
    if (!empty($id_liste)) {
        try {
            $db->deleteWithConditions('liste_contact', ['id_liste' => $id_liste]);
            $db->delete('liste', $id_liste, 'id_liste');
            $_SESSION['flash_message'] = "La liste a été supprimée avec succès";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Erreur lors de la suppression";
        }
    } else {
        $_SESSION['flash_error'] = "ID de liste invalide";
    }
    header('Location: index.php?page=listes/index');
    exit();
}

// ============================================
// RÉCUPÉRATION DES LISTES
// ============================================
$listes = $db->select('liste', ['id_compte' => $idCompte], '*', 'date_creation.desc');

foreach ($listes as $key => $listeItem) {
    $contactsCount = $db->select('liste_contact', ['id_liste' => $listeItem['id_liste']]);
    $listes[$key]['nb_contacts'] = count($contactsCount);
}

// Récupérer tous les contacts pour la modale d'ajout
$tousContacts = $db->select('contact', ['id_compte' => $idCompte], '*', 'nom.asc');

$totalListes = count($listes);

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
    <title>Mes listes - <?= APP_NAME ?></title>
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
        .liste-row.hidden-row { display: none; }
        .modal-add-liste {
            transition: all 0.3s ease;
            transform: scale(0.95);
            opacity: 0;
        }
        .modal-add-liste.modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        
        .contact-item.hide {
            display: none;
        }
        .dropdown-search-input {
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }
        
        .file-upload-wrapper {
            position: relative;
            overflow: hidden;
        }
        .file-upload-wrapper input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .file-upload-wrapper .file-info {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .file-upload-wrapper .file-info:hover {
            border-color: #8b5cf6;
            background: #f5f3ff;
        }
        .file-upload-wrapper .file-info .file-name {
            font-size: 14px;
            color: #1f2937;
        }
        .file-upload-wrapper .file-info .file-size {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Mes listes</h1>
            <p class="text-gray-500">Organisez vos contacts par groupes</p>
        </div>
        <button type="button" onclick="openAddListeModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-plus mr-2"></i>Nouvelle liste
        </button>
    </div>

    <?php if ($flashMessage): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded"><?= htmlspecialchars($flashMessage) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div><span class="text-gray-500">Total des listes</span><span class="text-2xl font-bold text-gray-800 ml-2" id="totalListesCount"><?= $totalListes ?></span></div>
                <div class="text-gray-400"><i class="fas fa-list text-2xl"></i></div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 md:col-span-2">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="searchInput" placeholder="Rechercher par nom de liste..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <div class="mt-2 text-right"><span id="filteredCount" class="text-xs text-gray-500"></span></div>
        </div>
    </div>

    <?php if (empty($listes)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <i class="fas fa-list text-5xl text-gray-300 mb-4"></i>
            <p class="text-gray-500">Aucune liste pour le moment.</p>
            <button onclick="openAddListeModal()" class="text-blue-600 mt-2 inline-block">Créer votre première liste →</button>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom de la liste</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contacts</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="listesTableBody">
                        <?php foreach ($listes as $liste): ?>
                            <tr class="liste-row hover:bg-gray-50 transition" data-name="<?= strtolower(htmlspecialchars($liste['nom_liste'])) ?>" data-id="<?= $liste['id_liste'] ?>">
                                <td class="px-6 py-4"><div class="flex items-center"><div class="bg-blue-100 rounded-full p-2 mr-3"><i class="fas fa-list text-blue-600 text-sm"></i></div><?= htmlspecialchars($liste['nom_liste']) ?></div></td>
                                <td class="px-6 py-4"><?= $liste['nb_contacts'] ?> contact(s)</td>
                                <td class="px-6 py-4"><?= date('d/m/Y', strtotime($liste['date_creation'])) ?></td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <button type="button" onclick="openRenameModal(this)" data-id="<?= $liste['id_liste'] ?>" data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>" class="text-yellow-600 hover:text-yellow-800" title="Renommer"><i class="fas fa-edit"></i></button>
                                    <button type="button" onclick="openAddContactToListModal(this)" data-id="<?= $liste['id_liste'] ?>" data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>" class="text-blue-600 hover:text-blue-800" title="Ajouter un contact"><i class="fas fa-user-plus"></i></button>
                                    <a href="index.php?page=listes/details&id=<?= $liste['id_liste'] ?>" class="text-green-600 hover:text-green-800" title="Voir les contacts"><i class="fas fa-eye"></i></a>
                                    <button type="button" onclick="openImportModal(this)" data-id="<?= $liste['id_liste'] ?>" data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>" class="text-purple-600 hover:text-purple-800" title="Importer CSV"><i class="fas fa-file-import"></i></button>
                                    <button type="button" onclick="openClearModal(this)" data-id="<?= $liste['id_liste'] ?>" data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>" data-count="<?= $liste['nb_contacts'] ?>" class="text-orange-600 hover:text-orange-800" title="Vider"><i class="fas fa-trash-alt"></i></button>
                                    <button type="button" onclick="openDeleteModal(this)" data-id="<?= $liste['id_liste'] ?>" data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>" class="text-red-600 hover:text-red-800" title="Supprimer"><i class="fas fa-times-circle"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="noResultRow" style="display: none;"><td colspan="4" class="px-6 py-8 text-center text-gray-500"><i class="fas fa-search text-4xl mb-2 block"></i>Aucune liste ne correspond à votre recherche.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================ -->
<!-- MODAL D'AJOUT DE LISTE -->
<!-- ============================================ -->
<div id="addListeModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-add-liste">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                        <i class="fas fa-list text-blue-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Créer une nouvelle liste</h3>
                </div>
                <button type="button" onclick="closeAddListeModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="addListeForm" method="POST">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la liste *</label>
                    <input type="text" name="nom_liste" id="nom_liste" required 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                           placeholder="Ex: Newsletter, Clients VIP, Prospects...">
                    <p class="text-xs text-gray-500 mt-1">Choisissez un nom explicite pour votre liste</p>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeAddListeModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                        Créer la liste
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL AJOUTER CONTACT À UNE LISTE -->
<!-- ============================================ -->
<div id="addContactToListModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] flex flex-col">
        <div class="p-6 border-b">
            <div class="flex justify-between items-center">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                        <i class="fas fa-user-plus text-blue-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Ajouter des contacts</h3>
                </div>
                <button type="button" onclick="closeAddContactToListModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <p class="text-gray-500 mt-1">Ajouter des contacts à la liste : <strong id="addContactListName"></strong></p>
        </div>
        
        <div class="p-6 flex-1 overflow-y-auto">
            <div class="mb-4">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    <input type="text" id="searchContactInput" placeholder="Rechercher un contact par nom, email ou téléphone..." 
                           class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div class="border rounded-lg overflow-hidden">
                <div class="max-h-96 overflow-y-auto" id="contactsListContainer">
                    <div id="loadingContacts" class="p-4 text-center text-gray-500">
                        <i class="fas fa-spinner fa-spin mr-2"></i>Chargement des contacts...
                    </div>
                </div>
            </div>
            
            <div class="mt-4 flex justify-between items-center">
                <div>
                    <span class="text-sm text-gray-500">
                        <span id="selectedCount">0</span> contact(s) sélectionné(s)
                    </span>
                </div>
                <div class="flex space-x-2">
                    <button type="button" onclick="selectAllContacts()" class="text-sm text-blue-600 hover:text-blue-800">
                        <i class="fas fa-check-double"></i> Tout sélectionner
                    </button>
                    <button type="button" onclick="selectNoneContacts()" class="text-sm text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i> Tout désélectionner
                    </button>
                </div>
            </div>
        </div>
        
        <div class="p-6 border-t bg-gray-50 flex justify-end space-x-2">
            <button type="button" onclick="closeAddContactToListModal()" 
                    class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Annuler
            </button>
            <button type="button" onclick="submitAddContactsToList()" id="submitAddContactsBtn"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                <i class="fas fa-plus mr-2"></i>Ajouter les contacts sélectionnés
            </button>
        </div>
    </div>
</div>

<!-- MODAL RENOMMER -->
<div id="renameModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6">
            <h3 class="text-lg font-bold mb-4">Renommer la liste</h3>
            <form method="POST">
                <input type="hidden" name="action_rename" value="1">
                <input type="hidden" name="id_liste" id="renameListId">
                <div class="mb-4"><label class="block text-sm font-medium text-gray-700 mb-1">Nouveau nom</label><input type="text" name="nom_liste" id="renameListName" required class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                <div class="flex justify-end space-x-2"><button type="button" onclick="closeRenameModal()" class="px-4 py-2 border rounded-lg">Annuler</button><button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg">Renommer</button></div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL VIDER -->
<div id="clearModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6 text-center">
            <h3 class="text-xl font-bold mb-2">Vider la liste</h3>
            <p class="text-gray-500 mb-4">Êtes-vous sûr de vouloir vider la liste <strong id="clearListName"></strong> ?</p>
            <form method="POST"><input type="hidden" name="action_clear" value="1"><input type="hidden" name="id_liste" id="clearListId"><div class="flex justify-center space-x-2"><button type="button" onclick="closeClearModal()" class="px-4 py-2 border rounded-lg">Annuler</button><button type="submit" class="px-4 py-2 bg-orange-600 text-white rounded-lg">Vider</button></div></form>
        </div>
    </div>
</div>

<!-- MODAL SUPPRIMER -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6 text-center">
            <h3 class="text-xl font-bold mb-2">Supprimer la liste</h3>
            <p class="text-gray-500 mb-4">Êtes-vous sûr de vouloir supprimer la liste <strong id="deleteListName"></strong> ?<br>Les contacts ne seront pas supprimés.</p>
            <form method="POST"><input type="hidden" name="action_delete" value="1"><input type="hidden" name="id_liste" id="deleteListId"><div class="flex justify-center space-x-2"><button type="button" onclick="closeDeleteModal()" class="px-4 py-2 border rounded-lg">Annuler</button><button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg">Supprimer</button></div></form>
        </div>
    </div>
</div>

<!-- MODAL IMPORT CSV -->
<div id="importModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold">Importer des contacts</h3>
                <button onclick="closeImportModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
            </div>
            <div class="bg-blue-50 p-3 rounded mb-4 text-sm">
                <strong>Format attendu :</strong> 
                <span class="block mt-1 text-xs text-gray-600">Colonnes: prenom, nom, email, telephone, ville, adresse, code_postal, pays, date_naissance</span>
                <span class="block mt-1 text-xs text-gray-600">Séparateur: ; ou ,</span>
                <span class="block mt-1 text-xs text-gray-600">Date: YYYY-MM-DD ou DD/MM/YYYY</span>
                <span class="block mt-1 text-xs text-gray-600 text-red-500">Âge minimum: 18 ans</span>
            </div>
            <form id="importForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id_liste" id="importListId">
                <div class="mb-4"><label class="block text-sm font-medium text-gray-700 mb-1">Liste cible</label><span id="importListName" class="font-semibold"></span></div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fichier CSV</label>
                    <div class="file-upload-wrapper">
                        <div class="file-info" id="fileInfo">
                            <i class="fas fa-cloud-upload-alt text-2xl text-purple-500"></i>
                            <span class="file-name" id="fileName">Cliquez ou glissez votre fichier CSV ici</span>
                            <span class="file-size" id="fileSize"></span>
                        </div>
                        <input type="file" name="csv_file" accept=".csv" required id="csvFileInput">
                    </div>
                </div>
                <div class="mb-4"><label class="block text-sm font-medium text-gray-700 mb-1">Séparateur</label><select name="separator" class="w-full border border-gray-300 rounded-lg px-3 py-2"><option value=";">Point-virgule (;)</option><option value=",">Virgule (,)</option></select></div>
                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="closeImportModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">Annuler</button>
                    <button type="submit" id="importSubmitBtn" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
                        <i class="fas fa-upload mr-2"></i>Importer
                    </button>
                </div>
            </form>
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
// MODAL D'AJOUT DE LISTE
// ============================================
function openAddListeModal() {
    const modal = document.getElementById('addListeModal');
    const modalContent = modal.querySelector('.modal-add-liste');
    document.getElementById('addListeForm').reset();
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeAddListeModal() {
    const modal = document.getElementById('addListeModal');
    const modalContent = modal.querySelector('.modal-add-liste');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// ============================================
// MODAL AJOUTER CONTACT À UNE LISTE
// ============================================
let currentListId = null;
let allContacts = <?= json_encode($tousContacts) ?>;
let contactsNotInList = [];

async function openAddContactToListModal(button) {
    currentListId = button.getAttribute('data-id');
    const listName = button.getAttribute('data-name');
    document.getElementById('addContactListName').innerHTML = listName;
    
    document.getElementById('contactsListContainer').innerHTML = '<div class="p-4 text-center text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Chargement des contacts...</div>';
    document.getElementById('addContactToListModal').style.display = 'flex';
    
    try {
        const response = await fetch(`index.php?page=listes/details&id=${currentListId}&get_contacts=1`, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json();
        
        if (result.success && result.contacts_ids) {
            const existingIds = result.contacts_ids;
            contactsNotInList = allContacts.filter(c => !existingIds.includes(c.id_contact));
        } else {
            contactsNotInList = allContacts;
        }
        
        renderContactsList();
    } catch (error) {
        contactsNotInList = allContacts;
        renderContactsList();
    }
}

function renderContactsList() {
    const container = document.getElementById('contactsListContainer');
    const searchTerm = document.getElementById('searchContactInput')?.value.toLowerCase() || '';
    
    let filteredContacts = contactsNotInList.filter(contact => {
        const fullName = (contact.prenom + ' ' + contact.nom).toLowerCase();
        const email = (contact.email || '').toLowerCase();
        const telephone = (contact.telephone || '').toLowerCase();
        return fullName.includes(searchTerm) || email.includes(searchTerm) || telephone.includes(searchTerm);
    });
    
    if (filteredContacts.length === 0) {
        container.innerHTML = '<div class="p-4 text-center text-gray-500"><i class="fas fa-users"></i> Aucun contact disponible à ajouter</div>';
        document.getElementById('selectedCount').innerText = '0';
        return;
    }
    
    let html = '';
    filteredContacts.forEach(contact => {
        html += `
            <label class="contact-item flex items-center p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100">
                <input type="checkbox" name="selected_contact" value="${contact.id_contact}" class="contact-checkbox w-4 h-4 text-blue-600 rounded mr-3" onchange="updateContactSelectedCount()">
                <div class="flex-1">
                    <div class="font-medium text-gray-800">${escapeHtml(contact.prenom)} ${escapeHtml(contact.nom)}</div>
                    <div class="text-xs text-gray-500">
                        ${contact.email ? '<i class="fas fa-envelope mr-1"></i>' + escapeHtml(contact.email) : ''}
                        ${contact.telephone ? '<i class="fas fa-phone ml-2 mr-1"></i>' + escapeHtml(contact.telephone) : ''}
                    </div>
                </div>
            </label>
        `;
    });
    
    container.innerHTML = html;
    updateContactSelectedCount();
}

function updateContactSelectedCount() {
    const checkboxes = document.querySelectorAll('#contactsListContainer .contact-checkbox:checked');
    document.getElementById('selectedCount').innerText = checkboxes.length;
}

function selectAllContacts() {
    document.querySelectorAll('#contactsListContainer .contact-checkbox').forEach(cb => cb.checked = true);
    updateContactSelectedCount();
}

function selectNoneContacts() {
    document.querySelectorAll('#contactsListContainer .contact-checkbox').forEach(cb => cb.checked = false);
    updateContactSelectedCount();
}

function closeAddContactToListModal() {
    document.getElementById('addContactToListModal').style.display = 'none';
}

async function submitAddContactsToList() {
    const selectedContacts = Array.from(document.querySelectorAll('#contactsListContainer .contact-checkbox:checked')).map(cb => cb.value);
    
    if (selectedContacts.length === 0) {
        showToast('Veuillez sélectionner au moins un contact', 'warning');
        return;
    }
    
    const submitBtn = document.getElementById('submitAddContactsBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Ajout...';
    submitBtn.disabled = true;
    
    const formData = new FormData();
    formData.append('action_add_contacts_to_list', '1');
    formData.append('id_liste', currentListId);
    selectedContacts.forEach(id => formData.append('selected_contacts[]', id));
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            closeAddContactToListModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error, 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        showToast('Erreur réseau', 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

document.getElementById('searchContactInput')?.addEventListener('input', function() {
    renderContactsList();
});

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// ============================================
// AJOUT DE LISTE AJAX
// ============================================
document.getElementById('addListeForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action_add_liste', '1');
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Création...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            closeAddListeModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error, 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        showToast('Erreur réseau', 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// ============================================
// RECHERCHE
// ============================================
const searchInput = document.getElementById('searchInput');
const listeRows = document.querySelectorAll('.liste-row');
const noResultRow = document.getElementById('noResultRow');
const filteredCountSpan = document.getElementById('filteredCount');
const totalListesCount = parseInt(document.getElementById('totalListesCount')?.textContent || 0);

function filterListes() {
    const searchTerm = searchInput.value.toLowerCase().trim();
    let visibleCount = 0;
    listeRows.forEach(row => {
        const name = row.getAttribute('data-name') || '';
        if (searchTerm === '' || name.includes(searchTerm)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    if (visibleCount === 0 && listeRows.length > 0) noResultRow.style.display = '';
    else noResultRow.style.display = 'none';
    if (filteredCountSpan) filteredCountSpan.textContent = `${visibleCount} liste(s) affichée(s) sur ${totalListesCount}`;
}
if (searchInput) searchInput.addEventListener('input', filterListes);

// ============================================
// MODALS EXISTANTS
// ============================================
function openRenameModal(button) {
    document.getElementById('renameListId').value = button.getAttribute('data-id');
    document.getElementById('renameListName').value = button.getAttribute('data-name');
    document.getElementById('renameModal').style.display = 'flex';
}
function closeRenameModal() { document.getElementById('renameModal').style.display = 'none'; }

function openClearModal(button) {
    if (parseInt(button.getAttribute('data-count')) === 0) {
        showToast('Cette liste est déjà vide.', 'warning');
        return;
    }
    document.getElementById('clearListId').value = button.getAttribute('data-id');
    document.getElementById('clearListName').innerHTML = button.getAttribute('data-name');
    document.getElementById('clearModal').style.display = 'flex';
}
function closeClearModal() { document.getElementById('clearModal').style.display = 'none'; }

function openDeleteModal(button) {
    document.getElementById('deleteListId').value = button.getAttribute('data-id');
    document.getElementById('deleteListName').innerHTML = button.getAttribute('data-name');
    document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }

function openImportModal(button) {
    document.getElementById('importListId').value = button.getAttribute('data-id');
    document.getElementById('importListName').innerHTML = button.getAttribute('data-name');
    document.getElementById('importModal').style.display = 'flex';
}
function closeImportModal() { document.getElementById('importModal').style.display = 'none'; }

// ============================================
// GESTION DU FICHIER DANS LE MODAL IMPORT
// ============================================
document.getElementById('csvFileInput')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        
        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
        fileName.textContent = file.name;
        fileSize.textContent = `(${sizeMB} MB)`;
        fileInfo.style.borderColor = '#8b5cf6';
        fileInfo.style.background = '#f5f3ff';
        
        // Vérifier l'extension
        const extension = file.name.split('.').pop().toLowerCase();
        if (extension !== 'csv') {
            showToast('Veuillez sélectionner un fichier CSV', 'warning');
            this.value = '';
            resetFileInfo();
        }
        
        // Vérifier la taille
        if (file.size > 5 * 1024 * 1024) {
            showToast('Fichier trop volumineux (max 5MB)', 'warning');
            this.value = '';
            resetFileInfo();
        }
    }
});

function resetFileInfo() {
    const fileInfo = document.getElementById('fileInfo');
    const fileName = document.getElementById('fileName');
    const fileSize = document.getElementById('fileSize');
    fileName.textContent = 'Cliquez ou glissez votre fichier CSV ici';
    fileSize.textContent = '';
    fileInfo.style.borderColor = '#d1d5db';
    fileInfo.style.background = 'transparent';
}

// ============================================
// IMPORT CSV AJAX - Version avec logs améliorés
// ============================================
document.getElementById('importForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const fileInput = document.getElementById('csvFileInput');
    if (!fileInput.files || fileInput.files.length === 0) {
        showToast('Veuillez sélectionner un fichier CSV', 'warning');
        return;
    }
    
    const formData = new FormData(this);
    const submitBtn = document.getElementById('importSubmitBtn');
    const originalText = submitBtn.innerHTML;
    
    //LOG : Afficher les données du formulaire
    console.log('=== IMPORT CSV ===');
    for (let pair of formData.entries()) {
        console.log(pair[0] + ': ' + pair[1]);
    }
    
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Import en cours...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 
                'X-Requested-With': 'XMLHttpRequest'
            }, 
            body: formData 
        });
        
        // LOG : Vérifier la réponse
        console.log('Status HTTP:', response.status);
        
        // Lire la réponse brute
        const textResponse = await response.text();
        console.log('Réponse brute:', textResponse);
        
        let result;
        try {
            result = JSON.parse(textResponse);
        } catch (parseError) {
            console.error('Erreur de parsing JSON:', parseError);
            console.error('Réponse reçue:', textResponse);
            showToast('Erreur de parsing: ' + textResponse.substring(0, 200), 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        if (result.success) {
            showToast(result.message, 'success');
            closeImportModal();
            setTimeout(() => window.location.reload(), 1500);
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

// Fermeture des modales
document.getElementById('addListeModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeAddListeModal(); });
document.getElementById('addContactToListModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeAddContactToListModal(); });
document.getElementById('renameModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeRenameModal(); });
document.getElementById('clearModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeClearModal(); });
document.getElementById('deleteModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeDeleteModal(); });
document.getElementById('importModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeImportModal(); });
document.addEventListener('keydown', e => { 
    if (e.key === 'Escape') { 
        closeAddListeModal(); 
        closeAddContactToListModal(); 
        closeRenameModal(); 
        closeClearModal(); 
        closeDeleteModal(); 
        closeImportModal(); 
    } 
});
</script>

</body>
</html>