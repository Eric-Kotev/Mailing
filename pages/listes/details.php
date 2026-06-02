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

// AJOUT MULTIPLE : ajouter plusieurs contacts à la liste
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_contacts'])) {
    $selectedContacts = $_POST['selected_contacts'] ?? [];
    
    if (!empty($selectedContacts)) {
        $addedCount = 0;
        
        foreach ($selectedContacts as $id_contact) {
            if (!in_array($id_contact, $idsDansListe)) {
                try {
                    $data = [
                        'id_liste' => $id_liste,
                        'id_contact' => $id_contact
                    ];
                    $db->insert('liste_contact', $data);
                    $addedCount++;
                } catch (Exception $e) {
                    // Erreur silencieuse
                }
            }
        }
        
        if ($addedCount > 0) {
            $_SESSION['flash_message'] = "$addedCount contact(s) ajouté(s) à la liste";
        } else {
            $_SESSION['flash_error'] = "Aucun contact ajouté";
        }
        
        ob_clean();
        header("Location: index.php?page=listes/details&id=$id_liste");
        exit;
    } else {
        $error = "Veuillez sélectionner au moins un contact";
    }
}

// RETIRER MULTIPLE : retirer plusieurs contacts de la liste
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retirer_contacts'])) {
    $selectedRetireContacts = $_POST['selected_retire_contacts'] ?? [];
    
    if (!empty($selectedRetireContacts)) {
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
            $_SESSION['flash_message'] = "$removedCount contact(s) retiré(s) de la liste";
        } else {
            $_SESSION['flash_error'] = "Aucun contact retiré";
        }
        
        ob_clean();
        header("Location: index.php?page=listes/details&id=$id_liste");
        exit;
    } else {
        $error = "Veuillez sélectionner au moins un contact à retirer";
    }
}

ob_end_clean();
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div class="flex items-center">
            <a href="index.php?page=listes/index" class="text-blue-600 hover:text-blue-800 mr-4">
                <i class="fas fa-arrow-left"></i> Retour
            </a>
            <h1 class="text-2xl font-bold text-gray-800">Liste : <?= htmlspecialchars($liste['nom_liste']) ?></h1>
        </div>
        <div class="text-sm text-gray-500">
            <i class="fas fa-users mr-1"></i> <?= count($contacts) ?> contact(s)
        </div>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded">
            <?= $_SESSION['flash_message'] ?>
            <?php unset($_SESSION['flash_message']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded">
            <?= $error ?>
        </div>
    <?php endif; ?>

    <!-- AJOUT AVEC DROPDOWN À CHECKBOXES -->
    <div class="bg-white rounded-lg shadow p-6">
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
            <form method="POST" id="addContactsForm">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Sélectionner les contacts à ajouter :</label>
                    
                    <!-- DROPDOWN PERSONNALISÉ -->
                    <div class="relative" id="dropdownContainer">
                        <button type="button" id="dropdownButton" 
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-left bg-white flex justify-between items-center focus:outline-none focus:border-blue-500">
                            <span id="selectedCount">Aucun contact sélectionné</span>
                            <i class="fas fa-chevron-down text-gray-400"></i>
                        </button>
                        
                        <div id="dropdownMenu" 
                             class="hidden absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-64 overflow-y-auto">
                            <div class="p-2 border-b bg-gray-50 sticky top-0">
                                <div class="flex justify-between text-sm">
                                    <button type="button" onclick="selectAll()" class="text-blue-600 hover:text-blue-800">
                                        <i class="fas fa-check-double"></i> Tout
                                    </button>
                                    <button type="button" onclick="selectNone()" class="text-gray-500 hover:text-gray-700">
                                        <i class="fas fa-times"></i> Aucun
                                    </button>
                                </div>
                            </div>
                            <?php foreach ($contactsDisponibles as $contact): ?>
                                <label class="flex items-center p-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100">
                                    <input type="checkbox" name="selected_contacts[]" value="<?= $contact['id_contact'] ?>" 
                                           class="contact-checkbox w-4 h-4 text-blue-600 rounded focus:ring-blue-500"
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
                <div class="flex justify-end">
                    <button type="submit" name="ajouter_contacts" 
                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-plus-circle mr-2"></i>Ajouter les contacts sélectionnés
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- Liste des contacts de la liste AVEC CHECKBOXES POUR RETIRER -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
            <h2 class="text-lg font-bold">Contacts dans cette liste</h2>
            <?php if (!empty($contacts)): ?>
                <button type="button" id="toggleSelectRetire" 
                        class="text-sm text-blue-600 hover:text-blue-800">
                    <i class="fas fa-check-square"></i> Sélectionner pour retirer
                </button>
            <?php endif; ?>
        </div>
        
        <form method="POST" id="removeContactsForm">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <?php if (!empty($contacts)): ?>
                                <th class="px-2 py-3 text-center w-10">
                                    <input type="checkbox" id="selectAllRetire" class="w-4 h-4">
                                </th>
                            <?php endif; ?>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Téléphone</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ville</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($contacts)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                    <i class="fas fa-users text-3xl mb-2 block text-gray-300"></i>
                                    Aucun contact dans cette liste
                                 </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($contacts as $contact): ?>
                                <tr class="hover:bg-gray-50">
                                    <?php if (!empty($contacts)): ?>
                                        <td class="px-2 py-4 text-center">
                                            <input type="checkbox" name="selected_retire_contacts[]" 
                                                   value="<?= $contact['id_contact'] ?>" 
                                                   class="retire-checkbox w-4 h-4 text-red-600 rounded">
                                        </td>
                                    <?php endif; ?>
                                    <td class="px-6 py-4 font-medium"><?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?></td>
                                    <td class="px-6 py-4"><?= htmlspecialchars($contact['email'] ?? '-') ?></td>
                                    <td class="px-6 py-4"><?= htmlspecialchars($contact['telephone'] ?? '-') ?></td>
                                    <td class="px-6 py-4"><?= htmlspecialchars($contact['ville'] ?? '-') ?></td>
                                    <td class="px-6 py-4">
                                        <a href="?page=listes/details&id=<?= $id_liste ?>&retirer=<?= $contact['id_contact'] ?>" 
                                           class="text-red-600 hover:text-red-800"
                                           onclick="return confirm('Retirer ce contact de la liste ?')">
                                            <i class="fas fa-times"></i> Retirer
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (!empty($contacts)): ?>
                <div class="p-4 border-t bg-gray-50 flex justify-end">
                    <button type="submit" name="retirer_contacts" 
                            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition"
                            onclick="return confirm('Retirer les contacts sélectionnés de la liste ?')">
                        <i class="fas fa-trash-alt mr-2"></i>Retirer les contacts sélectionnés
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
// ========== GESTION DU DROPDOWN POUR L'AJOUT ==========
const dropdownButton = document.getElementById('dropdownButton');
const dropdownMenu = document.getElementById('dropdownMenu');

if (dropdownButton) {
    dropdownButton.addEventListener('click', function() {
        dropdownMenu.classList.toggle('hidden');
    });
}

// Fermer le dropdown si on clique ailleurs
document.addEventListener('click', function(event) {
    if (dropdownButton && dropdownMenu && !dropdownButton.contains(event.target) && !dropdownMenu.contains(event.target)) {
        dropdownMenu.classList.add('hidden');
    }
});

// Mettre à jour le texte du bouton
function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.contact-checkbox:checked');
    const count = checkboxes.length;
    const selectedCountSpan = document.getElementById('selectedCount');
    
    if (selectedCountSpan) {
        if (count === 0) {
            selectedCountSpan.textContent = 'Aucun contact sélectionné';
        } else if (count === 1) {
            selectedCountSpan.textContent = '1 contact sélectionné';
        } else {
            selectedCountSpan.textContent = count + ' contacts sélectionnés';
        }
    }
}

function selectAll() {
    const checkboxes = document.querySelectorAll('.contact-checkbox');
    checkboxes.forEach(cb => cb.checked = true);
    updateSelectedCount();
}

function selectNone() {
    const checkboxes = document.querySelectorAll('.contact-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    updateSelectedCount();
}

// ========== GESTION DES CHECKBOXES POUR LE RETRAIT ==========
const toggleSelectRetire = document.getElementById('toggleSelectRetire');
const selectAllRetire = document.getElementById('selectAllRetire');
const retireCheckboxes = document.querySelectorAll('.retire-checkbox');

if (toggleSelectRetire) {
    toggleSelectRetire.addEventListener('click', function() {
        const isVisible = retireCheckboxes[0]?.style.display !== 'none';
        retireCheckboxes.forEach(cb => {
            cb.style.display = isVisible ? 'none' : 'inline-block';
            cb.checked = false;
        });
        if (selectAllRetire) selectAllRetire.checked = false;
        this.innerHTML = isVisible ? 
            '<i class="fas fa-check-square"></i> Afficher la sélection' : 
            '<i class="fas fa-check-square"></i> Masquer la sélection';
    });
}

// Cacher les checkboxes par défaut
retireCheckboxes.forEach(cb => cb.style.display = 'none');

if (selectAllRetire) {
    selectAllRetire.addEventListener('change', function() {
        retireCheckboxes.forEach(cb => {
            if (cb.style.display !== 'none') {
                cb.checked = this.checked;
            }
        });
    });
}

// Initialiser
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
});
</script>

<style>
/* Style pour les checkboxes de retrait */
.retire-checkbox {
    display: none;
}
</style>