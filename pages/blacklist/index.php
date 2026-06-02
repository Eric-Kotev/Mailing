<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer les types de messages
$typeMessages = $db->select('type_message');
if (empty($typeMessages)) {
    try {
        $db->insert('type_message', ['id_type_message' => 1, 'libelle_type' => 'SMS']);
        $db->insert('type_message', ['id_type_message' => 2, 'libelle_type' => 'Email']);
        $db->insert('type_message', ['id_type_message' => 3, 'libelle_type' => 'WhatsApp']);
        $typeMessages = $db->select('type_message');
    } catch (Exception $e) {
        // Ignorer
    }
}

// Récupérer la blacklist avec les infos contact
$blacklist = $db->select('blacklist', [], '*', 'date_ajout=order.desc');
$blacklistWithContact = [];

foreach ($blacklist as $bl) {
    $contact = $db->select('contact', ['id_contact' => $bl['id_contact'], 'id_compte' => $idCompte]);
    if ($contact && !empty($contact)) {
        $bl['contact'] = $contact[0];
        $blacklistWithContact[] = $bl;
    }
}

// Récupérer tous les contacts pour l'autocomplétion (non blacklistés)
$tousContacts = $db->select('contact', ['id_compte' => $idCompte]);
$contactIdsBlacklist = array_column($blacklist, 'id_contact');
$contactsDisponibles = [];
foreach ($tousContacts as $contact) {
    if (!in_array($contact['id_contact'], $contactIdsBlacklist)) {
        $contactsDisponibles[] = $contact;
    }
}

// Ajouter à la blacklist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_blacklist'])) {
    $id_contact = $_POST['id_contact'] ?? null;
    $id_type_message = $_POST['id_type_message'] ?? null;
    $motif = $_POST['motif'] ?? null;
    
    if ($id_contact && $id_type_message) {
        try {
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
                $_SESSION['flash_message'] = "Contact ajouté à la blacklist";
                header("Location: index.php?page=blacklist/index");
                exit;
            } else {
                $error = "Ce contact est déjà blacklisté pour ce type de message";
            }
        } catch (Exception $e) {
            $error = "Erreur : " . $e->getMessage();
        }
    } else {
        $error = "Veuillez sélectionner un contact et un type";
    }
}

// Retirer plusieurs contacts de la blacklist (action groupée via AJAX)
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
        // Retourner une réponse JSON pour AJAX
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'count' => $removedCount]);
            exit;
        } else {
            $_SESSION['flash_message'] = "$removedCount contact(s) retiré(s) de la blacklist avec succès !";
            header("Location: index.php?page=blacklist/index");
            exit;
        }
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Aucun contact sélectionné']);
            exit;
        } else {
            $_SESSION['flash_error'] = "Aucun contact sélectionné.";
            header("Location: index.php?page=blacklist/index");
            exit;
        }
    }
}

// Retirer un seul contact de la blacklist
if (isset($_GET['retirer'])) {
    $id_blacklist = $_GET['retirer'];
    try {
        $db->delete('blacklist', $id_blacklist, 'id_blacklist');
        $_SESSION['flash_message'] = "Contact retiré de la blacklist";
        header("Location: index.php?page=blacklist/index");
        exit;
    } catch (Exception $e) {
        $error = "Erreur : " . $e->getMessage();
    }
}

$totalBlacklisted = count($blacklistWithContact);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blacklist - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .select2-container--default .select2-selection--single {
            height: 42px;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 4px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            color: #1f2937;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }
        .select2-dropdown {
            border-radius: 0.5rem;
            border-color: #d1d5db;
        }
        .select2-search__field {
            border-radius: 0.5rem !important;
            border: 1px solid #d1d5db !important;
            padding: 6px !important;
        }
        .select2-results__option--highlighted {
            background-color: #3b82f6 !important;
        }
        .checkbox-column {
            width: 40px;
            text-align: center;
        }
        .checkbox-column input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .bulk-actions-bar {
            transition: all 0.3s ease;
        }
        
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
    </style>
</head>
<body>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">Blacklist</h1>
        <p class="text-gray-500">Gérez les contacts exclus par type de message</p>
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

    <!-- Ajouter à la blacklist avec recherche -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-bold mb-4">Ajouter un contact à la blacklist</h2>
        <form method="POST" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-search mr-1 text-gray-400"></i> Rechercher un contact
                    </label>
                    <select name="id_contact" id="contactSearch" required class="w-full" style="width: 100%;">
                        <option value="">Tapez le nom, prénom ou email...</option>
                        <?php foreach ($contactsDisponibles as $contact): ?>
                            <option value="<?= $contact['id_contact'] ?>">
                                <?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?> 
                                (<?= htmlspecialchars($contact['email'] ?? $contact['telephone'] ?? 'aucun contact') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-400 mt-1">
                        <i class="fas fa-info-circle"></i> Tapez pour rechercher un contact
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type de message</label>
                    <select name="id_type_message" required class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        <option value="">Sélectionner un type</option>
                        <?php if (!empty($typeMessages)): ?>
                            <?php foreach ($typeMessages as $type): ?>
                                <option value="<?= $type['id_type_message'] ?>">
                                    <?= htmlspecialchars($type['libelle_type']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Motif (optionnel)</label>
                    <input type="text" name="motif" placeholder="Pourquoi ce contact est bloqué ?" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
            </div>
            <div class="flex justify-end">
                <button type="submit" name="ajouter_blacklist" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-ban mr-2"></i>Ajouter à la blacklist
                </button>
            </div>
        </form>
    </div>

    <!-- Statistiques + Recherche dans la liste -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-500">Total blacklistés</span>
                    <span class="text-2xl font-bold text-gray-800 ml-2" id="totalCount"><?= $totalBlacklisted ?></span>
                </div>
                <div class="text-gray-400">
                    <i class="fas fa-ban text-2xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 md:col-span-2">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="searchInput" 
                       placeholder="Rechercher par nom, email, téléphone, type ou motif..."
                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500 focus:ring-2 focus:ring-red-200 transition">
            </div>
            <div class="mt-2 text-right">
                <span id="filteredCount" class="text-xs text-gray-500"></span>
            </div>
        </div>
    </div>

    <!-- Barre d'actions groupées -->
    <div id="bulkActionsBar" class="hidden bg-blue-50 rounded-lg p-4 flex justify-between items-center bulk-actions-bar">
        <div>
            <span id="selectedCount" class="text-sm font-semibold text-blue-700">0</span>
            <span class="text-sm text-blue-600">contact(s) sélectionné(s)</span>
        </div>
        <button id="bulkUnblacklistBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-check-double mr-2"></i>Retirer les sélectionnés
        </button>
    </div>

    <!-- Liste de la blacklist avec cases à cocher -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
            <h2 class="text-lg font-bold">Contacts blacklistés</h2>
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-600 flex items-center gap-1 cursor-pointer">
                    <input type="checkbox" id="selectAllCheckbox" class="rounded">
                    <span>Tout sélectionner</span>
                </label>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="checkbox-column px-2 py-3">
                            <input type="checkbox" id="selectAllHeader" class="rounded">
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type bloqué</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Motif</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody id="blacklistTableBody">
                    <?php if (empty($blacklistWithContact)): ?>
                        <tr id="noContactsRow">
                            <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-check-circle text-3xl mb-2 block text-gray-300"></i>
                                Aucun contact blacklisté
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($blacklistWithContact as $bl): 
                            $typeLabel = '';
                            foreach ($typeMessages as $t) {
                                if ($t['id_type_message'] == $bl['id_type_message']) {
                                    $typeLabel = $t['libelle_type'];
                                    break;
                                }
                            }
                        ?>
                            <tr class="blacklist-row hover:bg-gray-50 transition" 
                                data-id="<?= $bl['id_blacklist'] ?>"
                                data-name="<?= strtolower(htmlspecialchars($bl['contact']['prenom'] . ' ' . $bl['contact']['nom'])) ?>"
                                data-email="<?= strtolower(htmlspecialchars($bl['contact']['email'] ?? '')) ?>"
                                data-phone="<?= strtolower(htmlspecialchars($bl['contact']['telephone'] ?? '')) ?>"
                                data-type="<?= strtolower($typeLabel) ?>"
                                data-motif="<?= strtolower(htmlspecialchars($bl['motif'] ?? '')) ?>">
                                <td class="checkbox-column px-2 py-4 text-center">
                                    <input type="checkbox" value="<?= $bl['id_blacklist'] ?>" class="contact-checkbox rounded">
                                </td>
                                <td class="px-6 py-4">
                                    <?= htmlspecialchars($bl['contact']['prenom'] . ' ' . $bl['contact']['nom']) ?>
                                    <br><span class="text-xs text-gray-500"><?= htmlspecialchars($bl['contact']['email'] ?? $bl['contact']['telephone'] ?? '-') ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-1 rounded text-xs bg-red-100 text-red-700">
                                        <?= htmlspecialchars($typeLabel) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4"><?= htmlspecialchars($bl['motif'] ?? '-') ?></td>
                                <td class="px-6 py-4"><?= date('d/m/Y', strtotime($bl['date_ajout'])) ?></td>
                                <td class="px-6 py-4">
                                    <button onclick="openUnblacklistModal('<?= $bl['id_blacklist'] ?>', '<?= addslashes($bl['contact']['prenom'] . ' ' . $bl['contact']['nom']) ?>', '<?= addslashes($typeLabel) ?>')"
                                            class="text-green-600 hover:text-green-800 transition flex items-center gap-1">
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

<!-- MODALE POUR RETIRER UN SEUL CONTACT -->
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
            <a href="#" id="confirmUnblacklistBtn" 
               class="w-full inline-block bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition text-center">
                <i class="fas fa-check mr-2"></i>Retirer
            </a>
            <button type="button" onclick="closeUnblacklistModal()" 
                    class="w-full mt-3 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Annuler
            </button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    // Toast notification
    function showToast(message, type = 'success') {
        const existingToasts = document.querySelectorAll('.toast-notification');
        existingToasts.forEach(toast => toast.remove());
        
        const toast = document.createElement('div');
        toast.className = 'toast-notification';
        
        let icon = '✅';
        let bgColor = '#10b981';
        
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
        
        toast.innerHTML = `<div class="toast-content" style="background: ${bgColor};"><span>${icon}</span><span>${message}</span></div>`;
        document.body.appendChild(toast);
        
        setTimeout(() => toast.remove(), 3000);
    }

    $(document).ready(function() {
        $('#contactSearch').select2({
            placeholder: "Tapez le nom, prénom ou email...",
            allowClear: true,
            width: '100%',
            language: {
                searching: function() { return "Recherche..."; },
                noResults: function() { return "Aucun contact trouvé"; },
                inputTooShort: function() { return "Tapez au moins 1 caractère"; }
            }
        });
    });

    // Gestion des cases à cocher
    const checkboxes = document.querySelectorAll('.contact-checkbox');
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
        
        const allCount = document.querySelectorAll('.contact-checkbox').length;
        const allChecked = count === allCount && allCount > 0;
        if (selectAllHeader) selectAllHeader.checked = allChecked;
        if (selectAllCheckbox) selectAllCheckbox.checked = allChecked;
    }

    function toggleAllCheckboxes(checked) {
        document.querySelectorAll('.contact-checkbox').forEach(cb => {
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
    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkActionsBar);
    });

    // Suppression groupée avec toast et sans confirm()
    if (bulkUnblacklistBtn) {
        bulkUnblacklistBtn.addEventListener('click', function(e) {
            const checked = document.querySelectorAll('.contact-checkbox:checked');
            if (checked.length === 0) {
                showToast('Veuillez sélectionner au moins un contact', 'warning');
                return;
            }
            
            const selectedIds = Array.from(checked).map(cb => cb.value);
            
            // Afficher un toast de confirmation avant l'envoi
            showToast(`Retrait de ${checked.length} contact(s) en cours...`, 'info');
            
            // Envoi AJAX
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
                    showToast(`${data.count} contact(s) retiré(s) de la blacklist avec succès !`, 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast(data.error || 'Erreur lors du retrait', 'error');
                }
            })
            .catch(error => {
                showToast('Erreur réseau', 'error');
            });
        });
    }

    // Recherche dans la blacklist
    const searchInput = document.getElementById('searchInput');
    const blacklistRows = document.querySelectorAll('.blacklist-row');
    const totalCount = <?= $totalBlacklisted ?>;
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
            filteredCountSpan.textContent = `${visibleCount} / ${totalCount} contact(s) blacklisté(s)`;
        }
        
        let noResultRow = document.getElementById('noResultRow');
        if (visibleCount === 0 && blacklistRows.length > 0) {
            if (!noResultRow) {
                const tbody = document.getElementById('blacklistTableBody');
                noResultRow = document.createElement('tr');
                noResultRow.id = 'noResultRow';
                noResultRow.innerHTML = '<td colspan="6" class="px-6 py-8 text-center text-gray-500">' +
                    '<i class="fas fa-search text-4xl mb-2 block"></i>' +
                    'Aucun contact ne correspond à votre recherche.' +
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
    
    // Modale pour un seul contact
    function openUnblacklistModal(blacklistId, contactName, typeLabel) {
        const modal = document.getElementById('unblacklistModal');
        const modalContent = modal.querySelector('.modal-content-unblacklist');
        const confirmBtn = document.getElementById('confirmUnblacklistBtn');
        
        document.getElementById('unblacklistContactName').innerHTML = `${contactName} (${typeLabel})`;
        confirmBtn.href = '?page=blacklist/index&retirer=' + blacklistId;
        
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
        }, 200);
    }
    
    document.getElementById('unblacklistModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeUnblacklistModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeUnblacklistModal();
    });
</script>

</body>
</html>