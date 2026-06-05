<?php
requireAdmin();
global $db;

$currentUserId = $_SESSION['user_id'];
$users = $db->select('compte', [], '*', 'date_creation=order.desc');

// ============================================
// TRAITEMENT DE LA CRÉATION D'UN COMPTE (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_user']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $entreprise = trim($_POST['entreprise']);
    $prenom = trim($_POST['prenom']);
    $nom = trim($_POST['nom']);
    $user = trim($_POST['user']);
    $password = $_POST['password'];
    $credits = floatval($_POST['credits']);
    $role = $_POST['role'] ?? 'user';
    
    if (empty($entreprise) || empty($prenom) || empty($nom) || empty($user) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Veuillez remplir tous les champs obligatoires']);
        exit;
    }
    
    $existing = $db->select('compte', ['user' => $user]);
    if ($existing) {
        echo json_encode(['success' => false, 'error' => 'Cet identifiant existe déjà']);
        exit;
    }
    
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    $data = [
        'entreprise' => $entreprise,
        'prenom' => $prenom,
        'nom' => $nom,
        'user' => $user,
        'password' => $hashedPassword,
        'credits_total' => $credits,
        'role' => $role,
        'actif' => true
    ];
    
    try {
        $db->insert('compte', $data);
        echo json_encode(['success' => true, 'message' => 'Compte créé avec succès']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DE LA MODIFICATION D'UN COMPTE (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_user']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $id_compte = $_POST['id_compte'];
    $entreprise = trim($_POST['entreprise']);
    $prenom = trim($_POST['prenom']);
    $nom = trim($_POST['nom']);
    $user = trim($_POST['user']);
    $credits = floatval($_POST['credits']);
    $role = $_POST['role'] ?? 'user';
    $new_password = $_POST['new_password'] ?? '';
    
    if (empty($entreprise) || empty($prenom) || empty($nom) || empty($user)) {
        echo json_encode(['success' => false, 'error' => 'Veuillez remplir tous les champs obligatoires']);
        exit;
    }
    
    // Vérifier que l'utilisateur n'est pas en train de se modifier lui-même (admin ne peut pas modifier son propre rôle)
    if ($id_compte == $currentUserId && $role != 'admin') {
        // On ne change pas le rôle de l'admin qui se modifie lui-même
        $currentUser = $db->select('compte', ['id_compte' => $currentUserId]);
        if ($currentUser && $currentUser[0]['role'] == 'admin') {
            $role = 'admin';
        }
    }
    
    $data = [
        'entreprise' => $entreprise,
        'prenom' => $prenom,
        'nom' => $nom,
        'user' => $user,
        'credits_total' => $credits,
        'role' => $role
    ];
    
    if (!empty($new_password)) {
        $data['password'] = password_hash($new_password, PASSWORD_DEFAULT);
    }
    
    try {
        $db->update('compte', $data, ['id_compte' => $id_compte]);
        echo json_encode(['success' => true, 'message' => 'Compte modifié avec succès']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT POUR RÉCUPÉRER LES DONNÉES D'UN COMPTE (AJAX)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_user' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    $id = $_GET['id'];
    $user = $db->select('compte', ['id_compte' => $id]);
    
    if (!$user) {
        echo json_encode(['error' => 'Utilisateur non trouvé']);
        exit;
    }
    
    // Ne pas envoyer le mot de passe
    unset($user[0]['password']);
    echo json_encode($user[0]);
    exit;
}

// ============================================
// TRAITEMENT DE L'ACTIVATION/DÉSACTIVATION
// ============================================
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

// ============================================
// TRAITEMENT DU CHANGEMENT DE RÔLE
// ============================================
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
    <title>Gestion des utilisateurs - <?= APP_NAME ?></title>
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
        .modal-content, .modal-add-user, .modal-edit-user {
            transition: all 0.3s ease;
            transform: scale(0.95);
            opacity: 0;
        }
        .modal-content.modal-show, .modal-add-user.modal-show, .modal-edit-user.modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
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
        <button type="button" onclick="openAddUserModal()" 
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-user-plus mr-2"></i>Ajouter un utilisateur
        </button>
    </div>

    <?php if ($flashMessage): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded"><?= $flashMessage ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded"><?= $flashError ?></div>
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
                                <button type="button" onclick="openEditUserModal('<?= $user['id_compte'] ?>')" 
                                        class="text-blue-600 hover:text-blue-800" title="Modifier">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE D'AJOUT D'UTILISATEUR -->
<!-- ============================================ -->
<div id="addUserModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-add-user">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                        <i class="fas fa-user-plus text-blue-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Ajouter un utilisateur</h3>
                </div>
                <button type="button" onclick="closeAddUserModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="addUserForm" method="POST">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Entreprise *</label>
                        <input type="text" name="entreprise" id="add_entreprise" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label>
                            <input type="text" name="prenom" id="add_prenom" required 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                            <input type="text" name="nom" id="add_nom" required 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Identifiant *</label>
                        <input type="text" name="user" id="add_user" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                               placeholder="Nom d'utilisateur ou email">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe *</label>
                        <input type="password" name="password" id="add_password" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Crédits (€)</label>
                            <input type="number" name="credits" id="add_credits" step="0.01" value="0" 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Rôle</label>
                            <select name="role" id="add_role" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                <option value="user">Utilisateur</option>
                                <option value="admin">Administrateur</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeAddUserModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                        <i class="fas fa-save mr-2"></i>Créer le compte
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE DE MODIFICATION D'UTILISATEUR -->
<!-- ============================================ -->
<div id="editUserModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-edit-user">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-yellow-100 p-2 rounded-full mr-3">
                        <i class="fas fa-user-edit text-yellow-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Modifier l'utilisateur</h3>
                </div>
                <button type="button" onclick="closeEditUserModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="editUserForm" method="POST">
                <input type="hidden" name="id_compte" id="edit_id_compte">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Entreprise *</label>
                        <input type="text" name="entreprise" id="edit_entreprise" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label>
                            <input type="text" name="prenom" id="edit_prenom" required 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                            <input type="text" name="nom" id="edit_nom" required 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Identifiant *</label>
                        <input type="text" name="user" id="edit_user" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                               placeholder="Nom d'utilisateur ou email">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nouveau mot de passe</label>
                        <input type="password" name="new_password" id="edit_new_password" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                               placeholder="Laissez vide pour ne pas changer">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Crédits (€)</label>
                            <input type="number" name="credits" id="edit_credits" step="0.01" 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Rôle</label>
                            <select name="role" id="edit_role" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                <option value="user">Utilisateur</option>
                                <option value="admin">Administrateur</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeEditUserModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                        <i class="fas fa-save mr-2"></i>Enregistrer les modifications
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE POUR CHANGER LE STATUT -->
<!-- ============================================ -->
<div id="statusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
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
<div id="roleModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
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
<div id="creditModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
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
// MODALE D'AJOUT
// ============================================
function openAddUserModal() {
    const modal = document.getElementById('addUserModal');
    const modalContent = modal.querySelector('.modal-add-user');
    document.getElementById('addUserForm').reset();
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeAddUserModal() {
    const modal = document.getElementById('addUserModal');
    const modalContent = modal.querySelector('.modal-add-user');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// ============================================
// MODALE DE MODIFICATION
// ============================================
async function openEditUserModal(userId) {
    const modal = document.getElementById('editUserModal');
    const modalContent = modal.querySelector('.modal-edit-user');
    
    try {
        const response = await fetch(`index.php?page=admin/users&action=get_user&id=${userId}`);
        const user = await response.json();
        
        if (user.error) {
            showToast(user.error, 'error');
            return;
        }
        
        document.getElementById('edit_id_compte').value = user.id_compte;
        document.getElementById('edit_entreprise').value = user.entreprise || '';
        document.getElementById('edit_prenom').value = user.prenom || '';
        document.getElementById('edit_nom').value = user.nom || '';
        document.getElementById('edit_user').value = user.user || '';
        document.getElementById('edit_credits').value = user.credits_total || 0;
        document.getElementById('edit_role').value = user.role || 'user';
        document.getElementById('edit_new_password').value = '';
        
        modal.style.display = 'flex';
        setTimeout(() => modalContent.classList.add('modal-show'), 10);
    } catch (error) {
        showToast('Erreur lors du chargement des données', 'error');
    }
}

function closeEditUserModal() {
    const modal = document.getElementById('editUserModal');
    const modalContent = modal.querySelector('.modal-edit-user');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// ============================================
// AJOUT UTILISATEUR AJAX
// ============================================
document.getElementById('addUserForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action_add_user', '1');
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Création...';
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
            closeAddUserModal();
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
// MODIFICATION UTILISATEUR AJAX
// ============================================
document.getElementById('editUserForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action_edit_user', '1');
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = 'Enregistrement...';
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
            closeEditUserModal();
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
// MODALES STATUT, RÔLE, CRÉDITS (inchangées)
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
        title.textContent = 'Suspendre le compte';
        message.innerHTML = `Êtes-vous sûr de vouloir suspendre le compte de <strong>${userName}</strong> ?<br>L'utilisateur ne pourra plus se connecter.`;
        iconBg.className = 'mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-orange-100 mb-4';
        icon.className = 'fas fa-pause-circle text-orange-600 text-3xl';
        confirmBtn.className = 'flex-1 px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg transition';
        actionInput.value = 'suspendre';
    } else {
        title.textContent = 'Activer le compte';
        message.innerHTML = `Êtes-vous sûr de vouloir activer le compte de <strong>${userName}</strong> ?<br>L'utilisateur pourra se connecter à nouveau.`;
        iconBg.className = 'mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-4';
        icon.className = 'fas fa-check-circle text-green-600 text-3xl';
        confirmBtn.className = 'flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition';
        actionInput.value = 'activer';
    }
    
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeStatusModal() {
    const modal = document.getElementById('statusModal');
    const modalContent = modal.querySelector('.modal-content');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

function openRoleModal(userId, userName, currentRole) {
    const modal = document.getElementById('roleModal');
    const modalContent = modal.querySelector('.modal-content');
    const userNameSpan = document.getElementById('roleUserName');
    const roleSelect = document.getElementById('newRole');
    
    userNameSpan.textContent = userName;
    document.getElementById('roleCompteId').value = userId;
    roleSelect.value = currentRole;
    
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeRoleModal() {
    const modal = document.getElementById('roleModal');
    const modalContent = modal.querySelector('.modal-content');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

function showCreditModal(id, entreprise) {
    const modal = document.getElementById('creditModal');
    const modalContent = modal.querySelector('.modal-content');
    document.getElementById('modalIdCompte').value = id;
    document.getElementById('modalEntreprise').innerHTML = `<i class="fas fa-building mr-1"></i> ${entreprise}`;
    
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeCreditModal() {
    const modal = document.getElementById('creditModal');
    const modalContent = modal.querySelector('.modal-content');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// Fermeture des modales
document.getElementById('addUserModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeAddUserModal(); });
document.getElementById('editUserModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeEditUserModal(); });
document.getElementById('statusModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeStatusModal(); });
document.getElementById('roleModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeRoleModal(); });
document.getElementById('creditModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeCreditModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeAddUserModal(); closeEditUserModal(); closeStatusModal(); closeRoleModal(); closeCreditModal(); } });
</script>

</body>
</html>