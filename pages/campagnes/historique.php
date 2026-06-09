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
        <a href="index.php?page=campagnes/choix" class="text-blue-600 hover:text-blue-800">
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
        
        <!-- Barre de recherche -->
        <div class="flex gap-2">
            <div class="flex-1 relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="searchInput" 
                       placeholder="Rechercher par titre, message, destinataire ou appareil..."
                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition"
                       onkeyup="filterTable()">
            </div>
            <button onclick="clearSearch()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg">
                <i class="fas fa-times mr-1"></i> Effacer
            </button>
        </div>
    </div>

    <!-- Bouton export -->
    <div class="flex justify-end">
        <button onclick="exportToCSV()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
            <i class="fas fa-download mr-2"></i> Exporter CSV
        </button>
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
                    <?php foreach ($allCampagnes as $c): 
                        // Préparer les données pour le filtrage
                        $titre = strtolower(htmlspecialchars($c['titre'], ENT_QUOTES, 'UTF-8'));
                        $message = strtolower(htmlspecialchars($c['message'], ENT_QUOTES, 'UTF-8'));
                        $destArray = json_decode($c['destinataires'], true);
                        $destStr = is_array($destArray) ? implode(' ', $destArray) : $c['destinataires'];
                        $destStr = strtolower(htmlspecialchars($destStr, ENT_QUOTES, 'UTF-8'));
                        $appareil = strtolower(htmlspecialchars($c['appareil_utilise'] ?? '', ENT_QUOTES, 'UTF-8'));
                        $typeCampagne = $c['type_campagne'];
                    ?>
                        <tr class="campagne-row hover:bg-gray-50" 
                            data-type="<?= $typeCampagne ?>"
                            data-search="<?= $titre . ' ' . $message . ' ' . $destStr . ' ' . $appareil ?>">
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
                                <button onclick="showDetails('<?= $c['id_campagne'] ?>')" class="text-blue-600 hover:text-blue-800">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="noResults" class="text-center py-8 hidden">
            <i class="fas fa-search text-4xl text-gray-300 mb-2"></i>
            <p class="text-gray-500">Aucune campagne ne correspond à votre recherche</p>
        </div>
    </div>
</div>

<!-- Modal Détails -->
<div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[80vh] overflow-y-auto">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-gray-800" id="modalTitle"></h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div id="modalContent" class="text-center py-8">Chargement...</div>
            <div class="flex justify-end mt-6">
                <button onclick="closeModal()" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentType = 'tous';
let allRows = [];

function initRows() {
    allRows = Array.from(document.querySelectorAll('.campagne-row'));
}

function filterByType(type) {
    currentType = type;
    
    // Mettre à jour le style des boutons
    document.getElementById('filterTous').className = type == 'tous' ? 'px-4 py-2 rounded-lg transition bg-blue-600 text-white' : 'px-4 py-2 rounded-lg transition bg-gray-200 text-gray-700 hover:bg-gray-300';
    document.getElementById('filterWhatsapp').className = type == 'whatsapp' ? 'px-4 py-2 rounded-lg transition bg-green-600 text-white' : 'px-4 py-2 rounded-lg transition bg-gray-200 text-gray-700 hover:bg-gray-300';
    document.getElementById('filterSms').className = type == 'sms' ? 'px-4 py-2 rounded-lg transition bg-blue-600 text-white' : 'px-4 py-2 rounded-lg transition bg-gray-200 text-gray-700 hover:bg-gray-300';
    
    filterTable();
}

function filterTable() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
    let visibleCount = 0;
    
    allRows.forEach(row => {
        const rowType = row.getAttribute('data-type');
        const rowSearch = row.getAttribute('data-search') || '';
        
        // Vérifier le type
        let typeMatch = (currentType == 'tous' || rowType == currentType);
        
        // Vérifier la recherche
        let searchMatch = true;
        if (searchTerm !== '') {
            searchMatch = rowSearch.includes(searchTerm);
        }
        
        if (typeMatch && searchMatch) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    // Afficher ou cacher le message "aucun résultat"
    const noResults = document.getElementById('noResults');
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

// Initialisation
document.addEventListener('DOMContentLoaded', function() {
    initRows();
    document.getElementById('searchInput').addEventListener('keyup', filterTable);
});

function showDetails(id) {
    const modal = document.getElementById('detailsModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modalContent.innerHTML = '<div class="text-center py-8">Chargement...</div>';
    
    fetch(`/get_campagne_details.php?id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                modalTitle.textContent = data.titre;
                let destHtml = '<ul class="list-disc pl-5">';
                if (data.destinataires && data.destinataires.length > 0) {
                    data.destinataires.forEach(d => { destHtml += `<li>${escapeHtml(d)}</li>`; });
                } else {
                    destHtml = '<p class="text-gray-500">Aucun destinataire</p>';
                }
                destHtml += '</ul>';
                
                modalContent.innerHTML = `
                    <div class="space-y-3">
                        <div><label class="font-medium">Date:</label> ${data.date}</div>
                        <div><label class="font-medium">Type:</label> ${data.type === 'whatsapp' ? 'WhatsApp' : 'SMS'}</div>
                        <div><label class="font-medium">Appareil:</label> ${escapeHtml(data.appareil_utilise || '-')}</div>
                        <div><label class="font-medium">Message:</label><br><div class="p-2 bg-gray-50 rounded mt-1">${escapeHtml(data.message)}</div></div>
                        <div><label class="font-medium">Destinataires (${data.nb_destinataires}):</label><br><div class="p-2 bg-gray-50 rounded mt-1 max-h-40 overflow-y-auto">${destHtml}</div></div>
                        <div class="grid grid-cols-3 gap-2 text-center pt-2">
                            <div class="p-2 bg-gray-100 rounded"><span class="font-bold text-blue-600">${data.nb_destinataires}</span><br><span class="text-xs">Total</span></div>
                            <div class="p-2 bg-gray-100 rounded"><span class="font-bold text-green-600">${data.nb_succes}</span><br><span class="text-xs">Succès</span></div>
                            <div class="p-2 bg-gray-100 rounded"><span class="font-bold text-red-600">${data.nb_erreurs}</span><br><span class="text-xs">Échecs</span></div>
                        </div>
                    </div>
                `;
            } else {
                modalContent.innerHTML = `<p class="text-red-500">Erreur: ${data.error}</p>`;
            }
        })
        .catch(err => { modalContent.innerHTML = `<p class="text-red-500">Erreur: ${err.message}</p>`; });
}

function closeModal() {
    const modal = document.getElementById('detailsModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function exportToCSV() {
    const type = currentType;
    const search = document.getElementById('searchInput').value;
    let url = `/export_campagnes.php?type=${type}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    window.location.href = url;
}
</script>

<style>
    #detailsModal { z-index: 1000; }
    .campagne-row { transition: all 0.2s ease; }
</style>