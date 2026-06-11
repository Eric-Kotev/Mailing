<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer tous les contacts
$contacts = $db->select('contact', ['id_compte' => $idCompte], '*', 'date_inscription=order.desc');

// Récupérer les IDs des contacts blacklistés et leurs détails
$blacklistItems = $db->select('blacklist', [], '*');
$blacklistedIds = [];
$blacklistDetails = [];
foreach ($blacklistItems as $bl) {
    $blacklistedIds[] = $bl['id_contact'];
    $blacklistDetails[$bl['id_contact']] = $bl;
}

// Récupérer les champs personnalisés pour le modal
$customFields = getCustomFields($idCompte);

// Pré-calculer les valeurs des champs personnalisés pour tous les contacts
$contactsCustomValues = [];
foreach ($contacts as $contact) {
    $contactsCustomValues[$contact['id_contact']] = getContactCustomValues($contact['id_contact']);
}

$totalContacts = count($contacts);

// ============================================
// TRAITEMENT DE L'AJOUT DE CONTACT (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_contact']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $prenom = trim($_POST['prenom'] ?? '');
    $nom = trim($_POST['nom'] ?? '');
    
    if (empty($prenom) || empty($nom)) {
        echo json_encode(['success' => false, 'error' => 'Le prénom et le nom sont requis']);
        exit;
    }
    
    $data = [
        'id_compte' => $idCompte,
        'prenom' => $prenom,
        'nom' => $nom,
        'email' => !empty($_POST['email']) ? $_POST['email'] : null,
        'telephone' => !empty($_POST['telephone']) ? $_POST['telephone'] : null,
        'date_naissance' => !empty($_POST['date_naissance']) ? $_POST['date_naissance'] : null,
        'adresse' => !empty($_POST['adresse']) ? $_POST['adresse'] : null,
        'code_postal' => !empty($_POST['code_postal']) ? $_POST['code_postal'] : null,
        'ville' => !empty($_POST['ville']) ? $_POST['ville'] : null,
        'pays' => !empty($_POST['pays']) ? $_POST['pays'] : 'France'
    ];
    
    try {
        $contactId = $db->insertAndGetId('contact', $data);
        
        // Sauvegarder les champs personnalisés
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            saveContactCustomValues($contactId, $_POST['custom_fields']);
        }
        
        echo json_encode(['success' => true, 'message' => 'Contact ajouté avec succès']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DE LA MODIFICATION (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_contact']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $id = $_POST['id_contact'] ?? null;
    
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID contact manquant']);
        exit;
    }
    
    $data = [
        'prenom' => trim($_POST['prenom'] ?? ''),
        'nom' => trim($_POST['nom'] ?? ''),
        'email' => !empty($_POST['email']) ? $_POST['email'] : null,
        'telephone' => !empty($_POST['telephone']) ? $_POST['telephone'] : null,
        'date_naissance' => !empty($_POST['date_naissance']) ? $_POST['date_naissance'] : null,
        'adresse' => !empty($_POST['adresse']) ? $_POST['adresse'] : null,
        'code_postal' => !empty($_POST['code_postal']) ? $_POST['code_postal'] : null,
        'ville' => !empty($_POST['ville']) ? $_POST['ville'] : null,
        'pays' => !empty($_POST['pays']) ? $_POST['pays'] : 'France'
    ];
    
    try {
        $db->update('contact', $data, ['id_contact' => $id]);
        
        // Sauvegarder les champs personnalisés
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            saveContactCustomValues($id, $_POST['custom_fields']);
        }
        
        echo json_encode(['success' => true, 'message' => 'Contact modifié avec succès']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DE L'IMPORT CSV (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fichier']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
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
    
    $firstLine = fgets($handle);
    rewind($handle);
    $separator = (strpos($firstLine, ';') !== false) ? ';' : ',';
    
    $headers = fgetcsv($handle, 0, $separator);
    if (!$headers) {
        echo json_encode(['success' => false, 'error' => 'Format CSV invalide']);
        exit;
    }
    
    $headers = array_map('trim', $headers);
    $headers = array_map('strtolower', $headers);
    
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
    
    $importedCount = 0;
    $existingCount = 0;
    
    while (($row = fgetcsv($handle, 0, $separator)) !== false) {
        $prenom = $mapping['prenom'] !== false ? trim($row[$mapping['prenom']] ?? '') : '';
        $nom = $mapping['nom'] !== false ? trim($row[$mapping['nom']] ?? '') : '';
        $email = $mapping['email'] !== false ? trim($row[$mapping['email']] ?? '') : null;
        $telephone = $mapping['telephone'] !== false ? trim($row[$mapping['telephone']] ?? '') : null;
        
        if (empty($prenom) || empty($nom)) {
            continue;
        }
        
        $contactExists = false;
        
        if (!empty($email)) {
            $existing = $db->select('contact', ['id_compte' => $idCompte, 'email' => $email]);
            if (!empty($existing)) $contactExists = true;
        }
        
        if (!$contactExists && !empty($telephone)) {
            $existing = $db->select('contact', ['id_compte' => $idCompte, 'nom' => $nom, 'prenom' => $prenom, 'telephone' => $telephone]);
            if (!empty($existing)) $contactExists = true;
        }
        
        if ($contactExists) {
            $existingCount++;
            continue;
        }
        
        $data = [
            'id_compte' => $idCompte,
            'prenom' => $prenom,
            'nom' => $nom,
            'email' => $email,
            'telephone' => $telephone,
            'ville' => $mapping['ville'] !== false ? trim($row[$mapping['ville']] ?? '') : null,
            'adresse' => $mapping['adresse'] !== false ? trim($row[$mapping['adresse']] ?? '') : null,
            'code_postal' => $mapping['code_postal'] !== false ? trim($row[$mapping['code_postal']] ?? '') : null,
            'pays' => $mapping['pays'] !== false ? trim($row[$mapping['pays']] ?? 'France') : 'France',
            'date_naissance' => $mapping['date_naissance'] !== false ? trim($row[$mapping['date_naissance']] ?? '') : null
        ];
        
        try {
            $db->insert('contact', $data);
            $importedCount++;
        } catch (Exception $e) {
            // Silencieux
        }
    }
    fclose($handle);
    
    if ($importedCount > 0) {
        $message = "$importedCount contact(s) importé(s) avec succès.";
        if ($existingCount > 0) $message .= " $existingCount contact(s) existant(s) ignoré(s).";
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        if ($existingCount > 0) {
            echo json_encode(['success' => false, 'error' => "Aucun nouveau contact importé. $existingCount contact(s) existant(s) ignoré(s)."]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Aucun contact importé. Vérifiez le format de votre fichier.']);
        }
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
        .modal-add-contact, .modal-import-csv, .modal-edit-contact {
            transition: all 0.3s ease;
            transform: scale(0.95);
            opacity: 0;
        }
        .modal-add-contact.modal-show, .modal-import-csv.modal-show, .modal-edit-contact.modal-show {
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
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
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label><input type="text" name="prenom" id="add_prenom" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label><input type="text" name="nom" id="add_nom" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Email</label><input type="email" name="email" id="add_email" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label><input type="tel" name="telephone" id="add_telephone" placeholder="33612345678" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label><input type="date" name="date_naissance" id="add_date_naissance" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Ville</label><input type="text" name="ville" id="add_ville" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Code postal</label><input type="text" name="code_postal" id="add_code_postal" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Pays</label><input type="text" name="pays" id="add_pays" value="France" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                </div>
                <div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label><textarea name="adresse" id="add_adresse" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea></div>
                
                <!-- Champs personnalisés pour l'ajout -->
                <?php if (!empty($customFields)): ?>
                <div class="mt-6 pt-4 border-t border-gray-200">
                    <h3 class="text-md font-semibold text-gray-700 mb-3">Informations supplémentaires</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="addCustomFieldsContainer">
                        <?php foreach ($customFields as $field): ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <?= htmlspecialchars($field['field_label']) ?>
                                    <?php if ($field['is_required']): ?><span class="text-red-500">*</span><?php endif; ?>
                                </label>
                                <?php if ($field['field_type'] === 'textarea'): ?>
                                    <textarea name="custom_fields[<?= $field['field_name'] ?>]" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></textarea>
                                <?php elseif ($field['field_type'] === 'select' && !empty($field['field_options'])): 
                                    $options = explode('|', $field['field_options']);
                                ?>
                                    <select name="custom_fields[<?= $field['field_name'] ?>]" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                        <option value="">-- Sélectionner --</option>
                                        <?php foreach ($options as $opt): ?>
                                            <option value="<?= htmlspecialchars(trim($opt)) ?>"><?= htmlspecialchars(trim($opt)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($field['field_type'] === 'date'): ?>
                                    <input type="date" name="custom_fields[<?= $field['field_name'] ?>]" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                <?php elseif ($field['field_type'] === 'number'): ?>
                                    <input type="number" name="custom_fields[<?= $field['field_name'] ?>]" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                <?php else: ?>
                                    <input type="text" name="custom_fields[<?= $field['field_name'] ?>]" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
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
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Email</label><input type="email" name="email" id="edit_email" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label><input type="tel" name="telephone" id="edit_telephone" placeholder="33612345678" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label><input type="date" name="date_naissance" id="edit_date_naissance" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Ville</label><input type="text" name="ville" id="edit_ville" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Code postal</label><input type="text" name="code_postal" id="edit_code_postal" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">Pays</label><input type="text" name="pays" id="edit_pays" value="France" class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                </div>
                <div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label><textarea name="adresse" id="edit_adresse" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea></div>
                
                <!-- Champs personnalisés dynamiques -->
                <div class="mt-6 pt-4 border-t border-gray-200" id="customFieldsSection">
                    <h3 class="text-md font-semibold text-gray-700 mb-3">Informations supplémentaires</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="editCustomFieldsContainer"></div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeEditContactModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Annuler</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">Enregistrer</button>
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
                    <li><i class="fas fa-check-circle mr-2"></i> Colonnes requises : <strong>prenom, nom</strong></li>
                    <li><i class="fas fa-check-circle mr-2"></i> Colonnes optionnelles : email, telephone, ville, adresse, code_postal, pays, date_naissance</li>
                    <li><i class="fas fa-check-circle mr-2"></i> Séparateur : point-virgule (;) ou virgule (,)</li>
                    <li><i class="fas fa-info-circle mr-2"></i> Les contacts déjà existants sont ignorés</li>
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

// Définition des champs personnalisés depuis PHP
const customFieldsConfig = <?= json_encode($customFields) ?>;

// ============================================
// MODAL D'AJOUT
// ============================================
function openAddContactModal() {
    const modal = document.getElementById('addContactModal');
    const modalContent = modal.querySelector('.modal-add-contact');
    document.getElementById('addContactForm').reset();
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
// MODAL DE MODIFICATION
// ============================================
async function openEditContactModal(contactId) {
    const modal = document.getElementById('editContactModal');
    const modalContent = modal.querySelector('.modal-edit-contact');
    
    try {
        const response = await fetch(`index.php?page=contacts/get_contact&id=${contactId}`);
        const contact = await response.json();
        
        if (contact.error) {
            showToast(contact.error, 'error');
            return;
        }
        
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
        
        if (customFieldsConfig.length > 0) {
            document.getElementById('customFieldsSection').style.display = 'block';
            
            for (const field of customFieldsConfig) {
                const currentValue = contact.custom_values?.[field.field_name]?.value || '';
                const required = field.is_required ? '<span class="text-red-500">*</span>' : '';
                
                let fieldHtml = '<div><label class="block text-sm font-medium text-gray-700 mb-1">' + escapeHtml(field.field_label) + ' ' + required + '</label>';
                
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
            document.getElementById('customFieldsSection').style.display = 'none';
        }
        
        modal.style.display = 'flex';
        setTimeout(() => modalContent.classList.add('modal-show'), 10);
    } catch (error) {
        console.error(error);
        showToast('Erreur lors du chargement du contact', 'error');
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
        const response = await fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            closeAddContactModal();
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

// Modification contact
document.getElementById('editContactForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Envoi...';
    submitBtn.disabled = true;
    try {
        const response = await fetch(window.location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            closeEditContactModal();
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
        showToast('Erreur réseau', 'error');
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
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddContactModal();
        closeEditContactModal();
        closeImportModal();
        closeUnblacklistModal();
        closeModal();
    }
});
</script>

</body>
</html>