<?php
// Menu pour les administrateurs
$currentPage = $_GET['page'] ?? 'dashboard';
?>

<aside
    id="sidebar"
    class="w-64 bg-gray-800 text-white flex flex-col sidebar-transition transition-all duration-300"
>
    <div class="p-4 border-b border-gray-700 flex-shrink-0">
        <h1 id="logoText" class="text-xl font-bold text-center transition-opacity duration-200"><?= APP_NAME ?></h1>
        <p id="sousTitre" class="text-xs text-gray-400 text-center mt-1 transition-opacity duration-200">Menu administrateur</p>
    </div>
    
    <nav class="flex-1 p-4 space-y-1 overflow-y-auto">
        <!-- Dashboard Admin -->
        <a href="?page=admin/dashboard" 
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'admin/dashboard' ? 'bg-gray-700' : 'hover:bg-gray-700' ?>">
            <i class="fas fa-chart-pie w-5"></i>
            <span class="menu-text">Dashboard Admin</span>
        </a>
        <!-- Parametrage du compte-->
        <a href="index.php?page=parametres/compte"
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= strpos($currentPage, 'parametres/compte') === 0 ? 'bg-gray-700' : 'hover:bg-gray-700' ?>">
            <i class="fas fa-cog w-5"></i>
            <span class="menu-text">Paramétrage</span>
        </a>

        <!-- Gestion des comptes -->
        <a href="?page=admin/users" 
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= strpos($currentPage, 'admin/users') === 0 ? 'bg-gray-700' : 'hover:bg-gray-700' ?>">
            <i class="fas fa-users w-5"></i>
            <span class="menu-text">Comptes</span>
        </a>

        <!-- Gestion des clients -->
        <a href="?page=admin/clients" 
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= strpos($currentPage, 'admin/clients') === 0 ? 'bg-gray-700' : 'hover:bg-gray-700' ?>">
            <i class="fas fa-user-friends w-5"></i>
            <span class="menu-text">Clients</span>
        </a>

        <!-- Gestion des opérateurs -->
        <a href="?page=admin/operators" 
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'admin/operators' ? 'bg-gray-700' : 'hover:bg-gray-700' ?>">
            <i class="fas fa-server w-5"></i>
            <span class="menu-text">Opérateurs</span>
        </a>

        <hr class="border-gray-700 my-3">

        <a href="logout.php" 
           class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition text-red-400 hover:bg-red-900/20">
            <i class="fas fa-sign-out-alt w-5"></i>
            <span class="menu-text">Déconnexion</span>
        </a>
    </nav>
    
    <div class="p-4 border-t border-gray-700 text-xs text-gray-400 flex-shrink-0">
        <span class="text-white font-medium">Administrateur</span>
        <div class="mt-1"><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></div>
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
        toggleBtn.querySelector('i').className = 'fas fa-chevron-right text-sm';
        
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
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            const isCurrentlyCollapsed = sidebar.classList.contains('w-20');
            
            if (isCurrentlyCollapsed) {
                // Agrandir
                sidebar.classList.remove('w-20');
                sidebar.classList.add('w-64');
                this.querySelector('i').className = 'fas fa-chevron-left text-sm';
                
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
                
                localStorage.setItem('admin_sidebar_collapsed', 'false');
            } else {
                // Rétrécir
                sidebar.classList.add('w-20');
                sidebar.classList.remove('w-64');
                this.querySelector('i').className = 'fas fa-chevron-right text-sm';
                
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
                
                localStorage.setItem('admin_sidebar_collapsed', 'true');
            }
        });
    }
});
</script>

<style>
/* Transition fluide pour le texte */
.menu-text, #logoText, #sousTitre {
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
</style>