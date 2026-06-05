<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer la session WhatsApp de l'utilisateur
$compte = $db->select('compte', ['id_compte' => $idCompte], 'waha_session');
$whatsappSession = $compte ? $compte[0]['waha_session'] : null;

if (!$whatsappSession) {
    header('Location: index.php?page=campagnes/choix');
    exit;
}

// Récupérer les contacts de l'utilisateur
$contacts = $db->select('contact', ['id_compte' => $idCompte]);

if (!is_array($contacts)) {
    $contacts = [];
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
        
        if ($httpCode === 200 || $httpCode === 201) {
            $success = "Message envoyé avec succès !";
            if ($hasAudio) {
                $success .= " (audio inclus)";
            } elseif ($hasFile) {
                $success .= " (fichier joint inclus)";
            }
        } else {
            $error = "Erreur d'envoi: " . $response;
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
    </style>
</head>
<body>

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
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg transition">
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

// ============================================
// ENREGISTREMENT AUDIO
// ============================================
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

// Gestion de l'upload de fichier
fichierInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        const file = e.target.files[0];
        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
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
});

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
        alert('Impossible d\'accéder au microphone: ' + err.message);
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

// ============================================
// COMPTEUR DE CARACTÈRES
// ============================================
const messageTextarea = document.getElementById('message');
if (messageTextarea) {
    messageTextarea.addEventListener('input', function() {
        const countSpan = document.getElementById('charCount');
        if (countSpan) countSpan.textContent = this.value.length + ' caractères';
    });
}

// ============================================
// VALIDATION AVANT SOUMISSION
// ============================================
document.getElementById('whatsappForm').addEventListener('submit', function(e) {
    const hasFile = fichierInput.files.length > 0;
    const hasAudio = audioDataInput.value !== '';
    const hasMessage = messageTextarea.value.trim() !== '';
    
    if (!hasMessage && !hasFile && !hasAudio) {
        e.preventDefault();
        alert('Veuillez saisir un message ou ajouter un fichier/audio');
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
    toast.innerHTML = `<div class="toast-content">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}
</script>

</body>
</html>