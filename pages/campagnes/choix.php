<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer toutes les sessions WhatsApp de l'utilisateur
$sessions = $db->select('whatsapp_sessions', ['id_compte' => $idCompte], '*', 'created_at.desc');

// Récupérer la session active WhatsApp
$whatsappSession = null;
foreach ($sessions as $s) {
    if ($s['est_active']) {
        $whatsappSession = $s['nom_session'];
        break;
    }
}

if (!$whatsappSession && !empty($sessions)) {
    $whatsappSession = $sessions[0]['nom_session'];
    $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $sessions[0]['id_session']]);
}

$hasWhatsAppConfig = !empty($sessions);

// Récupérer les appareils SMS sauvegardés (triés par actif en premier)
$smsAppareils = $db->select('sms_appareils', ['id_compte' => $idCompte], '*', 'est_actif DESC, created_at DESC');
$hasSmsAppareils = !empty($smsAppareils);
?>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Nouvelle campagne</h1>
            <p class="text-gray-500">Choisissez le type de message à envoyer</p>
        </div>
        <a href="index.php?page=campagnes/index" class="text-blue-600 hover:text-blue-800">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- SMS -->
        <div onclick="handleSmsClick()" 
             class="block bg-white rounded-lg shadow hover:shadow-lg transition transform hover:-translate-y-1 cursor-pointer">
            <div class="p-6 text-center">
                <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-comment-dots text-blue-600 text-2xl"></i>
                </div>
                <h2 class="text-xl font-bold text-gray-800 mb-2">SMS</h2>
                <p class="text-gray-500 text-sm mb-3">Messages texte courts</p>
                <span class="text-xs <?= $hasSmsAppareils ? 'text-green-600' : 'text-blue-600' ?>">
                    <?php if ($hasSmsAppareils): ?>
                        <?= count($smsAppareils) ?> appareil(s) disponible(s)
                    <?php else: ?>
                         Configurer d'abord
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <!-- WhatsApp -->
        <div onclick="handleWhatsAppClick()" 
             class="block bg-white rounded-lg shadow hover:shadow-lg transition transform hover:-translate-y-1 cursor-pointer">
            <div class="p-6 text-center">
                <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                    <i class="fab fa-whatsapp text-green-600 text-3xl"></i>
                </div>
                <h2 class="text-xl font-bold text-gray-800 mb-2">WhatsApp</h2>
                <p class="text-gray-500 text-sm mb-3">Messages avec médias</p>
                <span class="text-xs <?= $hasWhatsAppConfig ? 'text-green-600' : 'text-blue-600' ?>">
                    <?php if ($hasWhatsAppConfig): ?>
                        <?php if (count($sessions) > 1): ?>
                            <?= count($sessions) ?> session(s) disponible(s)
                        <?php else: ?>
                            Session: <?= htmlspecialchars($whatsappSession) ?>
                        <?php endif; ?>
                    <?php else: ?>
                         Configurer d'abord
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DE SÉLECTION DE SESSION WHATSAPP -->
<div id="sessionModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 transform transition-all duration-300 scale-95 opacity-0" id="sessionModalContent">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-green-100 p-2 rounded-full mr-3">
                        <i class="fab fa-whatsapp text-green-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Gérer les sessions WhatsApp</h3>
                </div>
                <button onclick="closeSessionModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <p class="text-gray-500 text-sm mb-4">Sélectionnez la session à utiliser ou créez-en une nouvelle :</p>
            
            <div class="space-y-2 max-h-96 overflow-y-auto mb-4" id="sessionList">
                <?php if (empty($sessions)): ?>
                    <div class="text-center text-gray-500 py-4">
                        <i class="fas fa-info-circle mb-2"></i>
                        <p>Aucune session configurée</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($sessions as $session): ?>
                        <div class="flex items-center justify-between p-3 border rounded-lg hover:bg-green-50 transition <?= $session['est_active'] ? 'border-green-500 bg-green-50' : 'border-gray-200' ?>">
                            <div onclick="selectSession('<?= htmlspecialchars($session['nom_session']) ?>', '<?= $session['id_session'] ?>')" class="flex items-center flex-1 cursor-pointer">
                                <i class="fab fa-whatsapp mr-3 <?= $session['est_active'] ? 'text-green-600' : 'text-gray-400' ?>"></i>
                                <div>
                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($session['nom_session']) ?></p>
                                    <p class="text-xs text-gray-500">
                                        Créée le <?= date('d/m/Y H:i', strtotime($session['created_at'])) ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <?php if ($session['est_active']): ?>
                                    <span class="bg-green-100 text-green-700 text-xs px-2 py-1 rounded-full">Active</span>
                                <?php else: ?>
                                    <span class="bg-gray-100 text-gray-500 text-xs px-2 py-1 rounded-full">Inactive</span>
                                <?php endif; ?>
                                <button onclick="deleteSession('<?= $session['id_session'] ?>', '<?= htmlspecialchars($session['nom_session']) ?>')" 
                                        class="text-red-500 hover:text-red-700 transition ml-2 p-1">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="flex justify-between mt-6">
                <button type="button" onclick="openNewSessionModal()" 
                        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-plus-circle mr-2"></i>Nouvelle session
                </button>
                <button type="button" onclick="goToSendPage()" 
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fab fa-whatsapp mr-2"></i>Continuer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DE CONFIGURATION WHATSAPP AVEC QR CODE -->
<div id="whatsappModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4 transform transition-all duration-300 scale-95 opacity-0" id="modalContent">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-green-100 p-2 rounded-full mr-3">
                        <i class="fab fa-whatsapp text-green-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Nouvelle session WhatsApp</h3>
                </div>
                <button onclick="closeWhatsAppModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div id="step1" class="space-y-4">
                <p class="text-gray-500 text-sm">Créez une nouvelle session WhatsApp en scannant le QR code.</p>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Nom de la session *
                    </label>
                    <input type="text" id="sessionName" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-green-200 transition"
                           placeholder="Ex: Personnel, Commercial, Support...">
                </div>
                <div class="flex justify-end">
                    <button type="button" onclick="closeWhatsAppModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition mr-2">
                        Annuler
                    </button>
                    <button type="button" onclick="createAndStartSession()" 
                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-qrcode mr-2"></i>Générer QR Code
                    </button>
                </div>
            </div>
            
            <div id="step2" style="display: none;" class="text-center">
                <div class="bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 p-3 rounded mb-4 text-sm">
                    <i class="fas fa-info-circle mr-2"></i>
                    Scannez le QR code avec WhatsApp sur votre téléphone
                </div>
                <div id="qrContainer" class="flex justify-center p-4">
                    <div id="qrSpinner" class="text-center">
                        <i class="fas fa-spinner fa-spin text-3xl text-green-600"></i>
                        <p class="text-gray-500 mt-2">Chargement...</p>
                    </div>
                    <img id="qrImage" src="" alt="QR Code" style="display: none;" class="border rounded-lg shadow-lg" style="max-width: 250px;">
                </div>
                <div id="qrError" class="text-red-600 text-sm mt-2" style="display: none;"></div>
                <div id="qrWaitingMsg" class="text-blue-600 text-sm mt-2" style="display: none;"></div>
                <button type="button" onclick="closeWhatsAppModal()" 
                        class="mt-4 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Fermer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DE GESTION DES APPAREILS SMS -->
<div id="smsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 transform transition-all duration-300 scale-95 opacity-0" id="smsModalContent">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                        <i class="fas fa-comment-dots text-blue-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Gérer les appareils SMS</h3>
                </div>
                <button onclick="closeSmsModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <p class="text-gray-500 text-sm mb-4">Sélectionnez un appareil ou ajoutez-en un nouveau :</p>
            
            <div class="space-y-2 max-h-96 overflow-y-auto mb-4" id="smsAppareilList">
                <?php if (empty($smsAppareils)): ?>
                    <div class="text-center text-gray-500 py-4">
                        <i class="fas fa-info-circle mb-2"></i>
                        <p>Aucun appareil configuré</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($smsAppareils as $appareil): ?>
                        <div class="flex items-center justify-between p-3 border rounded-lg hover:bg-blue-50 transition cursor-pointer <?= $appareil['est_actif'] ? 'border-blue-500 bg-blue-50' : 'border-gray-200' ?>"
                             onclick="selectExistingAppareil('<?= $appareil['device_id'] ?>', '<?= htmlspecialchars($appareil['device_name'] ?: 'Appareil') ?>', '<?= $appareil['api_username'] ?>', '<?= $appareil['api_password'] ?>', '<?= $appareil['id_appareil'] ?>')">
                            <div class="flex items-center flex-1">
                                <i class="fas fa-mobile-alt mr-3 <?= $appareil['est_actif'] ? 'text-blue-600' : 'text-gray-400' ?>"></i>
                                <div>
                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($appareil['device_name'] ?: 'Appareil') ?></p>
                                    <p class="text-xs text-gray-500">ID: <?= htmlspecialchars($appareil['device_id']) ?></p>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <?php if ($appareil['est_actif']): ?>
                                    <span class="bg-blue-100 text-blue-700 text-xs px-2 py-1 rounded-full">Actif</span>
                                <?php else: ?>
                                    <span class="bg-gray-100 text-gray-500 text-xs px-2 py-1 rounded-full">Inactif</span>
                                <?php endif; ?>
                                <button onclick="event.stopPropagation(); deleteSmsAppareil('<?= $appareil['id_appareil'] ?>', '<?= htmlspecialchars($appareil['device_name'] ?: 'Appareil') ?>')" 
                                        class="text-red-500 hover:text-red-700 transition p-1">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="flex justify-between mt-6">
                <button type="button" onclick="openNewAppareilModal()" 
                        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-plus-circle mr-2"></i>Nouvel appareil
                </button>
                <button type="button" onclick="closeSmsModal()" 
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Annuler
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DE CONNEXION POUR NOUVEL APPAREIL -->
<div id="newAppareilModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 transform transition-all duration-300 scale-95 opacity-0" id="newAppareilContent">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                        <i class="fas fa-plus-circle text-blue-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Nouvel appareil SMS</h3>
                </div>
                <button onclick="closeNewAppareilModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <p class="text-gray-500 text-sm mb-4">Entrez vos identifiants pour récupérer vos appareils :</p>
            
            <form id="smsLoginForm">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Nom d'utilisateur API *
                    </label>
                    <input type="text" id="api_username" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition"
                           placeholder="Entrez votre nom d'utilisateur">
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Mot de passe API *
                    </label>
                    <input type="password" id="api_password" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition"
                           placeholder="Entrez votre mot de passe">
                </div>
                
                <div class="flex justify-end space-x-2 mt-6">
                    <button type="button" onclick="closeNewAppareilModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="submit" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-sign-in-alt mr-2"></i>Se connecter
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL DE SÉLECTION D'APPAREIL DEPUIS API -->
<div id="deviceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 transform transition-all duration-300 scale-95 opacity-0" id="deviceModalContent">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                        <i class="fas fa-mobile-alt text-blue-600 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800">Choisir un appareil</h3>
                </div>
                <button onclick="closeDeviceModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="space-y-2 max-h-96 overflow-y-auto mb-4" id="deviceList">
                <div class="text-center text-gray-500 py-4">
                    <i class="fas fa-spinner fa-spin mr-2"></i>
                    Chargement...
                </div>
            </div>
            
            <div class="flex justify-end mt-6">
                <button type="button" onclick="closeDeviceModal()" 
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Annuler
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const API_BASE_URL = 'http://72.62.26.166:8081/api/controller.php';
const API_KEY = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
const SMS_API_URL = 'http://72.62.26.166:8085';

let currentSession = '';
let statusInterval = null;
let whatsappSession = '<?= $whatsappSession ?>';
let hasSessions = <?= $hasWhatsAppConfig ? 'true' : 'false' ?>;
let currentApiUsername = '';
let currentApiPassword = '';

function showToast(message, type = 'warning') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    
    let bgColor = '#f59e0b';
    
    const types = {
        success: { color: '#10b981' },
        error: { color: '#ef4444' },
        info: { color: '#3b82f6' },
        warning: { color: '#f59e0b' }
    };
    
    if (types[type]) {
        bgColor = types[type].color;
    }
    
    toast.innerHTML = `<div class="toast-content" style="background: ${bgColor};"><span>${message}</span></div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// ============================================
// MODAL DE CONFIRMATION DYNAMIQUE
// ============================================

function showConfirmModal(itemName, onConfirm) {
    const existingModal = document.getElementById('dynamicConfirmModal');
    if (existingModal) existingModal.remove();
    
    const modal = document.createElement('div');
    modal.id = 'dynamicConfirmModal';
    modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-[100]';
    modal.innerHTML = `
        <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full mx-4 transform transition-all duration-300 scale-95 opacity-0" id="dynamicConfirmContent">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <div class="flex items-center">
                        <div class="bg-red-100 p-2 rounded-full mr-3">
                            <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800">Confirmer la suppression</h3>
                    </div>
                    <button onclick="closeDynamicConfirmModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <p class="text-gray-600 mb-4">Êtes-vous sûr de vouloir supprimer <strong>${escapeHtml(itemName)}</strong> ?</p>
                <p class="text-sm text-red-600 mb-4">Cette action est irréversible.</p>
                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="closeDynamicConfirmModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button type="button" id="dynamicConfirmBtn" 
                            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-trash-alt mr-2"></i>Supprimer
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    const content = document.getElementById('dynamicConfirmContent');
    setTimeout(() => content.classList.add('modal-show'), 10);
    
    document.getElementById('dynamicConfirmBtn').onclick = () => {
        closeDynamicConfirmModal();
        onConfirm();
    };
}

function closeDynamicConfirmModal() {
    const modal = document.getElementById('dynamicConfirmModal');
    if (modal) {
        const content = document.getElementById('dynamicConfirmContent');
        if (content) content.classList.remove('modal-show');
        setTimeout(() => modal.remove(), 200);
    }
}

// ============================================
// GESTION WHATSAPP
// ============================================

function deleteSession(sessionId, sessionName) {
    showConfirmModal(sessionName, async () => {
        showToast('Suppression en cours...', 'info');
        try {
            const response = await fetch('/delete_session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `session_id=${encodeURIComponent(sessionId)}`
            });
            const result = await response.json();
            if (result.success) {
                showToast(`Session "${sessionName}" supprimée`, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(result.error || 'Erreur', 'error');
            }
        } catch (error) {
            showToast('Erreur: ' + error.message, 'error');
        }
    });
}

function handleWhatsAppClick() {
    openSessionModal();
}

function openSessionModal() {
    const modal = document.getElementById('sessionModal');
    const modalContent = document.getElementById('sessionModalContent');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeSessionModal() {
    const modal = document.getElementById('sessionModal');
    const modalContent = document.getElementById('sessionModalContent');
    modalContent.classList.remove('modal-show');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 200);
}

function goToSendPage() {
    window.location.href = 'index.php?page=campagnes/envoyer_whatsapp';
}

function openNewSessionModal() {
    closeSessionModal();
    openWhatsAppModal();
}

async function selectSession(sessionName, sessionId) {
    try {
        const response = await fetch('/activate_session.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `session_id=${encodeURIComponent(sessionId)}&nom_session=${encodeURIComponent(sessionName)}`
        });
        const result = await response.json();
        if (result.success) {
            showToast(`Session "${sessionName}" activée`, 'success');
            // REDIRECTION VERS LA PAGE D'ENVOI WHATSAPP
            setTimeout(() => {
                window.location.href = 'index.php?page=campagnes/envoyer_whatsapp';
            }, 500);
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur: ' + error.message, 'error');
    }
}

// ============================================
// GESTION SMS
// ============================================

function handleSmsClick() {
    openSmsModal();
}

function openSmsModal() {
    const modal = document.getElementById('smsModal');
    const modalContent = document.getElementById('smsModalContent');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeSmsModal() {
    const modal = document.getElementById('smsModal');
    const modalContent = document.getElementById('smsModalContent');
    modalContent.classList.remove('modal-show');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 200);
}

function openNewAppareilModal() {
    closeSmsModal();
    const modal = document.getElementById('newAppareilModal');
    const modalContent = document.getElementById('newAppareilContent');
    document.getElementById('api_username').value = '';
    document.getElementById('api_password').value = '';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeNewAppareilModal() {
    const modal = document.getElementById('newAppareilModal');
    const modalContent = document.getElementById('newAppareilContent');
    modalContent.classList.remove('modal-show');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        openSmsModal();
    }, 200);
}

function openDeviceModal() {
    const modal = document.getElementById('deviceModal');
    const modalContent = document.getElementById('deviceModalContent');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeDeviceModal() {
    const modal = document.getElementById('deviceModal');
    const modalContent = document.getElementById('deviceModalContent');
    modalContent.classList.remove('modal-show');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }, 200);
}

async function selectExistingAppareil(deviceId, deviceName, apiUsername, apiPassword, appareilId) {
    try {
        showToast('Activation de l\'appareil...', 'info');
        const response = await fetch('/activate_sms_appareil.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `appareil_id=${encodeURIComponent(appareilId)}&device_id=${encodeURIComponent(deviceId)}&device_name=${encodeURIComponent(deviceName)}&api_username=${encodeURIComponent(apiUsername)}&api_password=${encodeURIComponent(apiPassword)}`
        });
        const result = await response.json();
        if (result.success) {
            showToast(`Appareil "${deviceName}" activé`, 'success');
            closeSmsModal();
            setTimeout(() => {
                window.location.href = 'index.php?page=campagnes/envoyer_sms';
            }, 500);
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur: ' + error.message, 'error');
    }
}

document.getElementById('smsLoginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const api_username = document.getElementById('api_username').value.trim();
    const api_password = document.getElementById('api_password').value.trim();
    if (!api_username || !api_password) {
        showToast('Veuillez entrer vos identifiants', 'error');
        return;
    }
    currentApiUsername = api_username;
    currentApiPassword = api_password;
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Connexion...';
    submitBtn.disabled = true;
    try {
        const response = await fetch(`${SMS_API_URL}/devices.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ api_username: api_username, api_password: api_password })
        });
        const result = await response.json();
        if (result.status === 'ok' && result.devices && result.devices.length > 0) {
            closeNewAppareilModal();
            displayDevices(result.devices);
        } else {
            showToast('Aucun appareil trouvé ou identifiants incorrects', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur de connexion à l\'API', 'error');
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

function displayDevices(devices) {
    const container = document.getElementById('deviceList');
    container.innerHTML = '';
    devices.forEach(device => {
        const deviceId = device.id;
        const deviceName = device.name || 'Appareil';
        const div = document.createElement('div');
        div.className = 'flex items-center justify-between p-3 border rounded-lg hover:bg-blue-50 transition cursor-pointer border-gray-200';
        div.onclick = () => saveAndSelectDevice(deviceId, deviceName);
        div.innerHTML = `
            <div class="flex items-center">
                <i class="fas fa-mobile-alt mr-3 text-blue-600"></i>
                <div>
                    <p class="font-medium text-gray-800">${escapeHtml(deviceName)}</p>
                    <p class="text-xs text-gray-500">ID: ${escapeHtml(deviceId)}</p>
                </div>
            </div>
            <i class="fas fa-chevron-right text-gray-400"></i>
        `;
        container.appendChild(div);
    });
    openDeviceModal();
}

async function saveAndSelectDevice(deviceId, deviceName) {
    try {
        showToast('Enregistrement...', 'info');
        const params = new URLSearchParams();
        params.append('device_id', deviceId);
        params.append('device_name', deviceName);
        params.append('api_username', currentApiUsername);
        params.append('api_password', currentApiPassword);
        const response = await fetch('/save_sms_appareil.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params
        });
        const result = await response.json();
        if (result.success) {
            showToast(`Appareil "${deviceName}" enregistré et activé`, 'success');
            closeDeviceModal();
            setTimeout(() => {
                window.location.href = 'index.php?page=campagnes/envoyer_sms';
            }, 500);
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur: ' + error.message, 'error');
    }
}

function deleteSmsAppareil(appareilId, appareilName) {
    closeSmsModal();
    setTimeout(() => {
        showConfirmModal(appareilName, async () => {
            showToast('Suppression en cours...', 'info');
            try {
                const response = await fetch('/delete_sms_appareil.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `appareil_id=${encodeURIComponent(appareilId)}`
                });
                const result = await response.json();
                if (result.success) {
                    showToast(`Appareil "${appareilName}" supprimé`, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(result.error || 'Erreur', 'error');
                }
            } catch (error) {
                showToast('Erreur: ' + error.message, 'error');
            }
        });
    }, 250);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// WHATSAPP - QR CODE
// ============================================

function openWhatsAppModal() {
    const modal = document.getElementById('whatsappModal');
    const modalContent = document.getElementById('modalContent');
    document.getElementById('step1').style.display = 'block';
    document.getElementById('step2').style.display = 'none';
    document.getElementById('sessionName').value = '';
    document.getElementById('qrImage').style.display = 'none';
    document.getElementById('qrSpinner').style.display = 'block';
    document.getElementById('qrError').style.display = 'none';
    document.getElementById('qrWaitingMsg').style.display = 'none';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => modalContent.classList.add('modal-show'), 10);
}

function closeWhatsAppModal() {
    const modal = document.getElementById('whatsappModal');
    const modalContent = document.getElementById('modalContent');
    modalContent.classList.remove('modal-show');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        openSessionModal();
    }, 200);
}

async function createAndStartSession() {
    const sessionName = document.getElementById('sessionName').value.trim();
    if (!sessionName) {
        showToast('Veuillez entrer un nom de session', 'warning');
        return;
    }
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Connexion...';
    btn.disabled = true;
    try {
        const listResponse = await fetch(`${API_BASE_URL}/sessions`, {
            method: 'GET',
            headers: { 'X-Controller-Key': API_KEY }
        });
        const listResult = await listResponse.json();
        const sessionsExistantes = listResult.sessions || [];
        const sessionExiste = sessionsExistantes.includes(sessionName);
        if (!sessionExiste) {
            const createResponse = await fetch(`${API_BASE_URL}/sessions`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Controller-Key': API_KEY },
                body: JSON.stringify({ name: sessionName })
            });
            const createResult = await createResponse.json();
            if (!createResponse.ok || createResult.ok === false) {
                throw new Error(createResult.error || 'Création échouée');
            }
        }
        const startResponse = await fetch(`${API_BASE_URL}/sessions/${sessionName}/start`, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Controller-Key': API_KEY }
        });
        const startResult = await startResponse.json();
        if (!startResponse.ok || startResult.ok === false) {
            throw new Error(startResult.error || 'Démarrage échoué');
        }
        const saveResponse = await fetch('/save_session.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `nom_session=${encodeURIComponent(sessionName)}&compte_id=<?= $_SESSION['user_id'] ?>`
        });
        const saveResult = await saveResponse.json();
        if (!saveResult.success) {
            throw new Error(saveResult.error || 'Erreur sauvegarde');
        }
        showToast('Session WhatsApp créée !', 'success');
        document.getElementById('step1').style.display = 'none';
        document.getElementById('step2').style.display = 'block';
        await loadQRCode(sessionName);
    } catch (error) {
        showToast('Erreur: ' + error.message, 'error');
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

async function loadQRCode(sessionName) {
    const qrSpinner = document.getElementById('qrSpinner');
    const qrImage = document.getElementById('qrImage');
    const qrError = document.getElementById('qrError');
    qrSpinner.style.display = 'block';
    qrImage.style.display = 'none';
    qrError.style.display = 'none';
    try {
        const response = await fetch(`${API_BASE_URL}/sessions/${sessionName}/qr`, {
            headers: { 'Accept': 'application/json', 'X-Controller-Key': API_KEY }
        });
        const data = await response.json();
        if (!response.ok || data.ok === false) {
            throw new Error(data.error || 'Impossible de récupérer le QR code');
        }
        let qrBase64 = data.qr_base64 || data.qr || data.qr_code || data.image;
        if (qrBase64) {
            if (!qrBase64.startsWith('data:image')) {
                qrBase64 = 'data:image/png;base64,' + qrBase64;
            }
            qrImage.onload = () => {
                qrSpinner.style.display = 'none';
                qrImage.style.display = 'block';
            };
            qrImage.onerror = () => {
                qrSpinner.style.display = 'none';
                qrError.style.display = 'block';
                qrError.innerHTML = 'Erreur chargement QR code';
            };
            qrImage.src = qrBase64;
        }
        checkSessionStatus(sessionName);
    } catch (error) {
        qrSpinner.style.display = 'none';
        qrError.style.display = 'block';
        qrError.innerHTML = 'Erreur: ' + error.message;
    }
}

async function checkSessionStatus(sessionName) {
    let attempts = 0;
    const maxAttempts = 60;
    let isConnected = false;
    if (statusInterval) clearInterval(statusInterval);
    statusInterval = setInterval(async () => {
        attempts++;
        try {
            const response = await fetch(`${API_BASE_URL}/sessions/${sessionName}/status`, {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'X-Controller-Key': API_KEY }
            });
            if (!response.ok) return;
            const data = await response.json();
            const currentStatus = data.status || data.state;
            if (currentStatus === 'WORKING' || currentStatus === 'connected') {
                isConnected = true;
                clearInterval(statusInterval);
                const qrContainer = document.getElementById('qrContainer');
                qrContainer.innerHTML = '<div class="bg-green-100 text-green-700 p-4 rounded text-center">' +
                    '<i class="fas fa-check-circle text-3xl mb-2 block"></i>' +
                    'Connexion WhatsApp réussie !</div>';
                showToast('Connexion réussie !', 'success');
                setTimeout(() => {
                    window.location.href = 'index.php?page=campagnes/envoyer_whatsapp';
                }, 2000);
            }
            if (attempts >= maxAttempts && !isConnected) {
                clearInterval(statusInterval);
                showToast('Délai dépassé. Veuillez réessayer.', 'error');
            }
        } catch (error) {
            console.error(error);
        }
    }, 3000);
}

// ============================================
// Événements
// ============================================

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeWhatsAppModal();
        closeSessionModal();
        closeDynamicConfirmModal();
        closeSmsModal();
        closeNewAppareilModal();
        closeDeviceModal();
    }
});

document.getElementById('whatsappModal').addEventListener('click', function(e) {
    if (e.target === this) closeWhatsAppModal();
});
document.getElementById('sessionModal').addEventListener('click', function(e) {
    if (e.target === this) closeSessionModal();
});
document.getElementById('smsModal').addEventListener('click', function(e) {
    if (e.target === this) closeSmsModal();
});
document.getElementById('newAppareilModal').addEventListener('click', function(e) {
    if (e.target === this) closeNewAppareilModal();
});
document.getElementById('deviceModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeviceModal();
});
</script>

<style>
    .modal-show { opacity: 1 !important; transform: scale(1) !important; }
    #qrImage { max-width: 250px; height: auto; }
    .toast-notification {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
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