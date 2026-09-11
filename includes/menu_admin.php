<?php
// Menu pour les administrateurs
$currentPage = $_GET['page'] ?? 'dashboard';

// Récupérer les informations de l'utilisateur connecté
$userName = $_SESSION['user_name'] ?? 'Administrateur';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'admin';

// Récupérer le logo de l'utilisateur depuis la base de données
$userLogo = '';
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    global $db;
    $userId = $_SESSION['user_id'];
    $userInfo = $db->select('compte', ['id_compte' => $userId], 'logo_url, prenom, nom');
    if (!empty($userInfo) && !empty($userInfo[0]['logo_url'])) {
        $userLogo = $userInfo[0]['logo_url'];
    }
    // Mettre à jour le nom si disponible
    if (!empty($userInfo)) {
        if (!empty($userInfo[0]['prenom']) && !empty($userInfo[0]['nom'])) {
            $userName = $userInfo[0]['prenom'] . ' ' . $userInfo[0]['nom'];
        }
    }
}

// Récupérer les initiales pour l'avatar par défaut
$initials = '';
if (!empty($userName) && $userName !== 'Administrateur') {
    $nameParts = explode(' ', $userName);
    foreach ($nameParts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    $initials = substr($initials, 0, 2);
} else {
    $initials = 'AD';
}
?>

<aside
    id="sidebar"
    class="w-64 bg-gray-800 text-white flex flex-col sidebar-transition transition-all duration-300 relative"
>
    <div class="flex justify-center mt-3">
        <div class="w-14 h-14 bg-blue-600 rounded-2xl flex items-center justify-center shadow-lg">
            <i class="fas fa-paper-plane text-xl"></i>
        </div>
    </div>

    <div class="p-4 border-b border-gray-700 flex-shrink-0">
        <h1 id="logoText" class="text-xl font-bold text-center transition-opacity duration-200"><?= APP_NAME ?></h1>
        <p id="sousTitre" class="text-xs text-gray-400 text-center mt-1 transition-opacity duration-200">Administrateur</p>
    </div>
    
    <nav class="flex-1 p-4 space-y-1 overflow-y-auto">
        <!-- Dashboard Admin -->
        <a href="?page=admin/dashboard" 
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'admin/dashboard' ? 'bg-gray-700' : 'hover:bg-gray-700' ?>">
            <i class="fas fa-tachometer-alt w-5 mr-3 text-gray-400"></i>
            <span class="menu-text">Dashboard Admin</span>
        </a>

        <!-- Gestion des comptes -->
        <a href="?page=admin/users" 
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= strpos($currentPage, 'admin/users') === 0 ? 'bg-gray-700' : 'hover:bg-gray-700' ?>">
            <i class="fas fa-id-badge w-5 mr-3 text-gray-400"></i>
            <span class="menu-text">Administrateur</span>
        </a>

        <!-- Gestion des clients -->
        <a href="?page=admin/clients" 
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= strpos($currentPage, 'admin/clients') === 0 ? 'bg-gray-700' : 'hover:bg-gray-700' ?>">
            <i class="fas fa-users w-5 mr-3 text-gray-400"></i>
            <span class="menu-text">Clients</span>
        </a>

        <!-- Gestion des opérateurs -->
        <a href="?page=admin/operators" 
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'admin/operators' ? 'bg-gray-700' : 'hover:bg-gray-700' ?>">
            <i class="fas fa-network-wired w-5 mr-3 text-gray-400"></i>
            <span class="menu-text">Opérateurs</span>
        </a>
    </nav>
    
    <!-- ============================================ -->
    <!-- FOOTER AVEC AVATAR DE L'UTILISATEUR CONNECTÉ -->
    <!-- DEVENU BOUTON OUVRANT UN MENU DÉROULANT      -->
    <!-- ============================================ -->
    <div id="userFooterWrapper" class="border-t border-gray-700 flex-shrink-0 relative">
        <button type="button"
                id="userFooter"
                class="w-full p-4 flex items-center gap-3 hover:bg-gray-700/50 transition-colors text-left focus:outline-none focus:bg-gray-700/50"
                title="Mon compte"
                aria-label="Ouvrir le menu utilisateur"
                aria-haspopup="true"
                aria-expanded="false">
            <!-- Avatar avec logo ou initiales -->
            <div class="relative flex-shrink-0">
                <?php if (!empty($userLogo)): ?>
                    <img src="<?= htmlspecialchars($userLogo) . '?t=' . time() ?>" 
                         alt="Avatar <?= htmlspecialchars($userName) ?>"
                         class="w-10 h-10 rounded-full object-cover border-2 border-gray-600"
                         onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold text-sm border-2 border-gray-600" style="display: none;">
                        <?= $initials ?>
                    </div>
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold text-sm border-2 border-gray-600">
                        <?= $initials ?>
                    </div>
                <?php endif; ?>
                
                <!-- Petit indicateur de statut en ligne -->
                <span class="absolute bottom-0 right-0 w-3 h-3 bg-green-500 border-2 border-gray-800 rounded-full"></span>
            </div>
            
            <!-- Informations utilisateur -->
            <div class="flex-1 min-w-0 user-info-container">
                <span class="text-m text-gray-400 truncate block">Connecté en tant que</span>
                <div class="text-sm font-medium text-white truncate" id="userFooterName">
                    <?= htmlspecialchars($userName) ?>
                </div>
                <div class="text-xs text-gray-400 truncate" id="userFooterEmail">
                    <?= htmlspecialchars($userEmail) ?>
                </div>
                <div class="text-xs text-gray-500 mt-0.5">
                    <span class="bg-blue-900/50 text-blue-300 px-2 py-0.5 rounded-full text-[10px]" id="userRoleBadge">
                        <?= htmlspecialchars($userRole) ?>
                    </span>
                </div>
            </div>

            <!-- Chevron indicateur -->
            <i class="fas fa-chevron-up text-gray-400 text-xs user-footer-chevron"></i>
        </button>

        <!-- Menu déroulant (s'ouvre vers le haut) -->
        <div id="userFooterDropdown"
             class="admin-user-dropdown"
             role="menu"
             aria-labelledby="userFooter">

            <!-- En-tête du menu -->
            <div class="admin-user-dropdown-header">
                <div class="admin-user-dropdown-avatar">
                    <?php if (!empty($userLogo)): ?>
                        <img src="<?= htmlspecialchars($userLogo) . '?t=' . time() ?>"
                             alt="Avatar <?= htmlspecialchars($userName) ?>"
                             class="admin-user-dropdown-avatar-img"
                             onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="admin-user-dropdown-avatar-fallback" style="display: none;">
                            <?= $initials ?>
                        </div>
                    <?php else: ?>
                        <div class="admin-user-dropdown-avatar-fallback">
                            <?= $initials ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="admin-user-dropdown-info">
                    <div class="admin-user-dropdown-name truncate"><?= htmlspecialchars($userName) ?></div>
                    <div class="admin-user-dropdown-email truncate"><?= htmlspecialchars($userEmail) ?></div>
                </div>
            </div>

            <div class="admin-user-dropdown-divider"></div>

            <!-- Profil -->
            <a href="index.php?page=parametres/compte"
               class="admin-user-dropdown-item"
               role="menuitem">
                <i class="fas fa-user-cog admin-user-dropdown-item-icon"></i>
                <span>Profil</span>
            </a>

            <!-- Se déconnecter -->
            <a href="logout.php"
               class="admin-user-dropdown-item admin-user-dropdown-item-danger"
               role="menuitem">
                <i class="fas fa-sign-out-alt admin-user-dropdown-item-icon"></i>
                <span>Se déconnecter</span>
            </a>
        </div>
    </div>
</aside>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    
    // Vérifier l'état du sidebar dans localStorage
    const isCollapsed = localStorage.getItem('admin_sidebar_collapsed') === 'true';
    
    if (isCollapsed) {
        sidebar.classList.add('w-20');
        sidebar.classList.remove('w-64');
        if (toggleBtn) {
            toggleBtn.querySelector('i').className = 'fas fa-chevron-right text-sm';
        }
        
        const allTexts = sidebar.querySelectorAll('.menu-text, #logoText, #sousTitre');
        allTexts.forEach(text => text.classList.add('hidden'));
        
        const userInfoContainer = sidebar.querySelector('.user-info-container');
        if (userInfoContainer) {
            userInfoContainer.style.display = 'none';
        }
        
        const header = sidebar.querySelector('.p-4');
        if (header) {
            header.classList.add('p-2');
            header.classList.remove('p-4');
        }
        const footer = sidebar.querySelector('#userFooter');
        if (footer) {
            footer.classList.add('p-2');
            footer.classList.remove('p-4');
        }
        
        const footerContent = sidebar.querySelector('#userFooter .flex');
        if (footerContent) {
            footerContent.classList.add('justify-center');
            footerContent.classList.remove('gap-3');
        }
        
        const avatar = sidebar.querySelector('#userFooter .w-10.h-10');
        if (avatar) {
            avatar.classList.remove('w-10', 'h-10');
            avatar.classList.add('w-8', 'h-8');
        }
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            const isCurrentlyCollapsed = sidebar.classList.contains('w-20');
            
            if (isCurrentlyCollapsed) {
                sidebar.classList.remove('w-20');
                sidebar.classList.add('w-64');
                this.querySelector('i').className = 'fas fa-chevron-left text-sm';
                
                const allTexts = sidebar.querySelectorAll('.menu-text, #logoText, #sousTitre');
                allTexts.forEach(text => text.classList.remove('hidden'));
                
                const userInfoContainer = sidebar.querySelector('.user-info-container');
                if (userInfoContainer) {
                    userInfoContainer.style.display = 'block';
                }
                
                const header = sidebar.querySelector('.p-2');
                if (header) {
                    header.classList.add('p-4');
                    header.classList.remove('p-2');
                }
                const footer = sidebar.querySelector('#userFooter.p-2');
                if (footer) {
                    footer.classList.add('p-4');
                    footer.classList.remove('p-2');
                }
                
                const footerContent = sidebar.querySelector('#userFooter .flex');
                if (footerContent) {
                    footerContent.classList.remove('justify-center');
                    footerContent.classList.add('gap-3');
                }
                
                const avatar = sidebar.querySelector('#userFooter .w-8.h-8');
                if (avatar) {
                    avatar.classList.remove('w-8', 'h-8');
                    avatar.classList.add('w-10', 'h-10');
                }
                
                localStorage.setItem('admin_sidebar_collapsed', 'false');
            } else {
                sidebar.classList.add('w-20');
                sidebar.classList.remove('w-64');
                this.querySelector('i').className = 'fas fa-chevron-right text-sm';
                
                const allTexts = sidebar.querySelectorAll('.menu-text, #logoText, #sousTitre');
                allTexts.forEach(text => text.classList.add('hidden'));
                
                const userInfoContainer = sidebar.querySelector('.user-info-container');
                if (userInfoContainer) {
                    userInfoContainer.style.display = 'none';
                }
                
                const header = sidebar.querySelector('.p-4');
                if (header) {
                    header.classList.add('p-2');
                    header.classList.remove('p-4');
                }
                const footer = sidebar.querySelector('#userFooter.p-4');
                if (footer) {
                    footer.classList.add('p-2');
                    footer.classList.remove('p-4');
                }
                
                const footerContent = sidebar.querySelector('#userFooter .flex');
                if (footerContent) {
                    footerContent.classList.add('justify-center');
                    footerContent.classList.remove('gap-3');
                }
                
                const avatar = sidebar.querySelector('#userFooter .w-10.h-10');
                if (avatar) {
                    avatar.classList.remove('w-10', 'h-10');
                    avatar.classList.add('w-8', 'h-8');
                }
                
                localStorage.setItem('admin_sidebar_collapsed', 'true');
            }
        });
    }

    // ============================================
    // MENU DÉROULANT UTILISATEUR (footer admin)
    // ============================================
    const userFooter = document.getElementById('userFooter');
    const userFooterDropdown = document.getElementById('userFooterDropdown');
    const userFooterWrapper = document.getElementById('userFooterWrapper');

    function openUserDropdown() {
        if (!userFooterDropdown) return;
        userFooterDropdown.classList.add('show');
        if (userFooter) userFooter.setAttribute('aria-expanded', 'true');
    }
    function closeUserDropdown() {
        if (!userFooterDropdown) return;
        userFooterDropdown.classList.remove('show');
        if (userFooter) userFooter.setAttribute('aria-expanded', 'false');
    }
    function toggleUserDropdown(e) {
        e.stopPropagation();
        if (!userFooterDropdown) return;
        if (userFooterDropdown.classList.contains('show')) {
            closeUserDropdown();
        } else {
            openUserDropdown();
        }
    }

    if (userFooter) userFooter.addEventListener('click', toggleUserDropdown);

    document.addEventListener('click', function (e) {
        if (!userFooterWrapper) return;
        if (!userFooterWrapper.contains(e.target)) closeUserDropdown();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeUserDropdown();
    });
});
</script>

<style>
/* Transition fluide pour le texte */
.menu-text, #logoText, #sousTitre, .user-info-container {
    transition: opacity 0.2s ease, visibility 0.2s ease;
}

/* Style du sidebar en mode réduit */
#sidebar.w-20 .menu-text {
    display: none;
}

#sidebar.w-20 .p-4 {
    padding: 0.5rem !important;
}

#sidebar.w-20 .p-2 {
    padding: 0.5rem !important;
}

#sidebar.w-20 .gap-3 {
    gap: 0 !important;
}

#sidebar.w-20 .px-4 {
    padding-left: 0.5rem;
    padding-right: 0.5rem;
    justify-content: center;
}

#sidebar.w-20 .w-5 {
    margin-right: 0 !important;
}

#sidebar.w-20 .flex.items-center {
    justify-content: center;
}

#sidebar.w-20 .px-4.py-1 {
    text-align: center;
}

#sidebar.w-20 hr {
    margin-left: 0.5rem;
    margin-right: 0.5rem;
}

/* Garder l'icône visible */
#sidebar.w-20 i {
    margin-right: 0 !important;
    font-size: 1.1rem;
}

/* Avatar dans le footer */
#userFooter .w-10.h-10 {
    transition: all 0.3s ease;
}

#sidebar.w-20 #userFooter {
    padding: 0.5rem !important;
}

#sidebar.w-20 #userFooter .flex {
    justify-content: center !important;
}

/* Cacher le texte "Connecté en tant que" en mode réduit */
#sidebar.w-20 .user-info-container {
    display: none !important;
}

/* Cacher le chevron en mode réduit */
#sidebar.w-20 .user-footer-chevron {
    display: none !important;
}

/* Bouton de bascule */
#sidebarToggle {
    transition: all 0.3s ease;
}

#sidebarToggle:hover {
    transform: scale(1.05);
}

#sidebarToggle i {
    transition: transform 0.3s ease;
}

/* Badge de rôle */
.bg-blue-900\/50 {
    background-color: rgba(30, 58, 138, 0.5);
}

/* Animation de l'avatar */
#userFooter .w-10.h-10,
#userFooter .w-8.h-8 {
    transition: all 0.3s ease;
}

/* ============================================
   MENU DÉROULANT UTILISATEUR (footer admin)
   ============================================ */
#userFooterWrapper {
    position: relative;
}

#userFooter {
    background: transparent;
    border: none;
    cursor: pointer;
}

#userFooter:hover .user-footer-chevron {
    color: #60a5fa;
}

.user-footer-chevron {
    transition: transform 0.2s ease, color 0.2s ease;
}

#userFooter[aria-expanded="true"] .user-footer-chevron {
    transform: rotate(180deg);
    color: #60a5fa;
}

.admin-user-dropdown {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 12px;
    right: 12px;
    min-width: 240px;
    background: #1f2937;
    border: 1px solid #374151;
    border-radius: 12px;
    box-shadow: 0 -10px 30px rgba(0, 0, 0, 0.45), 0 -4px 12px rgba(0, 0, 0, 0.25);
    padding: 8px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(8px);
    transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
    z-index: 1100;
}

.admin-user-dropdown.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.admin-user-dropdown-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px 8px;
}

.admin-user-dropdown-avatar {
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    border-radius: 50%;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}

.admin-user-dropdown-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
    border: 1px solid #374151;
}

.admin-user-dropdown-avatar-fallback {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #ffffff;
    font-weight: 700;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.admin-user-dropdown-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.admin-user-dropdown-name {
    font-size: 0.85rem;
    font-weight: 600;
    color: #f3f4f6;
    line-height: 1.2;
}

.admin-user-dropdown-email {
    font-size: 0.72rem;
    color: #9ca3af;
    line-height: 1.2;
}

.admin-user-dropdown-divider {
    height: 1px;
    background: #374151;
    margin: 4px 2px;
}

.admin-user-dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 500;
    color: #d1d5db;
    text-decoration: none;
    transition: background 0.15s ease, color 0.15s ease;
}

.admin-user-dropdown-item:hover {
    background: #374151;
    color: #ffffff;
}

.admin-user-dropdown-item-icon {
    width: 16px;
    text-align: center;
    font-size: 0.9rem;
    color: #9ca3af;
    transition: color 0.15s ease;
}

.admin-user-dropdown-item:hover .admin-user-dropdown-item-icon {
    color: #60a5fa;
}

.admin-user-dropdown-item-danger {
    color: #f87171;
}

.admin-user-dropdown-item-danger .admin-user-dropdown-item-icon {
    color: #f87171;
}

.admin-user-dropdown-item-danger:hover {
    background: rgba(239, 68, 68, 0.15);
    color: #fca5a5;
}

.admin-user-dropdown-item-danger:hover .admin-user-dropdown-item-icon {
    color: #fca5a5;
}

/* Mode réduit : élargir le dropdown vers la droite */
#sidebar.w-20 .admin-user-dropdown {
    left: 60px;
    right: auto;
    width: 240px;
    bottom: 12px;
}
</style>