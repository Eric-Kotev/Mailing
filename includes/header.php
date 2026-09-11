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

$userEntreprise = $_SESSION['user_entreprise'] ?? 'Aucune entreprise';
$userInitiale = strtoupper(substr($displayName ?: 'U', 0, 1));
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
                </div>
                <div class="text-xs text-gray-500 flex items-center gap-2">
                    <i class="fas fa-building text-gray-400"></i>
                    <?= htmlspecialchars($userEntreprise) ?>
                    <?php if ($isAdmin): ?>
                        <span class="bg-purple-100 text-purple-800 px-2 py-0.5 rounded-full text-[10px] font-medium">Admin</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- GROUPE DROITE : Crédits (si non-admin) + Logo & Nom avec menu flottant -->
        <div class="flex items-center space-x-4">
            <?php if (!$isAdmin): ?>
                <div class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium">
                    <i class="fas fa-coins mr-1"></i>
                    <?= number_format($credits, 3) ?> €
                </div>
            <?php endif; ?>

            <!-- Logo + Nom + Menu flottant -->
            <div class="relative" id="userMenuWrapper">
                <button type="button"
                        id="userMenuBtn"
                        class="header-user-btn flex items-center gap-3 rounded-full focus:outline-none focus:ring-2 focus:ring-blue-400"
                        title="Mon compte"
                        aria-label="Ouvrir le menu utilisateur"
                        aria-haspopup="true"
                        aria-expanded="false">
                    <!-- Logo / Avatar -->
                    <span class="header-user-avatar">
                        <?php if (!empty($userLogo)): ?>
                            <img src="<?= htmlspecialchars($userLogo) . '?t=' . time() ?>" 
                                 alt="Logo <?= htmlspecialchars($displayName) ?>"
                                 class="header-logo w-10 h-10 rounded-full object-cover border border-gray-200 shadow-sm"
                                 onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <span class="header-logo-fallback w-10 h-10 bg-gradient-to-r from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold shadow-sm" style="display: none;">
                                <?= $userInitiale ?>
                            </span>
                        <?php else: ?>
                            <span class="header-logo-fallback w-10 h-10 bg-gradient-to-r from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold shadow-sm">
                                <?= $userInitiale ?>
                            </span>
                        <?php endif; ?>
                    </span>

                    <!-- Nom de l'utilisateur -->
                    <span class="header-user-name hidden sm:block"><?= htmlspecialchars($displayName) ?></span>

                    <!-- Chevron -->
                    <i class="fas fa-chevron-down header-user-chevron hidden sm:block"></i>
                </button>

                <!-- Menu flottant -->
                <div id="userDropdown"
                     class="user-dropdown"
                     role="menu"
                     aria-labelledby="userMenuBtn">
                    
                    <!-- En-tête du menu : avatar + nom + entreprise -->
                    <div class="user-dropdown-header">
                        <div class="user-dropdown-avatar">
                            <?php if (!empty($userLogo)): ?>
                                <img src="<?= htmlspecialchars($userLogo) . '?t=' . time() ?>" 
                                     alt="Logo <?= htmlspecialchars($displayName) ?>"
                                     class="user-dropdown-avatar-img"
                                     onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="user-dropdown-avatar-fallback" style="display: none;">
                                    <?= $userInitiale ?>
                                </div>
                            <?php else: ?>
                                <div class="user-dropdown-avatar-fallback">
                                    <?= $userInitiale ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="user-dropdown-info">
                            <div class="user-dropdown-name truncate"><?= htmlspecialchars($displayName) ?></div>
                            <div class="user-dropdown-company truncate"><?= htmlspecialchars($userEntreprise) ?></div>
                            <?php if ($isAdmin): ?>
                                <span class="user-dropdown-badge">Admin</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="user-dropdown-divider"></div>

                    <!-- Option Profil -->
                    <a href="index.php?page=parametres/compte"
                       class="user-dropdown-item"
                       role="menuitem">
                        <i class="fas fa-user-cog user-dropdown-item-icon"></i>
                        <span>Profil</span>
                    </a>

                    <!-- Option Déconnexion -->
                    <a href="logout.php"
                       class="user-dropdown-item user-dropdown-item-danger"
                       role="menuitem">
                        <i class="fas fa-sign-out-alt user-dropdown-item-icon"></i>
                        <span>Se déconnecter</span>
                    </a>
                </div>
            </div>
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
    
    function toggleSidebar() {
        if (!sidebar) return;
        
        const isCollapsed = sidebar.classList.contains('w-20');
        
        if (isCollapsed) {
            sidebar.classList.remove('w-20');
            sidebar.classList.add('w-64');
            
            const allTexts = sidebar.querySelectorAll('.menu-text, #logoText, #sousTitre');
            allTexts.forEach(text => text.classList.remove('hidden'));
            
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
            
            if (sidebarToggle) {
                sidebarToggle.querySelector('i').className = 'fas fa-chevron-left text-sm';
            }
            
            localStorage.setItem('admin_sidebar_collapsed', 'false');
        } else {
            sidebar.classList.add('w-20');
            sidebar.classList.remove('w-64');
            
            const allTexts = sidebar.querySelectorAll('.menu-text, #logoText, #sousTitre');
            allTexts.forEach(text => text.classList.add('hidden'));
            
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
            
            if (sidebarToggle) {
                sidebarToggle.querySelector('i').className = 'fas fa-chevron-right text-sm';
            }
            
            localStorage.setItem('admin_sidebar_collapsed', 'true');
        }
    }
    
    if (headerToggleBtn) {
        headerToggleBtn.addEventListener('click', toggleSidebar);
    }
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', toggleSidebar);
    }

    // ============================================
    // MENU FLOTTANT UTILISATEUR (logo + nom cliquables)
    // ============================================
    const userMenuBtn = document.getElementById('userMenuBtn');
    const userMenuWrapper = document.getElementById('userMenuWrapper');
    const userDropdown = document.getElementById('userDropdown');

    function openUserMenu() {
        if (!userDropdown) return;
        userDropdown.classList.add('show');
        if (userMenuBtn) userMenuBtn.setAttribute('aria-expanded', 'true');
    }

    function closeUserMenu() {
        if (!userDropdown) return;
        userDropdown.classList.remove('show');
        if (userMenuBtn) userMenuBtn.setAttribute('aria-expanded', 'false');
    }

    function toggleUserMenu(e) {
        e.stopPropagation();
        if (!userDropdown) return;
        if (userDropdown.classList.contains('show')) {
            closeUserMenu();
        } else {
            openUserMenu();
        }
    }

    if (userMenuBtn) {
        userMenuBtn.addEventListener('click', toggleUserMenu);
    }

    // Fermer au clic en dehors du menu
    document.addEventListener('click', function (e) {
        if (!userMenuWrapper) return;
        if (!userMenuWrapper.contains(e.target)) {
            closeUserMenu();
        }
    });

    // Fermer avec la touche Échap
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeUserMenu();
        }
    });
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

    setInterval(verifierNotifications, 15000);
    verifierNotifications();
})();
</script>

<style>
.object-cover {
    object-fit: cover;
}

/* ============================================
   BOUTON UTILISATEUR (logo + nom) DANS LE HEADER
   ============================================ */
.header-user-btn {
    background: transparent;
    border: 1px solid transparent;
    padding: 4px 10px 4px 4px;
    cursor: pointer;
    transition: background 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}
.header-user-btn:hover {
    background: #f9fafb;
    border-color: #e5e7eb;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.header-user-btn:hover .header-logo,
.header-user-btn:hover .header-logo-fallback {
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35);
    border-color: #3b82f6;
}

.header-user-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.header-user-name {
    font-size: 0.9rem;
    font-weight: 600;
    color: #374151;
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.header-user-chevron {
    font-size: 0.7rem;
    color: #9ca3af;
    transition: transform 0.18s ease, color 0.18s ease;
}

.header-user-btn[aria-expanded="true"] .header-user-chevron {
    transform: rotate(180deg);
    color: #3b82f6;
}

.header-logo {
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
}
.header-logo-fallback {
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
}

/* ============================================
   MENU FLOTTANT UTILISATEUR
   ============================================ */
.user-dropdown {
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    min-width: 300px;
    max-width: 340px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.12), 0 5px 15px rgba(0, 0, 0, 0.06);
    padding: 8px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-8px);
    transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
    z-index: 1000;
}

.user-dropdown.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

/* Petite flèche au-dessus du menu */
.user-dropdown::before {
    content: "";
    position: absolute;
    top: -7px;
    right: 22px;
    width: 14px;
    height: 14px;
    background: #ffffff;
    border-left: 1px solid #e5e7eb;
    border-top: 1px solid #e5e7eb;
    transform: rotate(45deg);
}

/* ---------- En-tête du menu : avatar + infos ---------- */
.user-dropdown-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 14px 12px;
}

.user-dropdown-avatar {
    width: 48px;
    height: 48px;
    flex-shrink: 0;
    border-radius: 50%;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}

.user-dropdown-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
    border: 1px solid #e5e7eb;
}

.user-dropdown-avatar-fallback {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #ffffff;
    font-weight: 700;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.user-dropdown-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.user-dropdown-name {
    font-size: 0.95rem;
    font-weight: 600;
    color: #111827;
    line-height: 1.2;
}

.user-dropdown-company {
    font-size: 0.8rem;
    color: #6b7280;
    line-height: 1.2;
}

.user-dropdown-badge {
    display: inline-block;
    align-self: flex-start;
    margin-top: 4px;
    background: #ede9fe;
    color: #6d28d9;
    font-size: 0.65rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 999px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

/* ---------- Séparateur ---------- */
.user-dropdown-divider {
    height: 1px;
    background: #f3f4f6;
    margin: 6px 4px;
}

/* ---------- Items ---------- */
.user-dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 14px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 500;
    color: #374151;
    text-decoration: none;
    transition: background 0.15s ease, color 0.15s ease;
}

.user-dropdown-item:hover {
    background: #f3f4f6;
    color: #111827;
}

.user-dropdown-item-icon {
    width: 18px;
    text-align: center;
    font-size: 0.95rem;
    color: #9ca3af;
    transition: color 0.15s ease;
}

.user-dropdown-item:hover .user-dropdown-item-icon {
    color: #3b82f6;
}

.user-dropdown-item-danger {
    color: #dc2626;
}

.user-dropdown-item-danger .user-dropdown-item-icon {
    color: #f87171;
}

.user-dropdown-item-danger:hover {
    background: #fef2f2;
    color: #b91c1c;
}

.user-dropdown-item-danger:hover .user-dropdown-item-icon {
    color: #dc2626;
}

/* ============================================
   TOAST NOTIFICATIONS
   ============================================ */
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