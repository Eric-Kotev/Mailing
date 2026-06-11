<?php
requireAdmin();
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer les champs existants
$customFields = $db->select('custom_fields', ['id_compte' => $idCompte], '*', 'field_order ASC');

// Ajouter un champ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add'])) {
    $fieldName = strtolower(preg_replace('/[^a-z0-9_]/', '_', trim($_POST['field_name'])));
    
    $data = [
        'id_compte' => $idCompte,
        'field_name' => $fieldName,
        'field_label' => trim($_POST['field_label']),
        'field_type' => $_POST['field_type'],
        'field_options' => $_POST['field_options'] ?? null,
        'field_order' => intval($_POST['field_order']),
        'is_required' => isset($_POST['is_required']) ? 1 : 0,
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];
    
    $exists = $db->select('custom_fields', ['id_compte' => $idCompte, 'field_name' => $fieldName]);
    if (empty($exists)) {
        $db->insert('custom_fields', $data);
        $_SESSION['flash_message'] = "Champ ajouté avec succès";
    } else {
        $_SESSION['flash_error'] = "Ce nom de champ existe déjà";
    }
    header('Location: index.php?page=admin/custom_fields');
    exit;
}

// Modifier un champ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit'])) {
    $data = [
        'field_label' => trim($_POST['field_label']),
        'field_type' => $_POST['field_type'],
        'field_options' => $_POST['field_options'] ?? null,
        'field_order' => intval($_POST['field_order']),
        'is_required' => isset($_POST['is_required']) ? 1 : 0,
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];
    
    $db->update('custom_fields', $data, ['id_custom_field' => $_POST['id_custom_field'], 'id_compte' => $idCompte]);
    $_SESSION['flash_message'] = "Champ modifié avec succès";
    header('Location: index.php?page=admin/custom_fields');
    exit;
}

// Supprimer un champ (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $id = $_POST['id_custom_field'] ?? null;
    
    if ($id) {
        try {
            $db->delete('custom_fields', $id, 'id_custom_field');
            echo json_encode(['success' => true, 'message' => 'Champ supprimé avec succès']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'ID manquant']);
    }
    exit;
}

// Mettre à jour l'ordre (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'update_order') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    foreach ($data['orders'] as $order) {
        $db->update('custom_fields', ['field_order' => $order['order']], ['id_custom_field' => $order['id']]);
    }
    echo json_encode(['success' => true]);
    exit;
}

$flashMessage = $_SESSION['flash_message'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_message'], $_SESSION['flash_error']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Champs personnalisés - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.css" rel="stylesheet" />
    <style>
        .sortable-handle { cursor: move; }
        .sortable-ghost { opacity: 0.5; background: #f3f4f6; }
        .modal-show { opacity: 1 !important; transform: scale(1) !important; }
        
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
    </style>
</head>
<body>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Champs personnalisés</h1>
            <p class="text-gray-500">Ajoutez des champs supplémentaires pour vos contacts</p>
        </div>
        <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
            <i class="fas fa-plus mr-2"></i>Ajouter un champ
        </button>
    </div>

    <?php if ($flashMessage): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded"><?= $flashMessage ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded"><?= $flashError ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="p-4 border-b bg-gray-50">
            <h2 class="text-lg font-bold">Liste des champs</h2>
            <p class="text-sm text-gray-500">Glissez-déposez pour réorganiser</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 w-10"></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom technique</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Libellé</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requis</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody id="sortableList">
                    <?php if (empty($customFields)): ?>
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                <i class="fas fa-info-circle text-3xl mb-2 block"></i>
                                Aucun champ personnalisé. Cliquez sur "Ajouter un champ" pour commencer.
                             </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customFields as $field): ?>
                            <tr data-id="<?= $field['id_custom_field'] ?>">
                                <td class="px-4 py-3"><i class="fas fa-grip-vertical text-gray-400 sortable-handle cursor-move"></i></td>
                                <td class="px-4 py-3 font-mono text-sm"><?= htmlspecialchars($field['field_name']) ?></td>
                                <td class="px-4 py-3"><?= htmlspecialchars($field['field_label']) ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-1 rounded text-xs bg-gray-100">
                                        <?php 
                                        $types = [
                                            'text' => 'Texte court',
                                            'textarea' => 'Zone texte',
                                            'number' => 'Nombre',
                                            'date' => 'Date',
                                            'email' => 'Email',
                                            'tel' => 'Téléphone',
                                            'select' => 'Liste'
                                        ];
                                        echo $types[$field['field_type']] ?? $field['field_type'];
                                        ?>
                                    </span>
                                 </td>
                                <td class="px-4 py-3">
                                    <?= $field['is_required'] ? '<span class="text-red-600">Oui</span>' : '<span class="text-gray-400">Non</span>' ?>
                                 </td>
                                <td class="px-4 py-3">
                                    <?php if ($field['is_active']): ?>
                                        <span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs">Actif</span>
                                    <?php else: ?>
                                        <span class="bg-gray-100 text-gray-500 px-2 py-1 rounded-full text-xs">Inactif</span>
                                    <?php endif; ?>
                                 </td>
                                <td class="px-4 py-3 space-x-2">
                                    <button onclick='openEditModal(<?= json_encode($field) ?>)' class="text-yellow-600 hover:text-yellow-800">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick='confirmDeleteField(<?= $field['id_custom_field'] ?>, <?= json_encode($field['field_label']) ?>)' 
                                            class="text-red-600 hover:text-red-800">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                 </td>
                             </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
             </table>
        </div>
    </div>
</div>

<!-- MODALE AJOUT -->
<div id="addModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6">
            <h3 class="text-xl font-bold mb-4">Ajouter un champ</h3>
            <form method="POST">
                <input type="hidden" name="action_add" value="1">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Nom technique *</label>
                        <input type="text" name="field_name" required class="w-full border rounded-lg px-3 py-2"
                               placeholder="ex: societe, fonction">
                        <p class="text-xs text-gray-500 mt-1">Sans accent, sans espace (utilisez _ )</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Libellé *</label>
                        <input type="text" name="field_label" required class="w-full border rounded-lg px-3 py-2"
                               placeholder="ex: Société, Fonction">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Type</label>
                        <select name="field_type" id="add_field_type" class="w-full border rounded-lg px-3 py-2">
                            <option value="text">Texte court</option>
                            <option value="textarea">Zone texte</option>
                            <option value="number">Nombre</option>
                            <option value="date">Date</option>
                            <option value="email">Email</option>
                            <option value="tel">Téléphone</option>
                            <option value="select">Liste déroulante</option>
                        </select>
                    </div>
                    <div id="add_options_div" style="display:none">
                        <label class="block text-sm font-medium mb-1">Options (séparées par | )</label>
                        <input type="text" name="field_options" class="w-full border rounded-lg px-3 py-2"
                               placeholder="ex: Option 1|Option 2|Option 3">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Ordre</label>
                        <input type="number" name="field_order" value="<?= count($customFields) + 1 ?>" class="w-full border rounded-lg px-3 py-2">
                    </div>
                    <div class="flex items-center space-x-4">
                        <label><input type="checkbox" name="is_required"> Requis</label>
                        <label><input type="checkbox" name="is_active" checked> Actif</label>
                    </div>
                </div>
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeAddModal()" class="px-4 py-2 border rounded-lg">Annuler</button>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Ajouter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE EDITION -->
<div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6">
            <h3 class="text-xl font-bold mb-4">Modifier le champ</h3>
            <form method="POST" id="editForm">
                <input type="hidden" name="action_edit" value="1">
                <input type="hidden" name="id_custom_field" id="edit_id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Nom technique</label>
                        <input type="text" id="edit_field_name" disabled class="w-full border rounded-lg px-3 py-2 bg-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Libellé *</label>
                        <input type="text" name="field_label" id="edit_field_label" required class="w-full border rounded-lg px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Type</label>
                        <select name="field_type" id="edit_field_type" class="w-full border rounded-lg px-3 py-2">
                            <option value="text">Texte court</option>
                            <option value="textarea">Zone texte</option>
                            <option value="number">Nombre</option>
                            <option value="date">Date</option>
                            <option value="email">Email</option>
                            <option value="tel">Téléphone</option>
                            <option value="select">Liste déroulante</option>
                        </select>
                    </div>
                    <div id="edit_options_div" style="display:none">
                        <label class="block text-sm font-medium mb-1">Options (séparées par | )</label>
                        <input type="text" name="field_options" id="edit_field_options" class="w-full border rounded-lg px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Ordre</label>
                        <input type="number" name="field_order" id="edit_field_order" class="w-full border rounded-lg px-3 py-2">
                    </div>
                    <div class="flex items-center space-x-4">
                        <label><input type="checkbox" name="is_required" id="edit_is_required"> Requis</label>
                        <label><input type="checkbox" name="is_active" id="edit_is_active"> Actif</label>
                    </div>
                </div>
                <div class="mt-6 flex justify-end space-x-2">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 border rounded-lg">Annuler</button>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE DE CONFIRMATION SUPPRESSION (TOAST STYLE) -->
<div id="deleteConfirmModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4">
        <div class="p-6 text-center">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 text-3xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">Confirmer la suppression</h3>
            <p class="text-gray-500 mb-4">
                Êtes-vous sûr de vouloir supprimer le champ <strong id="deleteFieldName"></strong> ?
            </p>
            <p class="text-sm text-red-600 mb-6">Cette action est irréversible.</p>
            <div class="flex space-x-3">
                <button type="button" onclick="closeDeleteConfirmModal()" 
                        class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Annuler
                </button>
                <button type="button" id="confirmDeleteBtn" 
                        class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition">
                    Supprimer
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
// ============================================
// TOAST NOTIFICATION
// ============================================
function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const colors = { success: '#10b981', error: '#ef4444', warning: '#f59e0b', info: '#3b82f6' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// ============================================
// CONFIRMATION SUPPRESSION AVEC MODALE
// ============================================
let currentDeleteId = null;

function confirmDeleteField(id, label) {
    currentDeleteId = id;
    document.getElementById('deleteFieldName').textContent = label;
    document.getElementById('deleteConfirmModal').style.display = 'flex';
}

function closeDeleteConfirmModal() {
    document.getElementById('deleteConfirmModal').style.display = 'none';
    currentDeleteId = null;
}

document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
    if (!currentDeleteId) return;
    
    const btn = this;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Suppression...';
    btn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action_delete', '1');
        formData.append('id_custom_field', currentDeleteId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            closeDeleteConfirmModal();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.error || 'Erreur lors de la suppression', 'error');
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    } catch (error) {
        showToast('Erreur réseau', 'error');
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
});

// ============================================
// DRAG & DROP
// ============================================
const sortableList = document.getElementById('sortableList');
if (sortableList && sortableList.children.length > 0 && sortableList.children[0].getAttribute('data-id')) {
    new Sortable(sortableList, {
        handle: '.sortable-handle',
        animation: 150,
        ghostClass: 'sortable-ghost',
        onEnd: function() {
            const rows = document.querySelectorAll('#sortableList tr');
            const orders = [];
            rows.forEach((row, index) => {
                const id = row.getAttribute('data-id');
                if (id) orders.push({ id: id, order: index + 1 });
            });
            fetch('index.php?page=admin/custom_fields&action=update_order', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ orders: orders })
            }).catch(console.error);
        }
    });
}

// ============================================
// GESTION DES OPTIONS
// ============================================
document.getElementById('add_field_type')?.addEventListener('change', function() {
    document.getElementById('add_options_div').style.display = this.value === 'select' ? 'block' : 'none';
});
document.getElementById('edit_field_type')?.addEventListener('change', function() {
    document.getElementById('edit_options_div').style.display = this.value === 'select' ? 'block' : 'none';
});

// ============================================
// MODALES
// ============================================
function openAddModal() {
    const modal = document.getElementById('addModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeAddModal() {
    const modal = document.getElementById('addModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function openEditModal(field) {
    document.getElementById('edit_id').value = field.id_custom_field;
    document.getElementById('edit_field_name').value = field.field_name;
    document.getElementById('edit_field_label').value = field.field_label;
    document.getElementById('edit_field_type').value = field.field_type;
    document.getElementById('edit_field_options').value = field.field_options || '';
    document.getElementById('edit_field_order').value = field.field_order;
    document.getElementById('edit_is_required').checked = field.is_required == 1;
    document.getElementById('edit_is_active').checked = field.is_active == 1;
    
    document.getElementById('edit_options_div').style.display = field.field_type === 'select' ? 'block' : 'none';
    const modal = document.getElementById('editModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditModal() {
    const modal = document.getElementById('editModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Fermeture des modales avec clic externe
document.getElementById('addModal')?.addEventListener('click', function(e) { if (e.target === this) closeAddModal(); });
document.getElementById('editModal')?.addEventListener('click', function(e) { if (e.target === this) closeEditModal(); });
document.getElementById('deleteConfirmModal')?.addEventListener('click', function(e) { if (e.target === this) closeDeleteConfirmModal(); });

// Fermeture avec touche Echap
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddModal();
        closeEditModal();
        closeDeleteConfirmModal();
    }
});
</script>

</body>
</html>