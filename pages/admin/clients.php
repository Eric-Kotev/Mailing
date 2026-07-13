<?php
// Vérification que l'utilisateur est admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

global $db;

// ============================================
// TRAITEMENT DES ACTIONS AJAX
// ============================================

// --- CRÉATION D'UN CLIENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_client'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $entreprise = trim($_POST['entreprise'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');
        $code_postal = trim($_POST['code_postal'] ?? '');
        $ville = trim($_POST['ville'] ?? '');
        $mot_de_passe = $_POST['mot_de_passe'] ?? '';
        
        $errors = [];
        if (empty($entreprise)) $errors[] = "L'entreprise est requise";
        if (empty($nom)) $errors[] = "Le nom est requis";
        if (empty($prenom)) $errors[] = "Le prénom est requis";
        if (empty($user)) $errors[] = "L'email est requis";
        if (empty($mot_de_passe) || strlen($mot_de_passe) < 6) $errors[] = "Le mot de passe doit contenir au moins 6 caractères";
        
        // Vérifier que l'email n'est pas déjà utilisé
        if (!empty($user)) {
            $existing = $db->select('compte', ['user' => $user]);
            if (!empty($existing)) {
                $errors[] = "Cet email est déjà utilisé par un autre compte";
            }
        }
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
            exit;
        }
        
        $hashedPassword = password_hash($mot_de_passe, PASSWORD_DEFAULT);
        
        $data = [
            'entreprise' => $entreprise,
            'nom' => $nom,
            'prenom' => $prenom,
            'user' => $user,
            'password' => $hashedPassword,
            'credits_total' => 0,
            'role' => 'client',
            'actif' => true,
            'telephone' => $telephone,
            'adresse' => $adresse,
            'code_postal' => $code_postal,
            'ville' => $ville,
            'date_creation' => date('Y-m-d H:i:s'),
            'date_suspension' => null,
            'logo_url' => null
        ];
        
        $clientId = $db->insertAndGetId('compte', $data);
        
        if ($clientId) {
            echo json_encode(['success' => true, 'message' => 'Client créé avec succès', 'id' => $clientId]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur lors de la création du client']);
        }
        
    } catch (Exception $e) {
        error_log("ERREUR création client: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- MODIFICATION D'UN CLIENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_client'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = intval($_POST['id_compte'] ?? 0);
        $entreprise = trim($_POST['entreprise'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');
        $code_postal = trim($_POST['code_postal'] ?? '');
        $ville = trim($_POST['ville'] ?? '');
        $mot_de_passe = $_POST['mot_de_passe'] ?? '';
        $credits_total = floatval($_POST['credits_total'] ?? 0);
        $statut = $_POST['statut'] ?? 'actif';
        
        $errors = [];
        if (empty($clientId) || $clientId <= 0) $errors[] = "ID client invalide";
        if (empty($entreprise)) $errors[] = "L'entreprise est requise";
        if (empty($nom)) $errors[] = "Le nom est requis";
        if (empty($prenom)) $errors[] = "Le prénom est requis";
        if (empty($user)) $errors[] = "L'email est requis";
        
        // Vérifier que l'email n'est pas déjà utilisé par un autre compte
        if (!empty($user)) {
            $existing = $db->select('compte', ['user' => $user]);
            if (!empty($existing) && $existing[0]['id_compte'] != $clientId) {
                $errors[] = "Cet email est déjà utilisé par un autre compte";
            }
        }
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
            exit;
        }
        
        $data = [
            'entreprise' => $entreprise,
            'nom' => $nom,
            'prenom' => $prenom,
            'user' => $user,
            'credits_total' => $credits_total,
            'actif' => ($statut === 'actif' ? true : false),
            'telephone' => $telephone,
            'adresse' => $adresse,
            'code_postal' => $code_postal,
            'ville' => $ville
        ];
        
        // Si un nouveau mot de passe est fourni, le hasher
        if (!empty($mot_de_passe)) {
            if (strlen($mot_de_passe) < 6) {
                echo json_encode(['success' => false, 'error' => 'Le mot de passe doit contenir au moins 6 caractères']);
                exit;
            }
            $data['password'] = password_hash($mot_de_passe, PASSWORD_DEFAULT);
        }
        
        $result = $db->update('compte', $data, ['id_compte' => $clientId]);
        
        if ($result !== false) {
            echo json_encode(['success' => true, 'message' => 'Client modifié avec succès']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur lors de la modification du client']);
        }
        
    } catch (Exception $e) {
        error_log("ERREUR modification client: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- SUPPRESSION D'UN CLIENT (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_client'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        // 🔥 Récupérer l'ID comme string (ne pas utiliser intval)
        $clientId = $_POST['id_compte'] ?? '';
        
        // Log pour debug
        error_log("ID client reçu: " . $clientId . " - Type: " . gettype($clientId));
        
        if (empty($clientId)) {
            echo json_encode(['success' => false, 'error' => 'ID client invalide']);
            exit;
        }
        
        // Vérifier que c'est bien un client
        $client = $db->select('compte', ['id_compte' => $clientId, 'role' => 'client']);
        if (empty($client)) {
            echo json_encode(['success' => false, 'error' => 'Ce compte n\'est pas un client']);
            exit;
        }
        
        $db->delete('compte', (string)$clientId, 'id_compte');
        
        echo json_encode(['success' => true, 'message' => 'Client supprimé avec succès']);
        
    } catch (Exception $e) {
        error_log("ERREUR suppression client: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// --- SUPPRESSION D'UN CLIENT (GET - fallback) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $clientId = intval($_GET['id']);
    try {
        $db->delete('compte', $clientId, 'id_compte');
        $_SESSION['flash_message'] = "Client supprimé avec succès";
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Erreur lors de la suppression: " . $e->getMessage();
    }
    header('Location: index.php?page=admin/clients');
    exit;
}

// --- RÉCUPÉRATION DES DONNÉES D'UN CLIENT ---
if (isset($_GET['action']) && $_GET['action'] === 'get_client' && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = intval($_GET['id']);
        $client = $db->select('compte', ['id_compte' => $clientId, 'role' => 'client']);
        
        if (!empty($client)) {
            // Ne pas renvoyer le mot de passe
            unset($client[0]['password']);
            echo json_encode(['success' => true, 'client' => $client[0]]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Client non trouvé']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- CHANGEMENT DE STATUT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_toggle_status'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = intval($_POST['id_compte'] ?? 0);
        $newStatut = $_POST['statut'] ?? 'actif';
        
        if ($clientId <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID client invalide']);
            exit;
        }
        
        $actif = ($newStatut === 'actif') ? true : false;
        $db->update('compte', ['actif' => $actif], ['id_compte' => $clientId]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Statut mis à jour avec succès',
            'statut' => $newStatut
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR changement statut: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- Mise à jour du crédit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_credit'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = intval($_POST['id_compte'] ?? 0);
        $nouveauCredit = floatval($_POST['credit'] ?? 0);
        
        if ($clientId <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID client invalide']);
            exit;
        }
        
        if ($nouveauCredit < 0) {
            echo json_encode(['success' => false, 'error' => 'Le crédit ne peut pas être négatif']);
            exit;
        }
        
        $db->update('compte', ['credits_total' => $nouveauCredit], ['id_compte' => $clientId]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Crédit mis à jour avec succès',
            'credit' => number_format($nouveauCredit, 2)
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR mise à jour crédit: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// =========================
// RÉCUPÉRATION DES DONNÉES
// =========================

// Récupérer tous les clients (role = 'client')
$clients = $db->select('compte', ['role' => 'client'], '*', 'date_creation DESC');

// Recherche
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Filtrer les clients par recherche
$filteredClients = $clients;
if (!empty($search)) {
    $searchLower = strtolower($search);
    $filteredClients = array_filter($clients, function($c) use ($searchLower) {
        return strpos(strtolower($c['entreprise'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($c['nom'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($c['prenom'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($c['user'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($c['telephone'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($c['ville'] ?? ''), $searchLower) !== false;
    });
}

// Total clients (pour l'affichage)
$totalClients = count($clients);

// Messages flash
$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
unset($_SESSION['flash_message']);
unset($_SESSION['flash_error']);

function getStatutBadge($actif) {
    if ($actif) {
        return ['label' => 'Actif', 'class' => 'bg-green-100 text-green-800'];
    } else {
        return ['label' => 'Inactif', 'class' => 'bg-red-100 text-red-800'];
    }
}

function formatDate($date) {
    if (empty($date)) return '-';
    return date('d/m/Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clients - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
        .toast-notification.info .toast-content { background: #3b82f6; }
        
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
        .modal-add-client, .modal-edit-client, .modal-confirm {
            transition: all 0.3s ease;
            transform: scale(0.95);
            opacity: 0;
        }
        .modal-add-client.modal-show, .modal-edit-client.modal-show, .modal-confirm.modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        
        .client-table th {
            background-color: #f9fafb;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            color: #6b7280;
        }
        .client-table tr:hover {
            background-color: #f9fafb;
        }
        
        .btn-action {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        .btn-action:hover { transform: scale(1.05); }
        .btn-view { background: #dbeafe; color: #1e40af; }
        .btn-view:hover { background: #bfdbfe; }
        .btn-delete { background: #fee2e2; color: #991b1b; }
        .btn-delete:hover { background: #fecaca; }
        
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
        
        .search-input:focus {
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
            border-color: #10b981;
        }
    </style>
</head>
<body>

<!-- MODALE DE CONFIRMATION DE SUPPRESSION -->
<div id="confirmDeleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 modal-confirm">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-red-100 p-2 rounded-full mr-3">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Confirmation</h3>
                </div>
                <button type="button" onclick="closeConfirmDeleteModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="mb-6">
                <p class="text-gray-700" id="confirmDeleteMessage">
                    Êtes-vous sûr de vouloir supprimer ce client ?
                </p>
                <p class="text-sm text-red-600 mt-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    Cette action est irréversible.
                </p>
            </div>
            
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeConfirmDeleteModal()" 
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Annuler
                </button>
                <button type="button" id="confirmDeleteBtn" 
                        class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition">
                    <i class="fas fa-trash mr-2"></i>Supprimer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODALE DE CRÉATION DE CLIENT -->
<div id="addClientModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 modal-add-client max-h-[90vh] overflow-y-auto">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                        <i class="fas fa-user-plus text-blue-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Ajouter un client</h3>
                </div>
                <button type="button" onclick="closeAddClientModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="addClientForm" method="POST">
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Entreprise *</label>
                        <input type="text" name="entreprise" id="add_entreprise" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                        <input type="text" name="nom" id="add_nom" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label>
                        <input type="text" name="prenom" id="add_prenom" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                        <input type="email" name="user" id="add_user" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                        <input type="text" name="telephone" id="add_telephone" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500">
                    </div>
                    
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                        <input type="text" name="adresse" id="add_adresse" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Code postal</label>
                        <input type="text" name="code_postal" id="add_code_postal" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ville</label>
                        <input type="text" name="ville" id="add_ville" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500">
                    </div>
                    
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe *</label>
                        <div class="password-wrapper">
                            <input type="password" name="mot_de_passe" id="add_mot_de_passe" required 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500" 
                                   placeholder="Minimum 6 caractères">
                            <button type="button" class="password-toggle" onclick="togglePassword('add_mot_de_passe', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Le mot de passe doit contenir au moins 6 caractères</p>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeAddClientModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" id="createClientBtn" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                        <i class="fas fa-plus mr-2"></i>Créer le client
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE D'ÉDITION DE CLIENT -->
<div id="editClientModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full mx-4 modal-edit-client max-h-[90vh] overflow-y-auto">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                        <i class="fas fa-user-edit text-blue-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Modifier le client</h3>
                </div>
                <button type="button" onclick="closeEditClientModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form id="editClientForm" method="POST">
                <input type="hidden" name="id_compte" id="edit_id_compte">
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Entreprise *</label>
                        <input type="text" name="entreprise" id="edit_entreprise" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                        <input type="text" name="nom" id="edit_nom" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label>
                        <input type="text" name="prenom" id="edit_prenom" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                        <input type="email" name="user" id="edit_user" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                        <input type="text" name="telephone" id="edit_telephone" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                        <input type="text" name="adresse" id="edit_adresse" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Code postal</label>
                        <input type="text" name="code_postal" id="edit_code_postal" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ville</label>
                        <input type="text" name="ville" id="edit_ville" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Crédits</label>
                        <input type="number" name="credits_total" id="edit_credits_total" step="0.01" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nouveau mot de passe</label>
                        <div class="password-wrapper">
                            <input type="password" name="mot_de_passe" id="edit_mot_de_passe" 
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500" 
                                   placeholder="Laisser vide pour ne pas changer">
                            <button type="button" class="password-toggle" onclick="togglePassword('edit_mot_de_passe', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Laissez vide pour conserver le mot de passe actuel</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                        <select name="statut" id="edit_statut" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                            <option value="actif">Actif</option>
                            <option value="inactif">Inactif</option>
                        </select>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeEditClientModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" id="editClientBtn" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                        <i class="fas fa-save mr-2"></i>Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- CONTENU PRINCIPAL -->
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Clients</h1>
            <p class="text-gray-500">Gérez tous les clients de la plateforme</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500">Total: <strong><?= $totalClients ?></strong> client(s)</span>
            <button onclick="openAddClientModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-user-plus mr-2"></i>Nouveau client
            </button>
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flashMessage) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <!-- Barre de recherche -->
    <div class="bg-white rounded-lg shadow p-4">
        <form method="GET" action="" class="flex items-center gap-4">
            <input type="hidden" name="page" value="admin/clients">
            <div class="flex-1 relative">
                <input type="text" 
                       name="search" 
                       value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Rechercher un client (nom, entreprise, email, téléphone, ville...)" 
                       class="search-input w-full border border-gray-300 rounded-lg px-4 py-2.5 pl-10 focus:outline-none focus:border-green-500">
                <i class="fas fa-search absolute left-3 top-3.5 text-gray-400"></i>
            </div>
            <button type="submit" class="px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                <i class="fas fa-search mr-2"></i>Rechercher
            </button>
            <?php if (!empty($search)): ?>
                <a href="?page=admin/clients" class="px-4 py-2.5 border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 transition">
                    <i class="fas fa-times mr-2"></i>Effacer
                </a>
            <?php endif; ?>
        </form>
        <?php if (!empty($search)): ?>
            <div class="mt-2 text-sm text-gray-500">
                <i class="fas fa-info-circle mr-1"></i>
                Résultats pour "<strong><?= htmlspecialchars($search) ?></strong>" : 
                <strong><?= count($filteredClients) ?></strong> client(s) trouvé(s)
            </div>
        <?php endif; ?>
    </div>

    <!-- Tableau des clients -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full client-table">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left">Client</th>
                        <th class="px-6 py-3 text-left">Entreprise</th>
                        <th class="px-6 py-3 text-left">Email</th>
                        <th class="px-6 py-3 text-left">Ville</th>
                        <th class="px-6 py-3 text-left">Crédits</th>
                        <th class="px-6 py-3 text-left">Statut</th>
                        <th class="px-6 py-3 text-left">Date</th>
                        <th class="px-6 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filteredClients)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-8 text-gray-500">
                                <i class="fas fa-users text-4xl mb-2 block"></i>
                                <?php if (!empty($search)): ?>
                                    Aucun client ne correspond à votre recherche.
                                    <a href="?page=admin/clients" class="text-green-600 block mt-2">Voir tous les clients →</a>
                                <?php else: ?>
                                    Aucun client enregistré.
                                    <button onclick="openAddClientModal()" class="text-green-600 block mt-2">Créer le premier client →</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($filteredClients as $client): 
                            $statut = getStatutBadge($client['actif']);
                        ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900">
                                        <?= htmlspecialchars($client['prenom']) ?>
                                        <?= htmlspecialchars($client['nom']) ?>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        ID: <?= substr($client['id_compte'], 0, 8) ?>...
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <?= htmlspecialchars($client['entreprise']) ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <?= htmlspecialchars($client['user']) ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <?= htmlspecialchars($client['ville'] ?? '-') ?>
                                </td>
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900">
                                    <?= number_format($client['credits_total'] ?? 0, 2) ?> €
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?= $statut['class'] ?>">
                                        <?= $statut['label'] ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <?= formatDate($client['date_creation']) ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-center gap-2">
                                        <!-- Bouton Voir -->
                                        <a href="?page=admin/client-detail&id=<?= $client['id_compte'] ?>" 
                                           class="btn-action btn-view" title="Voir les détails">
                                            <i class="fas fa-eye"></i> Voir
                                        </a>
                                        
                                        <!-- 🔥 Bouton Supprimer avec data-* attributs (corrigé) -->
                                        <button type="button" 
                                                data-id="<?= $client['id_compte'] ?>" 
                                                data-name="<?= htmlspecialchars($client['prenom'] . ' ' . $client['nom'], ENT_QUOTES) ?>" 
                                                onclick="openConfirmDeleteModal(this)" 
                                                class="btn-action btn-delete" title="Supprimer">
                                            <i class="fas fa-trash"></i> Supprimer
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
// VARIABLES GLOBALES
// ============================================
let deleteClientId = null;

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
// MODALE DE CONFIRMATION DE SUPPRESSION (CORRIGÉE)
// ============================================
function openConfirmDeleteModal(button) {
    // 🔥 Récupérer l'ID tel quel (sans le convertir en nombre)
    const clientId = button.getAttribute('data-id');
    const clientName = button.getAttribute('data-name');
    
    console.log('ID du client à supprimer:', clientId, 'Type:', typeof clientId);
    
    deleteClientId = clientId;
    const modal = document.getElementById('confirmDeleteModal');
    const modalContent = modal.querySelector('.modal-confirm');
    const message = document.getElementById('confirmDeleteMessage');
    
    message.innerHTML = `Êtes-vous sûr de vouloir supprimer le client <strong>${clientName}</strong> ?`;
    
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeConfirmDeleteModal() {
    const modal = document.getElementById('confirmDeleteModal');
    const modalContent = modal.querySelector('.modal-confirm');
    modalContent.classList.remove('modal-show');
    setTimeout(() => {
        modal.style.display = 'none';
        deleteClientId = null;
    }, 200);
}

// Confirmation de suppression (AJAX)
document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
    if (!deleteClientId) {
        showToast('Aucun client sélectionné', 'error');
        return;
    }
    
    const btn = this;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Suppression...';
    btn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action_delete_client', '1');
        // 🔥 S'assurer que l'ID est une string
        formData.append('id_compte', String(deleteClientId));
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const textResponse = await response.text();
        console.log('Réponse brute:', textResponse);
        
        if (!textResponse.startsWith('{')) {
            showToast('Erreur: réponse invalide du serveur', 'error');
            btn.innerHTML = originalText;
            btn.disabled = false;
            return;
        }
        
        const result = JSON.parse(textResponse);
        
        if (result.success) {
            showToast(result.message, 'success');
            closeConfirmDeleteModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error || 'Erreur inconnue', 'error');
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
});

// ============================================
// MODALE DE CRÉATION
// ============================================
function openAddClientModal() {
    const modal = document.getElementById('addClientModal');
    const modalContent = modal.querySelector('.modal-add-client');
    document.getElementById('addClientForm').reset();
    modal.style.display = 'flex';
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeAddClientModal() {
    const modal = document.getElementById('addClientModal');
    const modalContent = modal.querySelector('.modal-add-client');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// ============================================
// MODALE D'ÉDITION
// ============================================
function openEditClientModal(clientId) {
    const modal = document.getElementById('editClientModal');
    const modalContent = modal.querySelector('.modal-edit-client');
    
    fetch(`?page=admin/clients&action=get_client&id=${clientId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const client = data.client;
                document.getElementById('edit_id_compte').value = client.id_compte;
                document.getElementById('edit_entreprise').value = client.entreprise || '';
                document.getElementById('edit_nom').value = client.nom || '';
                document.getElementById('edit_prenom').value = client.prenom || '';
                document.getElementById('edit_user').value = client.user || '';
                document.getElementById('edit_telephone').value = client.telephone || '';
                document.getElementById('edit_adresse').value = client.adresse || '';
                document.getElementById('edit_code_postal').value = client.code_postal || '';
                document.getElementById('edit_ville').value = client.ville || '';
                document.getElementById('edit_credits_total').value = client.credits_total || 0;
                document.getElementById('edit_statut').value = client.actif ? 'actif' : 'inactif';
                document.getElementById('edit_mot_de_passe').value = '';
                
                modal.style.display = 'flex';
                setTimeout(() => modalContent.classList.add('modal-show'), 10);
            } else {
                showToast(data.error || 'Erreur lors du chargement du client', 'error');
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            showToast('Erreur réseau lors du chargement du client', 'error');
        });
}

function closeEditClientModal() {
    const modal = document.getElementById('editClientModal');
    const modalContent = modal.querySelector('.modal-edit-client');
    modalContent.classList.remove('modal-show');
    setTimeout(() => modal.style.display = 'none', 200);
}

// ============================================
// SOUMISSION AJAX - CRÉATION
// ============================================
document.getElementById('addClientForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action_create_client', '1');
    
    const submitBtn = document.getElementById('createClientBtn');
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
        
        const textResponse = await response.text();
        
        if (!textResponse.startsWith('{')) {
            showToast('Erreur: réponse invalide du serveur', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        const result = JSON.parse(textResponse);
        
        if (result.success) {
            showToast(result.message, 'success');
            closeAddClientModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error || 'Erreur inconnue', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// ============================================
// SOUMISSION AJAX - ÉDITION
// ============================================
document.getElementById('editClientForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action_edit_client', '1');
    
    const submitBtn = document.getElementById('editClientBtn');
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
        
        const textResponse = await response.text();
        
        if (!textResponse.startsWith('{')) {
            showToast('Erreur: réponse invalide du serveur', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }
        
        const result = JSON.parse(textResponse);
        
        if (result.success) {
            showToast(result.message, 'success');
            closeEditClientModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error || 'Erreur inconnue', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
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

// Fermer les modales avec Echap
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeConfirmDeleteModal();
        closeAddClientModal();
        closeEditClientModal();
    }
});

// Fermer les modales en cliquant à l'extérieur
document.getElementById('confirmDeleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirmDeleteModal();
});

document.getElementById('addClientModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddClientModal();
});

document.getElementById('editClientModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditClientModal();
});
</script>

</body>
</html>