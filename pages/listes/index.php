<?php
// Désactiver le cache pour cette page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

global $db;

$idCompte = $_SESSION['user_id'];

// ============================================
// TRAITEMENT DE L'IMPORT CSV
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $id_liste = isset($_POST['id_liste']) ? $_POST['id_liste'] : null;
    $separator = isset($_POST['separator']) ? $_POST['separator'] : ';';
    
    if (!$id_liste) {
        $_SESSION['flash_error'] = "Veuillez sélectionner une liste.";
        header('Location: index.php?page=listes/index');
        exit();
    }
    
    $listeExists = $db->select('liste', ['id_liste' => $id_liste, 'id_compte' => $idCompte]);
    if (empty($listeExists)) {
        $_SESSION['flash_error'] = "Liste invalide.";
        header('Location: index.php?page=listes/index');
        exit();
    }
    
    $file = $_FILES['csv_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_error'] = "Erreur lors du téléchargement du fichier.";
        header('Location: index.php?page=listes/index');
        exit();
    }
    
    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        $_SESSION['flash_error'] = "Impossible d'ouvrir le fichier.";
        header('Location: index.php?page=listes/index');
        exit();
    }
    
    $headers = fgetcsv($handle, 1000, $separator);
    if (!$headers) {
        $_SESSION['flash_error'] = "Format CSV invalide.";
        header('Location: index.php?page=listes/index');
        exit();
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
                    $errors[] = "Ligne $rowNumber: impossible de créer le contact " . ($row['email'] ?: $row['nom']);
                    continue;
                }
            } catch (Exception $e) {
                $errors[] = "Ligne $rowNumber: erreur création - " . $e->getMessage();
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
                    $errors[] = "Ligne $rowNumber: erreur ajout liste - " . $e->getMessage();
                }
            } else {
                $errors[] = "Ligne $rowNumber: contact déjà dans la liste (" . ($row['email'] ?: $row['nom']) . ")";
            }
        }
    }
    
    fclose($handle);
    
    if ($importCount > 0) {
        $message = "$importCount contact(s) importé(s) dans la liste !";
        if ($createdCount > 0) {
            $message .= " ($createdCount nouveau(x) contact(s) créé(s))";
        }
        if ($existingCount > 0) {
            $message .= " ($existingCount contact(s) existant(s) ajouté(s))";
        }
        $_SESSION['flash_message'] = $message;
    } else {
        $_SESSION['flash_error'] = "Aucun contact importé.";
    }
    
    if (!empty($errors)) {
        $errorMsg = count($errors) . " erreur(s): " . implode(', ', array_slice($errors, 0, 3));
        if (count($errors) > 3) {
            $errorMsg .= "...";
        }
        $_SESSION['flash_error'] = ($_SESSION['flash_error'] ? $_SESSION['flash_error'] . " / " : "") . $errorMsg;
    }
    
    header('Location: index.php?page=listes/index');
    exit();
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
            $_SESSION['flash_message'] = "Liste renommée avec succès !";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Erreur lors du renommage: " . $e->getMessage();
        }
    } else {
        $_SESSION['flash_error'] = "Le nom ne peut pas être vide.";
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
            $_SESSION['flash_message'] = "La liste a été vidée avec succès !";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Erreur lors du vidage: " . $e->getMessage();
        }
    } else {
        $_SESSION['flash_error'] = "ID de liste invalide.";
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
            $_SESSION['flash_message'] = "La liste a été supprimée avec succès !";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Erreur lors de la suppression: " . $e->getMessage();
        }
    } else {
        $_SESSION['flash_error'] = "ID de liste invalide.";
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
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes listes - <?= APP_NAME ?></title>
    <style>
        /* Toast notification */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideInRight 0.3s ease-out;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
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
    </style>
</head>
<body>

<div class="space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Mes listes</h1>
            <p class="text-gray-500">Organisez vos contacts par groupes</p>
        </div>
        <a href="index.php?page=listes/ajouter" 
           class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-plus mr-2"></i>Nouvelle liste
        </a>
    </div>

    <!-- Messages flash -->
    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded">
            <i class="fas fa-check-circle mr-2"></i> <?= $_SESSION['flash_message'] ?>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded">
            <i class="fas fa-exclamation-circle mr-2"></i> <?= $_SESSION['flash_error'] ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <!-- Statistiques + Recherche -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-500">Total des listes</span>
                    <span class="text-2xl font-bold text-gray-800 ml-2" id="totalListesCount"><?= $totalListes ?></span>
                </div>
                <div class="text-gray-400">
                    <i class="fas fa-list text-2xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 md:col-span-2">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="searchInput" 
                       placeholder="Rechercher par nom de liste..."
                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
            </div>
            <div class="mt-2 text-right">
                <span id="filteredCount" class="text-xs text-gray-500"></span>
            </div>
        </div>
    </div>

    <!-- Liste compacte des listes -->
    <?php if (empty($listes)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <i class="fas fa-list text-5xl text-gray-300 mb-4"></i>
            <p class="text-gray-500">Aucune liste pour le moment.</p>
            <a href="index.php?page=listes/ajouter" class="text-blue-600 mt-2 inline-block">
                Créer votre première liste →
            </a>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom de la liste</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contacts</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date de création</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="listesTableBody" class="bg-white divide-y divide-gray-200">
                        <?php foreach ($listes as $liste): ?>
                            <tr class="liste-row hover:bg-gray-50 transition" 
                                data-name="<?= strtolower(htmlspecialchars($liste['nom_liste'])) ?>"
                                data-id="<?= $liste['id_liste'] ?>">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10">
                                            <div class="bg-blue-100 rounded-full p-2">
                                                <i class="fas fa-list text-blue-600 text-sm"></i>
                                            </div>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900 liste-name">
                                                <?= htmlspecialchars($liste['nom_liste']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <i class="fas fa-users mr-1 text-gray-400"></i> 
                                        <span class="contacts-count"><?= $liste['nb_contacts'] ?></span> contact(s)
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-500">
                                        <?= date('d/m/Y', strtotime($liste['date_creation'])) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex justify-end space-x-2">
                                        <button type="button" 
                                                onclick="openRenameModal(this)" 
                                                data-id="<?= $liste['id_liste'] ?>"
                                                data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>"
                                                class="text-yellow-600 hover:text-yellow-900 p-1 rounded hover:bg-yellow-50 transition"
                                                title="Renommer la liste">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <a href="index.php?page=listes/details&id=<?= $liste['id_liste'] ?>" 
                                           class="text-green-600 hover:text-green-900 p-1 rounded hover:bg-green-50 transition"
                                           title="Ajouter un contact">
                                            <i class="fas fa-user-plus"></i>
                                        </a>

                                        <button type="button" 
                                                onclick="openImportModal(this)" 
                                                data-id="<?= $liste['id_liste'] ?>"
                                                data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>"
                                                class="text-purple-600 hover:text-purple-900 p-1 rounded hover:bg-purple-50 transition"
                                                title="Importer des contacts">
                                            <i class="fas fa-file-import"></i>
                                        </button>
                                        
                                        <button type="button" 
                                                onclick="openClearModal(this)" 
                                                data-id="<?= $liste['id_liste'] ?>"
                                                data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>"
                                                data-count="<?= $liste['nb_contacts'] ?>"
                                                class="text-orange-600 hover:text-orange-900 p-1 rounded hover:bg-orange-50 transition"
                                                title="Vider la liste">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                        
                                        <button type="button" 
                                                onclick="openDeleteModal(this)" 
                                                data-id="<?= $liste['id_liste'] ?>"
                                                data-name="<?= htmlspecialchars($liste['nom_liste'], ENT_QUOTES) ?>"
                                                class="text-red-600 hover:text-red-900 p-1 rounded hover:bg-red-50 transition"
                                                title="Supprimer la liste">
                                            <i class="fas fa-times-circle"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="noResultRow" style="display: none;">
                            <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-search text-4xl mb-2 block"></i>
                                Aucune liste ne correspond à votre recherche.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Modal pour renommer la liste -->
<div id="renameModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Renommer la liste</h3>
            <form method="POST">
                <input type="hidden" name="action_rename" value="1">
                <input type="hidden" name="id_liste" id="renameListId">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Nouveau nom</label>
                    <input type="text" name="nom_liste" id="renameListName" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="closeRenameModal()" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 transition">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition">
                        Renommer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal pour vider la liste -->
<div id="clearModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-orange-100 mb-4">
                <i class="fas fa-exclamation-triangle text-orange-600"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 text-center mb-2">Vider la liste</h3>
            <p class="text-sm text-gray-500 text-center mb-4">
                Êtes-vous sûr de vouloir vider la liste <strong id="clearListName"></strong> ?<br>
                <span id="clearListCount" class="text-orange-600 font-semibold"></span> contact(s) seront retirés de cette liste.
            </p>
            <form method="POST">
                <input type="hidden" name="action_clear" value="1">
                <input type="hidden" name="id_liste" id="clearListId">
                <div class="flex justify-center space-x-2">
                    <button type="button" onclick="closeClearModal()" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 transition">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-orange-600 text-white rounded-md hover:bg-orange-700 transition">
                        Vider la liste
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal pour supprimer la liste -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                <i class="fas fa-exclamation-triangle text-red-600"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 text-center mb-2">Supprimer la liste</h3>
            <p class="text-sm text-gray-500 text-center mb-4">
                Êtes-vous sûr de vouloir supprimer la liste <strong id="deleteListName"></strong> ?<br>
                <span class="text-red-600 font-semibold">Les contacts ne seront pas supprimés</span>, seule la liste sera supprimée.
            </p>
            <form method="POST">
                <input type="hidden" name="action_delete" value="1">
                <input type="hidden" name="id_liste" id="deleteListId">
                <div class="flex justify-center space-x-2">
                    <button type="button" onclick="closeDeleteModal()" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 transition">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition">
                        Supprimer la liste
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal pour importer des contacts -->
<div id="importModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium text-gray-900">Importer des contacts</h3>
                <button onclick="closeImportModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id_liste" id="importListId">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Liste cible : <span class="text-purple-600 font-semibold" id="importListName"></span>
                    </label>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Fichier CSV</label>
                    <input type="file" name="csv_file" accept=".csv" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                    <p class="text-xs text-gray-500 mt-1">Formats acceptés : .csv (Max 10 Mo)</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Séparateur CSV</label>
                    <select name="separator" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                        <option value=";">Point-virgule (;) - Recommandé</option>
                        <option value=",">Virgule (,)</option>
                        <option value="\t">Tabulation</option>
                    </select>
                </div>
                
                <div class="bg-purple-50 p-4 rounded-md mb-4">
                    <h4 class="font-medium text-purple-800 mb-2">📌 Comment ça fonctionne ?</h4>
                    <ul class="text-sm text-purple-700 space-y-1">
                        <li><i class="fas fa-check-circle mr-2"></i> Les contacts sont automatiquement créés s'ils n'existent pas</li>
                        <li><i class="fas fa-check-circle mr-2"></i> Si un contact existe déjà, il est simplement ajouté à la liste</li>
                        <li><i class="fas fa-check-circle mr-2"></i> Évite les doublons dans la même liste</li>
                    </ul>
                </div>
                
                <div class="bg-blue-50 p-4 rounded-md mb-4">
                    <h4 class="font-medium text-blue-800 mb-2">Format attendu du CSV :</h4>
                    <p class="text-sm text-blue-700 mb-2">La première ligne doit contenir les en-têtes suivants :</p>
                    <code class="text-xs bg-white p-2 block rounded">
                        nom;prenom;email;telephone;adresse;ville;code_postal;pays
                    </code>
                    <p class="text-xs text-blue-600 mt-2">
                        <i class="fas fa-info-circle"></i> Seul l'email ou le nom est obligatoire.
                    </p>
                </div>
                
                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="closeImportModal()" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 transition">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition">
                        <i class="fas fa-upload mr-2"></i>Importer dans la liste
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
function showToast(message, type = 'warning') {
    // Supprimer les toasts existants
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    // Créer le toast
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    
    let icon = '⚠️';
    let bgColor = '#f59e0b';
    
    const types = {
        success: { icon: '✅', color: '#10b981' },
        error: { icon: '❌', color: '#ef4444' },
        info: { icon: 'ℹ️', color: '#3b82f6' },
        warning: { icon: '⚠️', color: '#f59e0b' }
    };
    
    if (types[type]) {
        icon = types[type].icon;
        bgColor = types[type].color;
    }
    
    toast.innerHTML = `
        <div class="toast-content" style="background: ${bgColor};">
            <span>${icon}</span>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    // Supprimer après 3 secondes
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

// ============================================
// FILTRE DE RECHERCHE
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
    
    if (visibleCount === 0 && listeRows.length > 0) {
        noResultRow.style.display = '';
    } else {
        noResultRow.style.display = 'none';
    }
    
    if (filteredCountSpan) {
        filteredCountSpan.textContent = `${visibleCount} liste(s) affichée(s) sur ${totalListesCount}`;
    }
}

if (searchInput) {
    searchInput.addEventListener('input', filterListes);
}

// ============================================
// FONCTIONS DES MODALS
// ============================================
function openRenameModal(button) {
    var id = button.getAttribute('data-id');
    var name = button.getAttribute('data-name');
    
    if (id && id !== '') {
        document.getElementById('renameListId').value = id;
        document.getElementById('renameListName').value = name;
        document.getElementById('renameModal').classList.remove('hidden');
    } else {
        showToast('Erreur: ID de liste invalide', 'error');
    }
}

function closeRenameModal() {
    document.getElementById('renameModal').classList.add('hidden');
}

function openClearModal(button) {
    var id = button.getAttribute('data-id');
    var name = button.getAttribute('data-name');
    var count = button.getAttribute('data-count');
    
    // Toast élégant au lieu de alert()
    if (parseInt(count) === 0) {
        showToast('Cette liste est déjà vide.', 'warning');
        return;
    }
    
    if (id && id !== '') {
        document.getElementById('clearListId').value = id;
        document.getElementById('clearListName').textContent = name;
        document.getElementById('clearListCount').textContent = count;
        document.getElementById('clearModal').classList.remove('hidden');
    } else {
        showToast('Erreur: ID de liste invalide', 'error');
    }
}

function closeClearModal() {
    document.getElementById('clearModal').classList.add('hidden');
}

function openDeleteModal(button) {
    var id = button.getAttribute('data-id');
    var name = button.getAttribute('data-name');
    
    if (id && id !== '') {
        document.getElementById('deleteListId').value = id;
        document.getElementById('deleteListName').textContent = name;
        document.getElementById('deleteModal').classList.remove('hidden');
    } else {
        showToast('Erreur: ID de liste invalide', 'error');
    }
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

function openImportModal(button) {
    var id = button.getAttribute('data-id');
    var name = button.getAttribute('data-name');
    
    if (id && id !== '') {
        document.getElementById('importListId').value = id;
        document.getElementById('importListName').textContent = name;
        document.getElementById('importModal').classList.remove('hidden');
    } else {
        showToast('Erreur: ID de liste invalide', 'error');
    }
}

function closeImportModal() {
    document.getElementById('importModal').classList.add('hidden');
}

function downloadExample() {
    const headers = ['nom', 'prenom', 'email', 'telephone', 'adresse', 'ville', 'code_postal', 'pays'];
    const exampleData = [
        ['Dupont', 'Jean', 'jean.dupont@email.com', '0612345678', '1 rue de Paris', 'Paris', '75001', 'France'],
        ['Martin', 'Sophie', 'sophie.martin@email.com', '0698765432', '2 avenue de Lyon', 'Lyon', '69000', 'France'],
        ['Bernard', 'Lucas', 'lucas.bernard@email.com', '0678945612', '3 boulevard Marseille', 'Marseille', '13001', 'France']
    ];
    
    let csvContent = headers.join(';') + '\n';
    exampleData.forEach(row => {
        csvContent += row.join(';') + '\n';
    });
    
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.href = url;
    link.setAttribute('download', 'exemple_contacts.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

// Fermer les modals en cliquant en dehors
window.onclick = function(event) {
    const renameModal = document.getElementById('renameModal');
    const clearModal = document.getElementById('clearModal');
    const deleteModal = document.getElementById('deleteModal');
    const importModal = document.getElementById('importModal');

    if (event.target === renameModal) closeRenameModal();
    if (event.target === clearModal) closeClearModal();
    if (event.target === deleteModal) closeDeleteModal();
    if (event.target === importModal) closeImportModal();
}
</script>

</body>
</html>