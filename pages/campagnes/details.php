<?php
global $db;

$idCompte = $_SESSION['user_id'];
$campagneId = $_GET['id'] ?? null;

if (!$campagneId) {
    header('Location: index.php?page=campagnes/index');
    exit;
}

// Récupérer la campagne
$campagne = $db->select('campagne_config', ['id_campagne_config' => $campagneId, 'id_compte' => $idCompte]);
if (empty($campagne)) {
    header('Location: index.php?page=campagnes/index');
    exit;
}
$campagne = $campagne[0];

// Récupérer tous les envois liés à cette campagne
$envois = $db->select('campagne', ['id_campagne_config' => $campagneId], '*', 'created_at DESC');

$totalEnvois = count($envois);
$totalSucces = 0;
$totalErreurs = 0;
foreach ($envois as $e) {
    $totalSucces += $e['nb_succes'];
    $totalErreurs += $e['nb_erreurs'];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($campagne['nom_campagne']) ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
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
        .campagne-row.hidden-row { display: none; }
        
        .envoi-row {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .envoi-row:hover {
            background-color: #f9fafb;
        }
    </style>
</head>
<body>

<div class="max-w-5xl mx-auto py-8 px-4">
    <!-- En-tête -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center">
            <a href="index.php?page=campagnes/index" class="text-blue-600 hover:text-blue-800 mr-4">
                <i class="fas fa-arrow-left"></i> Retour
            </a>
            <div class="bg-purple-100 p-3 rounded-full mr-4">
                <i class="fas fa-bullhorn text-purple-600 text-xl"></i>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($campagne['nom_campagne']) ?></h1>
                <p class="text-gray-500">Gérez les messages de cette campagne</p>
            </div>
        </div>
        <!-- Bouton Nouveau message - Toujours visible -->
        <a href="index.php?page=campagnes/choix&campagne_id=<?= $campagneId ?>" 
           class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-plus mr-2"></i>Nouveau message
        </a>
    </div>

    <!-- Informations de la campagne -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="text-xs text-gray-500 uppercase">Date de création</label>
                <div class="mt-1 font-medium"><?= date('d/m/Y H:i', strtotime($campagne['created_at'])) ?></div>
            </div>
            <?php if ($campagne['date_planification']): ?>
                <div>
                    <label class="text-xs text-gray-500 uppercase">Planifiée le</label>
                    <div class="mt-1 font-medium"><?= date('d/m/Y H:i', strtotime($campagne['date_planification'])) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($campagne['objet']): ?>
                <div>
                    <label class="text-xs text-gray-500 uppercase">Objet</label>
                    <div class="mt-1 font-medium"><?= htmlspecialchars($campagne['objet']) ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistiques des envois -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-blue-600"><?= $totalEnvois ?></div>
            <div class="text-sm text-gray-500">Messages envoyés</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-green-600"><?= $totalSucces ?></div>
            <div class="text-sm text-gray-500">Destinataires touchés</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 text-center">
            <div class="text-2xl font-bold text-red-600"><?= $totalErreurs ?></div>
            <div class="text-sm text-gray-500">Échecs</div>
        </div>
    </div>

    <!-- Barre de recherche -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <div class="relative">
            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            <input type="text" id="searchInput" placeholder="Rechercher un message..." 
                   class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
        </div>
    </div>

    <!-- Liste des envois (cliquable pour ouvrir modal) -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="p-4 border-b bg-gray-50">
            <h2 class="text-lg font-bold">Historique des envois</h2>
            <p class="text-sm text-gray-500">Cliquez sur un message pour voir les détails</p>
        </div>
        
        <?php if (empty($envois)): ?>
            <div class="text-center py-12">
                <i class="fas fa-envelope text-4xl text-gray-300 mb-3"></i>
                <p class="text-gray-500">Aucun message envoyé pour cette campagne.</p>
                <a href="index.php?page=campagnes/choix&campagne_id=<?= $campagneId ?>" 
                   class="text-green-600 mt-2 inline-block">
                    <i class="fas fa-plus mr-1"></i>Envoyer votre premier message
                </a>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Message</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Destinataires</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Statut</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="envoisTableBody">
                        <?php foreach ($envois as $envoi): 
                            $statutClass = $envoi['statut'] == 'envoye' ? 'text-green-600' : 'text-red-600';
                            $statutIcon = $envoi['statut'] == 'envoye' ? 'fa-check-circle' : 'fa-exclamation-circle';
                        ?>
                            <tr class="envoi-row hover:bg-gray-50 cursor-pointer" 
                                data-id="<?= $envoi['id_campagne'] ?>"
                                onclick="showDetails(<?= htmlspecialchars(json_encode($envoi)) ?>)">
                                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                    <?= date('d/m/Y H:i', strtotime($envoi['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($envoi['type_campagne'] == 'whatsapp'): ?>
                                        <span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs">WhatsApp</span>
                                    <?php else: ?>
                                        <span class="bg-blue-100 text-blue-700 px-2 py-1 rounded-full text-xs">SMS</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-800 max-w-xs truncate" title="<?= htmlspecialchars($envoi['message']) ?>">
                                        <?= htmlspecialchars(substr($envoi['message'], 0, 50)) ?>...
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center text-sm"><?= $envoi['nb_destinataires'] ?></td>
                                <td class="px-6 py-4 text-center">
                                    <i class="fas <?= $statutIcon ?> <?= $statutClass ?> mr-1"></i>
                                    <span class="text-sm <?= $statutClass ?>"><?= ucfirst($envoi['statut']) ?></span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <button onclick="event.stopPropagation(); showDetails(<?= htmlspecialchars(json_encode($envoi)) ?>)" 
                                            class="text-blue-600 hover:text-blue-800" title="Voir détails">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL DÉTAILS D'UN ENVOI -->
<div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 max-h-[85vh] overflow-y-auto transform transition-all duration-300 scale-95 opacity-0" id="modalContainer">
        <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 flex justify-between items-center rounded-t-2xl">
            <div class="flex items-center">
                <div id="modalIcon" class="w-10 h-10 rounded-full flex items-center justify-center mr-3">
                    <i id="modalIconImg" class="text-xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800" id="modalTitle"></h3>
            </div>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <div class="p-6" id="modalContent">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-500"></i>
                <p class="text-gray-500 mt-2">Chargement...</p>
            </div>
        </div>
        
        <div class="sticky bottom-0 bg-gray-50 border-t border-gray-200 px-6 py-4 flex justify-end rounded-b-2xl">
            <button onclick="closeModal()" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition">
                Fermer
            </button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
// ============================================
// AFFICHAGE DES DÉTAILS DANS LE MODAL
// ============================================
function showDetails(envoi) {
    const modal = document.getElementById('detailsModal');
    const modalContainer = document.getElementById('modalContainer');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    const modalIcon = document.getElementById('modalIcon');
    const modalIconImg = document.getElementById('modalIconImg');
    
    // Définir l'icône selon le type
    if (envoi.type_campagne === 'whatsapp') {
        modalIcon.className = 'w-10 h-10 rounded-full bg-green-100 flex items-center justify-center mr-3';
        modalIconImg.className = 'fab fa-whatsapp text-green-600 text-xl';
    } else {
        modalIcon.className = 'w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center mr-3';
        modalIconImg.className = 'fas fa-comment-dots text-blue-600 text-xl';
    }
    
    modalTitle.textContent = envoi.titre || 'Détails du message';
    
    // Décoder les destinataires
    let destinataires = [];
    try {
        destinataires = JSON.parse(envoi.destinataires);
    } catch(e) {
        destinataires = [envoi.destinataires];
    }
    
    let destHtml = '';
    if (destinataires && destinataires.length > 0) {
        destHtml = '<div class="space-y-1 max-h-48 overflow-y-auto">';
        for (let i = 0; i < destinataires.length; i++) {
            destHtml += '<div class="flex items-center p-2 bg-gray-50 rounded-lg mb-1">' +
                        '<i class="fas fa-user-circle text-gray-400 mr-2"></i>' +
                        '<span class="text-sm">' + escapeHtml(destinataires[i]) + '</span>' +
                        '</div>';
        }
        destHtml += '</div>';
    } else {
        destHtml = '<p class="text-gray-500 italic">Aucun destinataire enregistré</p>';
    }
    
    const statusBadge = envoi.statut === 'envoye' 
        ? '<span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs"><i class="fas fa-check-circle mr-1"></i>Envoyé</span>'
        : '<span class="bg-red-100 text-red-700 px-2 py-1 rounded-full text-xs"><i class="fas fa-exclamation-circle mr-1"></i>Échoué</span>';
    
    modalContent.innerHTML = `
        <div class="space-y-5">
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 mb-1">Date d'envoi</div>
                    <div class="font-medium text-gray-800">${formatDate(envoi.created_at)}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 mb-1">Statut</div>
                    <div>${statusBadge}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 mb-1">Appareil / Session</div>
                    <div class="font-medium text-gray-800 text-sm">${escapeHtml(envoi.appareil_utilise || '-')}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 mb-1">Type</div>
                    <div class="font-medium text-gray-800">${envoi.type_campagne === 'whatsapp' ? 'WhatsApp' : 'SMS'}</div>
                </div>
            </div>
            
            <div>
                <div class="text-xs text-gray-500 mb-2 flex items-center">
                    <i class="fas fa-comment mr-1"></i> Message
                </div>
                <div class="bg-gray-50 rounded-lg p-3 max-h-32 overflow-y-auto">
                    <p class="text-sm text-gray-700 whitespace-pre-wrap">${escapeHtml(envoi.message || '-')}</p>
                </div>
            </div>
            
            <div>
                <div class="text-xs text-gray-500 mb-2 flex items-center">
                    <i class="fas fa-chart-bar mr-1"></i> Statistiques d'envoi
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div class="bg-blue-50 rounded-lg p-3 text-center">
                        <div class="text-2xl font-bold text-blue-600">${envoi.nb_destinataires || 0}</div>
                        <div class="text-xs text-gray-500">Total</div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-3 text-center">
                        <div class="text-2xl font-bold text-green-600">${envoi.nb_succes || 0}</div>
                        <div class="text-xs text-gray-500">Succès</div>
                    </div>
                    <div class="bg-red-50 rounded-lg p-3 text-center">
                        <div class="text-2xl font-bold text-red-600">${envoi.nb_erreurs || 0}</div>
                        <div class="text-xs text-gray-500">Échecs</div>
                    </div>
                </div>
            </div>
            
            <div>
                <div class="text-xs text-gray-500 mb-2 flex items-center">
                    <i class="fas fa-users mr-1"></i> Destinataires (${envoi.nb_destinataires || 0})
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    ${destHtml}
                </div>
            </div>
            ${envoi.erreur ? `
            <div>
                <div class="text-xs text-red-500 mb-2 flex items-center">
                    <i class="fas fa-exclamation-triangle mr-1"></i> Message d'erreur
                </div>
                <div class="bg-red-50 border-l-4 border-red-500 rounded-lg p-3">
                    <p class="text-sm text-red-700">${escapeHtml(envoi.erreur)}</p>
                </div>
            </div>
            ` : ''}
        </div>
    `;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => modalContainer.classList.remove('scale-95', 'opacity-0'), 10);
}

function closeModal() {
    const modal = document.getElementById('detailsModal');
    const modalContainer = document.getElementById('modalContainer');
    
    modalContainer.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 300);
}

function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('fr-FR') + ' ' + date.toLocaleTimeString('fr-FR');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// RECHERCHE
// ============================================
const searchInput = document.getElementById('searchInput');
const envoisRows = document.querySelectorAll('.envoi-row');

if (searchInput) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        envoisRows.forEach(row => {
            const text = row.textContent.toLowerCase();
            if (searchTerm === '' || text.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
}

// Fermeture modale avec Echap
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Fermeture en cliquant sur l'overlay
document.getElementById('detailsModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>

</body>
</html>