<?php
ob_start();
global $db;

$id_liste = $_GET['id'] ?? null;

if (!$id_liste) {
    ob_clean();
    header('Location: index.php?page=listes/index');
    exit;
}

// Récupérer la liste
$listes = $db->select('liste', ['id_liste' => $id_liste, 'id_compte' => $_SESSION['user_id']]);
if (!$listes) {
    ob_clean();
    $_SESSION['flash_error'] = "Liste non trouvée";
    header('Location: index.php?page=listes/index');
    exit;
}
$liste = $listes[0];

// Récupérer les IDs des contacts dans la liste
$listeContacts = $db->select('liste_contact', ['id_liste' => $id_liste]);
$contacts = [];
$idsDansListe = [];

if (!empty($listeContacts)) {
    foreach ($listeContacts as $lc) {
        $idsDansListe[] = $lc['id_contact'];
        $contact = $db->select('contact', ['id_contact' => $lc['id_contact'], 'id_compte' => $_SESSION['user_id']]);
        if ($contact && !empty($contact)) {
            $contacts[] = $contact[0];
        }
    }
}

// Récupérer tous les contacts (pour ajout)
$tousContacts = $db->select('contact', ['id_compte' => $_SESSION['user_id']]);

// Filtrer les contacts disponibles
$contactsDisponibles = [];
foreach ($tousContacts as $contact) {
    if (!in_array($contact['id_contact'], $idsDansListe)) {
        $contactsDisponibles[] = $contact;
    }
}

// AJOUT MULTIPLE (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_contacts']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $selectedContacts = $_POST['selected_contacts'] ?? [];
    $addedCount = 0;
    
    foreach ($selectedContacts as $id_contact) {
        if (!in_array($id_contact, $idsDansListe)) {
            try {
                $db->insert('liste_contact', [
                    'id_liste' => $id_liste,
                    'id_contact' => $id_contact
                ]);
                $addedCount++;
            } catch (Exception $e) {
                // Erreur silencieuse
            }
        }
    }
    
    if ($addedCount > 0) {
        echo json_encode(['success' => true, 'message' => "$addedCount contact(s) ajouté(s) à la liste"]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Aucun contact ajouté']);
    }
    exit;
}

// RETIRER MULTIPLE (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retirer_contacts']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $selectedRetireContacts = $_POST['selected_retire_contacts'] ?? [];
    $removedCount = 0;
    
    foreach ($selectedRetireContacts as $id_contact) {
        if (in_array($id_contact, $idsDansListe)) {
            try {
                $db->deleteWithConditions('liste_contact', [
                    'id_liste' => $id_liste,
                    'id_contact' => $id_contact
                ]);
                $removedCount++;
            } catch (Exception $e) {
                // Erreur silencieuse
            }
        }
    }
    
    if ($removedCount > 0) {
        echo json_encode(['success' => true, 'message' => "$removedCount contact(s) retiré(s) de la liste"]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Aucun contact retiré']);
    }
    exit;
}

ob_end_clean();

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
    <title>Liste : <?= htmlspecialchars($liste['nom_liste']) ?> - <?= APP_NAME ?></title>
    <style>
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
            font-size: 14px;
            font-weight: 500;
        }
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        .toast-notification.warning .toast-content { background: #f59e0b; }
        
        /* ============================================
           DROPDOWN POUR L'AJOUT DE CONTACTS
           ============================================ */
        #dropdownMenu {
            z-index: 1000 !important;
            position: absolute !important;
            top: 100% !important;
            left: 0 !important;
            right: 0 !important;
            max-height: 400px !important;
            overflow: hidden !important;
            background: white !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 12px !important;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15) !important;
            margin-top: 4px !important;
        }
        
        #dropdownMenu .dropdown-search-input {
            position: sticky !important;
            top: 0 !important;
            background: white !important;
            z-index: 10 !important;
            padding: 10px !important;
            border-bottom: 1px solid #e5e7eb !important;
        }
        
        #dropdownMenu .max-h-64 {
            max-height: 300px !important;
            overflow-y: auto !important;
        }
        
        /* ============================================
           CONTENEUR DES CONTACTS
           ============================================ */
        .table-container {
            min-height: 200px;
            position: relative;
            overflow-x: auto;
        }
        
        /* ============================================
           BARRE D'ACTIONS
           ============================================ */
        .action-bar {
            background: #f9fafb !important;
            border-bottom: 1px solid #e5e7eb !important;
            border-top: 1px solid #e5e7eb !important;
            padding: 12px 16px !important;
        }
        
        /* ============================================
           CHECKBOXES POUR LE RETRAIT
           ============================================ */
        .retire-checkbox {
            display: none !important;
        }
        .retire-checkbox.visible {
            display: inline-block !important;
        }
        
        /* ============================================
           CONTACT ITEMS
           ============================================ */
        .contact-item.hide {
            display: none !important;
        }
        
        .contact-row.hide {
            display: none !important;
        }
        
        /* ============================================
           DROPDOWN BUTTON
           ============================================ */
        #dropdownButton {
            cursor: pointer !important;
            user-select: none !important;
        }
        
        #dropdownButton:hover {
            border-color: #3b82f6 !important;
        }
        
        /* ============================================
           MODALES
           ============================================ */
        .modal-overlay {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            background-color: rgba(0, 0, 0, 0.6) !important;
            backdrop-filter: blur(3px);
            z-index: 9999 !important;
            display: none;
            align-items: center !important;
            justify-content: center !important;
            padding: 20px !important;
        }
        
        .modal-content {
            background: white !important;
            border-radius: 16px !important;
            max-width: 500px !important;
            width: 100% !important;
            padding: 32px !important;
            animation: modalSlideIn 0.3s ease-out !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5) !important;
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(30px) scale(0.98);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }
        
        .modal-icon {
            width: 72px !important;
            height: 72px !important;
            border-radius: 50% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 auto 16px auto !important;
        }
        
        .modal-icon.bg-red-100 { background: #fee2e2 !important; }
        .modal-icon.bg-red-100 i { color: #dc2626 !important; font-size: 28px !important; }
        
        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 768px) {
            .action-bar {
                flex-direction: column !important;
                gap: 10px !important;
                align-items: stretch !important;
            }
            
            .action-bar .flex {
                justify-content: center !important;
            }
            
            #dropdownMenu {
                max-height: 300px !important;
            }
            
            #dropdownMenu .max-h-64 {
                max-height: 200px !important;
            }
            
            .modal-content {
                padding: 24px !important;
                margin: 16px !important;
            }
        }
    </style>
</head>
<body>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div class="flex items-center">
            <a href="javascript:history.back()" class="text-blue-600 hover:text-blue-800 mr-4">
                <i class="fas fa-arrow-left"></i> Retour
            </a>
            <h1 class="text-2xl font-bold text-gray-800">Liste : <?= htmlspecialchars($liste['nom_liste']) ?></h1>
        </div>
        <div class="text-sm text-gray-500">
            <i class="fas fa-users mr-1"></i> <?= count($contacts) ?> contact(s)
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded"><?= $flashMessage ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded"><?= $flashError ?></div>
    <?php endif; ?>

    <!-- AJOUT AVEC DROPDOWN À CHECKBOXES ET RECHERCHE -->
    <div class="bg-white rounded-lg shadow p-6" style="position: relative; z-index: 10;">
        <h2 class="text-lg font-bold mb-4">Ajouter des contacts à la liste</h2>
        
        <?php if (empty($contactsDisponibles)): ?>
            <div class="bg-gray-50 rounded-lg p-6 text-center">
                <i class="fas fa-check-circle text-green-500 text-3xl mb-2"></i>
                <p class="text-gray-600">Tous vos contacts sont déjà dans cette liste !</p>
                <a href="index.php?page=contacts/ajouter" class="text-blue-600 text-sm mt-2 inline-block">
                    <i class="fas fa-plus mr-1"></i>Créer un nouveau contact
                </a>
            </div>
        <?php else: ?>
            <form id="addContactsForm">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Sélectionner les contacts à ajouter :</label>
                    
                    <div class="relative" id="dropdownContainer" style="z-index: 1000;">
                        <button type="button" id="dropdownButton" 
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-left bg-white flex justify-between items-center focus:outline-none focus:border-blue-500">
                            <span id="selectedCount">Aucun contact sélectionné</span>
                            <i class="fas fa-chevron-down text-gray-400"></i>
                        </button>
                        
                        <div id="dropdownMenu" 
                             class="hidden" 
                             style="z-index: 1000 !important; position: absolute !important; top: 100% !important; left: 0 !important; right: 0 !important; background: white !important; border: 1px solid #e2e8f0 !important; border-radius: 12px !important; box-shadow: 0 10px 25px rgba(0,0,0,0.15) !important; margin-top: 4px !important;">
                            <!-- Barre de recherche -->
                            <div class="dropdown-search-input" style="position: sticky !important; top: 0 !important; background: white !important; z-index: 10 !important; padding: 10px !important; border-bottom: 1px solid #e5e7eb !important;">
                                <div class="relative">
                                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                                    <input type="text" 
                                           id="searchContactInput" 
                                           placeholder="Rechercher un contact..." 
                                           class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-blue-500">
                                </div>
                            </div>
                            <!-- Liste des contacts avec scroll -->
                            <div class="max-h-64" style="max-height: 300px !important; overflow-y: auto !important;">
                                <?php foreach ($contactsDisponibles as $contact): ?>
                                    <label class="contact-item flex items-center p-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100"
                                           data-nom="<?= strtolower(htmlspecialchars($contact['prenom'] . ' ' . $contact['nom'])) ?>"
                                           data-email="<?= strtolower(htmlspecialchars($contact['email'] ?? '')) ?>"
                                           data-telephone="<?= strtolower(htmlspecialchars($contact['telephone'] ?? '')) ?>">
                                        <input type="checkbox" name="selected_contacts[]" value="<?= $contact['id_contact'] ?>" 
                                               class="contact-checkbox w-4 h-4 text-blue-600 rounded"
                                               onchange="updateSelectedCount()">
                                        <div class="ml-3">
                                            <span class="text-sm font-medium text-gray-800">
                                                <?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?>
                                            </span>
                                            <?php if ($contact['email']): ?>
                                                <span class="text-xs text-gray-500 ml-2"><?= htmlspecialchars($contact['email']) ?></span>
                                            <?php elseif ($contact['telephone']): ?>
                                                <span class="text-xs text-gray-500 ml-2"><?= htmlspecialchars($contact['telephone']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" id="addContactsBtn" 
                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-plus-circle mr-2"></i>Ajouter les contacts sélectionnés
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- Liste des contacts de la liste avec recherche -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="p-4 border-b bg-gray-50">
            <div class="flex justify-between items-center flex-wrap gap-4">
                <h2 class="text-lg font-bold">Contacts dans cette liste</h2>
                <div class="flex items-center gap-4">
                    <!-- Champ de recherche pour la liste -->
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" 
                               id="searchListInput" 
                               placeholder="Rechercher un contact..." 
                               class="pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-blue-500 w-64">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Barre d'actions - Position normale (non sticky) -->
        <?php if (!empty($contacts)): ?>
            <div class="action-bar flex justify-between items-center flex-wrap gap-2">
                <div class="flex items-center gap-2">
                    <button type="button" id="toggleSelectRetire" 
                            class="text-sm bg-blue-50 hover:bg-blue-100 text-blue-600 hover:text-blue-800 px-4 py-2 rounded-lg transition flex items-center gap-2">
                        <i class="fas fa-check-square"></i> Sélectionner pour retirer
                    </button>
                    <span id="selectedRetireCount" class="text-sm text-gray-500 ml-2" style="display: none;">0 sélectionné(s)</span>
                </div>
                
                <button type="button" id="retirerContactsBtn" 
                        class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition flex items-center gap-2">
                    <i class="fas fa-trash-alt mr-2"></i>Retirer les contacts sélectionnés
                </button>
            </div>
        <?php endif; ?>
        
        <form id="removeContactsForm">
            <div class="overflow-x-auto table-container">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <?php if (!empty($contacts)): ?>
                                <th class="px-2 py-3 text-center w-10">
                                    <input type="checkbox" id="selectAllRetire" class="w-4 h-4" style="display: none;">
                                </th>
                            <?php endif; ?>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Téléphone</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ville</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200" id="contactsListBody">
                        <?php if (empty($contacts)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                    <i class="fas fa-users text-3xl mb-2 block text-gray-300"></i>
                                    Aucun contact dans cette liste
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($contacts as $contact): ?>
                                <tr class="contact-row hover:bg-gray-50" 
                                    data-nom="<?= strtolower(htmlspecialchars($contact['prenom'] . ' ' . $contact['nom'])) ?>"
                                    data-email="<?= strtolower(htmlspecialchars($contact['email'] ?? '')) ?>"
                                    data-telephone="<?= strtolower(htmlspecialchars($contact['telephone'] ?? '')) ?>"
                                    data-ville="<?= strtolower(htmlspecialchars($contact['ville'] ?? '')) ?>">
                                    <?php if (!empty($contacts)): ?>
                                        <td class="px-2 py-4 text-center">
                                            <input type="checkbox" name="selected_retire_contacts[]" 
                                                   value="<?= $contact['id_contact'] ?>" 
                                                   class="retire-checkbox w-4 h-4 text-red-600 rounded"
                                                   data-nom="<?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?>"
                                                   onchange="updateRetireSelectedCount()">
                                        </td>
                                    <?php endif; ?>
                                    <td class="px-6 py-4 font-medium"><?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?></td>
                                    <td class="px-6 py-4"><?= htmlspecialchars($contact['email'] ?? '-') ?></td>
                                    <td class="px-6 py-4"><?= htmlspecialchars($contact['telephone'] ?? '-') ?></td>
                                    <td class="px-6 py-4"><?= htmlspecialchars($contact['ville'] ?? '-') ?></td>
                                    <td class="px-6 py-4">
                                        <button type="button" onclick="openRemoveSingleModal('<?= $contact['id_contact'] ?>', '<?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom'], ENT_QUOTES) ?>')" 
                                                class="text-red-600 hover:text-red-800">
                                            <i class="fas fa-times"></i> Retirer
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE RETRAIT UNIQUE -->
<!-- ============================================ -->
<div id="removeSingleModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div class="text-center">
            <div class="modal-icon bg-red-100">
                <i class="fas fa-user-minus"></i>
            </div>
            <h3 class="text-2xl font-bold text-gray-800 mb-2">Retirer le contact</h3>
            <p class="text-gray-500 mb-2 text-base">Êtes-vous sûr de vouloir retirer</p>
            <p class="text-lg font-semibold text-gray-800 mb-1" id="removeSingleContactName"></p>
            <p class="text-gray-500 mb-6 text-base">de la liste <strong id="removeSingleListName"><?= htmlspecialchars($liste['nom_liste']) ?></strong> ?</p>
            
            <div class="flex justify-center space-x-3">
                <button type="button" onclick="closeRemoveSingleModal()" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition font-medium">
                    Annuler
                </button>
                <button type="button" onclick="confirmRemoveSingle()" class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition font-medium">
                    <i class="fas fa-user-minus mr-2"></i>Retirer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE RETRAIT MULTIPLE -->
<!-- ============================================ -->
<div id="removeMultipleModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div class="text-center">
            <div class="modal-icon bg-red-100">
                <i class="fas fa-users-minus"></i>
            </div>
            <h3 class="text-2xl font-bold text-gray-800 mb-2">Retirer les contacts sélectionnés</h3>
            <p class="text-gray-500 mb-2 text-base">Êtes-vous sûr de vouloir retirer</p>
            <p class="text-2xl font-bold text-red-600 mb-1" id="removeMultipleCount">0</p>
            <p class="text-gray-500 mb-2 text-base">contact(s) sélectionné(s) de la liste</p>
            <p class="text-lg font-semibold text-gray-800 mb-6" id="removeMultipleListName"><?= htmlspecialchars($liste['nom_liste']) ?></p>
            
            <div class="flex justify-center space-x-3">
                <button type="button" onclick="closeRemoveMultipleModal()" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition font-medium">
                    Annuler
                </button>
                <button type="button" onclick="confirmRemoveMultiple()" class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition font-medium">
                    <i class="fas fa-users-minus mr-2"></i>Retirer tous
                </button>
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
    const colors = { success: '#10b981', error: '#ef4444', warning: '#f59e0b' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// ============================================
// VARIABLES POUR LES MODALES
// ============================================
let singleContactId = null;
let multipleContactIds = [];

// ============================================
// DROPDOWN POUR L'AJOUT
// ============================================
const dropdownButton = document.getElementById('dropdownButton');
const dropdownMenu = document.getElementById('dropdownMenu');

if (dropdownButton) {
    dropdownButton.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdownMenu.classList.toggle('hidden');
        if (!dropdownMenu.classList.contains('hidden')) {
            const searchInput = document.getElementById('searchContactInput');
            if (searchInput) {
                setTimeout(() => searchInput.focus(), 100);
                searchInput.value = '';
                filterContacts('');
            }
        }
    });
}

// Fermer le dropdown en cliquant ailleurs
document.addEventListener('click', function(event) {
    if (dropdownButton && dropdownMenu && 
        !dropdownButton.contains(event.target) && 
        !dropdownMenu.contains(event.target)) {
        dropdownMenu.classList.add('hidden');
    }
});

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.contact-checkbox:checked');
    const count = checkboxes.length;
    const selectedCountSpan = document.getElementById('selectedCount');
    if (selectedCountSpan) {
        if (count === 0) selectedCountSpan.textContent = 'Aucun contact sélectionné';
        else if (count === 1) selectedCountSpan.textContent = '1 contact sélectionné';
        else selectedCountSpan.textContent = count + ' contacts sélectionnés';
    }
}

// ============================================
// COMPTEUR DES SÉLECTIONS POUR LE RETRAIT
// ============================================
function updateRetireSelectedCount() {
    const checkboxes = document.querySelectorAll('.retire-checkbox.visible:checked');
    const count = checkboxes.length;
    const countSpan = document.getElementById('selectedRetireCount');
    if (countSpan) {
        if (count === 0) {
            countSpan.style.display = 'none';
        } else {
            countSpan.style.display = 'inline';
            countSpan.textContent = count + ' sélectionné(s)';
        }
    }
}

// ============================================
// RECHERCHE DANS LE DROPDOWN
// ============================================
const searchContactInput = document.getElementById('searchContactInput');
if (searchContactInput) {
    searchContactInput.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        filterContacts(searchTerm);
    });
}

function filterContacts(searchTerm) {
    const contactItems = document.querySelectorAll('.contact-item');
    let visibleCount = 0;
    
    contactItems.forEach(item => {
        const nom = item.getAttribute('data-nom') || '';
        const email = item.getAttribute('data-email') || '';
        const telephone = item.getAttribute('data-telephone') || '';
        
        if (nom.includes(searchTerm) || email.includes(searchTerm) || telephone.includes(searchTerm) || searchTerm === '') {
            item.classList.remove('hide');
            visibleCount++;
        } else {
            item.classList.add('hide');
        }
    });
    
    // Afficher un message si aucun résultat
    const container = document.getElementById('contactsListContainer');
    const existingNoResult = document.getElementById('noResultMessage');
    
    if (visibleCount === 0) {
        if (!existingNoResult && container) {
            const noResultMsg = document.createElement('div');
            noResultMsg.id = 'noResultMessage';
            noResultMsg.className = 'p-4 text-center text-gray-500';
            noResultMsg.innerHTML = '<i class="fas fa-search"></i> Aucun contact trouvé';
            container.appendChild(noResultMsg);
        }
    } else if (existingNoResult) {
        existingNoResult.remove();
    }
}

// ============================================
// RECHERCHE DANS LA LISTE DES CONTACTS
// ============================================
const searchListInput = document.getElementById('searchListInput');
if (searchListInput) {
    searchListInput.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const rows = document.querySelectorAll('#contactsListBody .contact-row');
        
        rows.forEach(row => {
            const nom = row.getAttribute('data-nom') || '';
            const email = row.getAttribute('data-email') || '';
            const telephone = row.getAttribute('data-telephone') || '';
            const ville = row.getAttribute('data-ville') || '';
            
            if (nom.includes(searchTerm) || email.includes(searchTerm) || telephone.includes(searchTerm) || ville.includes(searchTerm) || searchTerm === '') {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
}

// ============================================
// AJOUT DE CONTACTS (AJAX)
// ============================================
document.getElementById('addContactsForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const selectedContacts = Array.from(document.querySelectorAll('.contact-checkbox:checked')).map(cb => cb.value);
    
    if (selectedContacts.length === 0) {
        showToast('Veuillez sélectionner au moins un contact', 'warning');
        return;
    }
    
    const formData = new FormData();
    formData.append('ajouter_contacts', '1');
    selectedContacts.forEach(id => formData.append('selected_contacts[]', id));
    
    const submitBtn = document.getElementById('addContactsBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Ajout en cours...';
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
// MODALE RETRAIT UNIQUE
// ============================================
function openRemoveSingleModal(contactId, contactName) {
    singleContactId = contactId;
    document.getElementById('removeSingleContactName').textContent = contactName;
    document.getElementById('removeSingleListName').textContent = '<?= htmlspecialchars($liste['nom_liste']) ?>';
    document.getElementById('removeSingleModal').style.display = 'flex';
}

function closeRemoveSingleModal() {
    document.getElementById('removeSingleModal').style.display = 'none';
    singleContactId = null;
}

function confirmRemoveSingle() {
    if (!singleContactId) return;
    
    const formData = new FormData();
    formData.append('retirer_contacts', '1');
    formData.append('selected_retire_contacts[]', singleContactId);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(res => res.json())
    .then(result => {
        closeRemoveSingleModal();
        if (result.success) {
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error, 'error');
        }
    })
    .catch(() => {
        closeRemoveSingleModal();
        showToast('Erreur réseau', 'error');
    });
}

// ============================================
// MODALE RETRAIT MULTIPLE
// ============================================
function openRemoveMultipleModal() {
    const selectedCheckboxes = document.querySelectorAll('.retire-checkbox.visible:checked');
    const count = selectedCheckboxes.length;
    
    if (count === 0) {
        showToast('Veuillez sélectionner au moins un contact à retirer', 'warning');
        return;
    }
    
    multipleContactIds = Array.from(selectedCheckboxes).map(cb => cb.value);
    document.getElementById('removeMultipleCount').textContent = count;
    document.getElementById('removeMultipleListName').textContent = '<?= htmlspecialchars($liste['nom_liste']) ?>';
    document.getElementById('removeMultipleModal').style.display = 'flex';
}

function closeRemoveMultipleModal() {
    document.getElementById('removeMultipleModal').style.display = 'none';
    multipleContactIds = [];
}

function confirmRemoveMultiple() {
    if (multipleContactIds.length === 0) return;
    
    const formData = new FormData();
    formData.append('retirer_contacts', '1');
    multipleContactIds.forEach(id => formData.append('selected_retire_contacts[]', id));
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(res => res.json())
    .then(result => {
        closeRemoveMultipleModal();
        if (result.success) {
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error, 'error');
        }
    })
    .catch(() => {
        closeRemoveMultipleModal();
        showToast('Erreur réseau', 'error');
    });
}

// ============================================
// GESTION DES CHECKBOXES POUR LE RETRAIT
// ============================================
const toggleSelectRetire = document.getElementById('toggleSelectRetire');
const selectAllRetire = document.getElementById('selectAllRetire');
let retireCheckboxes = document.querySelectorAll('.retire-checkbox');
const selectedRetireCount = document.getElementById('selectedRetireCount');

if (toggleSelectRetire) {
    toggleSelectRetire.addEventListener('click', function() {
        const isVisible = retireCheckboxes[0]?.classList.contains('visible');
        
        retireCheckboxes.forEach(cb => {
            if (isVisible) {
                cb.classList.remove('visible');
                cb.checked = false;
            } else {
                cb.classList.add('visible');
            }
        });
        
        if (selectAllRetire) {
            selectAllRetire.checked = false;
            selectAllRetire.style.display = isVisible ? 'none' : 'inline-block';
        }
        
        if (selectedRetireCount) {
            selectedRetireCount.style.display = 'none';
        }
        
        this.innerHTML = isVisible ? 
            '<i class="fas fa-check-square"></i> Sélectionner pour retirer' : 
            '<i class="fas fa-check-square"></i> Masquer la sélection';
        
        if (isVisible) {
            this.classList.remove('bg-blue-100');
            this.classList.add('bg-blue-50');
        } else {
            this.classList.remove('bg-blue-50');
            this.classList.add('bg-blue-100');
        }
        
        updateRetireSelectedCount();
    });
}

// Cacher les checkboxes au chargement
retireCheckboxes.forEach(cb => cb.classList.remove('visible'));
if (selectAllRetire) selectAllRetire.style.display = 'none';
if (selectedRetireCount) selectedRetireCount.style.display = 'none';

if (selectAllRetire) {
    selectAllRetire.addEventListener('change', function() {
        retireCheckboxes.forEach(cb => {
            if (cb.classList.contains('visible')) {
                cb.checked = this.checked;
            }
        });
        updateRetireSelectedCount();
    });
}

// ============================================
// BOUTON RETIRER MULTIPLE
// ============================================
document.getElementById('retirerContactsBtn')?.addEventListener('click', function(e) {
    e.preventDefault();
    openRemoveMultipleModal();
});

// ============================================
// INITIALISATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
    
    // Mettre à jour la liste des checkboxes après chaque modification
    const observer = new MutationObserver(function() {
        retireCheckboxes = document.querySelectorAll('.retire-checkbox');
    });
    
    const targetNode = document.getElementById('contactsListBody');
    if (targetNode) {
        observer.observe(targetNode, { childList: true, subtree: true });
    }
});

// Fermeture des modales en cliquant sur l'overlay
document.getElementById('removeSingleModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeRemoveSingleModal();
});
document.getElementById('removeMultipleModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeRemoveMultipleModal();
});

// Fermeture des modales avec Echap
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRemoveSingleModal();
        closeRemoveMultipleModal();
    }
});
</script>

</body>
</html>