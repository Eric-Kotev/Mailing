<?php
global $db;

$error = '';
$success = '';

// Récupérer les types de messages
$typeMessages = getTypesMessage();

// Récupérer les providers actifs
$providers = $db->select('provider', ['est_actif' => true]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom_canal = trim($_POST['nom_canal'] ?? '');
    $id_type_message = $_POST['id_type_message'] ?? null;
    $id_provider = $_POST['id_provider'] ?? null;
    $setup_du_canal = $_POST['setup_du_canal'] ?? [];
    
    if (empty($nom_canal) || !$id_type_message || !$id_provider) {
        $error = "Veuillez remplir tous les champs obligatoires";
    } else {
        // Construction du JSON de configuration
        $configJson = json_encode($setup_du_canal);
        
        $data = [
            'id_compte' => $_SESSION['user_id'],
            'nom_canal' => $nom_canal,
            'id_type_message' => intval($id_type_message),
            'id_provider' => $id_provider,
            'setup_du_canal' => $configJson,
            'est_actif' => true
        ];
        
        try {
            $db->insert('canal', $data);
            $_SESSION['flash_message'] = "Canal créé avec succès !";
            header('Location: index.php?page=canaux/index');
            exit;
        } catch (Exception $e) {
            $error = "Erreur : " . $e->getMessage();
        }
    }
}

// Récupérer les champs de configuration attendus pour le provider sélectionné
$configFields = [
    1 => [  // Octopush SMS
        ['name' => 'api_login', 'label' => 'API Login', 'type' => 'text', 'required' => true],
        ['name' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'required' => true]
    ],
    2 => [  // Allmysms
        ['name' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'required' => true]
    ],
    3 => [  // Brevo Email
        ['name' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'required' => true],
        ['name' => 'sender_email', 'label' => 'Email expéditeur', 'type' => 'email', 'required' => true]
    ],
    4 => [  // Mailjet
        ['name' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'required' => true],
        ['name' => 'api_secret', 'label' => 'API Secret', 'type' => 'text', 'required' => true]
    ],
    5 => [  // SMTP Gmail
        ['name' => 'smtp_host', 'label' => 'SMTP Host', 'type' => 'text', 'value' => 'smtp.gmail.com'],
        ['name' => 'smtp_port', 'label' => 'SMTP Port', 'type' => 'number', 'value' => 587],
        ['name' => 'smtp_user', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ['name' => 'smtp_password', 'label' => 'Mot de passe', 'type' => 'password', 'required' => true]
    ]
];
?>

<div class="max-w-3xl mx-auto">
    <div class="flex items-center mb-6">
        <a href="javascript:history.back()" class="text-blue-600 hover:text-blue-800 mr-4">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <h1 class="text-2xl font-bold text-gray-800">Ajouter un canal d'envoi</h1>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" id="canalForm">
            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
                    <i class="fas fa-exclamation-circle mr-2"></i> <?= $error ?>
                </div>
            <?php endif; ?>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Nom du canal *</label>
                <input type="text" name="nom_canal" required 
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                       placeholder="Ex: Mon canal SMS Octopush">
            </div>
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type de message *</label>
                    <select name="id_type_message" id="type_message" required 
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                        <option value="">Sélectionner un type</option>
                        <?php foreach ($typeMessages as $type): ?>
                            <option value="<?= $type['id_type_message'] ?>"><?= $type['libelle_type'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fournisseur *</label>
                    <select name="id_provider" id="provider" required 
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                        <option value="">Sélectionner un fournisseur</option>
                        <?php foreach ($providers as $provider): ?>
                            <option value="<?= $provider['id_provider'] ?>" data-type="<?= $provider['id_type_message'] ?>">
                                <?= $provider['nom_provider'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div id="config_fields" class="mb-4 p-4 bg-gray-50 rounded-lg">
                <h3 class="text-md font-semibold mb-3">Configuration du fournisseur</h3>
                <p class="text-sm text-gray-500 mb-3">Sélectionnez d'abord un fournisseur pour voir les champs de configuration.</p>
            </div>
            
            <div class="mt-6 flex justify-end">
                <a href="index.php?page=canaux/index" class="px-4 py-2 border border-gray-300 rounded-lg mr-2 hover:bg-gray-50">
                    Annuler
                </a>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition">
                    <i class="fas fa-save mr-2"></i>Créer le canal
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Configuration dynamique des champs
const configFields = <?= json_encode($configFields) ?>;

document.getElementById('provider').addEventListener('change', function() {
    const providerId = this.value;
    const configDiv = document.getElementById('config_fields');
    
    if (!providerId || !configFields[providerId]) {
        configDiv.innerHTML = '<h3 class="text-md font-semibold mb-3">Configuration du fournisseur</h3><p class="text-sm text-gray-500">Sélectionnez un fournisseur pour voir les champs de configuration.</p>';
        return;
    }
    
    const fields = configFields[providerId];
    let html = '<h3 class="text-md font-semibold mb-3">Configuration du fournisseur</h3>';
    
    fields.forEach(field => {
        html += `
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">${field.label} ${field.required ? '*' : ''}</label>
                <input type="${field.type}" name="setup_du_canal[${field.name}]" 
                       value="${field.value || ''}"
                       ${field.required ? 'required' : ''}
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                       placeholder="Entrez ${field.label.toLowerCase()}">
            </div>
        `;
    });
    
    configDiv.innerHTML = html;
});

// Filtrer les fournisseurs par type de message
document.getElementById('type_message').addEventListener('change', function() {
    const typeId = this.value;
    const providerSelect = document.getElementById('provider');
    const options = providerSelect.querySelectorAll('option');
    
    options.forEach(option => {
        if (option.value === '') return;
        const providerType = option.getAttribute('data-type');
        if (providerType == typeId || !typeId) {
            option.style.display = '';
        } else {
            option.style.display = 'none';
        }
    });
    
    providerSelect.value = '';
    document.getElementById('config_fields').innerHTML = '<h3 class="text-md font-semibold mb-3">Configuration du fournisseur</h3><p class="text-sm text-gray-500">Sélectionnez d\'abord un fournisseur pour voir les champs de configuration.</p>';
});
</script>