<?php
global $db;

$id = $_GET['id'] ?? null;

if (!$id) {
    header('Location: index.php?page=contacts/index');
    exit;
}

// Récupérer le contact
$contacts = $db->select('contact', ['id_contact' => $id, 'id_compte' => $_SESSION['user_id']]);
if (!$contacts) {
    header('Location: index.php?page=contacts/index');
    exit;
}
$contact = $contacts[0];

// Récupérer les champs personnalisés et leurs valeurs
$customFields = getCustomFields($_SESSION['user_id']);
$customValues = getContactCustomValues($id);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'prenom' => $_POST['prenom'] ?? '',
        'nom' => $_POST['nom'] ?? '',
        'email' => !empty($_POST['email']) ? $_POST['email'] : null,
        'telephone' => !empty($_POST['telephone']) ? $_POST['telephone'] : null,
        'date_naissance' => !empty($_POST['date_naissance']) ? $_POST['date_naissance'] : null,
        'adresse' => !empty($_POST['adresse']) ? $_POST['adresse'] : null,
        'code_postal' => !empty($_POST['code_postal']) ? $_POST['code_postal'] : null,
        'ville' => !empty($_POST['ville']) ? $_POST['ville'] : null,
        'pays' => !empty($_POST['pays']) ? $_POST['pays'] : 'France'
    ];
    
    try {
        $db->update('contact', $data, ['id_contact' => $id]);
        
        // Sauvegarder les champs personnalisés
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            saveContactCustomValues($id, $_POST['custom_fields']);
        }
        
        $_SESSION['flash_message'] = "Contact modifié avec succès !";
        header('Location: index.php?page=contacts/index');
        exit;
    } catch (Exception $e) {
        $error = "Erreur : " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier le contact - <?= APP_NAME ?></title>
    <style>
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
    </style>
</head>
<body>

<div class="max-w-3xl mx-auto">
    <div class="flex items-center mb-6">
        <a href="index.php?page=contacts/index" class="text-blue-600 hover:text-blue-800 mr-4">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <h1 class="text-2xl font-bold text-gray-800">Modifier le contact</h1>
    </div>
    
    <div class="bg-white rounded-lg shadow">
        <form method="POST" class="p-6">
            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
                    <i class="fas fa-exclamation-circle mr-2"></i> <?= $error ?>
                </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Prénom *</label>
                    <input type="text" name="prenom" required value="<?= htmlspecialchars($contact['prenom']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                    <input type="text" name="nom" required value="<?= htmlspecialchars($contact['nom']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($contact['email'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                    <input type="tel" name="telephone" value="<?= htmlspecialchars($contact['telephone'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                           placeholder="33612345678">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label>
                    <input type="date" name="date_naissance" value="<?= htmlspecialchars($contact['date_naissance'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ville</label>
                    <input type="text" name="ville" value="<?= htmlspecialchars($contact['ville'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Code postal</label>
                    <input type="text" name="code_postal" value="<?= htmlspecialchars($contact['code_postal'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Pays</label>
                    <input type="text" name="pays" value="<?= htmlspecialchars($contact['pays'] ?? 'France') ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                <textarea name="adresse" rows="2" 
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"><?= htmlspecialchars($contact['adresse'] ?? '') ?></textarea>
            </div>
            
            <!-- Champs personnalisés dynamiques -->
            <?php if (!empty($customFields)): ?>
            <div class="mt-6 pt-4 border-t border-gray-200">
                <h3 class="text-md font-semibold text-gray-700 mb-3">Informations supplémentaires</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($customFields as $field): 
                        $currentValue = $customValues[$field['field_name']]['value'] ?? '';
                    ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <?= htmlspecialchars($field['field_label']) ?>
                                <?php if ($field['is_required']): ?>
                                    <span class="text-red-500">*</span>
                                <?php endif; ?>
                            </label>
                            
                            <?php if ($field['field_type'] === 'textarea'): ?>
                                <textarea name="custom_fields[<?= $field['field_name'] ?>]" rows="2"
                                          class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"><?= htmlspecialchars($currentValue) ?></textarea>
                            
                            <?php elseif ($field['field_type'] === 'select' && !empty($field['field_options'])): 
                                $options = explode('|', $field['field_options']);
                            ?>
                                <select name="custom_fields[<?= $field['field_name'] ?>]" 
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                                    <option value="">-- Sélectionner --</option>
                                    <?php foreach ($options as $opt): 
                                        $opt = trim($opt);
                                    ?>
                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $currentValue === $opt ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($opt) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            
                            <?php elseif ($field['field_type'] === 'date'): ?>
                                <input type="date" name="custom_fields[<?= $field['field_name'] ?>]" value="<?= htmlspecialchars($currentValue) ?>"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                            
                            <?php elseif ($field['field_type'] === 'number'): ?>
                                <input type="number" name="custom_fields[<?= $field['field_name'] ?>]" value="<?= htmlspecialchars($currentValue) ?>"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                            
                            <?php elseif ($field['field_type'] === 'email'): ?>
                                <input type="email" name="custom_fields[<?= $field['field_name'] ?>]" value="<?= htmlspecialchars($currentValue) ?>"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                            
                            <?php elseif ($field['field_type'] === 'tel'): ?>
                                <input type="tel" name="custom_fields[<?= $field['field_name'] ?>]" value="<?= htmlspecialchars($currentValue) ?>"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                            
                            <?php else: ?>
                                <input type="text" name="custom_fields[<?= $field['field_name'] ?>]" value="<?= htmlspecialchars($currentValue) ?>"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="mt-6 flex justify-end">
                <a href="index.php?page=contacts/index" class="px-4 py-2 border border-gray-300 rounded-lg mr-2 hover:bg-gray-50">
                    Annuler
                </a>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition">
                    <i class="fas fa-save mr-2"></i>Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showToast(message, type = 'error') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    
    let icon = '❌';
    let bgColor = '#ef4444';
    
    const types = {
        success: {color: '#10b981' },
        error: {color: '#ef4444' },
        info: { color: '#3b82f6' },
        warning: { color: '#f59e0b' }
    };
    
    if (types[type]) {
        icon = types[type].icon;
        bgColor = types[type].color;
    }
    
    toast.innerHTML = `<div class="toast-content" style="background: ${bgColor};"><span>${icon}</span><span>${message}</span></div>`;
    document.body.appendChild(toast);
    
    setTimeout(() => toast.remove(), 3000);
}

<?php if ($error): ?>
    showToast('<?= addslashes($error) ?>', 'error');
<?php endif; ?>
</script>

</body>
</html>