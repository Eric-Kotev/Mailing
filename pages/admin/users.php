<?php
global $db;

$idCompte = $_SESSION['user_id'];

// ============================================
// TRAITEMENT DE LA CRÉATION DE COMPTE (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_user'])) {
    // 🔥 FORCER le type de contenu JSON et vider le buffer
    ob_clean();
    header('Content-Type: application/json');
    
    // 🔥 Désactiver l'affichage des erreurs pour éviter le HTML
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $entreprise = trim($_POST['entreprise'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $password = $_POST['password'] ?? '';
        $credits_total = floatval($_POST['credits_total'] ?? 0);
        $role = $_POST['role'] ?? 'user';
        
        // Log pour debug
        error_log("=== CRÉATION COMPTE ===");
        error_log("Entreprise: " . $entreprise);
        error_log("Prenom: " . $prenom);
        error_log("Nom: " . $nom);
        error_log("User: " . $user);
        error_log("Role: " . $role);
        error_log("Credits total: " . $credits_total);
        
        $errors = [];
        if (empty($entreprise)) $errors[] = "Le nom de l'entreprise est requis";
        if (empty($prenom)) $errors[] = 'Le prénom est requis';
        if (empty($nom)) $errors[] = 'Le nom est requis';
        if (empty($user)) $errors[] = "Le nom d'utilisateur est requis";
        if (empty($password) || strlen($password) < 6) $errors[] = 'Le mot de passe doit contenir au moins 6 caractères';
        
        $existingUser = $db->select('compte', ['user' => $user]);
        if (!empty($existingUser)) $errors[] = "Ce nom d'utilisateur est déjà utilisé";
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
            exit;
        }
        
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $data = [
            'entreprise' => $entreprise,
            'prenom' => $prenom,
            'nom' => $nom,
            'user' => $user,
            'password' => $hashedPassword,
            'credits_total' => $credits_total,
            'role' => $role,
            'actif' => true,
            'date_creation' => date('Y-m-d H:i:s'),
            'date_suspension' => null,
            'logo_url' => null
        ];
        
        $userId = $db->insertAndGetId('compte', $data);
        
        if ($userId) {
            echo json_encode(['success' => true, 'message' => 'Compte créé avec succès', 'id' => $userId]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur lors de la création du compte']);
        }
        
    } catch (Exception $e) {
        error_log("ERREUR création compte: " . $e->getMessage());
        error_log("TRACE: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DE LA MODIFICATION DE COMPTE (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_user'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $compteId = $_POST['id_compte'] ?? null;
        $entreprise = trim($_POST['entreprise'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $password = $_POST['password'] ?? '';
        $credits_total = floatval($_POST['credits_total'] ?? 0);
        $role = $_POST['role'] ?? 'user';
        
        error_log("=== MODIFICATION COMPTE ===");
        error_log("ID: " . $compteId);
        error_log("Entreprise: " . $entreprise);
        error_log("Prenom: " . $prenom);
        error_log("Nom: " . $nom);
        error_log("User: " . $user);
        error_log("Role: " . $role);
        error_log("Credits total: " . $credits_total);
        
        $errors = [];
        if (!$compteId) $errors[] = "ID compte manquant";
        if (empty($entreprise)) $errors[] = "Le nom de l'entreprise est requis";
        if (empty($prenom)) $errors[] = 'Le prénom est requis';
        if (empty($nom)) $errors[] = 'Le nom est requis';
        if (empty($user)) $errors[] = "Le nom d'utilisateur est requis";
        
        // Vérifier si le nom d'utilisateur existe déjà (sauf pour le compte actuel)
        $existingUser = $db->select('compte', ['user' => $user]);
        if (!empty($existingUser) && $existingUser[0]['id_compte'] != $compteId) {
            $errors[] = "Ce nom d'utilisateur est déjà utilisé par un autre compte";
        }
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
            exit;
        }
        
        $data = [
            'entreprise' => $entreprise,
            'prenom' => $prenom,
            'nom' => $nom,
            'user' => $user,
            'credits_total' => $credits_total,
            'role' => $role
        ];
        
        // Si un nouveau mot de passe est fourni, le hasher et l'ajouter
        if (!empty($password)) {
            if (strlen($password) < 6) {
                echo json_encode(['success' => false, 'error' => 'Le mot de passe doit contenir au moins 6 caractères']);
                exit;
            }
            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
        }
        
        $result = $db->update('compte', $data, ['id_compte' => $compteId]);
        
        if ($result !== false) {
            echo json_encode(['success' => true, 'message' => 'Compte modifié avec succès']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur lors de la modification du compte']);
        }
        
    } catch (Exception $e) {
        error_log("ERREUR modification compte: " . $e->getMessage());
        error_log("TRACE: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DU CHANGEMENT DE RÔLE (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_change_role'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $compteId = $_POST['id_compte'] ?? null;
        $newRole = $_POST['role'] ?? null;
        
        if (!$compteId) {
            echo json_encode(['success' => false, 'error' => 'ID compte manquant']);
            exit;
        }
        if (!$newRole) {
            echo json_encode(['success' => false, 'error' => 'Rôle manquant']);
            exit;
        }
        
        // EMPÊCHER LA MODIFICATION DE SON PROPRE COMPTE
        if ($compteId == $idCompte) {
            echo json_encode(['success' => false, 'error' => 'Vous ne pouvez pas modifier le rôle de votre propre compte']);
            exit;
        }
        
        $allowedRoles = ['admin', 'user', 'manager'];
        if (!in_array($newRole, $allowedRoles)) {
            echo json_encode(['success' => false, 'error' => 'Rôle non autorisé']);
            exit;
        }
        
        $db->update('compte', ['role' => $newRole], ['id_compte' => $compteId]);
        echo json_encode(['success' => true, 'message' => 'Rôle modifié avec succès']);
        
    } catch (Exception $e) {
        error_log("ERREUR changement rôle: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// RÉCUPÉRATION DES DONNÉES D'UN COMPTE (AJAX)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_user' && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $compteId = $_GET['id'];
        $user = $db->select('compte', ['id_compte' => $compteId]);
        
        if (!empty($user)) {
            // Ne pas renvoyer le mot de passe
            unset($user[0]['password']);
            echo json_encode(['success' => true, 'user' => $user[0]]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Compte non trouvé']);
        }
    } catch (Exception $e) {
        error_log("ERREUR récupération compte: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// TRAITEMENT DES ACTIONS (Activer/Suspendre)
// ============================================

if (isset($_GET['action']) && $_GET['action'] === 'activer' && isset($_GET['id'])) {
    $compteId = $_GET['id'];
    try {
        $db->update('compte', [
            'actif' => true,
            'date_suspension' => null
        ], ['id_compte' => $compteId]);
        $_SESSION['flash_message'] = "Compte activé avec succès";
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Erreur lors de l'activation: " . $e->getMessage();
    }
    header('Location: index.php?page=admin/users');
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'suspendre' && isset($_GET['id'])) {
    $compteId = $_GET['id'];
    try {
        $db->update('compte', [
            'actif' => false,
            'date_suspension' => date('Y-m-d H:i:s')
        ], ['id_compte' => $compteId]);
        $_SESSION['flash_message'] = "Compte suspendu avec succès";
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Erreur lors de la suspension: " . $e->getMessage();
    }
    header('Location: index.php?page=admin/users');
    exit;
}

// Récupérer tous les utilisateurs (comptes)
$users = $db->select('compte', [], '*', 'date_creation.desc');

$totalUsers = count($users);

function formatDate($date) {
    if (empty($date)) return '-';
    return date('d/m/Y H:i', strtotime($date));
}

function getStatut($user) {
    $isActive = isset($user['actif']) ? (bool)$user['actif'] : true;
    if ($isActive) {
        return ['label' => 'Actif', 'class' => 'status-active', 'value' => 'actif'];
    } else {
        return ['label' => 'Suspendu', 'class' => 'status-inactive', 'value' => 'inactif'];
    }
}

function getRoleLabel($role) {
    $roles = [
        'admin' => 'Admin',
        'manager' => 'Manager',
        'user' => 'Utilisateur'
    ];
    return $roles[$role] ?? $role;
}

function getRoleClass($role) {
    $classes = [
        'admin' => 'role-admin',
        'manager' => 'role-manager',
        'user' => 'role-user'
    ];
    return $classes[$role] ?? '';
}

$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
unset($_SESSION['flash_message']);
unset($_SESSION['flash_error']);

// Statistiques
$actifs = 0;
$inactifs = 0;
$totalCredits = 0;
$roleCounts = ['admin' => 0, 'manager' => 0, 'user' => 0];

foreach ($users as $u) {
    $isActive = isset($u['actif']) ? (bool)$u['actif'] : true;
    if ($isActive) $actifs++;
    else $inactifs++;
    $totalCredits += floatval($u['credits_total'] ?? 0);
    $role = $u['role'] ?? 'user';
    if (isset($roleCounts[$role])) $roleCounts[$role]++;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des comptes - <?= APP_NAME ?></title>
    <style>
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .status-active { background: #dcfce7; color: #166534; }
        .status-inactive { background: #fee2e2; color: #991b1b; }
        
        .role-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .role-admin { background: #fef3c7; color: #92400e; }
        .role-manager { background: #dbeafe; color: #1e40af; }
        .role-user { background: #f3f4f6; color: #4b5563; }
        
        .user-table {
            width: 100%;
            border-collapse: collapse;
        }
        .user-table th,
        .user-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .user-table th {
            background-color: #f9fafb;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            color: #6b7280;
        }
        .user-table tr:hover {
            background-color: #f9fafb;
        }
        .credits-amount {
            font-weight: 600;
            color: #1f2937;
        }
        .btn-action {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-action:hover {
            transform: scale(1.05);
        }
        .btn-activer { background: #dcfce7; color: #166534; }
        .btn-activer:hover { background: #bbf7d0; }
        .btn-suspendre { background: #fee2e2; color: #991b1b; }
        .btn-suspendre:hover { background: #fecaca; }
        .btn-edit { background: #e0f2fe; color: #0369a1; }
        .btn-edit:hover { background: #bae6fd; }
        .btn-add-user { background: #8b5cf6; color: white; }
        .btn-add-user:hover { background: #7c3aed; }
        .btn-role { background: #f3f4f6; color: #374151; padding: 6px 10px; border-radius: 6px; font-size: 11px; }
        .btn-role:hover { background: #e5e7eb; }
        .btn-role.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-role.disabled:hover {
            transform: none;
            background: #f3f4f6;
        }
        
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
        .toast-notification.info .toast-content { background: #3b82f6; }
        
        .confirm-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
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
            border-bottom: 1px solid #e5e7eb;
        }
        .confirm-modal-header h3 {
            font-size: 18px;
            font-weight: bold;
            color: #1f2937;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .confirm-modal-body {
            padding: 24px;
        }
        .confirm-modal-body p {
            margin: 0 0 10px 0;
            color: #374151;
            line-height: 1.5;
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
        .btn-confirm-action {
            background: #dc2626;
            color: white;
        }
        .btn-confirm-action:hover {
            background: #b91c1c;
        }
        .btn-confirm-action.activate {
            background: #16a34a;
        }
        .btn-confirm-action.activate:hover {
            background: #15803d;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .alert-success { background: #dcfce7; border-left: 4px solid #16a34a; color: #166534; }
        .alert-error { background: #fee2e2; border-left: 4px solid #dc2626; color: #991b1b; }
        
        .modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        .modal-add-user, .modal-role, .modal-edit-user {
            transition: all 0.3s ease;
            transform: scale(0.95);
            opacity: 0;
        }
        .modal-add-user.modal-show, .modal-role.modal-show, .modal-edit-user.modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        
        .text-muted {
            color: #9ca3af;
            font-size: 11px;
            font-style: italic;
        }
        
        .password-wrapper {
            position: relative;
        }
        .password-wrapper input {
            padding-right: 40px !important;
        }
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            transition: color 0.2s;
            background: none;
            border: none;
            font-size: 16px;
            padding: 0;
        }
        .password-toggle:hover {
            color: #4b5563;
        }
    </style>
</head>
<body>

<!-- MODALE DE CONFIRMATION -->
<div id="confirmModal" class="confirm-modal">
    <div class="confirm-modal-content">
        <div class="confirm-modal-header">
            <h3 id="confirmModalTitle">Confirmation</h3>
        </div>
        <div class="confirm-modal-body">
            <p id="confirmModalMessage">Êtes-vous sûr de vouloir effectuer cette action ?</p>
        </div>
        <div class="confirm-modal-footer">
            <button class="btn-confirm-cancel" onclick="closeConfirmModal()">Annuler</button>
            <button class="btn-confirm-action" id="confirmModalBtn">Confirmer</button>
        </div>
    </div>
</div>

<!-- MODALE DE CRÉATION DE COMPTE -->
<div id="addUserModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-add-user">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-purple-100 p-2 rounded-full mr-3">
                        <i class="fas fa-user-plus text-purple-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Créer un nouveau compte</h3>
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
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500"
                               placeholder="Nom de l'entreprise">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label>
                            <input type="text" name="prenom" id="add_prenom" required 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                            <input type="text" name="nom" id="add_nom" required 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nom d'utilisateur *</label>
                        <input type="text" name="user" id="add_user" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500"
                               placeholder="ex: utilisateur">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe *</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="add_password" required 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500"
                                   placeholder="Min 6 caractères">
                            <button type="button" class="password-toggle" onclick="togglePassword('add_password', this)" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Le mot de passe doit contenir au moins 6 caractères</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rôle</label>
                        <select name="role" id="add_role" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500">
                            <option value="user">Utilisateur</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Crédits initiaux</label>
                        <input type="number" name="credits_total" id="add_credits_total" value="0" step="0.01" min="0"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500">
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeAddUserModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" id="createUserBtn"
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
                        <i class="fas fa-plus mr-2"></i>Créer le compte
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE D'ÉDITION DE COMPTE -->
<div id="editUserModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-edit-user">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                        <i class="fas fa-user-edit text-blue-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Modifier le compte</h3>
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
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                               placeholder="Nom de l'entreprise">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
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
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nom d'utilisateur *</label>
                        <input type="text" name="user" id="edit_user" required 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                               placeholder="ex: utilisateur">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nouveau mot de passe</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="edit_password" 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                                   placeholder="Laisser vide pour ne pas changer">
                            <button type="button" class="password-toggle" onclick="togglePassword('edit_password', this)" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Laissez vide pour conserver le mot de passe actuel</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rôle</label>
                        <select name="role" id="edit_role" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                            <option value="user">Utilisateur</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Crédits</label>
                        <input type="number" name="credits_total" id="edit_credits_total" value="0" step="0.01" min="0"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeEditUserModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" id="editUserBtn"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                        <i class="fas fa-save mr-2"></i>Enregistrer les modifications
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE DE CHANGEMENT DE RÔLE -->
<div id="roleModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-role">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                        <i class="fas fa-user-tag text-blue-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Changer le rôle</h3>
                </div>
                <button type="button" onclick="closeRoleModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="roleForm">
                <input type="hidden" name="id_compte" id="roleUserId">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nouveau rôle</label>
                    <select name="role" id="roleSelect" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                        <option value="user">Utilisateur</option>
                        <option value="admin">Admin</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Le rôle détermine les permissions du compte</p>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeRoleModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" id="changeRoleBtn"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                        <i class="fas fa-save mr-2"></i>Modifier le rôle
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Gestion des comptes</h1>
            <p class="text-gray-500">Gérez tous les comptes de la plateforme</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500">Total: <strong><?= $totalUsers ?></strong> compte(s)</span>

            <button onclick="openAddUserModal()" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-user-plus mr-2"></i>Nouveau compte
            </button>
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flashMessage) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div><span class="text-gray-500">Total</span><span class="text-2xl font-bold text-gray-800 ml-2"><?= $totalUsers ?></span></div>
                <div class="text-blue-400"><i class="fas fa-users text-2xl"></i></div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div><span class="text-gray-500">Actifs</span><span class="text-2xl font-bold text-green-600 ml-2"><?= $actifs ?></span></div>
                <div class="text-green-400"><i class="fas fa-check-circle text-2xl"></i></div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div><span class="text-gray-500">Suspendus</span><span class="text-2xl font-bold text-red-600 ml-2"><?= $inactifs ?></span></div>
                <div class="text-red-400"><i class="fas fa-times-circle text-2xl"></i></div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div><span class="text-gray-500">Crédits total</span><span class="text-2xl font-bold text-yellow-600 ml-2"><?= number_format($totalCredits, 2) ?> €</span></div>
                <div class="text-yellow-400"><i class="fas fa-coins text-2xl"></i></div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div><span class="text-gray-500">Admins</span><span class="text-2xl font-bold text-amber-600 ml-2"><?= $roleCounts['admin'] ?></span></div>
                <div class="text-amber-400"><i class="fas fa-crown text-2xl"></i></div>
            </div>
        </div>
    </div>

    <!-- Tableau des comptes -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="user-table">
                <thead>
                    <tr>
                        <th>Compte</th>
                        <th>Entreprise</th>
                        <th>Nom d'utilisateur</th>
                        <th>Crédits</th>
                        <th>Rôle</th>
                        <th>Statut</th>
                        <th>Date d'inscription</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-8 text-gray-500">
                                <i class="fas fa-users text-4xl mb-2 block"></i>
                                Aucun compte enregistré.
                                <button onclick="openAddUserModal()" class="text-purple-600 block mt-2">Créer le premier compte →</button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): 
                            $statutInfo = getStatut($user);
                            $isActive = isset($user['actif']) ? (bool)$user['actif'] : true;
                            $role = $user['role'] ?? 'user';
                            $roleLabel = getRoleLabel($role);
                            $roleClass = getRoleClass($role);
                            $isCurrentUser = ($user['id_compte'] == $idCompte);
                        ?>
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold">
                                            <?= strtoupper(substr($user['prenom'] ?? $user['user'] ?? 'U', 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="font-medium text-gray-800">
                                                <?= htmlspecialchars($user['prenom'] ?? $user['user'] ?? 'Utilisateur') ?>
                                                <?= htmlspecialchars($user['nom'] ?? '') ?>
                                                <?php if ($isCurrentUser): ?>
                                                    <span class="text-xs text-blue-600 ml-1">(Vous)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-xs text-gray-500">ID: <?= substr($user['id_compte'], 0, 8) ?>...</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <?php if (!empty($user['logo_url'])): ?>
                                            <img src="<?= htmlspecialchars($user['logo_url']) ?>" alt="Logo" class="w-6 h-6 rounded-full object-cover">
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($user['entreprise'] ?? '-') ?></span>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($user['user'] ?? '-') ?></td>
                                <td class="credits-amount"><?= number_format($user['credits_total'] ?? 0, 2) ?> €</td>
                                <td>
                                    <span class="role-badge <?= $roleClass ?>"><?= $roleLabel ?></span>
                                </td>
                                <td>
                                    <span class="status-badge <?= $statutInfo['class'] ?>"><?= $statutInfo['label'] ?></span>
                                </td>
                                <td><?= formatDate($user['date_creation'] ?? $user['created_at'] ?? '') ?></td>
                                <td>
                                    <div class="flex gap-2 flex-wrap">
                                        <?php if ($isActive): ?>
                                            <button onclick="openConfirmModal('suspendre', '<?= $user['id_compte'] ?>', '<?= htmlspecialchars($user['prenom'] ?? $user['user'] ?? 'Utilisateur') ?>')" 
                                                    class="btn-action btn-suspendre" title="Suspendre le compte">
                                                <i class="fas fa-pause"></i>
                                            </button>
                                        <?php else: ?>
                                            <button onclick="openConfirmModal('activer', '<?= $user['id_compte'] ?>', '<?= htmlspecialchars($user['prenom'] ?? $user['user'] ?? 'Utilisateur') ?>')" 
                                                    class="btn-action btn-activer" title="Activer le compte">
                                                <i class="fas fa-play"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($isCurrentUser): ?>
                                            <button class="btn-action btn-role disabled" title="Vous ne pouvez pas modifier votre propre rôle" disabled>
                                                <i class="fas fa-user-tag"></i>
                                                <span class="text-muted">(Vous)</span>
                                            </button>
                                        <?php else: ?>
                                            <button onclick="openRoleModal('<?= $user['id_compte'] ?>', '<?= $role ?>')" 
                                                    class="btn-action btn-role" title="Changer le rôle">
                                                <i class="fas fa-user-tag"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button onclick="openEditUserModal('<?= $user['id_compte'] ?>')" 
                                                class="btn-action btn-edit" title="Modifier le compte">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// ============================================
// AFFICHER/MASQUER LE MOT DE PASSE
// ============================================
function togglePassword(inputId, button) {
    const input = document.getElementById(inputId);
    const icon = button.querySelector('i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

// ============================================
// MODALE DE CRÉATION DE COMPTE
// ============================================
function openAddUserModal() {
    const modal = document.getElementById('addUserModal');
    const modalContent = modal.querySelector('.modal-add-user');
    document.getElementById('addUserForm').reset();
    document.getElementById('add_credits_total').value = '0';
    // Remettre le mot de passe en mode caché
    const pwdInput = document.getElementById('add_password');
    pwdInput.type = 'password';
    const toggleBtn = pwdInput.parentElement.querySelector('.password-toggle');
    toggleBtn.querySelector('i').className = 'fas fa-eye';
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
// MODALE D'ÉDITION DE COMPTE
// ============================================
function openEditUserModal(userId) {
    const modal = document.getElementById('editUserModal');
    const modalContent = modal.querySelector('.modal-edit-user');
    
    // Réinitialiser le formulaire
    document.getElementById('editUserForm').reset();
    document.getElementById('edit_password').value = '';
    
    // Charger les données du compte
    fetch(`?page=admin/users&action=get_user&id=${userId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const user = data.user;
                document.getElementById('edit_id_compte').value = user.id_compte;
                document.getElementById('edit_entreprise').value = user.entreprise || '';
                document.getElementById('edit_prenom').value = user.prenom || '';
                document.getElementById('edit_nom').value = user.nom || '';
                document.getElementById('edit_user').value = user.user || '';
                document.getElementById('edit_role').value = user.role || 'user';
                document.getElementById('edit_credits_total').value = user.credits_total || 0;
                
                // Remettre le mot de passe en mode caché
                const pwdInput = document.getElementById('edit_password');
                pwdInput.type = 'password';
                const toggleBtn = pwdInput.parentElement.querySelector('.password-toggle');
                toggleBtn.querySelector('i').className = 'fas fa-eye';
                
                modal.style.display = 'flex';
                setTimeout(() => modalContent.classList.add('modal-show'), 10);
            } else {
                showToast(data.error || 'Erreur lors du chargement du compte', 'error');
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            showToast('Erreur réseau lors du chargement du compte', 'error');
        });
}

function closeEditUserModal() {
    const modal = document.getElementById('editUserModal');
    const modalContent = modal.querySelector('.modal-edit-user');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// ============================================
// MODALE DE CHANGEMENT DE RÔLE
// ============================================
let currentRoleUserId = null;

function openRoleModal(userId, currentRole) {
    currentRoleUserId = userId;
    document.getElementById('roleUserId').value = userId;
    document.getElementById('roleSelect').value = currentRole;
    
    const modal = document.getElementById('roleModal');
    const modalContent = modal.querySelector('.modal-role');
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeRoleModal() {
    const modal = document.getElementById('roleModal');
    const modalContent = modal.querySelector('.modal-role');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
    currentRoleUserId = null;
}

// ============================================
// SOUMISSION DU FORMULAIRE DE CRÉATION DE COMPTE (AJAX)
// ============================================
document.getElementById('addUserForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action_create_user', '1');
    
    const submitBtn = document.getElementById('createUserBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Création...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        if (!response.ok) {
            const text = await response.text();
            console.error('HTTP Error:', response.status, text);
            showToast('Erreur serveur: ' + response.status, 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        const textResponse = await response.text();
        console.log('Réponse brute:', textResponse);
        
        if (!textResponse.startsWith('{')) {
            console.error('La réponse n\'est pas du JSON:', textResponse);
            showToast('Erreur: réponse invalide du serveur', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        let result;
        try {
            result = JSON.parse(textResponse);
        } catch (parseError) {
            console.error('Erreur de parsing:', parseError);
            showToast('Erreur de parsing: ' + textResponse.substring(0, 200), 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        if (result.success) {
            showToast(result.message, 'success');
            closeAddUserModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error || 'Erreur inconnue', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Erreur réseau:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// ============================================
// SOUMISSION DU FORMULAIRE D'ÉDITION DE COMPTE (AJAX)
// ============================================
document.getElementById('editUserForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action_edit_user', '1');
    
    const submitBtn = document.getElementById('editUserBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enregistrement...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        if (!response.ok) {
            const text = await response.text();
            console.error('HTTP Error:', response.status, text);
            showToast('Erreur serveur: ' + response.status, 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        const textResponse = await response.text();
        console.log('Réponse brute edit:', textResponse);
        
        if (!textResponse.startsWith('{')) {
            console.error('La réponse n\'est pas du JSON:', textResponse);
            showToast('Erreur: réponse invalide du serveur', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        let result;
        try {
            result = JSON.parse(textResponse);
        } catch (parseError) {
            console.error('Erreur de parsing:', parseError);
            showToast('Erreur de parsing: ' + textResponse.substring(0, 200), 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        if (result.success) {
            showToast(result.message, 'success');
            closeEditUserModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error || 'Erreur inconnue', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Erreur réseau:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// ============================================
// SOUMISSION DU FORMULAIRE DE CHANGEMENT DE RÔLE (AJAX)
// ============================================
document.getElementById('roleForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action_change_role', '1');
    
    const submitBtn = document.getElementById('changeRoleBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Modification...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        if (!response.ok) {
            const text = await response.text();
            console.error('HTTP Error:', response.status, text);
            showToast('Erreur serveur: ' + response.status, 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        const textResponse = await response.text();
        console.log('Réponse brute role:', textResponse);
        
        if (!textResponse.startsWith('{')) {
            console.error('La réponse n\'est pas du JSON:', textResponse);
            showToast('Erreur: réponse invalide du serveur', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        let result;
        try {
            result = JSON.parse(textResponse);
        } catch (parseError) {
            console.error('Erreur de parsing:', parseError);
            showToast('Erreur de parsing: ' + textResponse.substring(0, 200), 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        if (result.success) {
            showToast(result.message, 'success');
            closeRoleModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error || 'Erreur inconnue', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Erreur réseau:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// ============================================
// MODALE DE CONFIRMATION
// ============================================
let confirmAction = null;
let confirmId = null;
let confirmName = null;

function openConfirmModal(action, id, name) {
    confirmAction = action;
    confirmId = id;
    confirmName = name;
    
    const modal = document.getElementById('confirmModal');
    const title = document.getElementById('confirmModalTitle');
    const message = document.getElementById('confirmModalMessage');
    const btn = document.getElementById('confirmModalBtn');
    
    if (action === 'activer') {
        title.innerHTML = 'Activer le compte';
        message.innerHTML = `Êtes-vous sûr de vouloir <strong>activer</strong> le compte de <strong>${name}</strong> ?`;
        btn.textContent = 'Activer';
        btn.className = 'btn-confirm-action activate';
    } else {
        title.innerHTML = 'Suspendre le compte';
        message.innerHTML = `Êtes-vous sûr de vouloir <strong>suspendre</strong> le compte de <strong>${name}</strong> ?<br><br><span style="color: #dc2626; font-size: 13px;">Le compte ne pourra plus se connecter jusqu'à une nouvelle activation.</span>`;
        btn.textContent = 'Suspendre';
        btn.className = 'btn-confirm-action';
    }
    
    modal.classList.add('show');
}

function closeConfirmModal() {
    document.getElementById('confirmModal').classList.remove('show');
    confirmAction = null;
    confirmId = null;
    confirmName = null;
}

document.getElementById('confirmModalBtn').addEventListener('click', function() {
    if (confirmAction && confirmId) {
        window.location.href = `index.php?page=admin/users&action=${confirmAction}&id=${confirmId}`;
    }
});

document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirmModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeConfirmModal();
        closeAddUserModal();
        closeRoleModal();
        closeEditUserModal();
    }
});

document.getElementById('addUserModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddUserModal();
});

document.getElementById('editUserModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditUserModal();
});

document.getElementById('roleModal').addEventListener('click', function(e) {
    if (e.target === this) closeRoleModal();
});

// ============================================
// TOAST NOTIFICATION
// ============================================
function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const colors = { success: '#10b981', error: '#ef4444', info: '#3b82f6' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Messages flash
<?php if ($flashMessage): ?>
    showToast('<?= addslashes($flashMessage) ?>', 'success');
<?php endif; ?>
<?php if ($flashError): ?>
    showToast('<?= addslashes($flashError) ?>', 'error');
<?php endif; ?>
</script>

</body>
</html>