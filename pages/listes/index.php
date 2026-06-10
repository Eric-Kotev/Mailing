<?php
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
// TRAITEMENT DE L'IMPORT CSV (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
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
    
    $file = $_FILES['csv_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Erreur lors du téléchargement du fichier']);
        exit;
    }
    
    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        echo json_encode(['success' => false, 'error' => 'Impossible d\'ouvrir le fichier']);
        exit;
    }
    
    $headers = fgetcsv($handle, 1000, $separator);
    if (!$headers) {
        echo json_encode(['success' => false, 'error' => 'Format CSV invalide']);
        exit;
    }
    
    $headers = array_map('trim', $headers);
    $headers = array_map('strtolower', $headers);
    
    $importCount = 0;
    $createdCount = 0;
    $existingCount = 0;
    $errors = [];
    $rowNumber = 1;
    
    while (($data = fgetcsv($handle, 1000, $separator)) !== FALSE) {
        $rowNumber++;
        
        $row = [];
        foreach ($headers as $index => $header) {
            $row[$header] = isset($data[$index]) ? trim($data[$index]) : '';
        }
        
        if (empty($row['email']) && empty($row['nom'])) {
            $errors[] = "Ligne $rowNumber: email ou nom requis";
            continue;
        }
        
        $contactId = null;
        $isExisting = false;
        
        if (!empty($row['email'])) {
            $existingContacts = $db->select('contact', [
                'id_compte' => $idCompte,
                'email' => $row['email']
            ]);
            if (!empty($existingContacts)) {
                $contactId = $existingContacts[0]['id_contact'];
                $isExisting = true;
            }
        }
        
        if (!$contactId) {
            $contactData = [
                'id_compte' => $idCompte,
                'nom' => $row['nom'] ?? '',
                'prenom' => $row['prenom'] ?? '',
                'email' => !empty($row['email']) ? $row['email'] : null,
                'telephone' => !empty($row['telephone']) ? $row['telephone'] : null,
                'adresse' => !empty($row['adresse']) ? $row['adresse'] : null,
                'ville' => !empty($row['ville']) ? $row['ville'] : null,
                'code_postal' => !empty($row['code_postal']) ? $row['code_postal'] : null,
                'pays' => !empty($row['pays']) ? $row['pays'] : 'France',
                'date_inscription' => date('Y-m-d H:i:s')
            ];
            
            try {
                $contactId = $db->insertAndGetId('contact', $contactData);
                if ($contactId) {
                    $createdCount++;
                } else {
                    $errors[] = "Ligne $rowNumber: impossible de créer le contact";
                    continue;
                }
            } catch (Exception $e) {
                $errors[] = "Ligne $rowNumber: erreur création";
                continue;
            }
        }
        
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
                    $errors[] = "Ligne $rowNumber: erreur ajout liste";
                }
            } else {
                $errors[] = "Ligne $rowNumber: contact déjà dans la liste";
            }
        }
    }
    
    fclose($handle);
    
    if ($importCount > 0) {
        $message = "$importCount contact(s) importé(s) dans la liste";
        if ($createdCount > 0) {
            $message .= " ($createdCount nouveau(x) créé(s))";
        }
        if ($existingCount > 0) {
            $message .= " ($existingCount existant(s) ajouté(s))";
        }
        echo json_encode(['success' => true, 'message' => $message, 'imported' => $importCount]);
    } else {
        if ($createdCount == 0 && $existingCount == 0) {
            echo json_encode(['success' => false, 'error' => 'Aucun contact importé. Vérifiez le format de votre fichier.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur lors de l\'importation']);
        }
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

$totalListes = count($listes);

// Récupérer les contacts blacklistés pour affichage
$blacklist = $db->select('blacklist');
$blacklistIds = [];
foreach ($blacklist as $b) {
    if (!empty($b['id_contact'])) {
        $blacklistIds[] = $b['id_contact'];
    }
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
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded"><?= $flashMessage ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded"><?= $flashError ?></div>
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
                                    <a href="index.php?page=listes/details&id=<?= $liste['id_liste'] ?>" class="text-green-600 hover:text-green-800" title="Voir ou ajouter un contact"><i class="fas fa-eye"></i></a>
                                    <button type="button" onclick="openImportModal(this)" data-id="<?= $liste['id_liste'] ?>" data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>" class="text-purple-600 hover:text-purple-800" title="Importer"><i class="fas fa-file-import"></i></button>
                                    <button type="button" onclick="openBlacklistModal()" class="text-red-600 hover:text-red-800" title="Gérer la blacklist"><i class="fas fa-ban"></i></button>
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
            <div class="flex justify-between items-center mb-4"><h3 class="text-xl font-bold">Importer des contacts</h3><button onclick="closeImportModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button></div>
            <div class="bg-blue-50 p-3 rounded mb-4 text-sm"><strong>Format attendu :</strong> nom, prenom, email, telephone (séparateur ; ou ,)</div>
            <form id="importForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id_liste" id="importListId">
                <div class="mb-4"><label class="block text-sm font-medium text-gray-700 mb-1">Liste cible</label><span id="importListName" class="font-semibold"></span></div>
                <div class="mb-4"><label class="block text-sm font-medium text-gray-700 mb-1">Fichier CSV</label><input type="file" name="csv_file" accept=".csv" required class="w-full border border-gray-300 rounded-lg px-3 py-2"></div>
                <div class="mb-4"><label class="block text-sm font-medium text-gray-700 mb-1">Séparateur</label><select name="separator" class="w-full border border-gray-300 rounded-lg px-3 py-2"><option value=";">Point-virgule (;)</option><option value=",">Virgule (,)</option></select></div>
                <div class="flex justify-end space-x-2"><button type="button" onclick="closeImportModal()" class="px-4 py-2 border rounded-lg">Annuler</button><button type="submit" id="importSubmitBtn" class="px-4 py-2 bg-purple-600 text-white rounded-lg">Importer</button></div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL BLACKLIST (Gestion des contacts bloqués) -->
<!-- ============================================ -->
<div id="blacklistModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full mx-4">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-red-100 p-2 rounded-full mr-3">
                        <i class="fas fa-ban text-red-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Gestion de la blacklist</h3>
                </div>
                <button onclick="closeBlacklistModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <p class="text-gray-500 mb-4">Les contacts bloqués ne recevront aucun message.</p>
            
            <div class="mb-4 relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="blacklistSearchInput" placeholder="Rechercher un contact bloqué..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prénom</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Téléphone</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date de blocage</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="blacklistTableBody">
                        <!-- Les contacts blacklistés seront chargés ici -->
                    </tbody>
                </table>
            </div>
            <div class="mt-4 text-right">
                <button onclick="closeBlacklistModal()" class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition">Fermer</button>
            </div>
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
// MODAL BLACKLIST
// ============================================
const blacklistIds = <?= json_encode($blacklistIds) ?>;

async function openBlacklistModal() {
    const modal = document.getElementById('blacklistModal');
    modal.style.display = 'flex';
    await loadBlacklistContacts();
}

function closeBlacklistModal() {
    document.getElementById('blacklistModal').style.display = 'none';
}

async function loadBlacklistContacts() {
    try {
        const response = await fetch('index.php?page=contacts/blacklist&action=get_blacklist');
        const result = await response.json();
        
        if (result.success && result.data) {
            displayBlacklistContacts(result.data);
        } else {
            document.getElementById('blacklistTableBody').innerHTML = '<tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">Aucun contact bloqué</td></tr>';
        }
    } catch (error) {
        console.error('Erreur:', error);
        document.getElementById('blacklistTableBody').innerHTML = '<tr><td colspan="6" class="px-6 py-8 text-center text-red-500">Erreur lors du chargement</td></tr>';
    }
}

function displayBlacklistContacts(contacts) {
    const tbody = document.getElementById('blacklistTableBody');
    const searchTerm = document.getElementById('blacklistSearchInput').value.toLowerCase();
    
    const filteredContacts = contacts.filter(contact => {
        return contact.nom?.toLowerCase().includes(searchTerm) ||
               contact.prenom?.toLowerCase().includes(searchTerm) ||
               contact.email?.toLowerCase().includes(searchTerm) ||
               contact.telephone?.toLowerCase().includes(searchTerm);
    });
    
    if (filteredContacts.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">Aucun contact bloqué trouvé</td></tr>';
        return;
    }
    
    tbody.innerHTML = filteredContacts.map(contact => `
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4">${escapeHtml(contact.nom || '')}</td>
            <td class="px-6 py-4">${escapeHtml(contact.prenom || '')}</td>
            <td class="px-6 py-4">${escapeHtml(contact.email || '-')}</td>
            <td class="px-6 py-4">${escapeHtml(contact.telephone || '-')}</td>
            <td class="px-6 py-4">${formatDate(contact.date_blocage)}</td>
            <td class="px-6 py-4 text-right">
                <button onclick="unblockContact(${contact.id_contact}, '${escapeHtml(contact.prenom)} ${escapeHtml(contact.nom)}')" 
                        class="text-green-600 hover:text-green-800" title="Débloquer">
                    <i class="fas fa-check-circle"></i> Débloquer
                </button>
            </td>
        </tr>
    `).join('');
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('fr-FR');
}

async function unblockContact(contactId, contactName) {
    if (!confirm(`Êtes-vous sûr de vouloir débloquer ${contactName} ?`)) return;
    
    try {
        const response = await fetch('index.php?page=contacts/blacklist&action=unblock', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ id_contact: contactId })
        });
        const result = await response.json();
        
        if (result.success) {
            showToast(`${contactName} a été débloqué avec succès`, 'success');
            loadBlacklistContacts();
        } else {
            showToast(result.error || 'Erreur lors du déblocage', 'error');
        }
    } catch (error) {
        showToast('Erreur réseau', 'error');
    }
}

document.getElementById('blacklistSearchInput')?.addEventListener('input', function() {
    const tbody = document.getElementById('blacklistTableBody');
    if (tbody && tbody.innerHTML) {
        const rows = tbody.querySelectorAll('tr');
        const searchTerm = this.value.toLowerCase();
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    }
});

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
// IMPORT CSV AJAX
// ============================================
document.getElementById('importForm').addEventListener('submit', async function(e) {
    e.preventDefault();
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

// Fermeture des modales
document.getElementById('addListeModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeAddListeModal(); });
document.getElementById('renameModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeRenameModal(); });
document.getElementById('clearModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeClearModal(); });
document.getElementById('deleteModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeDeleteModal(); });
document.getElementById('importModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeImportModal(); });
document.getElementById('blacklistModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeBlacklistModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeAddListeModal(); closeRenameModal(); closeClearModal(); closeDeleteModal(); closeImportModal(); closeBlacklistModal(); } });
</script>

</body>
</html>