<?php
global $db;
$idCompte = $_SESSION['user_id'];

// Récupérer toutes les campagnes
$allCampagnes = $db->select('campagne', ['id_compte' => $idCompte], '*', 'created_at DESC');

// Statistiques
$stats = ['total' => 0, 'whatsapp' => 0, 'sms' => 0, 'messages' => 0];
foreach ($allCampagnes as $c) {
    $stats['total']++;
    if ($c['type_campagne'] == 'whatsapp') $stats['whatsapp']++;
    else $stats['sms']++;
    $stats['messages'] += $c['nb_envoyes'];
}
?>

<div class="space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Historique des campagnes</h1>
            <p class="text-gray-500">Retrouvez toutes vos campagnes d'envoi (SMS et WhatsApp)</p>
        </div>
        <a href="javascript:history.back()" class="text-blue-600 hover:text-blue-800">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
    </div>

    <!-- Cartes statistiques -->
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-blue-600"><?= $stats['total'] ?></div>
            <div class="text-sm text-gray-500">Campagnes totales</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-green-600"><?= $stats['whatsapp'] ?></div>
            <div class="text-sm text-gray-500">WhatsApp</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-blue-600"><?= $stats['sms'] ?></div>
            <div class="text-sm text-gray-500">SMS</div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex flex-wrap gap-2 mb-4">
            <button onclick="filterByType('tous')" id="filterTous" class="px-4 py-2 rounded-lg transition bg-blue-600 text-white">
                Tous
            </button>
            <button onclick="filterByType('whatsapp')" id="filterWhatsapp" class="px-4 py-2 rounded-lg transition bg-gray-200 text-gray-700 hover:bg-gray-300">
                <i class="fab fa-whatsapp mr-1"></i> WhatsApp
            </button>
            <button onclick="filterByType('sms')" id="filterSms" class="px-4 py-2 rounded-lg transition bg-gray-200 text-gray-700 hover:bg-gray-300">
                <i class="fas fa-comment-dots mr-1"></i> SMS
            </button>
        </div>
        
        <div class="flex gap-2">
            <div class="flex-1 relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="searchInput" 
                       placeholder="Rechercher..."
                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
            </div>
            <button onclick="clearSearch()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg">
                <i class="fas fa-times mr-1"></i> Effacer
            </button>
        </div>
    </div>

    <!-- Tableau -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Message</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Nb</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Appareil</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php foreach ($allCampagnes as $c): ?>
                        <tr class="campagne-row hover:bg-gray-50" data-type="<?= $c['type_campagne'] ?>">
                            <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                                <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($c['type_campagne'] == 'whatsapp'): ?>
                                    <span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs">WhatsApp</span>
                                <?php else: ?>
                                    <span class="bg-blue-100 text-blue-700 px-2 py-1 rounded-full text-xs">SMS</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm text-gray-800 max-w-md truncate" title="<?= htmlspecialchars($c['message']) ?>">
                                    <?= htmlspecialchars(substr($c['message'], 0, 50)) ?>...
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center text-sm text-gray-600"><?= $c['nb_destinataires'] ?></td>
                            <td class="px-4 py-3 text-sm text-gray-500">
                                <?= htmlspecialchars(substr($c['appareil_utilise'] ?? '-', 0, 30)) ?>
                            </td>
                            <td class="px-4 py-3">
                                <button onclick='showDetails(<?= json_encode($c) ?>);' class="text-blue-600 hover:text-blue-800">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="noResults" class="text-center py-8 hidden">
            <p class="text-gray-500">Aucune campagne trouvée</p>
        </div>
    </div>
</div>

<!-- Modal Détails -->
<div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[85vh] overflow-y-auto">
        <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 flex justify-between items-center">
            <h3 class="text-xl font-bold text-gray-800" id="modalTitle"></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-6" id="modalContent"></div>
        <div class="sticky bottom-0 bg-gray-50 border-t border-gray-200 px-6 py-4 flex justify-end">
            <button onclick="closeModal()" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg">Fermer</button>
        </div>
    </div>
</div>

<script>
function filterByType(type) {
    var rows = document.querySelectorAll('.campagne-row');
    var filterTous = document.getElementById('filterTous');
    var filterWhatsapp = document.getElementById('filterWhatsapp');
    var filterSms = document.getElementById('filterSms');
    
    if (type === 'tous') {
        filterTous.className = 'px-4 py-2 rounded-lg transition bg-blue-600 text-white';
        filterWhatsapp.className = 'px-4 py-2 rounded-lg transition bg-gray-200 text-gray-700 hover:bg-gray-300';
        filterSms.className = 'px-4 py-2 rounded-lg transition bg-gray-200 text-gray-700 hover:bg-gray-300';
    } else if (type === 'whatsapp') {
        filterTous.className = 'px-4 py-2 rounded-lg transition bg-gray-200 text-gray-700 hover:bg-gray-300';
        filterWhatsapp.className = 'px-4 py-2 rounded-lg transition bg-green-600 text-white';
        filterSms.className = 'px-4 py-2 rounded-lg transition bg-gray-200 text-gray-700 hover:bg-gray-300';
    } else if (type === 'sms') {
        filterTous.className = 'px-4 py-2 rounded-lg transition bg-gray-200 text-gray-700 hover:bg-gray-300';
        filterWhatsapp.className = 'px-4 py-2 rounded-lg transition bg-gray-200 text-gray-700 hover:bg-gray-300';
        filterSms.className = 'px-4 py-2 rounded-lg transition bg-blue-600 text-white';
    }
    
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var rowType = row.getAttribute('data-type');
        
        if (type === 'tous' || rowType === type) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
    
    filterTable();
}

function filterTable() {
    var searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
    var rows = document.querySelectorAll('.campagne-row');
    var visibleCount = 0;
    var noResults = document.getElementById('noResults');
    
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var text = row.textContent.toLowerCase();
        
        if (searchTerm === '' || text.indexOf(searchTerm) !== -1) {
            if (row.style.display !== 'none') {
                row.style.display = '';
                visibleCount++;
            }
        } else {
            if (row.style.display !== 'none') {
                row.style.display = 'none';
            }
        }
    }
    
    if (visibleCount === 0) {
        noResults.classList.remove('hidden');
    } else {
        noResults.classList.add('hidden');
    }
}

function clearSearch() {
    document.getElementById('searchInput').value = '';
    filterTable();
}

function showDetails(campagne) {
    var modal = document.getElementById('detailsModal');
    var modalTitle = document.getElementById('modalTitle');
    var modalContent = document.getElementById('modalContent');
    
    modalTitle.textContent = campagne.titre || 'Détails de la campagne';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    var statusBadge = campagne.statut === 'envoye' 
        ? '<span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs"><i class="fas fa-check-circle mr-1"></i>Envoyé</span>'
        : '<span class="bg-red-100 text-red-700 px-2 py-1 rounded-full text-xs"><i class="fas fa-exclamation-circle mr-1"></i>Échoué</span>';
    
    var typeIcon = campagne.type_campagne === 'whatsapp' ? 'fab fa-whatsapp text-green-600' : 'fas fa-comment-dots text-blue-600';
    var typeBg = campagne.type_campagne === 'whatsapp' ? 'bg-green-100' : 'bg-blue-100';
    
    var destList = '';
    if (campagne.destinataires) {
        var destinataires = [];
        try {
            destinataires = JSON.parse(campagne.destinataires);
        } catch(e) {
            destinataires = [campagne.destinataires];
        }
        
        if (destinataires && destinataires.length > 0) {
            destList = '<div class="space-y-1 max-h-48 overflow-y-auto">';
            for (var i = 0; i < destinataires.length; i++) {
                destList += '<div class="flex items-center p-2 bg-gray-50 rounded-lg mb-1">' +
                            '<i class="fas fa-user-circle text-gray-400 mr-2"></i>' +
                            '<span class="text-sm">' + escapeHtml(destinataires[i]) + '</span>' +
                            '</div>';
            }
            destList += '</div>';
        } else {
            destList = '<p class="text-gray-500 italic">Aucun destinataire</p>';
        }
    } else {
        destList = '<p class="text-gray-500 italic">Aucun destinataire</p>';
    }
    
    modalContent.innerHTML = `
        <div class="space-y-5">
            <div class="flex items-center mb-2">
                <div class="${typeBg} w-10 h-10 rounded-full flex items-center justify-center mr-3">
                    <i class="${typeIcon} text-xl"></i>
                </div>
                <div>${statusBadge}</div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 mb-1">Date</div>
                    <div class="font-medium">${escapeHtml(campagne.created_at ? formatDate(campagne.created_at) : '-')}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 mb-1">Appareil</div>
                    <div class="font-medium text-sm">${escapeHtml(campagne.appareil_utilise || '-')}</div>
                </div>
            </div>
            
            <div>
                <div class="text-xs text-gray-500 mb-2">Message</div>
                <div class="bg-gray-50 rounded-lg p-3 max-h-32 overflow-y-auto">
                    <p class="text-sm whitespace-pre-wrap">${escapeHtml(campagne.message || '-')}</p>
                </div>
            </div>
            
            <div>
                <div class="text-xs text-gray-500 mb-2">Statistiques</div>
                <div class="grid grid-cols-3 gap-3">
                    <div class="bg-blue-50 rounded-lg p-3 text-center">
                        <div class="text-2xl font-bold text-blue-600">${campagne.nb_destinataires || 0}</div>
                        <div class="text-xs">Total</div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-3 text-center">
                        <div class="text-2xl font-bold text-green-600">${campagne.nb_succes || 0}</div>
                        <div class="text-xs">Succès</div>
                    </div>
                    <div class="bg-red-50 rounded-lg p-3 text-center">
                        <div class="text-2xl font-bold text-red-600">${campagne.nb_erreurs || 0}</div>
                        <div class="text-xs">Échecs</div>
                    </div>
                </div>
            </div>
            
            <div>
                <div class="text-xs text-gray-500 mb-2">Destinataires (${campagne.nb_destinataires || 0})</div>
                <div class="bg-gray-50 rounded-lg p-3">${destList}</div>
            </div>
        </div>
    `;
}

function formatDate(dateString) {
    if (!dateString) return '-';
    var date = new Date(dateString);
    return date.toLocaleDateString('fr-FR') + ' ' + date.toLocaleTimeString('fr-FR');
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function closeModal() {
    var modal = document.getElementById('detailsModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

document.getElementById('searchInput').addEventListener('keyup', filterTable);
document.getElementById('detailsModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<style>
    #detailsModal { z-index: 1000; }
    .campagne-row { transition: all 0.2s ease; }
</style>