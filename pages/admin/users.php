<?php
requireAdmin();
global $db;

// Récupérer tous les utilisateurs
$currentUserId = $_SESSION['user_id'];
$users = $db->select('compte', [], '*', 'date_creation=order.desc');

// Activer/Désactiver un compte (traitement POST depuis la modale)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_toggle'])) {
    $id = $_POST['id_compte'];
    $action = $_POST['toggle_action'];
    
    $userToToggle = $db->select('compte', ['id_compte' => $id]);
    if ($userToToggle && $userToToggle[0]['id_compte'] != $currentUserId) {
        $newStatus = ($action === 'activer');
        $db->update('compte', ['actif' => $newStatus], ['id_compte' => $id]);
        $_SESSION['flash_message'] = "Compte " . ($newStatus ? "activé" : "désactivé") . " avec succès";
    } else {
        $_SESSION['flash_error'] = "Vous ne pouvez pas modifier votre propre statut";
    }
    header('Location: index.php?page=admin/users');
    exit;
}

// Changer le rôle (traitement POST depuis la modale)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_role'])) {
    $id = $_POST['id_compte'];
    $newRole = $_POST['new_role'];
    
    $userToChange = $db->select('compte', ['id_compte' => $id]);
    if ($userToChange && $userToChange[0]['id_compte'] != $currentUserId) {
        if (in_array($newRole, ['admin', 'user'])) {
            $db->update('compte', ['role' => $newRole], ['id_compte' => $id]);
            $_SESSION['flash_message'] = "Rôle modifié avec succès";
        }
    } else {
        $_SESSION['flash_error'] = "Vous ne pouvez pas modifier votre propre rôle";
    }
    header('Location: index.php?page=admin/users');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des utilisateurs - <?= APP_NAME ?></title>
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

        /* Animation modale */
        .modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        
        .modal-content {
            transition: all 0.3s ease;
            transform: scale(0.95);
            opacity: 0;
        }
    </style>
</head>
<body>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Gestion des utilisateurs</h1>
            <p class="text-gray-500">Gérez les comptes, rôles et statuts</p>
        </div>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded">
            <?= $_SESSION['flash_message'] ?>
            <?php unset($_SESSION['flash_message']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded">
            <?= $_SESSION['flash_error'] ?>
            <?php unset($_SESSION['flash_error']); ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entreprise</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Utilisateur</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Identifiant</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Crédits</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rôle</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Inscrit le</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($users as $user): 
                        $isCurrentUser = ($user['id_compte'] == $currentUserId);
                        $statusColor = $user['actif'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
                        $statusText = $user['actif'] ? 'Actif' : 'Suspendu';
                        $roleColor = $user['role'] == 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800';
                    ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 font-medium"><?= htmlspecialchars($user['entreprise']) ?></td>
                            <td class="px-6 py-4"><?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></td>
                            <td class="px-6 py-4"><?= htmlspecialchars($user['user']) ?></td>
                            <td class="px-6 py-4 font-bold">
                                <?= number_format($user['credits_total'], 2) ?> €
                                <button onclick="showCreditModal('<?= $user['id_compte'] ?>', '<?= addslashes($user['entreprise']) ?>')" 
                                        class="ml-2 text-blue-600 hover:text-blue-800 text-sm" title="Ajouter des crédits">
                                    <i class="fas fa-plus-circle"></i>
                                </button>
                            </td>
                            <td class="px-6 py-4">
                                <?php if (!$isCurrentUser): ?>
                                    <button onclick="openRoleModal('<?= $user['id_compte'] ?>', '<?= addslashes($user['prenom'] . ' ' . $user['nom']) ?>', '<?= $user['role'] ?>')" 
                                            class="px-2 py-1 rounded text-xs <?= $roleColor ?> hover:opacity-80 cursor-pointer transition">
                                        <?= strtoupper($user['role']) ?>
                                        <i class="fas fa-chevron-down ml-1 text-xs"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="px-2 py-1 rounded text-xs <?= $roleColor ?>"><?= strtoupper($user['role']) ?></span>
                                    <span class="text-xs text-gray-400 ml-1">(vous)</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if (!$isCurrentUser): ?>
                                    <button onclick="openStatusModal('<?= $user['id_compte'] ?>', '<?= addslashes($user['prenom'] . ' ' . $user['nom']) ?>', '<?= $user['actif'] ?>')" 
                                            class="px-2 py-1 rounded text-xs <?= $user['actif'] ? 'bg-red-100 text-red-800 hover:bg-red-200' : 'bg-green-100 text-green-800 hover:bg-green-200' ?> transition cursor-pointer">
                                        <?= $user['actif'] ? 'Suspendre' : 'Activer' ?>
                                    </button>
                                <?php else: ?>
                                    <span class="px-2 py-1 rounded text-xs bg-gray-100 text-gray-400">Vous-même</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4"><?= date('d/m/Y', strtotime($user['date_creation'])) ?></td>
                            <td class="px-6 py-4 space-x-2">
                                <a href="index.php?page=admin/users/edit&id=<?= $user['id_compte'] ?>" 
                                   class="text-blue-600 hover:text-blue-800" title="Modifier">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE POUR CHANGER LE STATUT (SUSPENDRE/ACTIVER) -->
<!-- ============================================ -->
<div id="statusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-content">
        <div class="p-6 text-center">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full" id="statusIconBg">
                <i class="fas fa-shield-alt text-3xl" id="statusIcon"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-2" id="statusTitle">Confirmer</h3>
            <p class="text-gray-500 mb-6" id="statusMessage"></p>
            <form method="POST" id="statusForm">
                <input type="hidden" name="action_toggle" value="1">
                <input type="hidden" name="id_compte" id="statusCompteId">
                <input type="hidden" name="toggle_action" id="toggleAction">
                <div class="flex space-x-3">
                    <button type="button" onclick="closeStatusModal()" 
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" id="statusConfirmBtn" 
                            class="flex-1 px-4 py-2 rounded-lg text-white transition">
                        Confirmer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE POUR CHANGER LE RÔLE -->
<!-- ============================================ -->
<div id="roleModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-content">
        <div class="p-6 text-center">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-purple-100 mb-4">
                <i class="fas fa-user-tag text-purple-600 text-3xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">Modifier le rôle</h3>
            <p class="text-gray-500 mb-4">
                Utilisateur : <strong id="roleUserName"></strong>
            </p>
            <form method="POST" id="roleForm">
                <input type="hidden" name="action_role" value="1">
                <input type="hidden" name="id_compte" id="roleCompteId">
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Nouveau rôle</label>
                    <select name="new_role" id="newRole" class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-purple-500">
                        <option value="user">Utilisateur</option>
                        <option value="admin">Administrateur</option>
                    </select>
                </div>
                <div class="flex space-x-3">
                    <button type="button" onclick="closeRoleModal()" 
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
                        Modifier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE POUR AJOUTER DES CRÉDITS -->
<!-- ============================================ -->
<div id="creditModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-content">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-gray-800">Ajouter des crédits</h3>
                <button onclick="closeCreditModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <p class="text-sm text-gray-500 mb-4" id="modalEntreprise"></p>
            <form method="POST" action="?page=admin/users/add_credits">
                <input type="hidden" name="id_compte" id="modalIdCompte">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Montant (€)</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500">€</span>
                        <input type="number" name="montant" step="0.01" min="0.01" required 
                               class="w-full pl-8 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-blue-500">
                    </div>
                </div>
                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="closeCreditModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" name="add_credits" 
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                        Ajouter
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
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
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
    
    toast.innerHTML = `<div class="toast-content" style="background: ${bgColor};"><span>${icon}</span><span>${message}</span></div>`;
    document.body.appendChild(toast);
    
    setTimeout(() => toast.remove(), 3000);
}

// ============================================
// MODALE STATUT (SUSPENDRE/ACTIVER)
// ============================================
function openStatusModal(userId, userName, currentStatus) {
    const isActive = currentStatus === '1' || currentStatus === true;
    const modal = document.getElementById('statusModal');
    const modalContent = modal.querySelector('.modal-content');
    const title = document.getElementById('statusTitle');
    const message = document.getElementById('statusMessage');
    const iconBg = document.getElementById('statusIconBg');
    const icon = document.getElementById('statusIcon');
    const confirmBtn = document.getElementById('statusConfirmBtn');
    const actionInput = document.getElementById('toggleAction');
    const compteIdInput = document.getElementById('statusCompteId');
    
    compteIdInput.value = userId;
    
    if (isActive) {
        // Suspendre
        title.textContent = 'Suspendre le compte';
        message.innerHTML = `Êtes-vous sûr de vouloir suspendre le compte de <strong>${userName}</strong> ?<br>L'utilisateur ne pourra plus se connecter.`;
        iconBg.className = 'mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-orange-100 mb-4';
        icon.className = 'fas fa-pause-circle text-orange-600 text-3xl';
        confirmBtn.className = 'flex-1 px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg transition';
        actionInput.value = 'suspendre';
    } else {
        // Activer
        title.textContent = 'Activer le compte';
        message.innerHTML = `Êtes-vous sûr de vouloir activer le compte de <strong>${userName}</strong> ?<br>L'utilisateur pourra se connecter à nouveau.`;
        iconBg.className = 'mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-4';
        icon.className = 'fas fa-check-circle text-green-600 text-3xl';
        confirmBtn.className = 'flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition';
        actionInput.value = 'activer';
    }
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeStatusModal() {
    const modal = document.getElementById('statusModal');
    const modalContent = modal.querySelector('.modal-content');
    modalContent.classList.remove('modal-show');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 200);
}

// ============================================
// MODALE RÔLE
// ============================================
function openRoleModal(userId, userName, currentRole) {
    const modal = document.getElementById('roleModal');
    const modalContent = modal.querySelector('.modal-content');
    const userNameSpan = document.getElementById('roleUserName');
    const roleSelect = document.getElementById('newRole');
    
    userNameSpan.textContent = userName;
    document.getElementById('roleCompteId').value = userId;
    
    // Pré-sélectionner le rôle actuel
    roleSelect.value = currentRole;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeRoleModal() {
    const modal = document.getElementById('roleModal');
    const modalContent = modal.querySelector('.modal-content');
    modalContent.classList.remove('modal-show');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 200);
}

// ============================================
// MODALE CRÉDITS
// ============================================
function showCreditModal(id, entreprise) {
    const modal = document.getElementById('creditModal');
    const modalContent = modal.querySelector('.modal-content');
    document.getElementById('modalIdCompte').value = id;
    document.getElementById('modalEntreprise').innerHTML = `<i class="fas fa-building mr-1"></i> ${entreprise}`;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeCreditModal() {
    const modal = document.getElementById('creditModal');
    const modalContent = modal.querySelector('.modal-content');
    modalContent.classList.remove('modal-show');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 200);
}

// Fermer les modales en cliquant en dehors
window.onclick = function(event) {
    const statusModal = document.getElementById('statusModal');
    const roleModal = document.getElementById('roleModal');
    const creditModal = document.getElementById('creditModal');
    
    if (event.target === statusModal) closeStatusModal();
    if (event.target === roleModal) closeRoleModal();
    if (event.target === creditModal) closeCreditModal();
}

// Fermer avec la touche Echap
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeStatusModal();
        closeRoleModal();
        closeCreditModal();
    }
});
</script>

</body>
</html>