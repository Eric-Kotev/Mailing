<?php
// Vérification que l'utilisateur est admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

global $db;

// Récupérer l'ID du client
$id = isset($_GET['id']) ? $_GET['id'] : '';

if (empty($id)) {
    header('Location: index.php?page=admin/clients');
    exit;
}

$id = (string)$id;

// Récupérer les données du client (dans la table compte)
$clientData = $db->select('compte', ['id_compte' => $id, 'role' => 'client']);
if (empty($clientData)) {
    header('Location: index.php?page=admin/clients');
    exit;
}
$client = $clientData[0];

// =======================
// TRAITEMENT DES ACTIONS
// =======================

// --- Changement de statut (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_toggle_status'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {

        $clientId = $_POST['id_compte'] ?? '';
        $newStatut = $_POST['statut'] ?? 'actif';
        
        if (empty($clientId)) {
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

// --- Mise à jour du crédit (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_credit'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        $nouveauCredit = floatval($_POST['credit'] ?? 0);
        
        if (empty($clientId)) {
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

// --- Modification des informations (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_info'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
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
        
        $db->update('compte', $data, ['id_compte' => $clientId]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Informations mises à jour avec succès'
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR mise à jour informations: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ======================
// FONCTIONS UTILITAIRES
// ======================

function getStatutBadge($actif) {
    if ($actif) {
        return [
            'label' => 'Actif', 
            'class' => 'bg-green-100 text-green-800',
            'icon' => 'fa-check-circle'
        ];
    } else {
        return [
            'label' => 'Inactif', 
            'class' => 'bg-red-100 text-red-800',
            'icon' => 'fa-times-circle'
        ];
    }
}

function formatDate($date) {
    if (empty($date)) return '-';
    return date('d/m/Y', strtotime($date));
}

function getInitials($prenom, $nom) {
    return strtoupper(substr($prenom, 0, 1) . substr($nom, 0, 1));
}

$statut = getStatutBadge($client['actif']);
$initials = getInitials($client['prenom'], $client['nom']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails du client - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .statut-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .statut-badge i {
            margin-right: 6px;
        }
        
        .statut-badge.actif {
            background: #dcfce7;
            color: #166534;
        }
        
        .statut-badge.inactif {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .btn-toggle {
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-toggle:hover {
            transform: scale(1.05);
        }
        
        .btn-toggle.activer {
            background: #22c55e;
            color: white;
        }
        
        .btn-toggle.activer:hover {
            background: #16a34a;
        }
        
        .btn-toggle.desactiver {
            background: #ef4444;
            color: white;
        }
        
        .btn-toggle.desactiver:hover {
            background: #dc2626;
        }
        
        .info-card {
            background: white;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        
        .info-card-header {
            padding: 14px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .info-card-header .title {
            font-weight: 700;
            font-size: 14px;
            color: #1f2937;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            padding: 16px 20px;
        }
        
        .info-item .label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.03em;
        }
        
        .info-item .value {
            font-size: 14px;
            margin-top: 4px;
            color: #1f2937;
        }
        
        .stat-card {
            background: white;
            border-radius: 14px;
            padding: 16px 20px;
            border: 1px solid #e5e7eb;
        }
        
        .stat-card .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
        }
        
        .stat-card .stat-value {
            font-size: 20px;
            font-weight: 700;
            margin-top: 4px;
            color: #1f2937;
        }
        
        .edit-icon {
            color: #3b82f6;
            font-size: 13px;
            cursor: pointer;
            font-weight: 600;
            transition: color 0.2s;
        }
        
        .edit-icon:hover {
            color: #1d4ed8;
        }
        
        .save-icon {
            color: #22c55e;
            font-size: 13px;
            cursor: pointer;
            font-weight: 600;
            transition: color 0.2s;
        }
        
        .save-icon:hover {
            color: #16a34a;
        }
        
        .cancel-icon {
            color: #6b7280;
            font-size: 13px;
            cursor: pointer;
            font-weight: 600;
            transition: color 0.2s;
        }
        
        .cancel-icon:hover {
            color: #4b5563;
        }
        
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(20, 20, 40, 0.45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 50;
            backdrop-filter: blur(4px);
        }
        
        .modal-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            width: 420px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
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
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            font-size: 14px;
            font-weight: 500;
        }
        
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        .toast-notification.info .toast-content { background: #3b82f6; }
        
        .input-edit {
            width: 100%;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        
        .input-edit:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: color 0.2s;
            text-decoration: none;
        }
        
        .btn-back:hover {
            color: #1f2937;
        }
        
        .btn-action-blue {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            background: #3b82f6;
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-action-blue:hover {
            background: #2563eb;
            transform: scale(1.05);
        }
        
        .btn-action-green {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            background: #22c55e;
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-action-green:hover {
            background: #16a34a;
            transform: scale(1.05);
        }
        
        .btn-action-red {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            background: #ef4444;
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-action-red:hover {
            background: #dc2626;
            transform: scale(1.05);
        }
    </style>
</head>
<body>

<!-- MODALE DE RECHARGE DE CRÉDIT -->
<div id="rechargeModal" class="modal-overlay" style="display: none;">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Recharger le crédit</h3>
                <p class="text-sm text-gray-500"><?= htmlspecialchars($client['entreprise']) ?></p>
            </div>
            <button onclick="closeRechargeModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <div class="mb-4">
            <p class="text-sm text-gray-600">Solde actuel : <strong><?= number_format($client['credits_total'] ?? 0, 2) ?> €</strong></p>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Montant à ajouter (€)</label>
            <input type="number" id="rechargeAmount" step="0.01" min="0.01" 
                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:outline-none focus:border-blue-500"
                   placeholder="Ex: 100">
        </div>
        
        <div class="mt-6 flex justify-end gap-2">
            <button onclick="closeRechargeModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Annuler
            </button>
            <button onclick="confirmRecharge()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                <i class="fas fa-plus mr-2"></i>Recharger
            </button>
        </div>
    </div>
</div>

<!-- CONTENU PRINCIPAL -->
<div class="p-6">

    <!-- Bouton retour -->
    <div class="mb-6">
        <a href="?page=admin/clients" class="btn-back">
            <i class="fas fa-arrow-left"></i> Retour aux clients
        </a>
    </div>

    <!-- En-tête du client -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold text-2xl">
                <?= $initials ?>
            </div>
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-gray-800">
                        <?= htmlspecialchars($client['prenom']) ?> <?= htmlspecialchars($client['nom']) ?>
                    </h1>
                    <span class="statut-badge <?= $client['actif'] ? 'actif' : 'inactif' ?>">
                        <i class="fas <?= $statut['icon'] ?>"></i>
                        <?= $statut['label'] ?>
                    </span>
                </div>
                <p class="text-gray-500 text-sm">
                    <?= htmlspecialchars($client['entreprise']) ?>
                </p>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="flex gap-2">
            <button onclick="toggleStatus()" id="toggleStatusBtn" 
                    class="<?= $client['actif'] ? 'btn-action-red' : 'btn-action-green' ?>">
                <i class="fas <?= $client['actif'] ? 'fa-pause' : 'fa-play' ?>"></i>
                <?= $client['actif'] ? 'Désactiver' : 'Activer' ?>
            </button>
            
            <button onclick="openRechargeModal()" class="btn-action-blue">
                <i class="fas fa-plus"></i> Recharger le crédit
            </button>
        </div>
    </div>

    <!-- Cartes de statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="stat-card">
            <div class="stat-label">Crédit disponible</div>
            <div class="stat-value">
                <span id="creditDisplay"><?= number_format($client['credits_total'] ?? 0, 2) ?></span> €
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Date d'inscription</div>
            <div class="stat-value"><?= formatDate($client['date_creation']) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Email</div>
            <div class="stat-value text-base truncate"><?= htmlspecialchars($client['user']) ?></div>
        </div>
    </div>

    <!-- Informations du client -->
    <div class="info-card mb-6">
        <div class="info-card-header">
            <span class="title"><i class="fas fa-user mr-2 text-gray-400"></i>Informations du client</span>
            <div id="infoActions">
                <span onclick="enableEditInfo()" id="editInfoBtn" class="edit-icon">
                    <i class="fas fa-edit mr-1"></i> Modifier
                </span>
                <span id="saveInfoBtn" style="display:none;">
                    <span onclick="saveInfo()" class="save-icon mr-3">
                        <i class="fas fa-save mr-1"></i> Enregistrer
                    </span>
                    <span onclick="cancelEditInfo()" class="cancel-icon">
                        <i class="fas fa-times mr-1"></i> Annuler
                    </span>
                </span>
            </div>
        </div>
        
        <div id="infoDisplay" class="info-grid">
            <div class="info-item">
                <div class="label">Entreprise</div>
                <div class="value" id="display_entreprise"><?= htmlspecialchars($client['entreprise']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Nom</div>
                <div class="value" id="display_nom"><?= htmlspecialchars($client['nom']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Prénom</div>
                <div class="value" id="display_prenom"><?= htmlspecialchars($client['prenom']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Téléphone</div>
                <div class="value" id="display_telephone"><?= htmlspecialchars($client['telephone'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="label">Adresse</div>
                <div class="value" id="display_adresse"><?= htmlspecialchars($client['adresse'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="label">Code postal</div>
                <div class="value" id="display_code_postal"><?= htmlspecialchars($client['code_postal'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="label">Ville</div>
                <div class="value" id="display_ville"><?= htmlspecialchars($client['ville'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="label">Email</div>
                <div class="value" id="display_user"><?= htmlspecialchars($client['user']) ?></div>
            </div>
        </div>
        
        <!-- Formulaire d'édition -->
        <div id="infoEdit" style="display:none;" class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Entreprise</label>
                    <input type="text" id="edit_entreprise" value="<?= htmlspecialchars($client['entreprise']) ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Nom</label>
                    <input type="text" id="edit_nom" value="<?= htmlspecialchars($client['nom']) ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Prénom</label>
                    <input type="text" id="edit_prenom" value="<?= htmlspecialchars($client['prenom']) ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Téléphone</label>
                    <input type="text" id="edit_telephone" value="<?= htmlspecialchars($client['telephone'] ?? '') ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Adresse</label>
                    <input type="text" id="edit_adresse" value="<?= htmlspecialchars($client['adresse'] ?? '') ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Code postal</label>
                    <input type="text" id="edit_code_postal" value="<?= htmlspecialchars($client['code_postal'] ?? '') ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Ville</label>
                    <input type="text" id="edit_ville" value="<?= htmlspecialchars($client['ville'] ?? '') ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email</label>
                    <input type="email" id="edit_user" value="<?= htmlspecialchars($client['user']) ?>" class="input-edit">
                </div>
                <div class="col-span-4">
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Nouveau mot de passe</label>
                    <input type="password" id="edit_mot_de_passe" placeholder="Laisser vide pour ne pas changer" class="input-edit">
                    <p class="text-xs text-gray-500 mt-1">Laissez vide pour conserver le mot de passe actuel</p>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// ============================================
// VARIABLES
// ============================================
const clientId = '<?= $id ?>';  // 

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

// ============================================
// CHANGEMENT DE STATUT (AJAX)
// ============================================
async function toggleStatus() {
    const currentStatut = <?= $client['actif'] ? 'true' : 'false' ?>;
    const newStatut = currentStatut ? 'inactif' : 'actif';
    
    try {
        const formData = new FormData();
        formData.append('action_toggle_status', '1');
        formData.append('id_compte', clientId);
        formData.append('statut', newStatut);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            
            // Mettre à jour l'affichage du statut
            const statutBadges = document.querySelectorAll('.statut-badge');
            const toggleBtn = document.getElementById('toggleStatusBtn');
            
            if (newStatut === 'actif') {
                statutBadges.forEach(badge => {
                    badge.className = 'statut-badge actif';
                    badge.innerHTML = '<i class="fas fa-check-circle"></i> Actif';
                });
                toggleBtn.className = 'btn-action-red';
                toggleBtn.innerHTML = '<i class="fas fa-pause"></i> Désactiver';
            } else {
                statutBadges.forEach(badge => {
                    badge.className = 'statut-badge inactif';
                    badge.innerHTML = '<i class="fas fa-times-circle"></i> Inactif';
                });
                toggleBtn.className = 'btn-action-green';
                toggleBtn.innerHTML = '<i class="fas fa-play"></i> Activer';
            }
        } else {
            showToast(result.error || 'Erreur lors du changement de statut', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// ==============================
// MODALE DE RECHARGE DE CRÉDIT
// ==============================
function openRechargeModal() {
    document.getElementById('rechargeModal').style.display = 'flex';
    document.getElementById('rechargeAmount').value = '';
    document.getElementById('rechargeAmount').focus();
}

function closeRechargeModal() {
    document.getElementById('rechargeModal').style.display = 'none';
}

async function confirmRecharge() {
    const amount = parseFloat(document.getElementById('rechargeAmount').value);
    
    if (!amount || amount <= 0) {
        showToast('Veuillez entrer un montant valide', 'error');
        return;
    }
    
    const currentCredit = <?= $client['credits_total'] ?? 0 ?>;
    const newCredit = currentCredit + amount;
    
    try {
        const formData = new FormData();
        formData.append('action_update_credit', '1');
        formData.append('id_compte', clientId);
        formData.append('credit', newCredit);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('Crédit rechargé avec succès', 'success');
            document.querySelectorAll('#creditDisplay').forEach(el => {
                el.textContent = result.credit;
            });
            closeRechargeModal();
        } else {
            showToast(result.error || 'Erreur lors du rechargement', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// ==========================
// ÉDITION DES INFORMATIONS
// ==========================
function enableEditInfo() {
    document.getElementById('infoDisplay').style.display = 'none';
    document.getElementById('infoEdit').style.display = 'block';
    document.getElementById('editInfoBtn').style.display = 'none';
    document.getElementById('saveInfoBtn').style.display = 'inline';
}

function cancelEditInfo() {
    document.getElementById('infoDisplay').style.display = 'grid';
    document.getElementById('infoEdit').style.display = 'none';
    document.getElementById('editInfoBtn').style.display = 'inline';
    document.getElementById('saveInfoBtn').style.display = 'none';
}

async function saveInfo() {
    const formData = new FormData();
    formData.append('action_update_info', '1');
    formData.append('id_compte', clientId);
    formData.append('entreprise', document.getElementById('edit_entreprise').value);
    formData.append('nom', document.getElementById('edit_nom').value);
    formData.append('prenom', document.getElementById('edit_prenom').value);
    formData.append('telephone', document.getElementById('edit_telephone').value);
    formData.append('adresse', document.getElementById('edit_adresse').value);
    formData.append('code_postal', document.getElementById('edit_code_postal').value);
    formData.append('ville', document.getElementById('edit_ville').value);
    formData.append('user', document.getElementById('edit_user').value);
    formData.append('mot_de_passe', document.getElementById('edit_mot_de_passe').value);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            
            // Mettre à jour l'affichage
            const fields = ['entreprise', 'nom', 'prenom', 'telephone', 'adresse', 'code_postal', 'ville', 'user'];
            fields.forEach(field => {
                const displayEl = document.getElementById('display_' + field);
                const editEl = document.getElementById('edit_' + field);
                if (displayEl && editEl) {
                    displayEl.textContent = editEl.value || '-';
                }
            });
            
            cancelEditInfo();
            
            // Mettre à jour l'en-tête
            const prenom = document.getElementById('edit_prenom').value;
            const nom = document.getElementById('edit_nom').value;
            const entreprise = document.getElementById('edit_entreprise').value;
            
            document.querySelector('h1').textContent = prenom + ' ' + nom;
            document.querySelector('.text-gray-500.text-sm').textContent = entreprise;
            
            // Mettre à jour les initiales
            const initials = (prenom.charAt(0) + nom.charAt(0)).toUpperCase();
            document.querySelector('.w-14.h-14').textContent = initials;
            
        } else {
            showToast(result.error || 'Erreur lors de la mise à jour', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// =================================
// FERMETURE DES MODALES AVEC ECHAP
// =================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRechargeModal();
    }
});

// Fermer la modale en cliquant à l'extérieur
document.getElementById('rechargeModal').addEventListener('click', function(e) {
    if (e.target === this) closeRechargeModal();
});

// ============================================
// TOUCHER LE CRÉDIT AVEC ENTRÉE DANS LA MODALE
// ============================================
document.getElementById('rechargeAmount').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        confirmRecharge();
    }
});
</script>

</body>
</html>