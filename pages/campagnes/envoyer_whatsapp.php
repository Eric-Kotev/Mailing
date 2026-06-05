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
    
    if (empty($chatId) || empty($message)) {
        $error = "Veuillez remplir tous les champs";
    } else {
        // Vérifier si un fichier a été uploadé
        $hasFile = isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK;
        $filePath = null;
        $apiUrl = 'http://192.168.88.132:8081/api/controller.php';
        $endpoint = '/messages/send-text';
        $data = [];
        
        if ($hasFile) {
            // Créer le dossier d'upload si nécessaire
            $uploadDir = __DIR__ . '/../../uploads/temp/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            // Sauvegarder le fichier temporairement
            $originalName = $_FILES['fichier']['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $tempName = uniqid() . '.' . $extension;
            $filePath = $uploadDir . $tempName;
            move_uploaded_file($_FILES['fichier']['tmp_name'], $filePath);
            
            // Déterminer le type MIME
            $mimeType = mime_content_type($filePath);
            
            // Lire le fichier en base64
            $fileData = base64_encode(file_get_contents($filePath));
            
            // Préparer les données selon le type de fichier
            if (strpos($mimeType, 'image/') !== false) {
                // Envoi d'image
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
                // Envoi de vidéo
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
                // Envoi de message vocal
                $endpoint = '/messages/send-voice';
                $data = [
                    'session' => $whatsappSession,
                    'chatId' => $chatId,
                    'data' => $fileData,
                    'mimetype' => 'audio/ogg; codecs=opus',
                    'filename' => pathinfo($originalName, PATHINFO_FILENAME) . '.ogg',
                    'convert' => true
                ];
            } else {
                // Envoi de fichier (document)
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
        } else {
            // Envoi de texte simple
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
        
        // Nettoyer le fichier temporaire
        if ($hasFile && $filePath && file_exists($filePath)) {
            unlink($filePath);
        }
        
        if ($httpCode === 200 || $httpCode === 201) {
            $success = "Message envoyé avec succès !";
            if ($hasFile) {
                $success .= " (fichier joint inclus)";
            }
        } else {
            $error = "Erreur d'envoi: " . $response;
        }
    }
}
?>
<div class="max-w-3xl mx-auto">
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
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded">
                <i class="fas fa-check-circle mr-2"></i> <?= $success ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
                <i class="fas fa-exclamation-circle mr-2"></i> <?= $error ?>
            </div>
        <?php endif; ?>
        
        <?php if (empty($contacts)): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4 rounded">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                Aucun contact disponible. 
                <a href="index.php?page=contacts/ajouter" class="underline font-semibold">Ajoutez d'abord des contacts</a>
                pour envoyer des messages WhatsApp.
            </div>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fab fa-whatsapp mr-1 text-green-600"></i> Destinataire *
                    </label>
                    <select name="chat_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500">
                        <option value="">-- Sélectionner un contact --</option>
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
                                    ( Pas de numéro)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Format attendu: 33612345678@c.us</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Message *</label>
                    <textarea name="message" required rows="5" 
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500"
                              placeholder="Votre message..."></textarea>
                    <p class="text-xs text-gray-500 mt-1" id="charCount">0 caractères</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fichier joint (optionnel)</label>
                    <input type="file" name="fichier" id="fichier" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500">
                    <p class="text-xs text-gray-500 mt-1" id="fileInfo">Images, vidéos, audio, PDF (Max 10 Mo)</p>
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg transition">
                        <i class="fab fa-whatsapp mr-2"></i>Envoyer
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
const messageTextarea = document.getElementById('message');
if (messageTextarea) {
    messageTextarea.addEventListener('input', function() {
        const countSpan = document.getElementById('charCount');
        if (countSpan) countSpan.textContent = this.value.length + ' caractères';
    });
}

const fileInput = document.getElementById('fichier');
if (fileInput) {
    fileInput.addEventListener('change', function() {
        const fileInfo = document.getElementById('fileInfo');
        if (this.files.length > 0) {
            const file = this.files[0];
            const sizeMB = (file.size / 1024 / 1024).toFixed(2);
            let typeLabel = '';
            if (file.type.startsWith('image/')) typeLabel = '📷 Image';
            else if (file.type.startsWith('video/')) typeLabel = '🎥 Vidéo';
            else if (file.type.startsWith('audio/')) typeLabel = '🎵 Audio';
            else typeLabel = '📄 Document';
            fileInfo.innerHTML = `<i class="fas fa-paperclip mr-1"></i> ${typeLabel}: ${file.name} (${sizeMB} Mo)`;
            fileInfo.classList.add('text-green-600');
        } else {
            fileInfo.innerHTML = 'Images, vidéos, audio, PDF (Max 10 Mo)';
            fileInfo.classList.remove('text-green-600');
        }
    });
}
</script>