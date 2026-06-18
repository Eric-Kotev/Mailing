<?php
// En-tête du BackOffice
global $db;

// Mettre à jour les crédits en session
$credits = getCreditsDisponibles($_SESSION['user_id']);
$_SESSION['user_credits'] = $credits;

// Récupérer le prénom et nom pour l'affichage
$prenom = $_SESSION['user_prenom'] ?? '';
$nom = $_SESSION['user_nom'] ?? '';
$userName = $_SESSION['user_name'] ?? '';

if (!empty($prenom) && !empty($nom)) {
    $displayName = $prenom . ' ' . $nom;
} else {
    $displayName = $userName;
}

// Récupérer le logo de l'utilisateur
$userLogo = '';
$userId = $_SESSION['user_id'] ?? 0;
if ($userId) {
    $userInfo = $db->select('compte', ['id_compte' => $userId], 'logo_url');
    if ($userInfo && !empty($userInfo[0]['logo_url'])) {
        $userLogo = $userInfo[0]['logo_url'];
    }
}

// ============================================
// VÉRIFICATION DES CAMPAGNES PLANIFIÉES À ENVOYER
// ============================================
$campagnesAAlerter = [];
if (isset($_SESSION['user_id'])) {
    $idCompte = $_SESSION['user_id'];
    $now = date('Y-m-d H:i:s');
    
    // Initialiser la session pour les notifications si pas existante
    if (!isset($_SESSION['campagnes_notifiees'])) {
        $_SESSION['campagnes_notifiees'] = [];
    }
    
    // Récupérer les campagnes planifiées
    $campagnesPlanifiees = $db->select('campagne_config', [
        'id_compte' => $idCompte,
        'statut' => 'planifiee'
    ]);
    
    foreach ($campagnesPlanifiees as $campagne) {
        // Vérifier si la date de planification est passée ou dans les 5 minutes
        $datePlanif = strtotime($campagne['date_planification']);
        $dateNow = time();
        
        // Si la date est passée (ou dans les 5 minutes)
        if (!empty($campagne['date_planification']) && $datePlanif <= $dateNow) {
            // Vérifier si déjà notifiée
            if (!in_array($campagne['id_campagne_config'], $_SESSION['campagnes_notifiees'])) {
                $_SESSION['campagnes_notifiees'][] = $campagne['id_campagne_config'];
                $campagnesAAlerter[] = $campagne;
                
                // Mettre à jour le statut pour ne plus alerter
                $db->update('campagne_config', ['statut' => 'a_envoyer'], ['id_campagne_config' => $campagne['id_campagne_config']]);
            }
        }
    }
}
?>

<header class="bg-white shadow-sm">
    <div class="flex justify-between items-center px-6 py-3">
        <!--  GROUPE GAUCHE : Menu hamburger + Message Bonjour -->
        <div class="flex items-center gap-4">
            <button id="sidebarToggle" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-bars text-xl"></i>
            </button>
            
            <div class="text-left">
                <div class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <span>Bonjour</span>
                    <span><?= htmlspecialchars($displayName) ?></span>
                    <span class="text-xl"></span>
                </div>
                <div class="text-xs text-gray-500 flex items-center gap-2">
                    <i class="fas fa-building text-gray-400"></i>
                    <?= htmlspecialchars($_SESSION['user_entreprise'] ?? 'Aucune entreprise') ?>
                </div>
            </div>
        </div>
        
        <!-- GROUPE DROITE : Crédits + Logo + Déconnexion -->
        <div class="flex items-center space-x-4">
            <div class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium">
                <i class="fas fa-coins mr-1"></i>
                <?= number_format($credits, 2) ?> €
            </div>

            <?php if (!empty($userLogo)): ?>
                <img src="<?= htmlspecialchars($userLogo) . '?t=' . time() ?>" 
                     alt="Logo <?= htmlspecialchars($displayName) ?>"
                     class="w-10 h-10 rounded-full object-cover border border-gray-200 shadow-sm"
                     onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold shadow-sm" style="display: none;">
                    <?= strtoupper(substr($displayName, 0, 1)) ?>
                </div>
            <?php else: ?>
                <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold shadow-sm">
                    <?= strtoupper(substr($displayName, 0, 1)) ?>
                </div>
            <?php endif; ?>
            
            <a href="logout.php" class="text-gray-500 hover:text-red-600 transition" title="Déconnexion">
                <i class="fas fa-sign-out-alt text-xl"></i>
            </a>
        </div>
    </div>
</header>

<style>
    .object-cover {
        object-fit: cover;
    }
    
    .toast-container {
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 10000;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    
    .toast-notification {
        min-width: 300px;
        max-width: 400px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        animation: slideInRight 0.3s ease-out;
        overflow: hidden;
    }
    
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    .toast-notification .toast-content {
        padding: 14px 16px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .toast-notification.success .toast-content { background: #10b981; color: white; }
    .toast-notification.error .toast-content { background: #ef4444; color: white; }
    .toast-notification.warning .toast-content { background: #f59e0b; color: white; }
    .toast-notification.info .toast-content { background: #3b82f6; color: white; }
    
    .toast-notification .toast-icon {
        font-size: 1.25rem;
    }
    
    .toast-notification .toast-message {
        flex: 1;
        font-size: 0.875rem;
        font-weight: 500;
    }
    
    .toast-notification .toast-close {
        cursor: pointer;
        opacity: 0.7;
        transition: opacity 0.2s;
    }
    
    .toast-notification .toast-close:hover {
        opacity: 1;
    }
    
    .toast-notification.fade-out {
        animation: fadeOut 0.3s ease forwards;
    }
    
    @keyframes fadeOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
</style>

<script>
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer') || (() => {
        const newContainer = document.createElement('div');
        newContainer.id = 'toastContainer';
        newContainer.className = 'toast-container';
        document.body.appendChild(newContainer);
        return newContainer;
    })();
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    
    let icon = '';
    switch(type) {
        case 'success': icon = '<i class="fas fa-check-circle"></i>'; break;
        case 'error': icon = '<i class="fas fa-exclamation-circle"></i>'; break;
        case 'warning': icon = '<i class="fas fa-exclamation-triangle"></i>'; break;
        case 'info': icon = '<i class="fas fa-info-circle"></i>'; break;
        default: icon = '<i class="fas fa-bell"></i>';
    }
    
    toast.innerHTML = `
        <div class="toast-content">
            <div class="toast-icon">${icon}</div>
            <div class="toast-message">${escapeHtml(message)}</div>
            <div class="toast-close"><i class="fas fa-times"></i></div>
        </div>
    `;
    
    container.appendChild(toast);
    
    const closeBtn = toast.querySelector('.toast-close');
    closeBtn.addEventListener('click', () => {
        toast.classList.add('fade-out');
        setTimeout(() => toast.remove(), 300);
    });
    
    setTimeout(() => {
        if (toast.parentNode) {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 300);
        }
    }, 5000);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// AFFICHER LES ALERTES AU CHARGEMENT
// ============================================
<?php foreach ($campagnesAAlerter as $campagne): ?>
    setTimeout(function() {
        showToast('La campagne "<?= addslashes($campagne['nom_campagne']) ?>" doit être envoyée !', 'warning');
    }, 1000);
<?php endforeach; ?>
</script>