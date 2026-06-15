<?php
global $db;

$idCompte = $_SESSION['user_id'];

// ============================================
// VÉRIFICATION DES CAMPAGNES PLANIFIÉES À ENVOYER
// ============================================
$campagnesAAlerter = [];
if (isset($_SESSION['user_id'])) {
    $idCompte = $_SESSION['user_id'];
    $now = date('Y-m-d H:i:s');
    
    // Initialiser la session pour les notifications si pas existante
    if (!isset($_SESSION['campagnes_notifiees'])) {
        $_SESSION['campagnes_notifiees'] = [];
    }
    
    // Récupérer TOUTES les campagnes planifiées
    $campagnesPlanifiees = $db->select('campagne_config', [
        'id_compte' => $idCompte,
        'statut' => 'planifiee'
    ]);
    
    foreach ($campagnesPlanifiees as $campagne) {
        // Vérifier si la date de planification est passée
        if (!empty($campagne['date_planification']) && 
            strtotime($campagne['date_planification']) <= strtotime($now) &&
            !in_array($campagne['id_campagne_config'], $_SESSION['campagnes_notifiees'])) {
            
            $_SESSION['campagnes_notifiees'][] = $campagne['id_campagne_config'];
            $campagnesAAlerter[] = $campagne;
        }
    }
}

// Pagination
$page = isset($_GET['page_num']) ? max(1, intval($_GET['page_num'])) : 1;
$limit = 10;
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

// Traitement de la création d'une nouvelle campagne (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_creer_campagne']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $nom_campagne = trim($_POST['nom_campagne'] ?? '');
    $date_planification = !empty($_POST['date_planification']) ? $_POST['date_planification'] : null;
    
    if (empty($nom_campagne)) {
        echo json_encode(['success' => false, 'error' => 'Veuillez saisir un nom de campagne']);
        exit;
    }
    
    $statut = $date_planification ? 'planifiee' : 'brouillon';
    
    $data = [
        'id_compte' => $idCompte,
        'nom_campagne' => $nom_campagne,
        'date_planification' => $date_planification,
        'statut' => $statut,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        $db->insert('campagne_config', $data);
        
        $nouvelleCampagne = $db->select('campagne_config', [
            'id_compte' => $idCompte,
            'nom_campagne' => $nom_campagne
        ], '*', 'created_at DESC', 1);
        
        if (!empty($nouvelleCampagne)) {
            $id_campagne = $nouvelleCampagne[0]['id_campagne_config'];
            
            echo json_encode([
                'success' => true, 
                'message' => 'Campagne créée avec succès', 
                'id_campagne' => $id_campagne
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Impossible de récupérer la campagne créée']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Traitement pour supprimer une campagne planifiée
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_supprimer_campagne'])) {
    $id_campagne = $_POST['id_campagne'];
    
    try {
        $campagne = $db->select('campagne_config', ['id_campagne_config' => $id_campagne, 'id_compte' => $idCompte]);
        if (!empty($campagne) && $campagne[0]['statut'] == 'planifiee') {
            $db->delete('campagne_config', $id_campagne, 'id_campagne_config');
            $_SESSION['flash_message'] = "Campagne planifiée supprimée avec succès";
        } else {
            $_SESSION['flash_error'] = "Impossible de supprimer cette campagne";
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Erreur lors de la suppression";
    }
    
    header('Location: index.php?page=campagnes/index');
    exit;
}

$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
unset($_SESSION['flash_message']);
unset($_SESSION['flash_error']);

$allCampagnes = $db->select('campagne_config', ['id_compte' => $idCompte]);
$activeCount = 0;
$planifieesCount = 0;
$totalEnvois = 0;
foreach ($allCampagnes as $c) {
    if ($c['statut'] == 'brouillon') $activeCount++;
    if ($c['statut'] == 'planifiee') {
        $activeCount++;
        $planifieesCount++;
    }
    $envoisCount = $db->select('campagne', ['id_campagne_config' => $c['id_campagne_config']]);
    $totalEnvois += count($envoisCount);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes campagnes - <?= APP_NAME ?></title>
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
        
        .datetime-input {
            font-family: inherit;
        }
        
        .btn-annuler {
            background: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-annuler:hover {
            transform: scale(1.1);
        }
        
        /* MODAL DE CONFIRMATION PERSONNALISÉ */
        .confirm-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 10001;
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        .confirm-modal.show {
            visibility: visible;
            opacity: 1;
        }
        .confirm-modal-content {
            background: white;
            border-radius: 16px;
            max-width: 450px;
            width: 90%;
            overflow: hidden;
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }
        .confirm-modal.show .confirm-modal-content {
            transform: scale(1);
        }
        .confirm-modal-header {
            padding: 20px 24px;
            background: #fef3c7;
            border-bottom: 1px solid #fde68a;
        }
        .confirm-modal-header h3 {
            font-size: 18px;
            font-weight: bold;
            color: #92400e;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .confirm-modal-header h3 i {
            font-size: 24px;
        }
        .confirm-modal-body {
            padding: 24px;
        }
        .confirm-modal-body p {
            margin: 0 0 10px 0;
            color: #374151;
            line-height: 1.5;
        }
        .confirm-modal-body .warning-text {
            color: #dc2626;
            font-size: 13px;
            margin-top: 12px;
            padding: 10px;
            background: #fee2e2;
            border-radius: 8px;
        }
        .confirm-modal-footer {
            padding: 16px 24px;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .confirm-modal-footer button {
            padding: 8px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-confirm-cancel {
            background: #e5e7eb;
            color: #374151;
        }
        .btn-confirm-cancel:hover {
            background: #d1d5db;
        }
        .btn-confirm-delete {
            background: #dc2626;
            color: white;
        }
        .btn-confirm-delete:hover {
            background: #b91c1c;
        }
        
        /* Styles pour le tableau */
        .table-container {
            overflow-x: auto;
        }
        .campagne-table {
            min-width: 800px;
            width: 100%;
        }
        .campagne-table th,
        .campagne-table td {
            padding: 12px 16px;
            vertical-align: middle;
        }
        .campagne-table th {
            background-color: #f9fafb;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            color: #6b7280;
        }
        .campagne-table td {
            border-bottom: 1px solid #e5e7eb;
        }
        .campagne-row:last-child td {
            border-bottom: none;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .action-btn {
            padding: 6px;
            border-radius: 6px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .action-btn:hover {
            transform: scale(1.1);
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .badge-brouillon { background: #f3f4f6; color: #4b5563; }
        .badge-planifiee { background: #fef3c7; color: #92400e; }
        .badge-envoyee { background: #dcfce7; color: #166534; }
        .planification-date {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        .planification-date i {
            margin-right: 4px;
            color: #f59e0b;
        }
        .text-center {
            text-align: center;
        }
        .text-left {
            text-align: left;
        }
        .font-medium {
            font-weight: 500;
        }
        .text-gray-800 {
            color: #1f2937;
        }
        .text-gray-500 {
            color: #6b7280;
        }
        .text-blue-600 {
            color: #2563eb;
        }
        .text-green-600 {
            color: #16a34a;
        }
        .text-orange-600 {
            color: #ea580c;
        }
        .text-red-600 {
            color: #dc2626;
        }
        .hover\:text-blue-800:hover {
            color: #1e40af;
        }
        .hover\:text-green-800:hover {
            color: #15803d;
        }
        .hover\:text-orange-800:hover {
            color: #c2410c;
        }
        .hover\:text-red-800:hover {
            color: #b91c1c;
        }
        .bg-purple-100 {
            background-color: #f3e8ff;
        }
        .text-purple-600 {
            color: #9333ea;
        }
        .rounded-full {
            border-radius: 9999px;
        }
        .p-2 {
            padding: 8px;
        }
        .mr-3 {
            margin-right: 12px;
        }
        .flex {
            display: flex;
        }
        .items-center {
            align-items: center;
        }
        .gap-2 {
            gap: 8px;
        }
        .mx-1 {
            margin-left: 4px;
            margin-right: 4px;
        }
        .inline-flex {
            display: inline-flex;
        }
        
        
    </style>
</head>
<body>

<!-- MODAL DE CONFIRMATION PERSONNALISÉ -->
<div id="confirmDeleteModal" class="confirm-modal">
    <div class="confirm-modal-content">
        <div class="confirm-modal-header">
            <h3>
                <i class="fas fa-exclamation-triangle"></i>
                Confirmer la suppression
            </h3>
        </div>
        <div class="confirm-modal-body">
            <p>Êtes-vous sûr de vouloir supprimer la campagne planifiée <strong id="confirmCampagneNom"></strong> ?</p>
            <p>Cette action est irréversible et supprimera définitivement la campagne.</p>
            <div class="warning-text">
                <i class="fas fa-info-circle mr-1"></i>
                Les messages déjà envoyés ne seront pas affectés.
            </div>
        </div>
        <div class="confirm-modal-footer">
            <button class="btn-confirm-cancel" id="confirmCancelBtn">
                <i class="fas fa-times mr-1"></i>Annuler
            </button>
            <button class="btn-confirm-delete" id="confirmDeleteBtn">
                <i class="fas fa-trash-alt mr-1"></i>Supprimer
            </button>
        </div>
    </div>
</div>

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
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded"><?= htmlspecialchars($flashMessage) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
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
                    <span class="text-2xl font-bold text-green-600 ml-2"><?= $activeCount ?></span>
                </div>
                <div class="text-green-400"><i class="fas fa-play-circle text-2xl"></i></div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-500">Planifiées</span>
                    <span class="text-2xl font-bold text-yellow-600 ml-2"><?= $planifieesCount ?></span>
                </div>
                <div class="text-yellow-400"><i class="fas fa-calendar-alt text-2xl"></i></div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-500">Messages envoyés</span>
                    <span class="text-2xl font-bold text-blue-600 ml-2"><?= $totalEnvois ?></span>
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
        <div class="table-container">
            <table class="campagne-table">
                <thead>
                    <tr>
                        <th class="text-left">Nom</th>
                        <th class="text-center">Messages</th>
                        <th class="text-left">Statut</th>
                        <th class="text-left">Date planification</th>
                        <th class="text-left">Date création</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($campagnes)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-8 text-gray-500">
                                <i class="fas fa-bullhorn text-4xl mb-2 block"></i>
                                Aucune campagne pour le moment.
                                <button onclick="openAddCampagneModal()" class="text-purple-600 block mt-2">Créer votre première campagne →</button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($campagnes as $campagne): ?>
                            <tr class="campagne-row" 
                                data-name="<?= strtolower(htmlspecialchars($campagne['nom_campagne'])) ?>"
                                onclick="window.location.href='index.php?page=campagnes/details&id=<?= $campagne['id_campagne_config'] ?>'">
                                <td>
                                    <div class="flex items-center">
                                        <div class="bg-purple-100 rounded-full p-2 mr-3">
                                            <i class="fas fa-bullhorn text-purple-600 text-sm"></i>
                                        </div>
                                        <span class="font-medium text-gray-800"><?= htmlspecialchars($campagne['nom_campagne']) ?></span>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="font-medium text-blue-600"><?= $campagne['nb_envois'] ?></span>
                                    <span class="text-gray-500 text-xs"> envoi(s)</span>
                                </td>
                                <td>
                                    <?php
                                    $badgeClass = '';
                                    $statusLabel = '';
                                    if ($campagne['statut'] == 'brouillon') {
                                        $badgeClass = 'badge-brouillon';
                                        $statusLabel = 'Brouillon';
                                    } elseif ($campagne['statut'] == 'planifiee') {
                                        $badgeClass = 'badge-planifiee';
                                        $statusLabel = 'Planifiée';
                                    } else {
                                        $badgeClass = 'badge-envoyee';
                                        $statusLabel = 'Envoyée';
                                    }
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
                                    <?php if ($campagne['statut'] == 'planifiee' && !empty($campagne['date_planification'])): ?>
                                        <div class="planification-date">
                                            <i class="fas fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($campagne['date_planification'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($campagne['date_planification'])): ?>
                                        <div class="planification-date">
                                            <i class="fas fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($campagne['date_planification'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-gray-500 text-sm">
                                    <?= date('d/m/Y H:i', strtotime($campagne['created_at'])) ?>
                                </td>
                                <td class="text-center">
                                    <div class="action-buttons" onclick="event.stopPropagation()">
                                        <?php if ($campagne['statut'] == 'brouillon'): ?>
                                            <a href="index.php?page=campagnes/choix&campagne_id=<?= $campagne['id_campagne_config'] ?>" 
                                               class="action-btn text-green-600 hover:text-green-800" title="Envoyer maintenant">
                                                <i class="fas fa-paper-plane"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($campagne['statut'] == 'planifiee'): ?>
                                            <a href="index.php?page=campagnes/choix&campagne_id=<?= $campagne['id_campagne_config'] ?>" 
                                               class="action-btn text-orange-600 hover:text-orange-800" title="Envoyer (forcer)">
                                                <i class="fas fa-paper-plane"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="index.php?page=campagnes/details&id=<?= $campagne['id_campagne_config'] ?>" 
                                           class="action-btn text-blue-600 hover:text-blue-800" title="Voir les détails">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($campagne['statut'] == 'planifiee'): ?>
                                            <button onclick="openConfirmDeleteModal('<?= $campagne['id_campagne_config'] ?>', '<?= addslashes($campagne['nom_campagne']) ?>')" 
                                                    class="action-btn text-red-600 hover:text-red-800" title="Supprimer la campagne">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
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

<!-- MODALE D'AJOUT DE CAMPAGNE AVEC DATE DE PLANIFICATION OPTIONNELLE -->
<div id="addCampagneModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-campagne">
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
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Nom de la campagne * <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="nom_campagne" id="nom_campagne" required 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500"
                           placeholder="Ex: Newsletter Juin 2026">
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Date de planification (optionnelle)
                    </label>
                    <input type="datetime-local" name="date_planification" id="date_planification" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500 datetime-input">
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-info-circle mr-1"></i>
                        Laissez vide pour une création immédiate (brouillon). Remplissez pour planifier l'envoi automatique.
                    </p>
                </div>
                
                <div class="bg-yellow-50 p-3 rounded-lg mb-4 text-sm text-yellow-700">
                    <i class="fas fa-clock mr-2"></i>
                    <strong>Note :</strong> Si vous définissez une date de planification, la campagne sera envoyée automatiquement à cette date.
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
// MODAL DE CONFIRMATION POUR SUPPRESSION
// ============================================
let campagneToDelete = null;

function openConfirmDeleteModal(id, nom) {
    campagneToDelete = id;
    document.getElementById('confirmCampagneNom').innerHTML = nom;
    const modal = document.getElementById('confirmDeleteModal');
    modal.classList.add('show');
}

function closeConfirmDeleteModal() {
    const modal = document.getElementById('confirmDeleteModal');
    modal.classList.remove('show');
    campagneToDelete = null;
}

function confirmDelete() {
    if (campagneToDelete) {
        const formData = new FormData();
        formData.append('action_supprimer_campagne', '1');
        formData.append('id_campagne', campagneToDelete);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (response.ok) {
                showToast('Campagne supprimée avec succès', 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast('Erreur lors de la suppression', 'error');
            }
        })
        .catch(() => showToast('Erreur réseau', 'error'))
        .finally(() => {
            closeConfirmDeleteModal();
        });
    }
}

// Écouteurs des boutons du modal
document.getElementById('confirmCancelBtn').addEventListener('click', closeConfirmDeleteModal);
document.getElementById('confirmDeleteBtn').addEventListener('click', confirmDelete);

// Fermer le modal en cliquant en dehors
document.getElementById('confirmDeleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeConfirmDeleteModal();
    }
});

// ============================================
// MODAL D'AJOUT DE CAMPAGNE
// ============================================
function openAddCampagneModal() {
    const modal = document.getElementById('addCampagneModal');
    const modalContent = modal.querySelector('.modal-campagne');
    document.getElementById('addCampagneForm').reset();
    document.getElementById('date_planification').value = '';
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeAddCampagneModal() {
    const modal = document.getElementById('addCampagneModal');
    const modalContent = modal.querySelector('.modal-campagne');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// ============================================
// AJOUT DE CAMPAGNE AJAX
// ============================================
document.getElementById('addCampagneForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const nomCampagne = document.getElementById('nom_campagne').value.trim();
    const datePlanification = document.getElementById('date_planification').value;
    
    if (!nomCampagne) {
        showToast('Veuillez saisir un nom de campagne', 'warning');
        return;
    }
    
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
        
        const text = await response.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            showToast('Erreur serveur: réponse invalide', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        if (result.success) {
            let message = result.message;
            if (datePlanification) {
                const dateFormatted = new Date(datePlanification).toLocaleString('fr-FR');
                message = `Campagne créée et planifiée pour le ${dateFormatted}`;
                showToast(message, 'success');
            } else {
                showToast(result.message, 'success');
            }
            closeAddCampagneModal();
            setTimeout(() => window.location.reload(), 1000);
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

// ============================================
// AFFICHER LES ALERTES DE CAMPAGNES PLANIFIÉES
// ============================================
<?php if (!empty($campagnesAAlerter)): ?>
    console.log("Campagnes à alerter: <?= count($campagnesAAlerter) ?>");
    <?php foreach ($campagnesAAlerter as $campagne): ?>
        setTimeout(function() {
            showToast('📅 La campagne "<?= addslashes($campagne['nom_campagne']) ?>" est prête à être envoyée !', 'warning');
        }, 1000);
    <?php endforeach; ?>
<?php else: ?>
    console.log("Aucune campagne à alerter");
<?php endif; ?>

// Fermeture des modales
document.getElementById('addCampagneModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeAddCampagneModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddCampagneModal();
        closeConfirmDeleteModal();
    }
});
</script>

</body>
</html>