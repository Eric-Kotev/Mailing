<?php
global $db;

// Vérifier si un appareil SMS est sélectionné
if (!isset($_SESSION['sms_device_id']) || !isset($_SESSION['sms_api_username'])) {
    header('Location: index.php?page=campagnes/choix');
    exit;
}

$device_id = $_SESSION['sms_device_id'];
$device_name = $_SESSION['sms_device_name'] ?? 'Appareil SMS';
$api_username = $_SESSION['sms_api_username'];
$api_password = $_SESSION['sms_api_password'];

// Récupérer les contacts (en excluant la blacklist)
$idCompte = $_SESSION['user_id'];

// 1. Récupérer les IDs des contacts blacklistés
$blacklist = $db->select('blacklist');
$blacklistIds = [];
foreach ($blacklist as $b) {
    if (!empty($b['id_contact'])) {
        $blacklistIds[] = $b['id_contact'];
    }
}

// 2. Récupérer tous les contacts du compte
$tousContacts = $db->select('contact', ['id_compte' => $idCompte]);

// 3. Filtrer les contacts non blacklistés
$contacts = [];
foreach ($tousContacts as $contact) {
    if (!in_array($contact['id_contact'], $blacklistIds)) {
        $contacts[] = $contact;
    }
}

// Récupérer les listes avec le nombre de contacts (via la table liste_contact)
$listesBrutes = $db->select('liste', ['id_compte' => $idCompte]);
$listes = [];

foreach ($listesBrutes as $liste) {
    // Compter les contacts dans cette liste via la table liste_contact
    $listeContacts = $db->select('liste_contact', ['id_liste' => $liste['id_liste']]);
    $nbContacts = 0;
    foreach ($listeContacts as $lc) {
        // Vérifier si le contact n'est pas blacklisté
        if (!in_array($lc['id_contact'], $blacklistIds)) {
            $nbContacts++;
        }
    }
    
    $listes[] = [
        'id_liste' => $liste['id_liste'],
        'nom_liste' => $liste['nom_liste'],
        'nombre_contacts' => $nbContacts
    ];
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = $_POST['message'] ?? '';
    $type_envoi = $_POST['type_envoi'] ?? 'simple';
    
    $recipients = [];
    $destinatairesNoms = []; // Pour l'historique
    
    if ($type_envoi === 'simple') {
        // Envoi à un seul destinataire
        $contact_id = $_POST['contact_unique'] ?? '';
        if (!empty($contact_id)) {
            $contact = $db->select('contact', ['id_contact' => $contact_id]);
            if (!empty($contact) && !in_array($contact_id, $blacklistIds)) {
                $telephone = $contact[0]['telephone'];
                $telephone = preg_replace('/[^0-9]/', '', $telephone);
                if (strlen($telephone) == 10 && substr($telephone, 0, 1) == '0') {
                    $telephone = '261' . substr($telephone, 1);
                }
                if (substr($telephone, 0, 3) != '261') {
                    $telephone = '261' . $telephone;
                }
                $recipients[] = '+' . $telephone;
                $destinatairesNoms[] = $contact[0]['prenom'] . ' ' . $contact[0]['nom'] . ' (' . $telephone . ')';
            }
        }
    } else {
        // Envoi multiple
        $liste_id = $_POST['liste_id'] ?? '';
        if (!empty($liste_id)) {
            // Récupérer les ID des contacts dans la liste via liste_contact
            $listeContacts = $db->select('liste_contact', ['id_liste' => $liste_id]);
            foreach ($listeContacts as $lc) {
                // Vérifier si le contact n'est pas blacklisté
                if (!in_array($lc['id_contact'], $blacklistIds)) {
                    $contact = $db->select('contact', ['id_contact' => $lc['id_contact']]);
                    if (!empty($contact)) {
                        $telephone = $contact[0]['telephone'];
                        $telephone = preg_replace('/[^0-9]/', '', $telephone);
                        if (strlen($telephone) == 10 && substr($telephone, 0, 1) == '0') {
                            $telephone = '261' . substr($telephone, 1);
                        }
                        if (substr($telephone, 0, 3) != '261') {
                            $telephone = '261' . $telephone;
                        }
                        $recipients[] = '+' . $telephone;
                        $destinatairesNoms[] = $contact[0]['prenom'] . ' ' . $contact[0]['nom'] . ' (' . $telephone . ')';
                    }
                }
            }
        } else {
            // Sélection manuelle de plusieurs contacts
            $destinataires = $_POST['destinataires'] ?? [];
            foreach ($destinataires as $contact_id) {
                if (!in_array($contact_id, $blacklistIds)) {
                    $contact = $db->select('contact', ['id_contact' => $contact_id]);
                    if (!empty($contact)) {
                        $telephone = $contact[0]['telephone'];
                        $telephone = preg_replace('/[^0-9]/', '', $telephone);
                        if (strlen($telephone) == 10 && substr($telephone, 0, 1) == '0') {
                            $telephone = '261' . substr($telephone, 1);
                        }
                        if (substr($telephone, 0, 3) != '261') {
                            $telephone = '261' . $telephone;
                        }
                        $recipients[] = '+' . $telephone;
                        $destinatairesNoms[] = $contact[0]['prenom'] . ' ' . $contact[0]['nom'] . ' (' . $telephone . ')';
                    }
                }
            }
        }
    }
    
    if (empty($recipients)) {
        $error = "Veuillez sélectionner au moins un destinataire";
    } elseif (empty($message)) {
        $error = "Veuillez saisir un message";
    } else {
        // Appel API SMS
        $apiUrl = 'http://72.62.26.166:8085/api.php/sendBulk';
        $data = [
            'text' => $message,
            'recipients' => $recipients,
            'api_username' => $api_username,
            'api_password' => $api_password,
            'device_id' => $device_id,
            'user_id' => 'campagne_' . date('Ymd_His')
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // ============================================
        // PRÉPARER LES DONNÉES POUR L'HISTORIQUE
        // ============================================
        $destinatairesJson = json_encode($destinatairesNoms);
        $titre = "SMS - " . date('d/m/Y H:i');
        if (!empty($message)) {
            $titre = "SMS: " . (strlen($message) > 40 ? substr($message, 0, 40) . '...' : $message);
        }
        
        if ($httpCode === 200) {
            $success = "SMS envoyés avec succès à " . count($recipients) . " destinataire(s) !";
            
            // ENREGISTREMENT SUCCÈS
            $campagneData = [
                'id_compte' => $idCompte,
                'type_campagne' => 'sms',
                'titre' => $titre,
                'message' => $message,
                'destinataires' => $destinatairesJson,
                'nb_destinataires' => count($recipients),
                'nb_envoyes' => count($recipients),
                'nb_succes' => count($recipients),
                'nb_erreurs' => 0,
                'appareil_utilise' => $device_name . ' (' . $device_id . ')',
                'statut' => 'envoye',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
        } else {
            $error = "Erreur d'envoi: " . $response;
            
            // ENREGISTREMENT ÉCHEC
            $campagneData = [
                'id_compte' => $idCompte,
                'type_campagne' => 'sms',
                'titre' => $titre,
                'message' => $message,
                'destinataires' => $destinatairesJson,
                'nb_destinataires' => count($recipients),
                'nb_envoyes' => count($recipients),
                'nb_succes' => 0,
                'nb_erreurs' => count($recipients),
                'appareil_utilise' => $device_name . ' (' . $device_id . ')',
                'statut' => 'echoue',
                'erreur' => substr($response, 0, 500),
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        // Enregistrer dans la base
        try {
            $db->insert('campagne', $campagneData);
        } catch (Exception $e) {
            error_log("Erreur insertion historique SMS: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Envoyer SMS - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .select2-container--default .select2-selection--single,
        .select2-container--default .select2-selection--multiple {
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            min-height: 42px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 40px;
            padding-left: 12px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }
        .select2-container--default .select2-selection--multiple .select2-selection__rendered {
            padding: 4px 8px;
        }
        .select2-container--default .select2-selection--multiple .select2-search__field {
            margin-top: 6px;
            padding: 0 8px;
        }
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: #3b82f6;
            border: none;
            color: white;
            border-radius: 20px;
            padding: 4px 12px;
            margin: 2px 4px;
        }
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: white;
            margin-right: 6px;
        }
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
            color: #fef08a;
        }
        .select2-dropdown {
            border-radius: 0.5rem;
            border-color: #d1d5db;
        }
        .select2-search__field {
            border-radius: 0.5rem !important;
            border: 1px solid #d1d5db !important;
            padding: 6px !important;
        }
        .select2-results__option--highlighted {
            background-color: #3b82f6 !important;
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
        
        .type-envoi-option {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .type-envoi-option:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>

<div class="max-w-3xl mx-auto py-8 px-4">
    <div class="flex items-center mb-6">
        <a href="index.php?page=campagnes/choix" class="text-blue-600 hover:text-blue-800 mr-4">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="bg-blue-100 p-3 rounded-full mr-4">
            <i class="fas fa-comment-dots text-blue-600 text-xl"></i>
        </div>
        <h1 class="text-2xl font-bold text-gray-800">Envoyer des SMS</h1>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <div class="bg-blue-50 p-3 rounded mb-4">
            <p class="text-sm text-blue-700">
                <i class="fas fa-mobile-alt mr-1"></i> Appareil: <strong><?= htmlspecialchars($device_name) ?></strong>
                <br><small>ID: <?= htmlspecialchars($device_id) ?></small>
            </p>
        </div>
        
        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded"><?= $success ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded"><?= $error ?></div>
        <?php endif; ?>
        
        <form method="POST" id="smsForm">
            <!-- Type d'envoi -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-envelope mr-1"></i> Type d'envoi *
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <div id="typeSimple" 
                         class="type-envoi-option border-2 rounded-lg p-3 text-center cursor-pointer transition <?= (!isset($_POST['type_envoi']) || $_POST['type_envoi'] == 'simple') ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-blue-300' ?>">
                        <i class="fas fa-user text-blue-600 text-xl mb-1"></i>
                        <p class="font-medium text-gray-800">Envoi unique</p>
                        <p class="text-xs text-gray-500">À un seul destinataire</p>
                    </div>
                    <div id="typeMultiple" 
                         class="type-envoi-option border-2 rounded-lg p-3 text-center cursor-pointer transition <?= (isset($_POST['type_envoi']) && $_POST['type_envoi'] == 'multiple') ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-blue-300' ?>">
                        <i class="fas fa-users text-blue-600 text-xl mb-1"></i>
                        <p class="font-medium text-gray-800">Envoi multiple</p>
                        <p class="text-xs text-gray-500">À plusieurs destinataires</p>
                    </div>
                </div>
                <input type="hidden" name="type_envoi" id="type_envoi" value="<?= isset($_POST['type_envoi']) ? $_POST['type_envoi'] : 'simple' ?>">
            </div>
            
            <!-- Zone Envoi unique -->
            <div id="simpleZone" class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <i class="fas fa-user mr-1"></i> Destinataire *
                </label>
                <select name="contact_unique" id="contact_unique" class="w-full">
                    <option value="">Sélectionnez un contact...</option>
                    <?php foreach ($contacts as $contact): ?>
                        <option value="<?= $contact['id_contact'] ?>" <?= (isset($_POST['contact_unique']) && $_POST['contact_unique'] == $contact['id_contact']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?> - <?= htmlspecialchars($contact['telephone']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Zone Envoi multiple -->
            <div id="multipleZone" class="mb-4" style="display: none;">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-list mr-1"></i> Choisir une liste
                    </label>
                    <select name="liste_id" id="liste_id" class="w-full">
                        <option value="">-- Sélectionnez une liste --</option>
                        <?php foreach ($listes as $liste): ?>
                            <option value="<?= $liste['id_liste'] ?>">
                                <?= htmlspecialchars($liste['nom_liste']) ?> (<?= $liste['nombre_contacts'] ?> contact<?= $liste['nombre_contacts'] > 1 ? 's' : '' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Ou sélectionnez des contacts individuellement ci-dessous</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-users mr-1"></i> Sélection manuelle des contacts
                    </label>
                    <select name="destinataires[]" id="contact_search" multiple class="w-full">
                        <?php foreach ($contacts as $contact): ?>
                            <option value="<?= $contact['id_contact'] ?>">
                                <?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?> - <?= htmlspecialchars($contact['telephone']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Tapez pour rechercher, sélectionnez-en plusieurs</p>
                </div>
            </div>
            
            <!-- Message -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    <i class="fas fa-comment mr-1"></i> Message *
                </label>
                <textarea name="message" id="message" rows="5" required
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition"
                          placeholder="Votre message..."><?= isset($_POST['message']) ? htmlspecialchars($_POST['message']) : '' ?></textarea>
                <p class="text-xs text-gray-500 mt-1" id="charCount">0 caractères</p>
            </div>
            
            <div class="flex justify-end">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition">
                    <i class="fas fa-paper-plane mr-2"></i>Envoyer
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/fr.js"></script>

<script>
$(document).ready(function() {
    $('#contact_search').select2({
        placeholder: "Rechercher et sélectionner des contacts...",
        allowClear: true,
        width: '100%',
        language: 'fr',
        closeOnSelect: false
    });
    
    $('#contact_unique').select2({
        placeholder: "Sélectionnez un contact...",
        allowClear: true,
        width: '100%',
        language: 'fr'
    });
    
    $('#liste_id').select2({
        placeholder: "-- Sélectionnez une liste --",
        allowClear: true,
        width: '100%',
        language: 'fr'
    });
});

const typeSimple = document.getElementById('typeSimple');
const typeMultiple = document.getElementById('typeMultiple');
const simpleZone = document.getElementById('simpleZone');
const multipleZone = document.getElementById('multipleZone');
const typeEnvoiInput = document.getElementById('type_envoi');

function setTypeEnvoi(type) {
    if (type === 'simple') {
        typeSimple.classList.add('border-blue-500', 'bg-blue-50');
        typeSimple.classList.remove('border-gray-200');
        typeMultiple.classList.remove('border-blue-500', 'bg-blue-50');
        typeMultiple.classList.add('border-gray-200');
        simpleZone.style.display = 'block';
        multipleZone.style.display = 'none';
        typeEnvoiInput.value = 'simple';
        
        $('#contact_search').prop('disabled', true);
        $('#liste_id').prop('disabled', true);
        $('#contact_search').next().css('opacity', '0.5');
        $('#liste_id').next().css('opacity', '0.5');
        $('#contact_unique').prop('disabled', false);
        $('#contact_unique').next().css('opacity', '1');
    } else {
        typeMultiple.classList.add('border-blue-500', 'bg-blue-50');
        typeMultiple.classList.remove('border-gray-200');
        typeSimple.classList.remove('border-blue-500', 'bg-blue-50');
        typeSimple.classList.add('border-gray-200');
        simpleZone.style.display = 'none';
        multipleZone.style.display = 'block';
        typeEnvoiInput.value = 'multiple';
        
        $('#contact_search').prop('disabled', false);
        $('#liste_id').prop('disabled', false);
        $('#contact_search').next().css('opacity', '1');
        $('#liste_id').next().css('opacity', '1');
        $('#contact_unique').prop('disabled', true);
        $('#contact_unique').next().css('opacity', '0.5');
    }
}

typeSimple.addEventListener('click', () => setTypeEnvoi('simple'));
typeMultiple.addEventListener('click', () => setTypeEnvoi('multiple'));

setTypeEnvoi(typeEnvoiInput.value);

const messageTextarea = document.getElementById('message');
if (messageTextarea) {
    messageTextarea.addEventListener('input', function() {
        const countSpan = document.getElementById('charCount');
        countSpan.textContent = this.value.length + ' caractères';
        if (this.value.length > 160) {
            countSpan.classList.add('text-red-500');
            countSpan.classList.remove('text-gray-500');
        } else {
            countSpan.classList.remove('text-red-500');
            countSpan.classList.add('text-gray-500');
        }
    });
    messageTextarea.dispatchEvent(new Event('input'));
}

$('#liste_id').on('change', function() {
    const selected = $(this).find('option:selected');
    const text = selected.text();
    const match = text.match(/\((\d+)/);
    if (match && match[1] > 0) {
        showToast(`${match[1]} contact(s) dans cette liste`, 'info');
    }
});

document.getElementById('smsForm').addEventListener('submit', function(e) {
    const type_envoi = document.getElementById('type_envoi').value;
    const message = document.getElementById('message').value.trim();
    let hasRecipients = false;
    
    if (type_envoi === 'simple') {
        const contact = $('#contact_unique').val();
        hasRecipients = contact && contact !== '';
    } else {
        const liste = $('#liste_id').val();
        const contacts = $('#contact_search').val();
        hasRecipients = (liste && liste !== '') || (contacts && contacts.length > 0);
    }
    
    if (!hasRecipients) {
        e.preventDefault();
        showToast('Veuillez sélectionner au moins un destinataire', 'error');
        return false;
    }
    if (!message) {
        e.preventDefault();
        showToast('Veuillez saisir un message', 'error');
        return false;
    }
});

function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `<div class="toast-content">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}
</script>

</body>
</html>