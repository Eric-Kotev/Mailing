<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Pagination
$page = isset($_GET['page_num']) ? max(1, intval($_GET['page_num'])) : 1;
$limit = 10; // Nombre de campagnes par page
$offset = ($page - 1) * $limit;

// Compter le total des campagnes
$totalCampagnes = count($db->select('campagne_config', ['id_compte' => $idCompte]));
$totalPages = ceil($totalCampagnes / $limit);

// Récupérer les campagnes avec pagination
$campagnes = $db->select('campagne_config', ['id_compte' => $idCompte], '*', 'created_at DESC', $limit, $offset);

// Compter les envois pour chaque campagne
foreach ($campagnes as $key => $campagne) {
    $envois = $db->select('campagne', ['id_campagne_config' => $campagne['id_campagne_config']]);
    $campagnes[$key]['nb_envois'] = count($envois);
}

// Récupérer les listes pour le modal de création
$listes = $db->select('liste', ['id_compte' => $idCompte], '*', 'nom_liste ASC');

// Traitement de la création d'une nouvelle campagne (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_creer_campagne']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $nom_campagne = trim($_POST['nom_campagne'] ?? '');
    $id_liste = !empty($_POST['id_liste']) ? $_POST['id_liste'] : null;
    $objet = trim($_POST['objet'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $date_planification = !empty($_POST['date_planification']) ? $_POST['date_planification'] : null;
    
    if (empty($nom_campagne)) {
        echo json_encode(['success' => false, 'error' => 'Veuillez saisir un nom de campagne']);
        exit;
    }
    if (empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Veuillez saisir un message']);
        exit;
    }
    
    $data = [
        'id_compte' => $idCompte,
        'nom_campagne' => $nom_campagne,
        'id_liste' => $id_liste,
        'objet' => $objet,
        'message' => $message,
        'date_planification' => $date_planification,
        'statut' => $date_planification ? 'planifiee' : 'brouillon',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        $db->insert('campagne_config', $data);
        echo json_encode(['success' => true, 'message' => 'Campagne créée avec succès']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Suppression d'une campagne
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $campagne = $db->select('campagne_config', ['id_campagne_config' => $id, 'id_compte' => $idCompte]);
    if ($campagne && $campagne[0]['statut'] == 'brouillon') {
        $db->delete('campagne_config', $id, 'id_campagne_config');
        $_SESSION['flash_message'] = "Campagne supprimée avec succès";
    } else {
        $_SESSION['flash_error'] = "Impossible de supprimer cette campagne";
    }
    header('Location: index.php?page=campagnes/index');
    exit;
}

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
    <title>Mes campagnes - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
        
        .modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        .modal-campagne {
            transition: all 0.3s ease;
            transform: scale(0.95);
            opacity: 0;
        }
        .modal-campagne.modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .status-brouillon { background: #f3f4f6; color: #4b5563; }
        .status-planifiee { background: #fef3c7; color: #92400e; }
        .status-envoyee { background: #dcfce7; color: #166534; }
        
        .campagne-row {
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .campagne-row:hover {
            background-color: #f9fafb;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            color: #4b5563;
            text-decoration: none;
            transition: all 0.2s;
        }
        .pagination a:hover {
            background-color: #f3f4f6;
            border-color: #cbd5e1;
        }
        .pagination .active {
            background-color: #8b5cf6;
            border-color: #8b5cf6;
            color: white;
        }
        .pagination .disabled {
            color: #cbd5e1;
            cursor: not-allowed;
        }
    </style>
</head>
<body>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Mes campagnes</h1>
            <p class="text-gray-500">Gérez toutes vos campagnes marketing</p>
        </div>
        <button type="button" onclick="openAddCampagneModal()" 
                class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-plus mr-2"></i>Nouvelle campagne
        </button>
    </div>

    <?php if ($flashMessage): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded"><?= $flashMessage ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded"><?= $flashError ?></div>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-500">Total campagnes</span>
                    <span class="text-2xl font-bold text-gray-800 ml-2"><?= $totalCampagnes ?></span>
                </div>
                <div class="text-purple-400"><i class="fas fa-bullhorn text-2xl"></i></div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-500">Campagnes actives</span>
                    <span class="text-2xl font-bold text-green-600 ml-2">
                        <?php 
                        $activeCount = 0;
                        $allCampagnes = $db->select('campagne_config', ['id_compte' => $idCompte]);
                        foreach ($allCampagnes as $c) {
                            if ($c['statut'] == 'brouillon' || $c['statut'] == 'planifiee') $activeCount++;
                        }
                        echo $activeCount;
                        ?>
                    </span>
                </div>
                <div class="text-green-400"><i class="fas fa-play-circle text-2xl"></i></div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-500">Messages envoyés</span>
                    <span class="text-2xl font-bold text-blue-600 ml-2">
                        <?php 
                        $totalEnvois = 0;
                        foreach ($allCampagnes as $c) { 
                            $envoisCount = $db->select('campagne', ['id_campagne_config' => $c['id_campagne_config']]);
                            $totalEnvois += count($envoisCount);
                        }
                        echo $totalEnvois;
                        ?>
                    </span>
                </div>
                <div class="text-blue-400"><i class="fas fa-envelope text-2xl"></i></div>
            </div>
        </div>
    </div>

    <!-- Barre de recherche -->
    <div class="bg-white rounded-lg shadow p-4">
        <div class="relative">
            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            <input type="text" id="searchInput" placeholder="Rechercher une campagne par nom..." 
                   class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
        </div>
        <div class="mt-2 text-right">
            <span id="filteredCount" class="text-xs text-gray-500"></span>
        </div>
    </div>

    <!-- Tableau des campagnes -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Objet</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Messages</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date création</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody id="campagnesTableBody">
                    <?php if (empty($campagnes)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-bullhorn text-4xl mb-2 block"></i>
                                Aucune campagne pour le moment.
                                <button onclick="openAddCampagneModal()" class="text-purple-600 block mt-2">Créer votre première campagne →</button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($campagnes as $campagne): ?>
                            <tr class="campagne-row hover:bg-gray-50" 
                                data-name="<?= strtolower(htmlspecialchars($campagne['nom_campagne'])) ?>"
                                onclick="window.location.href='index.php?page=campagnes/details&id=<?= $campagne['id_campagne_config'] ?>'">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="bg-purple-100 rounded-full p-2 mr-3">
                                            <i class="fas fa-bullhorn text-purple-600 text-sm"></i>
                                        </div>
                                        <div>
                                            <span class="font-medium text-gray-800"><?= htmlspecialchars($campagne['nom_campagne']) ?></span>
                                            <?php if ($campagne['id_liste']): ?>
                                                <div class="text-xs text-gray-400">
                                                    <i class="fas fa-list mr-1"></i> Liste associée
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-600 max-w-xs truncate" title="<?= htmlspecialchars($campagne['objet'] ?? '') ?>">
                                        <?= htmlspecialchars($campagne['objet'] ?? '-') ?>
                                    </div>
                                    <div class="text-xs text-gray-400 truncate max-w-xs" title="<?= htmlspecialchars($campagne['message']) ?>">
                                        <?= htmlspecialchars(substr($campagne['message'], 0, 40)) ?>...
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="font-semibold text-blue-600"><?= $campagne['nb_envois'] ?></span>
                                    <span class="text-xs text-gray-500"> envoi(s)</span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="status-badge status-<?= $campagne['statut'] ?>">
                                        <?php
                                        $statusText = [
                                            'brouillon' => 'Brouillon',
                                            'planifiee' => 'Planifiée',
                                            'envoyee' => 'Envoyée'
                                        ];
                                        echo $statusText[$campagne['statut']] ?? $campagne['statut'];
                                        ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <?= date('d/m/Y H:i', strtotime($campagne['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4 text-center whitespace-nowrap">
                                    <?php if ($campagne['statut'] == 'brouillon'): ?>
                                        <a href="index.php?page=campagnes/index&delete=<?= $campagne['id_campagne_config'] ?>" 
                                           onclick="event.stopPropagation(); return confirm('Supprimer cette campagne ?')"
                                           class="text-red-600 hover:text-red-800 inline-flex items-center mx-1" title="Supprimer">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    <?php endif; ?>
                                    <button onclick="event.stopPropagation(); window.location.href='index.php?page=campagnes/details&id=<?= $campagne['id_campagne_config'] ?>'" 
                                            class="text-blue-600 hover:text-blue-800 inline-flex items-center mx-1" title="Voir détails">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=campagnes/index&page_num=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i> Précédent</a>
        <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-left"></i> Précédent</span>
        <?php endif; ?>
        
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i == $page): ?>
                <span class="active"><?= $i ?></span>
            <?php else: ?>
                <a href="?page=campagnes/index&page_num=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
            <a href="?page=campagnes/index&page_num=<?= $page + 1 ?>">Suivant <i class="fas fa-chevron-right"></i></a>
        <?php else: ?>
            <span class="disabled">Suivant <i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- MODALE D'AJOUT DE CAMPAGNE -->
<div id="addCampagneModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 modal-campagne">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-purple-100 p-2 rounded-full mr-3">
                        <i class="fas fa-plus-circle text-purple-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Nouvelle campagne</h3>
                </div>
                <button type="button" onclick="closeAddCampagneModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="addCampagneForm" method="POST">
                <input type="hidden" name="action_creer_campagne" value="1">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Nom de la campagne * <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="nom_campagne" id="nom_campagne" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500"
                               placeholder="Ex: Newsletter Juin 2026">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Liste de diffusion
                        </label>
                        <select name="id_liste" id="liste_id" class="w-full">
                            <option value="">-- Sélectionnez une liste --</option>
                            <?php foreach ($listes as $liste): ?>
                                <option value="<?= $liste['id_liste'] ?>">
                                    <?= htmlspecialchars($liste['nom_liste']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Optionnel. Vous pourrez choisir les destinataires à l'envoi.</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Objet
                        </label>
                        <input type="text" name="objet" id="objet" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500"
                               placeholder="Objet du message (optionnel)">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Message * <span class="text-red-500">*</span>
                        </label>
                        <textarea name="message" id="message" rows="4" required 
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500"
                                  placeholder="Votre message..."></textarea>
                        <p class="text-xs text-gray-500 mt-1" id="charCount">0 caractères</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Date de planification
                        </label>
                        <input type="datetime-local" name="date_planification" id="date_planification" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500">
                        <p class="text-xs text-gray-500 mt-1">Laissez vide pour un envoi immédiat</p>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeAddCampagneModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" id="submitBtn"
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
                        <i class="fas fa-save mr-2"></i>Créer la campagne
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#liste_id').select2({
        placeholder: "-- Sélectionnez une liste --",
        allowClear: true,
        width: '100%',
        language: 'fr'
    });
});

function openAddCampagneModal() {
    const modal = document.getElementById('addCampagneModal');
    const modalContent = modal.querySelector('.modal-campagne');
    document.getElementById('addCampagneForm').reset();
    $('#liste_id').val(null).trigger('change');
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeAddCampagneModal() {
    const modal = document.getElementById('addCampagneModal');
    const modalContent = modal.querySelector('.modal-campagne');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

document.getElementById('addCampagneForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Création...';
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
            closeAddCampagneModal();
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

function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const colors = { success: '#10b981', error: '#ef4444' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

const messageTextarea = document.getElementById('message');
if (messageTextarea) {
    messageTextarea.addEventListener('input', function() {
        const countSpan = document.getElementById('charCount');
        if (countSpan) countSpan.textContent = this.value.length + ' caractères';
    });
}

// Recherche sur la page courante
const searchInput = document.getElementById('searchInput');
const campagneRows = document.querySelectorAll('.campagne-row');
const filteredCountSpan = document.getElementById('filteredCount');
const totalVisible = campagneRows.length;

if (searchInput) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        let visibleCount = 0;
        
        campagneRows.forEach(row => {
            const name = row.getAttribute('data-name') || '';
            if (searchTerm === '' || name.includes(searchTerm)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        if (filteredCountSpan) {
            filteredCountSpan.textContent = `${visibleCount} campagne(s) affichée(s) sur cette page`;
        }
    });
    
    if (filteredCountSpan) {
        filteredCountSpan.textContent = `${totalVisible} campagne(s) sur cette page`;
    }
}

document.getElementById('addCampagneModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeAddCampagneModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAddCampagneModal();
});
</script>

</body>
</html>