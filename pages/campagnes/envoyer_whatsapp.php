<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer la session WhatsApp active depuis la nouvelle table whatsapp_sessions
$sessions = $db->select('whatsapp_sessions', [
    'id_compte' => $idCompte,
    'est_active' => true
]);

$whatsappSession = null;
if (!empty($sessions)) {
    $whatsappSession = $sessions[0]['nom_session'];
} else {
    // Si aucune session active, prendre la première session disponible
    $sessions = $db->select('whatsapp_sessions', ['id_compte' => $idCompte], '*', 'created_at.desc');
    if (!empty($sessions)) {
        $whatsappSession = $sessions[0]['nom_session'];
        // Activer cette session par défaut
        $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $sessions[0]['id_session']]);
    }
}

if (!$whatsappSession) {
    header('Location: index.php?page=campagnes/choix');
    exit;
}

// Récupérer les IDs des contacts blacklistés (sans condition id_compte)
$blacklist = $db->select('blacklist');
$blacklistIds = [];
foreach ($blacklist as $b) {
    if (!empty($b['id_contact'])) {
        $blacklistIds[] = $b['id_contact'];
    }
}

// Récupérer tous les contacts du compte
$tousContacts = $db->select('contact', ['id_compte' => $idCompte]);

// Filtrer
$contacts = [];
foreach ($tousContacts as $contact) {
    if (!in_array($contact['id_contact'], $blacklistIds)) {
        $contacts[] = $contact;
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chatId = $_POST['chat_id'] ?? '';
    $message = $_POST['message'] ?? '';
    
    // Vérifier si un fichier audio a été enregistré
    $audioData = $_POST['audio_data'] ?? '';
    $hasAudio = !empty($audioData) && strpos($audioData, 'base64,') !== false;
    
    // Vérifier si un fichier a été uploadé
    $hasFile = isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK;
    
    if (empty($chatId)) {
        $error = "Veuillez sélectionner un destinataire";
    } elseif (empty($message) && !$hasFile && !$hasAudio) {
        $error = "Veuillez saisir un message ou ajouter un fichier/audio";
    } else {
        $apiUrl = 'http://72.62.26.166:8081/api/controller.php';
        $endpoint = '/messages/send-text';
        $data = [];
        
        // Récupérer le nom du contact pour l'historique
        $contactNom = '';
        foreach ($contacts as $contact) {
            $telephone = $contact['telephone'] ?? '';
            if (!empty($telephone)) {
                $telephoneClean = preg_replace('/[^0-9]/', '', $telephone);
                if (strlen($telephoneClean) == 10 && substr($telephoneClean, 0, 1) == '0') {
                    $telephoneClean = '33' . substr($telephoneClean, 1);
                }
                $whatsappNumberTest = $telephoneClean . '@c.us';
                if ($whatsappNumberTest === $chatId) {
                    $contactNom = $contact['prenom'] . ' ' . $contact['nom'];
                    break;
                }
            }
        }
        
        // Priorité à l'audio enregistré
        if ($hasAudio) {
            // Extraire les données base64
            $base64Data = preg_replace('#^data:audio/[^;]+;base64,#', '', $audioData);
            $fileData = $base64Data;
            $originalName = 'audio_enregistre_' . date('Ymd_His') . '.webm';
            
            $endpoint = '/messages/send-voice';
            $data = [
                'session' => $whatsappSession,
                'chatId' => $chatId,
                'data' => $fileData,
                'mimetype' => 'audio/webm',
                'filename' => $originalName,
                'convert' => true
            ];
            
            // Ajouter la légende si un message est présent
            if (!empty($message)) {
                $data['caption'] = $message;
            }
        }
        // Sinon fichier uploadé
        elseif ($hasFile) {
            $uploadDir = __DIR__ . '/../../uploads/temp/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $originalName = $_FILES['fichier']['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $tempName = uniqid() . '.' . $extension;
            $filePath = $uploadDir . $tempName;
            move_uploaded_file($_FILES['fichier']['tmp_name'], $filePath);
            
            $mimeType = mime_content_type($filePath);
            $fileData = base64_encode(file_get_contents($filePath));
            
            if (strpos($mimeType, 'image/') !== false) {
                $endpoint = '/messages/send-image';
                $data = [
                    'session' => $whatsappSession,
                    'chatId' => $chatId,
                    'data' => $fileData,
                    'mimetype' => $mimeType,
                    'filename' => $originalName,
                    'caption' => $message
                ];
            } elseif (strpos($mimeType, 'video/') !== false) {
                $endpoint = '/messages/send-video';
                $data = [
                    'session' => $whatsappSession,
                    'chatId' => $chatId,
                    'data' => $fileData,
                    'mimetype' => $mimeType,
                    'filename' => $originalName,
                    'caption' => $message,
                    'asNote' => false,
                    'convert' => false
                ];
            } elseif (strpos($mimeType, 'audio/') !== false) {
                $endpoint = '/messages/send-voice';
                $data = [
                    'session' => $whatsappSession,
                    'chatId' => $chatId,
                    'data' => $fileData,
                    'mimetype' => $mimeType,
                    'filename' => $originalName,
                    'convert' => true
                ];
                if (!empty($message)) {
                    $data['caption'] = $message;
                }
            } else {
                $endpoint = '/messages/send-file';
                $data = [
                    'session' => $whatsappSession,
                    'chatId' => $chatId,
                    'data' => $fileData,
                    'mimetype' => $mimeType,
                    'filename' => $originalName,
                    'caption' => $message
                ];
            }
            
            unlink($filePath);
        } 
        // Sinon message texte simple
        else {
            $endpoint = '/messages/send-text';
            $data = [
                'session' => $whatsappSession,
                'chatId' => $chatId,
                'text' => $message
            ];
        }
        
        // Appel API WhatsApp
        $fullUrl = $apiUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Controller-Key: 29f51fbe00e64ac5a5e3ce6eefbb79b5'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // ============================================
        // PRÉPARER LES DONNÉES POUR L'HISTORIQUE
        // ============================================
        $destinatairesNoms = [];
        if (!empty($contactNom)) {
            $destinatairesNoms[] = $contactNom . ' (' . $chatId . ')';
        } else {
            $destinatairesNoms[] = $chatId;
        }
        $destinatairesJson = json_encode($destinatairesNoms);
        
        $titre = "WhatsApp - " . date('d/m/Y H:i');
        if (!empty($message)) {
            $titre = "WhatsApp: " . (strlen($message) > 40 ? substr($message, 0, 40) . '...' : $message);
        }
        
        if ($httpCode === 200 || $httpCode === 201) {
            $success = "Message envoyé avec succès !";
            if ($hasAudio) {
                $success .= " (audio inclus)";
            } elseif ($hasFile) {
                $success .= " (fichier joint inclus)";
            }
            
            // ENREGISTREMENT SUCCÈS
            $campagneData = [
                'id_compte' => $idCompte,
                'type_campagne' => 'whatsapp',
                'titre' => $titre,
                'message' => $message,
                'destinataires' => $destinatairesJson,
                'nb_destinataires' => 1,
                'nb_envoyes' => 1,
                'nb_succes' => 1,
                'nb_erreurs' => 0,
                'appareil_utilise' => $whatsappSession,
                'statut' => 'envoye',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
        } else {
            $error = "Echec: Votre message n'a pas été correctement envoyé";
            
            // ENREGISTREMENT ÉCHEC
            $campagneData = [
                'id_compte' => $idCompte,
                'type_campagne' => 'whatsapp',
                'titre' => $titre,
                'message' => $message,
                'destinataires' => $destinatairesJson,
                'nb_destinataires' => 1,
                'nb_envoyes' => 1,
                'nb_succes' => 0,
                'nb_erreurs' => 1,
                'appareil_utilise' => $whatsappSession,
                'statut' => 'echoue',
                'erreur' => substr($response, 0, 500),
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        // Enregistrer dans la base
        try {
            $db->insert('campagne', $campagneData);
        } catch (Exception $e) {
            error_log("Erreur insertion historique WhatsApp: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Envoyer WhatsApp - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .select2-container--default .select2-selection--single {
            height: 42px;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 4px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            color: #1f2937;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
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
            background-color: #22c55e !important;
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
        
        .recording-active {
            animation: pulse 1s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }

        /* Styles pour le drag & drop */
        #fileUploadArea {
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        /* Animation de chargement du bouton */
        .btn-loading {
            opacity: 0.7;
            cursor: not-allowed;
            position: relative;
            pointer-events: none;
        }
        
        .btn-loading i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* Overlay de chargement global */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9998;
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .loading-overlay.active {
            visibility: visible;
            opacity: 1;
        }
        
        .loading-spinner {
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            text-align: center;
        }
        
        .loading-spinner i {
            font-size: 48px;
            color: #22c55e;
            animation: spin 1s linear infinite;
            margin-bottom: 10px;
        }
        
        .loading-spinner p {
            margin: 0;
            color: #333;
            font-size: 14px;
        }
        
        .progress-bar-container {
            width: 300px;
            margin-top: 15px;
        }
        
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: #22c55e;
            width: 0%;
            transition: width 0.3s ease;
            animation: loading 2s infinite;
        }
        
        @keyframes loading {
            0% { width: 0%; }
            50% { width: 70%; }
            100% { width: 100%; }
        }
    </style>
</head>
<body>

<!-- Overlay de chargement global -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner">
        <i class="fab fa-whatsapp"></i>
        <p>Envoi du message en cours...</p>
        <div class="progress-bar-container">
            <div class="progress-bar">
                <div class="progress-bar-fill"></div>
            </div>
        </div>
        <p class="text-xs text-gray-500 mt-2">Veuillez patienter, ne fermez pas cette page</p>
    </div>
</div>

<div class="max-w-3xl mx-auto py-8 px-4">
    <div class="flex items-center mb-6">
        <a href="index.php?page=campagnes/choix" class="text-blue-600 hover:text-blue-800 mr-4">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="bg-green-100 p-3 rounded-full mr-4">
            <i class="fab fa-whatsapp text-green-600 text-xl"></i>
        </div>
        <h1 class="text-2xl font-bold text-gray-800">Envoyer un message WhatsApp</h1>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <div class="bg-green-50 p-3 rounded mb-4">
            <p class="text-sm text-green-700">
                <i class="fas fa-check-circle mr-1"></i> Session active: <strong><?= htmlspecialchars($whatsappSession) ?></strong>
            </p>
        </div>
        
        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded"><?= $success ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded"><?= $error ?></div>
        <?php endif; ?>
        
        <?php if (empty($contacts)): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4 rounded">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                Aucun contact disponible. 
                <a href="index.php?page=contacts/ajouter" class="underline font-semibold">Ajoutez d'abord des contacts</a>
            </div>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data" id="whatsappForm">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fab fa-whatsapp mr-1 text-green-600"></i> Destinataire *
                    </label>
                    <select name="chat_id" id="contact_search" required class="w-full" style="width: 100%;">
                        <option value="">Tapez le nom, prénom ou numéro...</option>
                        <?php foreach ($contacts as $contact): 
                            $telephone = $contact['telephone'] ?? '';
                            $whatsappNumber = '';
                            
                            if (!empty($telephone)) {
                                $telephone = preg_replace('/[^0-9]/', '', $telephone);
                                if (strlen($telephone) == 10 && substr($telephone, 0, 1) == '0') {
                                    $telephone = '33' . substr($telephone, 1);
                                }
                                $whatsappNumber = $telephone . '@c.us';
                            }
                        ?>
                            <option value="<?= htmlspecialchars($whatsappNumber) ?>" <?= empty($whatsappNumber) ? 'disabled' : '' ?>>
                                <?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?>
                                <?php if (!empty($telephone)): ?>
                                    (<?= htmlspecialchars($telephone) ?>)
                                <?php else: ?>
                                    (⚠️ Pas de numéro)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-search mr-1"></i> Tapez pour rechercher par nom, prénom ou numéro
                    </p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Message <span id="messageRequired" class="text-gray-400 text-xs">(optionnel si fichier/audio)</span></label>
                    <textarea name="message" id="message" rows="4" 
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500"
                              placeholder="Votre message..."></textarea>
                    <p class="text-xs text-gray-500 mt-1" id="charCount">0 caractères</p>
                </div>
                
                <!-- Options de pièce jointe -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Pièce jointe (optionnel)</label>
                    
                    <div class="flex space-x-2 mb-3">
                        <button type="button" id="uploadFileBtn" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg transition">
                            <i class="fas fa-upload mr-2"></i>Fichier
                        </button>
                        <button type="button" id="recordAudioBtn" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg transition">
                            <i class="fas fa-microphone mr-2"></i>Enregistrer audio
                        </button>
                    </div>
                    
                    <!-- Zone d'upload fichier -->
                    <div id="fileUploadArea" class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hidden">
                        <input type="file" name="fichier" id="fichier" class="hidden" accept="image/*,video/*,audio/*,.pdf">
                        <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2"></i>
                        <p class="text-gray-500">Cliquez ou glissez un fichier ici</p>
                        <p class="text-xs text-gray-400 mt-1">Images, vidéos, audio, PDF (Max 10 Mo)</p>
                        <div id="fileInfo" class="mt-2 text-sm hidden"></div>
                        <button type="button" id="removeFileBtn" class="text-red-500 text-sm mt-2 hidden">Supprimer</button>
                    </div>
                    
                    <!-- Zone d'enregistrement audio -->
                    <div id="audioRecordArea" class="border-2 border-gray-300 rounded-lg p-4 text-center hidden">
                        <div class="mb-3">
                            <div id="recordingTimer" class="text-2xl font-mono text-gray-700 mb-2">00:00</div>
                        </div>
                        <div class="flex justify-center space-x-3">
                            <button type="button" id="startRecordBtn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition">
                                <i class="fas fa-circle mr-2"></i>Commencer
                            </button>
                            <button type="button" id="stopRecordBtn" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition hidden">
                                <i class="fas fa-stop mr-2"></i>Arrêter
                            </button>
                        </div>
                        <div id="audioPreview" class="mt-3 hidden">
                            <audio controls class="w-full"></audio>
                            <button type="button" id="removeAudioBtn" class="text-red-500 text-sm mt-2">Supprimer l'audio</button>
                        </div>
                        <input type="hidden" name="audio_data" id="audioData">
                    </div>
                </div>
                
                <div class="flex justify-end mt-6">
                    <button type="submit" id="submitBtn" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg transition">
                        <i class="fab fa-whatsapp mr-2"></i>Envoyer
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/fr.js"></script>

<script>
// Initialisation Select2
$(document).ready(function() {
    $('#contact_search').select2({
        placeholder: "Tapez le nom, prénom ou numéro...",
        allowClear: true,
        width: '100%',
        language: 'fr'
    });
});

// Éléments du DOM
const submitBtn = document.getElementById('submitBtn');
const loadingOverlay = document.getElementById('loadingOverlay');
const whatsappForm = document.getElementById('whatsappForm');

// Fonction pour activer/désactiver le mode chargement
function setLoading(loading) {
    if (loading) {
        // Désactiver le bouton et changer son apparence
        submitBtn.classList.add('btn-loading');
        submitBtn.disabled = true;
        
        // Sauvegarder le contenu original
        const originalContent = submitBtn.innerHTML;
        submitBtn.setAttribute('data-original-content', originalContent);
        
        // Modifier le bouton
        submitBtn.innerHTML = '<i class="fab fa-whatsapp fa-spin mr-2"></i>Envoi en cours...';
        
        // Afficher l'overlay
        loadingOverlay.classList.add('active');
    } else {
        // Réactiver le bouton
        submitBtn.classList.remove('btn-loading');
        submitBtn.disabled = false;
        
        // Restaurer le contenu original
        const originalContent = submitBtn.getAttribute('data-original-content');
        if (originalContent) {
            submitBtn.innerHTML = originalContent;
        }
        
        // Cacher l'overlay
        loadingOverlay.classList.remove('active');
    }
}

// ENREGISTREMENT AUDIO
let mediaRecorder = null;
let audioChunks = [];
let recordingTimer = null;
let recordingSeconds = 0;
let stream = null;

const uploadFileBtn = document.getElementById('uploadFileBtn');
const recordAudioBtn = document.getElementById('recordAudioBtn');
const fileUploadArea = document.getElementById('fileUploadArea');
const audioRecordArea = document.getElementById('audioRecordArea');
const fichierInput = document.getElementById('fichier');
const fileInfoDiv = document.getElementById('fileInfo');
const removeFileBtn = document.getElementById('removeFileBtn');
const startRecordBtn = document.getElementById('startRecordBtn');
const stopRecordBtn = document.getElementById('stopRecordBtn');
const recordingTimerSpan = document.getElementById('recordingTimer');
const audioPreview = document.getElementById('audioPreview');
const audioDataInput = document.getElementById('audioData');
const removeAudioBtn = document.getElementById('removeAudioBtn');
const messageRequired = document.getElementById('messageRequired');

// Toggle entre les options
uploadFileBtn.addEventListener('click', () => {
    fileUploadArea.classList.remove('hidden');
    audioRecordArea.classList.add('hidden');
    resetRecording();
});

recordAudioBtn.addEventListener('click', () => {
    audioRecordArea.classList.remove('hidden');
    fileUploadArea.classList.add('hidden');
    resetFileUpload();
});

// GESTION DE L'UPLOAD DE FICHIER

// Fonction pour gérer le fichier
function handleFile(file) {
    const sizeMB = (file.size / 1024 / 1024).toFixed(2);
    
    // Vérifier la taille (max 10 Mo)
    if (file.size > 10 * 1024 * 1024) {
        showToast('Le fichier est trop volumineux. Maximum 10 Mo.', 'error');
        resetFileUpload();
        return;
    }
    
    let typeLabel = '';
    if (file.type.startsWith('image/')) typeLabel = 'Image';
    else if (file.type.startsWith('video/')) typeLabel = 'Vidéo';
    else if (file.type.startsWith('audio/')) typeLabel = 'Audio';
    else typeLabel = 'Document';
    
    fileInfoDiv.innerHTML = `<i class="fas fa-paperclip mr-1"></i> ${typeLabel}: ${file.name} (${sizeMB} Mo)`;
    fileInfoDiv.classList.remove('hidden');
    removeFileBtn.classList.remove('hidden');
    messageRequired.innerHTML = '<span class="text-green-600">(optionnel)</span>';
}

// Gestion du clic sur la zone pour ouvrir l'explorateur
fileUploadArea.addEventListener('click', (e) => {
    // Éviter de déclencher si on clique sur le bouton supprimer
    if (e.target !== removeFileBtn && !removeFileBtn.contains(e.target)) {
        fichierInput.click();
    }
});

// Gestion du changement de fichier via l'input
fichierInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        handleFile(e.target.files[0]);
    }
});

// DRAG & DROP
// Empêcher les comportements par défaut
['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    fileUploadArea.addEventListener(eventName, preventDefaults, false);
    document.body.addEventListener(eventName, preventDefaults, false);
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

// Mettre en évidence la zone
['dragenter', 'dragover'].forEach(eventName => {
    fileUploadArea.addEventListener(eventName, highlight, false);
});

['dragleave', 'drop'].forEach(eventName => {
    fileUploadArea.addEventListener(eventName, unhighlight, false);
});

function highlight(e) {
    fileUploadArea.classList.add('border-green-500', 'bg-green-50');
    fileUploadArea.classList.remove('border-gray-300');
}

function unhighlight(e) {
    fileUploadArea.classList.remove('border-green-500', 'bg-green-50');
    fileUploadArea.classList.add('border-gray-300');
}

// Gestion du drop
fileUploadArea.addEventListener('drop', handleDrop, false);

function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    
    if (files.length > 0) {
        fichierInput.files = files;
        handleFile(files[0]);
    }
}

// Bouton supprimer
removeFileBtn.addEventListener('click', () => {
    fichierInput.value = '';
    fileInfoDiv.classList.add('hidden');
    removeFileBtn.classList.add('hidden');
    if (!audioDataInput.value) {
        messageRequired.innerHTML = '<span class="text-gray-400 text-xs">(optionnel si fichier/audio)</span>';
    }
});

// Enregistrement audio
async function startRecording() {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        audioChunks = [];
        
        mediaRecorder.ondataavailable = (event) => {
            audioChunks.push(event.data);
        };
        
        mediaRecorder.onstop = () => {
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            const audioUrl = URL.createObjectURL(audioBlob);
            const audioElement = audioPreview.querySelector('audio');
            audioElement.src = audioUrl;
            
            const reader = new FileReader();
            reader.onloadend = () => {
                audioDataInput.value = reader.result;
                messageRequired.innerHTML = '<span class="text-green-600">(optionnel)</span>';
            };
            reader.readAsDataURL(audioBlob);
            
            audioPreview.classList.remove('hidden');
            startRecordBtn.classList.remove('hidden');
            stopRecordBtn.classList.add('hidden');
            startRecordBtn.classList.remove('recording-active');
        };
        
        mediaRecorder.start();
        startRecordBtn.classList.add('hidden');
        stopRecordBtn.classList.remove('hidden');
        startRecordBtn.classList.add('recording-active');
        
        recordingSeconds = 0;
        updateTimerDisplay();
        recordingTimer = setInterval(() => {
            recordingSeconds++;
            updateTimerDisplay();
        }, 1000);
        
    } catch (err) {
        showToast('Impossible d\'accéder au microphone: ' + err.message, 'error');
    }
}

function stopRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
        clearInterval(recordingTimer);
    }
}

function updateTimerDisplay() {
    const minutes = Math.floor(recordingSeconds / 60);
    const seconds = recordingSeconds % 60;
    recordingTimerSpan.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
}

function resetRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
    }
    clearInterval(recordingTimer);
    audioChunks = [];
    recordingSeconds = 0;
    updateTimerDisplay();
    audioPreview.classList.add('hidden');
    audioDataInput.value = '';
    startRecordBtn.classList.remove('hidden');
    stopRecordBtn.classList.add('hidden');
    startRecordBtn.classList.remove('recording-active');
}

function resetFileUpload() {
    fichierInput.value = '';
    fileInfoDiv.classList.add('hidden');
    removeFileBtn.classList.add('hidden');
}

startRecordBtn.addEventListener('click', startRecording);
stopRecordBtn.addEventListener('click', stopRecording);

removeAudioBtn.addEventListener('click', () => {
    resetRecording();
    if (!fichierInput.files.length && !document.getElementById('message').value.trim()) {
        messageRequired.innerHTML = '<span class="text-gray-400 text-xs">(optionnel si fichier/audio)</span>';
    }
});

// COMPTEUR DE CARACTÈRES
const messageTextarea = document.getElementById('message');
if (messageTextarea) {
    messageTextarea.addEventListener('input', function() {
        const countSpan = document.getElementById('charCount');
        if (countSpan) countSpan.textContent = this.value.length + ' caractères';
    });
}

// VALIDATION AVANT SOUMISSION AVEC INDICATEUR DE CHARGEMENT
whatsappForm.addEventListener('submit', function(e) {
    // Récupérer la valeur correcte du select2
    const chatId = $('#contact_search').val();
    const hasFile = fichierInput.files.length > 0;
    const hasAudio = audioDataInput.value !== '';
    const hasMessage = messageTextarea.value.trim() !== '';
    
    if (!chatId || chatId === '') {
        e.preventDefault();
        showToast('Veuillez sélectionner un destinataire', 'error');
        return false;
    }
    
    if (!hasMessage && !hasFile && !hasAudio) {
        e.preventDefault();
        showToast('Veuillez saisir un message ou ajouter un fichier/audio', 'error');
        return false;
    }
    
    // Si tout est valide, on active le mode chargement
    setLoading(true);
    
    // Le formulaire va se soumettre normalement ici
});

// TOAST NOTIFICATION
function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `<div class="toast-content">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Si la page se recharge avec un succès ou une erreur, on cache le loading
if (performance.navigation.type === 1) {
    // Page rechargée (après soumission)
    setLoading(false);
}
</script>

</body>
</html>