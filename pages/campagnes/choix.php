<?php
global $db;

$idCompte = $_SESSION['user_id'];


// DEBUG - Afficher l'URL complète
echo "<!-- URL: " . $_SERVER['REQUEST_URI'] . " -->";
echo "<!-- GET: " . print_r($_GET, true) . " -->";

$idCompte = $_SESSION['user_id'];

// Récupérer l'ID depuis l'URL
$campagneConfigId = $_GET['campagne_id'] ?? null;

echo "<!-- campagneConfigId: " . $campagneConfigId . " -->";

// ============================================
// RÉCUPÉRATION DE LA CAMPAGNE CONFIG
// ============================================
// Nettoyer l'ancien ID de session
unset($_SESSION['campagne_config_id']);

// Récupérer l'ID uniquement depuis l'URL (GET)
$campagneConfigId = $_GET['campagne_id'] ?? null;

// Debug - Afficher l'ID reçu (à supprimer après test)
error_log("=== choix.php ===");
error_log("campagne_id reçu: " . ($campagneConfigId ?? 'NULL'));

if (!$campagneConfigId) {
    header('Location: index.php?page=campagnes/index');
    exit;
}

// Récupérer les infos de la campagne config
$campagneConfig = $db->select('campagne_config', [
    'id_campagne_config' => $campagneConfigId,
    'id_compte' => $idCompte
]);

if (empty($campagneConfig)) {
    error_log("Campagne non trouvée pour ID: " . $campagneConfigId);
    $_SESSION['flash_error'] = "Campagne non trouvée";
    header('Location: index.php?page=campagnes/index');
    exit;
}

$campagne = $campagneConfig[0];

error_log("Campagne trouvée: " . $campagne['nom_campagne'] . " (ID: " . $campagne['id_campagne_config'] . ")");

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

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choisir un canal - <?= APP_NAME ?></title>
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
            min-width: 350px;
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
            width: 100%;
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
        }
        
        .modal-show {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        
        .campagne-info {
            background: #f3e8ff;
            border: 1px solid #d8b4fe;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }
        .campagne-info-title {
            font-size: 14px;
            font-weight: 600;
            color: #6b21a5;
            margin-bottom: 8px;
        }
        
        .password-container {
            position: relative;
        }
        .password-container input {
            padding-right: 45px;
        }
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            background: transparent;
            border: none;
            font-size: 1.1rem;
        }
        .toggle-password:hover {
            color: #3b82f6;
        }
    </style>
</head>
<body>

<div class="max-w-3xl mx-auto py-8 px-4">
    <div class="flex items-center mb-6">
        <a href="index.php?page=campagnes/index" class="text-blue-600 hover:text-blue-800 mr-4">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="bg-purple-100 p-3 rounded-full mr-4">
            <i class="fas fa-bullhorn text-purple-600 text-xl"></i>
        </div>
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Choisir un canal d'envoi</h1>
            <p class="text-gray-500">Sélectionnez le canal pour votre campagne</p>
        </div>
    </div>

    <!-- Affichage des infos de la campagne -->
    <div class="campagne-info">
        <div class="campagne-info-title">
            <i class="fas fa-bullhorn mr-2"></i>
            Campagne : <?= htmlspecialchars($campagne['nom_campagne']) ?>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- SMS -->
            <div onclick="handleSmsClick()" 
                 class="type-envoi-option border-2 rounded-lg p-6 text-center cursor-pointer transition border-gray-200 hover:border-blue-300 hover:shadow-md">
                <div class="bg-blue-100 rounded-full w-20 h-20 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-comment-dots text-blue-600 text-3xl"></i>
                </div>
                <h2 class="text-xl font-bold text-gray-800 mb-2">SMS</h2>
                <p class="text-gray-500 text-sm mb-3">Messages texte courts</p>
                <span class="text-xs <?= $hasSmsAppareils ? 'text-green-600' : 'text-orange-600' ?>">
                    <?php if ($hasSmsAppareils): ?>
                        <i class="fas fa-check-circle mr-1"></i> <?= count($smsAppareils) ?> appareil(s) disponible(s)
                    <?php else: ?>
                        <i class="fas fa-exclamation-triangle mr-1"></i> Aucun appareil configuré
                    <?php endif; ?>
                </span>
            </div>

            <!-- WhatsApp -->
            <div onclick="handleWhatsAppClick()" 
                 class="type-envoi-option border-2 rounded-lg p-6 text-center cursor-pointer transition border-gray-200 hover:border-green-300 hover:shadow-md">
                <div class="bg-green-100 rounded-full w-20 h-20 flex items-center justify-center mx-auto mb-4">
                    <i class="fab fa-whatsapp text-green-600 text-3xl"></i>
                </div>
                <h2 class="text-xl font-bold text-gray-800 mb-2">WhatsApp</h2>
                <p class="text-gray-500 text-sm mb-3">Messages avec médias</p>
                <span class="text-xs <?= $hasWhatsAppConfig ? 'text-green-600' : 'text-orange-600' ?>">
                    <?php if ($hasWhatsAppConfig): ?>
                        <i class="fas fa-check-circle mr-1"></i> 
                        <?php if (count($sessions) > 1): ?>
                            <?= count($sessions) ?> session(s) disponible(s)
                        <?php else: ?>
                            Session: <?= htmlspecialchars($whatsappSession) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <i class="fas fa-exclamation-triangle mr-1"></i> Aucune session configurée
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
                            <div onclick="selectSession('<?= htmlspecialchars($session['nom_session']) ?>', '<?= $session['id_session'] ?>', '<?= $campagneConfigId ?>')" class="flex items-center flex-1 cursor-pointer">
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
                                <button onclick="event.stopPropagation(); deleteSession('<?= $session['id_session'] ?>', '<?= htmlspecialchars($session['nom_session']) ?>')" 
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
                <button type="button" onclick="closeSessionModal()" 
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Annuler
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
                             onclick="selectExistingAppareil('<?= $appareil['device_id'] ?>', '<?= htmlspecialchars($appareil['device_name'] ?: 'Appareil') ?>', '<?= $appareil['api_username'] ?>', '<?= $appareil['api_password'] ?>', '<?= $appareil['id_appareil'] ?>', '<?= $campagneConfigId ?>')">
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
                    <div class="password-container">
                        <input type="password" id="api_password" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition"
                               placeholder="Entrez votre mot de passe">
                        <button type="button" class="toggle-password" onclick="togglePassword('api_password', this)">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
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

<!-- Formulaire caché pour passer l'ID de campagne -->
<form id="campagneForm" method="POST" style="display: none;">
    <input type="hidden" name="campagne_config_id" id="campagne_config_id" value="<?= $campagneConfigId ?>">
</form>

<script>
// Configuration
const API_BASE_URL = 'http://164.68.103.147:8081/api/controller.php';
const API_KEY = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
const SMS_API_URL = 'http://72.62.26.166:8085';

let currentSession = '';
let statusInterval = null;
let currentApiUsername = '';
let currentApiPassword = '';
let campagneConfigId = '<?= $campagneConfigId ?>';

// Log de l'ID côté JavaScript
console.log("campagneConfigId JS = " + campagneConfigId);

function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const colors = { success: '#10b981', error: '#ef4444', info: '#3b82f6', warning: '#f59e0b' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function togglePassword(inputId, buttonElement) {
    const passwordInput = document.getElementById(inputId);
    const icon = buttonElement.querySelector('i');
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

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

// Gestion WhatsApp
function handleWhatsAppClick() {
    openSessionModal();
}

function handleSmsClick() {
    openSmsModal();
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

function openNewSessionModal() {
    closeSessionModal();
    openWhatsAppModal();
}

function goToSendPage() {
    const form = document.getElementById('campagneForm');
    document.getElementById('campagne_config_id').value = campagneConfigId;
    form.action = 'index.php?page=campagnes/envoyer_whatsapp';
    form.submit();
}

async function selectSession(sessionName, sessionId, campagneId) {
    try {
        const response = await fetch('/activate_session.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `session_id=${encodeURIComponent(sessionId)}&nom_session=${encodeURIComponent(sessionName)}`
        });
        const result = await response.json();
        if (result.success) {
            showToast(`Session "${sessionName}" activée`, 'success');
            setTimeout(() => {
                const form = document.getElementById('campagneForm');
                document.getElementById('campagne_config_id').value = campagneId;
                form.action = 'index.php?page=campagnes/envoyer_whatsapp';
                form.submit();
            }, 500);
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur: ' + error.message, 'error');
    }
}

async function deleteSession(sessionId, sessionName) {
    showConfirmModal(sessionName, async () => {
        showToast('Suppression en cours...', 'info');
        
        try {
            const wahaUrl = `http://164.68.103.147:8081/api/controller.php/sessions/${encodeURIComponent(sessionName)}`;
            await fetch(wahaUrl, { method: 'DELETE', headers: { 'X-Controller-Key': API_KEY } });
            
            const dbResponse = await fetch('/delete_session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `session_id=${encodeURIComponent(sessionId)}&session_name=${encodeURIComponent(sessionName)}`
            });
            const dbResult = await dbResponse.json();
            
            if (dbResult.success) {
                showToast(`Session "${sessionName}" supprimée`, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(dbResult.error || 'Erreur', 'error');
            }
        } catch (error) {
            showToast('Erreur: ' + error.message, 'error');
        }
    });
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
        const listResponse = await fetch(`${API_BASE_URL}/sessions`, { headers: { 'X-Controller-Key': API_KEY } });
        const listResult = await listResponse.json();
        const sessionsExistantes = listResult.sessions || [];
        
        if (!sessionsExistantes.includes(sessionName)) {
            await fetch(`${API_BASE_URL}/sessions`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Controller-Key': API_KEY },
                body: JSON.stringify({ name: sessionName })
            });
        }
        
        await fetch(`${API_BASE_URL}/sessions/${sessionName}/start`, {
            method: 'POST',
            headers: { 'X-Controller-Key': API_KEY }
        });
        
        const saveResponse = await fetch('/save_session.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `nom_session=${encodeURIComponent(sessionName)}&compte_id=<?= $_SESSION['user_id'] ?>`
        });
        const saveResult = await saveResponse.json();
        
        if (!saveResult.success) throw new Error(saveResult.error);
        
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
            headers: { 'X-Controller-Key': API_KEY }
        });
        const data = await response.json();
        if (!response.ok || data.ok === false) throw new Error(data.error);
        
        let qrBase64 = data.qr_base64 || data.qr || data.qr_code || data.image;
        if (qrBase64) {
            if (!qrBase64.startsWith('data:image')) qrBase64 = 'data:image/png;base64,' + qrBase64;
            qrImage.onload = () => { qrSpinner.style.display = 'none'; qrImage.style.display = 'block'; };
            qrImage.onerror = () => { qrSpinner.style.display = 'none'; qrError.style.display = 'block'; qrError.innerHTML = 'Erreur chargement QR code'; };
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
                headers: { 'X-Controller-Key': API_KEY }
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
                    const form = document.getElementById('campagneForm');
                    document.getElementById('campagne_config_id').value = campagneConfigId;
                    form.action = 'index.php?page=campagnes/envoyer_whatsapp';
                    form.submit();
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

// Gestion SMS
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

async function selectExistingAppareil(deviceId, deviceName, apiUsername, apiPassword, appareilId, campagneId) {
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
                const form = document.getElementById('campagneForm');
                document.getElementById('campagne_config_id').value = campagneId;
                form.action = 'index.php?page=campagnes/envoyer_sms';
                form.submit();
            }, 500);
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur: ' + error.message, 'error');
    }
}

document.getElementById('smsLoginForm')?.addEventListener('submit', async (e) => {
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
            body: JSON.stringify({ api_username, api_password })
        });
        const result = await response.json();
        if (result.status === 'ok' && result.devices?.length > 0) {
            closeNewAppareilModal();
            displayDevices(result.devices);
        } else {
            showToast('Aucun appareil trouvé', 'error');
        }
    } catch (error) {
        showToast('Erreur de connexion', 'error');
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
            showToast(`Appareil "${deviceName}" enregistré`, 'success');
            closeDeviceModal();
            setTimeout(() => {
                const form = document.getElementById('campagneForm');
                document.getElementById('campagne_config_id').value = campagneConfigId;
                form.action = 'index.php?page=campagnes/envoyer_sms';
                form.submit();
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

// Fermeture des modales
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

document.getElementById('whatsappModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeWhatsAppModal();
});
document.getElementById('sessionModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeSessionModal();
});
document.getElementById('smsModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeSmsModal();
});
document.getElementById('newAppareilModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeNewAppareilModal();
});
document.getElementById('deviceModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeDeviceModal();
});
</script>

</body>
</html>