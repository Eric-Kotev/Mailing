<?php
global $db;

$idCompte = $_SESSION['user_id'];

// ============================================
// TRAITEMENTS DES ACTIONS
// ============================================

// Activer un compte (mettre actif = TRUE)
if (isset($_GET['action']) && $_GET['action'] === 'activer' && isset($_GET['id'])) {
    $compteId = $_GET['id'];
    try {
        $db->update('compte', ['actif' => true], ['id_compte' => $compteId]);
        $_SESSION['flash_message'] = "Compte activé avec succès";
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Erreur lors de l'activation: " . $e->getMessage();
    }
    header('Location: index.php?page=admin/users');
    exit;
}

// Suspendre un compte (mettre actif = FALSE)
if (isset($_GET['action']) && $_GET['action'] === 'suspendre' && isset($_GET['id'])) {
    $compteId = $_GET['id'];
    try {
        $db->update('compte', ['actif' => false], ['id_compte' => $compteId]);
        $_SESSION['flash_message'] = "Compte suspendu avec succès";
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Erreur lors de la suspension: " . $e->getMessage();
    }
    header('Location: index.php?page=admin/users');
    exit;
}

// Récupérer tous les utilisateurs (comptes)
$users = $db->select('compte', [], '*', 'date_creation.desc');

// Nombre total d'utilisateurs
$totalUsers = count($users);

// Fonction pour formater la date
function formatDate($date) {
    if (empty($date)) {
        return '-';
    }
    return date('d/m/Y H:i', strtotime($date));
}

// Fonction pour obtenir le statut d'un compte (basé sur la colonne actif)
function getStatut($user) {
    // Si actif est TRUE ou NULL, considérer comme actif
    $isActive = isset($user['actif']) ? (bool)$user['actif'] : true;
    
    if ($isActive) {
        return [
            'label' => 'Actif',
            'class' => 'status-active',
            'value' => 'actif'
        ];
    } else {
        return [
            'label' => 'Suspendu',
            'class' => 'status-inactive',
            'value' => 'inactif'
        ];
    }
}

// Messages flash
$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
unset($_SESSION['flash_message']);
unset($_SESSION['flash_error']);

// Statistiques
$actifs = 0;
$inactifs = 0;
$totalCredits = 0;
foreach ($users as $u) {
    $isActive = isset($u['actif']) ? (bool)$u['actif'] : true;
    if ($isActive) $actifs++;
    else $inactifs++;
    $totalCredits += floatval($u['credits'] ?? 0);
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
        .alert-success {
            background: #dcfce7;
            border-left: 4px solid #16a34a;
            color: #166534;
        }
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            color: #991b1b;
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

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">👥 Gestion des comptes</h1>
            <p class="text-gray-500">Gérez tous les comptes de la plateforme</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500">Total: <strong><?= $totalUsers ?></strong> compte(s)</span>
        </div>
    </div>

    <!-- Messages flash -->
    <?php if ($flashMessage): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flashMessage) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-500">Total</span>
                    <span class="text-2xl font-bold text-gray-800 ml-2"><?= $totalUsers ?></span>
                </div>
                <div class="text-blue-400"><i class="fas fa-users text-2xl"></i></div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-500">Actifs</span>
                    <span class="text-2xl font-bold text-green-600 ml-2"><?= $actifs ?></span>
                </div>
                <div class="text-green-400"><i class="fas fa-check-circle text-2xl"></i></div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-500">Suspendus</span>
                    <span class="text-2xl font-bold text-red-600 ml-2"><?= $inactifs ?></span>
                </div>
                <div class="text-red-400"><i class="fas fa-times-circle text-2xl"></i></div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-500">Crédits total</span>
                    <span class="text-2xl font-bold text-yellow-600 ml-2"><?= number_format($totalCredits, 2) ?> €</span>
                </div>
                <div class="text-yellow-400"><i class="fas fa-coins text-2xl"></i></div>
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
                        <th>Email</th>
                        <th>Crédits</th>
                        <th>Statut</th>
                        <th>Date d'inscription</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-8 text-gray-500">
                                <i class="fas fa-users text-4xl mb-2 block"></i>
                                Aucun compte enregistré.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): 
                            $statutInfo = getStatut($user);
                            $isActive = isset($user['actif']) ? (bool)$user['actif'] : true;
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
                                            </div>
                                            <div class="text-xs text-gray-500">ID: <?= substr($user['id_compte'], 0, 8) ?>...</div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($user['email'] ?? $user['user'] ?? '-') ?></td>
                                <td class="credits-amount"><?= number_format($user['credits'] ?? 0, 2) ?> €</td>
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
                                        <button class="btn-action btn-edit" onclick="alert('Modifier le compte <?= htmlspecialchars($user['user'] ?? '') ?>')" title="Modifier">
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
    if (e.target === this) {
        closeConfirmModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeConfirmModal();
    }
});

<?php if ($flashMessage): ?>
    showToast('<?= addslashes($flashMessage) ?>', 'success');
<?php endif; ?>
<?php if ($flashError): ?>
    showToast('<?= addslashes($flashError) ?>', 'error');
<?php endif; ?>

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
</script>

</body>
</html>