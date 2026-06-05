<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer la session WhatsApp de l'utilisateur depuis la colonne waha_session
$compte = $db->select('compte', ['id_compte' => $idCompte], 'waha_session');
$whatsappSession = $compte ? $compte[0]['waha_session'] : null;
$hasWhatsAppConfig = !empty($whatsappSession);
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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- SMS -->
        <a href="index.php?page=campagnes/nouvelle&type=sms" 
           class="block bg-white rounded-lg shadow hover:shadow-lg transition transform hover:-translate-y-1">
            <div class="p-6 text-center">
                <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-comment-dots text-blue-600 text-2xl"></i>
                </div>
                <h2 class="text-xl font-bold text-gray-800 mb-2">SMS</h2>
                <p class="text-gray-500 text-sm mb-3">Messages texte courts</p>
            </div>
        </a>

        <!-- Email -->
        <a href="index.php?page=campagnes/nouvelle&type=email" 
           class="block bg-white rounded-lg shadow hover:shadow-lg transition transform hover:-translate-y-1">
            <div class="p-6 text-center">
                <div class="bg-red-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-envelope text-red-600 text-2xl"></i>
                </div>
                <h2 class="text-xl font-bold text-gray-800 mb-2">Email</h2>
                <p class="text-gray-500 text-sm mb-3">Messages avec mise en page</p>
            </div>
        </a>

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
                         Session: <?= htmlspecialchars($whatsappSession) ?>
                    <?php else: ?>
                         Configurer d'abord
                    <?php endif; ?>
                </span>
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
                    <h3 class="text-xl font-bold text-gray-800">Configuration WhatsApp</h3>
                </div>
                <button onclick="closeWhatsAppModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <!-- Étape 1 : Nom de session -->
            <div id="step1" class="space-y-4">
                <p class="text-gray-500 text-sm">Pour envoyer des messages WhatsApp, vous devez d'abord configurer une session.</p>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Nom de la session *
                    </label>
                    <input type="text" id="sessionName" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-green-200 transition"
                           placeholder="Ex: MaSessionWhatsApp">
                    <p class="text-xs text-gray-500 mt-1">Nom unique pour identifier cette session</p>
                </div>
                <div class="flex justify-end">
                    <button type="button" onclick="closeWhatsAppModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition mr-2">
                        Annuler
                    </button>
                    <button type="button" onclick="createAndStartSession()" 
                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-plus-circle mr-2"></i>Créer et démarrer
                    </button>
                </div>
            </div>
            
            <!-- Étape 2 : QR Code -->
            <div id="step2" style="display: none;" class="text-center">
                <div class="bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 p-3 rounded mb-4 text-sm">
                    <i class="fas fa-info-circle mr-2"></i>
                    Scannez le QR code avec votre téléphone
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
            </div>
        </div>
    </div>
</div>

<script>
const API_BASE_URL = 'http://192.168.88.132:8081/api/controller.php';
const API_KEY = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
let currentSession = '';
let statusInterval = null;
let whatsappSession = '<?= $whatsappSession ?>';

function showToast(message, type = 'warning') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    
    let icon = '⚠️';
    let bgColor = '#f59e0b';
    
    const types = {
        success: { color: '#10b981' },
        error: { color: '#ef4444' },
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

function handleWhatsAppClick() {
    if (whatsappSession) {
        // Session existe → rediriger vers l'envoi
        window.location.href = 'index.php?page=campagnes/envoyer_whatsapp';
    } else {
        // Pas de session → ouvrir le modal de configuration
        openWhatsAppModal();
    }
}

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
        // Lister les sessions existantes
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
                headers: { 
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Controller-Key': API_KEY
                },
                body: JSON.stringify({ name: sessionName })
            });
            const createResult = await createResponse.json();
            if (!createResponse.ok || createResult.ok === false) {
                throw new Error(createResult.error || 'Création échouée');
            }
        }
        
        // Démarrer la session
        const startResponse = await fetch(`${API_BASE_URL}/sessions/${sessionName}/start`, {
            method: 'POST',
            headers: { 
                'Accept': 'application/json',
                'X-Controller-Key': API_KEY
            }
        });
        const startResult = await startResponse.json();
        if (!startResponse.ok || startResult.ok === false) {
            throw new Error(startResult.error || 'Démarrage échoué');
        }
        
        // Sauvegarder dans la BDD
        const saveResponse = await fetch('save_session.php', {
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
    } finally {
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
            headers: { 
                'Accept': 'application/json',
                'X-Controller-Key': API_KEY
            }
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
                headers: { 
                    'Accept': 'application/json',
                    'X-Controller-Key': API_KEY
                }
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
                    '✅ Connexion WhatsApp réussie !</div>';
                
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

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeWhatsAppModal();
});

document.getElementById('whatsappModal').addEventListener('click', function(e) {
    if (e.target === this) closeWhatsAppModal();
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