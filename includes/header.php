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

// Récupérer le rôle pour l'affichage
$userRole = $_SESSION['user_role'] ?? 'user';
$isAdmin = ($userRole === 'admin');

// Récupérer le logo de l'utilisateur
$userLogo = '';
$userId = $_SESSION['user_id'] ?? 0;
if ($userId) {
    $userInfo = $db->select('compte', ['id_compte' => $userId], 'logo_url');
    if ($userInfo && !empty($userInfo[0]['logo_url'])) {
        $userLogo = $userInfo[0]['logo_url'];
    }
}
?>

<header class="bg-white shadow-sm">
    <div class="flex justify-between items-center px-6 py-3">
        <!-- GROUPE GAUCHE : Menu hamburger + Message Bonjour -->
        <div class="flex items-center gap-4">
            <!-- Bouton toggle pour le sidebar -->
            <button id="headerToggleBtn" class="text-gray-500 hover:text-gray-700 focus:outline-none">
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
                    <?php if ($isAdmin): ?>
                        <span class="bg-purple-100 text-purple-800 px-2 py-0.5 rounded-full text-[10px] font-medium">Admin</span>
                    <?php endif; ?>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    // ============================================
    // SYNC AVEC LE BOUTON DU SIDEBAR
    // ============================================
    const headerToggleBtn = document.getElementById('headerToggleBtn');
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    
    // Fonction pour basculer le sidebar
    function toggleSidebar() {
        if (!sidebar) return;
        
        const isCollapsed = sidebar.classList.contains('w-20');
        
        if (isCollapsed) {
            // Agrandir
            sidebar.classList.remove('w-20');
            sidebar.classList.add('w-64');
            
            // Afficher les textes
            const allTexts = sidebar.querySelectorAll('.menu-text, #logoText, #sousTitre');
            allTexts.forEach(text => text.classList.remove('hidden'));
            
            // Restaurer les paddings
            const header = sidebar.querySelector('.p-2');
            if (header) {
                header.classList.add('p-4');
                header.classList.remove('p-2');
            }
            const footer = sidebar.querySelector('.p-2.border-t');
            if (footer) {
                footer.classList.add('p-4');
                footer.classList.remove('p-2');
            }
            
            // Mettre à jour l'icône du bouton du sidebar
            if (sidebarToggle) {
                sidebarToggle.querySelector('i').className = 'fas fa-chevron-left text-sm';
            }
            
            localStorage.setItem('admin_sidebar_collapsed', 'false');
        } else {
            // Rétrécir
            sidebar.classList.add('w-20');
            sidebar.classList.remove('w-64');
            
            // Cacher les textes
            const allTexts = sidebar.querySelectorAll('.menu-text, #logoText, #sousTitre');
            allTexts.forEach(text => text.classList.add('hidden'));
            
            // Réduire les paddings
            const header = sidebar.querySelector('.p-4');
            if (header) {
                header.classList.add('p-2');
                header.classList.remove('p-4');
            }
            const footer = sidebar.querySelector('.p-4.border-t');
            if (footer) {
                footer.classList.add('p-2');
                footer.classList.remove('p-4');
            }
            
            // Mettre à jour l'icône du bouton du sidebar
            if (sidebarToggle) {
                sidebarToggle.querySelector('i').className = 'fas fa-chevron-right text-sm';
            }
            
            localStorage.setItem('admin_sidebar_collapsed', 'true');
        }
    }
    
    // Événement sur le bouton du header
    if (headerToggleBtn) {
        headerToggleBtn.addEventListener('click', toggleSidebar);
    }
    
    // Événement sur le bouton du sidebar (si existant)
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', toggleSidebar);
    }
});

// ============================================
// TOAST NOTIFICATIONS
// ============================================
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
// POLLING DES NOTIFICATIONS D'ENVOI AUTOMATIQUE
// ============================================
(function() {
    async function verifierNotifications() {
        try {
            const res = await fetch('pages/campagnes/check_notifications.php');
            const data = await res.json();

            if (data.success && data.nouveaux.length > 0) {
                data.nouveaux.forEach(envoi => {
                    let msg, type;
                    if (envoi.statut === 'envoye') {
                        msg = `Campagne envoyée (${envoi.nb_succes} destinataires)`;
                        type = 'success';
                    } else if (envoi.statut === 'echoue') {
                        msg = `Échec d'un envoi de campagne`;
                        type = 'error';
                    } else {
                        msg = `Envoi partiel : ${envoi.nb_succes} succès, ${envoi.nb_erreurs} échecs`;
                        type = 'warning';
                    }

                    showToast(msg, type);

                    fetch('pages/campagnes/marquer_notification_vue.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id_campagne: envoi.id_campagne })
                    });
                });
            }
        } catch (err) {
            console.error('Erreur vérification notifications:', err);
        }
    }

    setInterval(verifierNotifications, 15000); // toutes les 15 secondes
    verifierNotifications(); // vérification immédiate au chargement
})();
</script>

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

/* Bouton header toggle */
#headerToggleBtn {
    transition: all 0.2s ease;
}

#headerToggleBtn:hover {
    transform: scale(1.1);
    color: #1f2937;
}
</style>