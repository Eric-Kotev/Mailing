<?php
global $db;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'id_compte' => $_SESSION['user_id'],
        'prenom' => $_POST['prenom'] ?? '',
        'nom' => $_POST['nom'] ?? '',
        'email' => !empty($_POST['email']) ? $_POST['email'] : null,
        'telephone' => !empty($_POST['telephone']) ? $_POST['telephone'] : null,
        'date_naissance' => !empty($_POST['date_naissance']) ? $_POST['date_naissance'] : null,
        'adresse' => !empty($_POST['adresse']) ? $_POST['adresse'] : null,
        'code_postal' => !empty($_POST['code_postal']) ? $_POST['code_postal'] : null,
        'ville' => !empty($_POST['ville']) ? $_POST['ville'] : null,
        'pays' => !empty($_POST['pays']) ? $_POST['pays'] : 'France',
        'champs1' => !empty($_POST['champs1']) ? $_POST['champs1'] : null,
        'champs2' => !empty($_POST['champs2']) ? $_POST['champs2'] : null,
        'champs3' => !empty($_POST['champs3']) ? $_POST['champs3'] : null,
        'champs4' => !empty($_POST['champs4']) ? $_POST['champs4'] : null,
        'champs5' => !empty($_POST['champs5']) ? $_POST['champs5'] : null
    ];
    
    try {
        $db->insert('contact', $data);
        $_SESSION['flash_message'] = "Contact ajouté avec succès !";
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
    <title>Ajouter un contact - <?= APP_NAME ?></title>
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
        <h1 class="text-2xl font-bold text-gray-800">Ajouter un contact</h1>
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
                    <input type="text" name="prenom" required 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                    <input type="text" name="nom" required 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                    <input type="tel" name="telephone" placeholder="33612345678"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label>
                    <input type="date" name="date_naissance" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ville</label>
                    <input type="text" name="ville" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Code postal</label>
                    <input type="text" name="code_postal" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Pays</label>
                    <input type="text" name="pays" value="France"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                </div>
            </div>
            
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                <textarea name="adresse" rows="2" 
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"></textarea>
            </div>
            
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

<?php if ($error): ?>
    showToast('<?= addslashes($error) ?>', 'error');
<?php endif; ?>
</script>

</body>
</html>