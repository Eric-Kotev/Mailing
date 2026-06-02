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

$totalContacts = count($contacts);

// Vérifier s'il y a des messages flash en session (après redirection)
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
        .modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
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
        .contact-row.hidden-row {
            display: none;
        }
    </style>
</head>
<body>

<div class="space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Mes contacts</h1>
            <p class="text-gray-500">Gérez votre base de contacts</p>
        </div>
        <div class="space-x-2">
            <a href="index.php?page=contacts/ajouter" 
               class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-plus mr-2"></i>Ajouter un contact
            </a>
            <a href="index.php?page=contacts/import" 
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-upload mr-2"></i>Importer CSV
            </a>
        </div>
    </div>

    <!-- Statistiques + Recherche -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-500">Total des contacts</span>
                    <span class="text-2xl font-bold text-gray-800 ml-2" id="totalCount"><?= $totalContacts ?></span>
                </div>
                <div class="text-gray-400">
                    <i class="fas fa-users text-2xl"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 md:col-span-2">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="searchInput" 
                       placeholder="Rechercher par nom, email, téléphone ou ville..."
                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
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

    <!-- Liste des contacts -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Téléphone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ville</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody id="contactsTableBody">
                    <?php if (empty($contacts)): ?>
                        <tr id="noContactsRow">
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-address-book text-4xl mb-2 block"></i>
                                Aucun contact pour le moment.
                                <a href="index.php?page=contacts/ajouter" class="text-blue-600 block mt-2">Ajouter votre premier contact →</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($contacts as $contact): 
                            $isBlacklisted = in_array($contact['id_contact'], $blacklistedIds);
                            $blacklistInfo = $isBlacklisted ? $blacklistDetails[$contact['id_contact']] : null;
                            $motif = $blacklistInfo ? $blacklistInfo['motif'] : '';
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
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-800"><?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?></div>
                                 </td>
                                <td class="px-6 py-4"><?= htmlspecialchars($contact['email'] ?? '-') ?></td>
                                <td class="px-6 py-4"><?= htmlspecialchars($contact['telephone'] ?? '-') ?></td>
                                <td class="px-6 py-4"><?= htmlspecialchars($contact['ville'] ?? '-') ?></td>
                                <td class="px-6 py-4"><?= date('d/m/Y', strtotime($contact['date_inscription'])) ?></td>
                                <td class="px-6 py-4">
                                    <?php if ($isBlacklisted): ?>
                                        <button onclick="openUnblacklistModal('<?= $contact['id_contact'] ?>', '<?= addslashes($contact['prenom'] . ' ' . $contact['nom']) ?>', '<?= addslashes($motif) ?>')" 
                                                class="px-2 py-1 rounded text-xs bg-red-100 text-red-700 hover:bg-red-200 transition cursor-pointer flex items-center gap-1" title="Cliquer pour débloquer">
                                            <i class="fas fa-ban mr-1"></i> Blacklisté
                                            <i class="fas fa-chevron-down text-xs"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="px-2 py-1 rounded text-xs bg-green-100 text-green-700">
                                            <i class="fas fa-check-circle mr-1"></i> Normal
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 space-x-2">
                                    <a href="index.php?page=contacts/modifier&id=<?= $contact['id_contact'] ?>" 
                                       class="text-blue-600 hover:text-blue-800 transition" title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button type="button" 
                                            onclick="showDeleteModal('<?= $contact['id_contact'] ?>', '<?= addslashes($contact['prenom'] . ' ' . $contact['nom']) ?>')"
                                            class="text-red-600 hover:text-red-800 transition" title="Supprimer">
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

<!-- MODALE POUR DÉBLACKLISTER -->
<div id="unblacklistModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-content-unblacklist">
        <div class="p-6 text-center">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-4">
                <i class="fas fa-unlock-alt text-green-600 text-3xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">Débloquer le contact</h3>
            <p class="text-gray-500 mb-2">
                Êtes-vous sûr de vouloir retirer le contact <br>
                <strong id="unblacklistContactName" class="text-gray-700"></strong> de la blacklist ?
            </p>
            <div class="bg-yellow-50 p-3 rounded-lg mb-6 text-left">
                <p class="text-xs text-yellow-700">
                    <i class="fas fa-info-circle mr-1"></i> Motif du blocage :
                </p>
                <p id="unblacklistMotif" class="text-sm text-gray-600 italic">-</p>
            </div>
            <form method="POST" action="?page=contacts/unblacklist" id="unblacklistForm">
                <input type="hidden" name="id_contact" id="unblacklistContactId">
                <div class="flex space-x-3">
                    <button type="button" onclick="closeUnblacklistModal()" 
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition">
                        <i class="fas fa-check mr-2"></i>Débloquer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE DE CONFIRMATION SUPPRESSION -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 transform transition-all duration-300 scale-95 opacity-0" id="modalContent">
        <div class="p-6 text-center">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">Confirmer la suppression</h3>
            <p class="text-gray-500 mb-6">
                Êtes-vous sûr de vouloir supprimer le contact <br>
                <span id="contactName" class="font-semibold text-gray-700"></span> ?
            </p>
            <p class="text-sm text-gray-400 mb-6">Cette action est irréversible.</p>
            <div class="flex space-x-3">
                <button type="button" onclick="closeModal()" 
                        class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Annuler
                </button>
                <a href="#" id="confirmDeleteBtn" 
                   class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition text-center">
                    Supprimer
                </a>
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

// Afficher les messages flash sous forme de toasts
<?php if ($flashMessage): ?>
    showToast('<?= addslashes($flashMessage) ?>', 'success');
<?php endif; ?>

<?php if ($flashError): ?>
    showToast('<?= addslashes($flashError) ?>', 'error');
<?php endif; ?>

// ============================================
// FILTRES ET RECHERCHE
// ============================================
const searchInput = document.getElementById('searchInput');
const filterBtns = document.querySelectorAll('.filter-btn');
const contactsRows = document.querySelectorAll('.contact-row');
const filteredCountSpan = document.getElementById('filteredCount');
let currentFilter = 'all';

function filterContacts() {
    const searchTerm = searchInput.value.toLowerCase().trim();
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
        if (currentFilter === 'email') {
            filterMatch = hasEmail;
        } else if (currentFilter === 'phone') {
            filterMatch = hasPhone;
        } else if (currentFilter === 'blacklisted') {
            filterMatch = isBlacklisted;
        }
        
        let searchMatch = true;
        if (searchTerm !== '') {
            searchMatch = name.includes(searchTerm) || 
                          email.includes(searchTerm) || 
                          phone.includes(searchTerm) || 
                          city.includes(searchTerm);
        }
        
        if (filterMatch && searchMatch) {
            row.classList.remove('hidden-row');
            visibleCount++;
        } else {
            row.classList.add('hidden-row');
        }
    });
    
    if (filteredCountSpan) {
        filteredCountSpan.textContent = `${visibleCount} contact(s) affiché(s)`;
    }
    
    let noResultRow = document.getElementById('noResultRow');
    if (visibleCount === 0 && contactsRows.length > 0) {
        if (!noResultRow) {
            const tbody = document.getElementById('contactsTableBody');
            noResultRow = document.createElement('tr');
            noResultRow.id = 'noResultRow';
            noResultRow.innerHTML = '<td colspan="7" class="px-6 py-8 text-center text-gray-500">' +
                '<i class="fas fa-search text-4xl mb-2 block"></i>' +
                'Aucun contact ne correspond à votre recherche.' +
                '</td>';
            tbody.appendChild(noResultRow);
        }
        noResultRow.style.display = '';
    } else if (noResultRow) {
        noResultRow.style.display = 'none';
    }
}

if (searchInput) {
    searchInput.addEventListener('input', filterContacts);
}

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
// MODALE POUR DÉBLACKLISTER
// ============================================
function openUnblacklistModal(contactId, contactName, motif) {
    const modal = document.getElementById('unblacklistModal');
    const modalContent = modal.querySelector('.modal-content-unblacklist');
    document.getElementById('unblacklistContactId').value = contactId;
    document.getElementById('unblacklistContactName').innerHTML = contactName;
    document.getElementById('unblacklistMotif').innerHTML = motif || 'Aucun motif spécifié';
    
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

// ============================================
// MODALE SUPPRESSION
// ============================================
function showDeleteModal(contactId, contactName) {
    const modal = document.getElementById('deleteModal');
    const modalContent = document.getElementById('modalContent');
    document.getElementById('contactName').textContent = contactName;
    document.getElementById('confirmDeleteBtn').href = 'index.php?page=contacts/supprimer&id=' + contactId;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeModal() {
    const modal = document.getElementById('deleteModal');
    const modalContent = document.getElementById('modalContent');
    modalContent.classList.remove('modal-show');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 200);
}

// Fermeture des modales
document.getElementById('deleteModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.getElementById('unblacklistModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeUnblacklistModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        closeUnblacklistModal();
    }
});
</script>

</body>
</html>