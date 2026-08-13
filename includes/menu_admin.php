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
    class="w-64 bg-gray-800 text-white flex flex-col sidebar-transition transition-all duration-300"
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
            <span class="menu-text">Comptes</span>
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

        <!-- Parametrage du compte-->
        <a href="index.php?page=parametres/compte"
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= strpos($currentPage, 'parametres/compte') === 0 ? 'bg-gray-700' : 'hover:bg-gray-700' ?>">
            <i class="fas fa-cog w-5"></i>
            <span class="menu-text">Paramétrage</span>
        </a>

        <hr class="border-gray-700 my-3">

        <a href="logout.php" 
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition text-red-400 hover:bg-red-900/20">
            <i class="fas fa-sign-out-alt w-5"></i>
            <span class="menu-text">Déconnexion</span>
        </a>
    </nav>
    
    <!-- ============================================ -->
    <!-- FOOTER AVEC AVATAR DE L'UTILISATEUR CONNECTÉ -->
    <!-- ============================================ -->
    <div id="userFooter" class="p-4 border-t border-gray-700 flex-shrink-0">
        <div class="flex items-center gap-3">
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
        
        // Cacher les textes du menu
        const allTexts = sidebar.querySelectorAll('.menu-text, #logoText, #sousTitre');
        allTexts.forEach(text => text.classList.add('hidden'));
        
        // Cacher les informations utilisateur (sauf l'avatar)
        const userInfoContainer = sidebar.querySelector('.user-info-container');
        if (userInfoContainer) {
            userInfoContainer.style.display = 'none';
        }
        
        // Réduire les paddings
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
        
        // Centrer l'avatar dans le footer
        const footerContent = sidebar.querySelector('#userFooter .flex');
        if (footerContent) {
            footerContent.classList.add('justify-center');
            footerContent.classList.remove('gap-3');
        }
        
        // Réduire la taille de l'avatar
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
                // Agrandir
                sidebar.classList.remove('w-20');
                sidebar.classList.add('w-64');
                this.querySelector('i').className = 'fas fa-chevron-left text-sm';
                
                // Afficher les textes du menu
                const allTexts = sidebar.querySelectorAll('.menu-text, #logoText, #sousTitre');
                allTexts.forEach(text => text.classList.remove('hidden'));
                
                // Afficher les informations utilisateur
                const userInfoContainer = sidebar.querySelector('.user-info-container');
                if (userInfoContainer) {
                    userInfoContainer.style.display = 'block';
                }
                
                // Restaurer les paddings
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
                
                // Remettre l'avatar à gauche
                const footerContent = sidebar.querySelector('#userFooter .flex');
                if (footerContent) {
                    footerContent.classList.remove('justify-center');
                    footerContent.classList.add('gap-3');
                }
                
                // Restaurer la taille de l'avatar
                const avatar = sidebar.querySelector('#userFooter .w-8.h-8');
                if (avatar) {
                    avatar.classList.remove('w-8', 'h-8');
                    avatar.classList.add('w-10', 'h-10');
                }
                
                localStorage.setItem('admin_sidebar_collapsed', 'false');
            } else {
                // Rétrécir
                sidebar.classList.add('w-20');
                sidebar.classList.remove('w-64');
                this.querySelector('i').className = 'fas fa-chevron-right text-sm';
                
                // Cacher les textes du menu
                const allTexts = sidebar.querySelectorAll('.menu-text, #logoText, #sousTitre');
                allTexts.forEach(text => text.classList.add('hidden'));
                
                // Cacher les informations utilisateur (sauf l'avatar)
                const userInfoContainer = sidebar.querySelector('.user-info-container');
                if (userInfoContainer) {
                    userInfoContainer.style.display = 'none';
                }
                
                // Réduire les paddings
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
                
                // Centrer l'avatar dans le footer
                const footerContent = sidebar.querySelector('#userFooter .flex');
                if (footerContent) {
                    footerContent.classList.add('justify-center');
                    footerContent.classList.remove('gap-3');
                }
                
                // Réduire la taille de l'avatar
                const avatar = sidebar.querySelector('#userFooter .w-10.h-10');
                if (avatar) {
                    avatar.classList.remove('w-10', 'h-10');
                    avatar.classList.add('w-8', 'h-8');
                }
                
                localStorage.setItem('admin_sidebar_collapsed', 'true');
            }
        });
    }
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
</style>