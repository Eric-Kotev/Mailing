<?php
$currentPage = $_GET['page'] ?? 'dashboard';

// Vérifier si l'utilisateur est admin
function isAdminForMenu()
{
    global $db;

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    try {
        if (function_exists('isAdmin')) {
            return isAdmin();
        }

        $user = $db->select('compte', ['id_compte' => $_SESSION['user_id']], 'role');
        return ($user && $user[0]['role'] === 'admin');
    } catch (Exception $e) {
        return false;
    }
}

$isAdmin = isAdminForMenu();
?>

<aside
    id="sidebar"
    class="w-64 min-h-screen bg-slate-900 text-white flex-shrink-0 transition-all duration-300 shadow-2xl border-r border-slate-800 overflow-y-auto"
>

    <!-- Logo / Header -->
    <div class="p-6 border-b border-slate-800">
        <div class="flex justify-center">
            <div class="w-14 h-14 bg-blue-600 rounded-2xl flex items-center justify-center shadow-lg">
                <i class="fas fa-paper-plane text-xl"></i>
            </div>
        </div>

        <h2 id="logoText" class="text-lg font-bold text-center mt-4 transition-opacity duration-200">
            <?= APP_NAME ?>
        </h2>

        <p id="sousTitre" class="text-xs text-slate-400 text-center mt-1 transition-opacity duration-200">
            Plateforme Multi-canal
        </p>
    </div>

    <nav class="py-4">

        <!-- Dashboard -->
        <a href="index.php?page=dashboard"
           class="mx-3 mb-1 flex items-center px-4 py-3 rounded-xl transition-all duration-200
           <?= $currentPage == 'dashboard'
                ? 'bg-blue-600 text-white shadow-lg'
                : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
            <i class="fas fa-tachometer-alt w-5 mr-3 text-blue-400"></i>
            <span class="menu-text">Tableau de bord</span>
        </a>

        <!-- Campagnes -->
        <div class="px-5 pt-5 pb-2 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">
            <span class="menu-title">Campagnes</span>
        </div>

        <a href="index.php?page=campagnes/historique"
           class="mx-3 mb-1 flex items-center px-4 py-3 rounded-xl transition-all duration-200
           <?= $currentPage == 'campagnes/index' || $currentPage == 'campagnes/historique'
                ? 'bg-blue-600 text-white shadow-lg'
                : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
            <i class="fas fa-history w-5 mr-3 text-cyan-400"></i>
            <span class="menu-text">Historique</span>
        </a>

        <a href="index.php?page=campagnes/creer"
           class="mx-3 mb-1 flex items-center px-4 py-3 rounded-xl transition-all duration-200
           <?= $currentPage == 'campagnes/nouvelle' || $currentPage == 'campagnes/choix'
                ? 'bg-blue-600 text-white shadow-lg'
                : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
            <i class="fas fa-plus-circle w-5 mr-3 text-green-400"></i>
            <span class="menu-text">Mes campagnes</span>
        </a>

        <!-- Contacts -->
        <div class="px-5 pt-5 pb-2 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">
            <span class="menu-title">Contacts</span>
        </div>

        <a href="index.php?page=contacts/index"
           class="mx-3 mb-1 flex items-center px-4 py-3 rounded-xl transition-all duration-200
           <?= $currentPage == 'contacts/index'
                ? 'bg-blue-600 text-white shadow-lg'
                : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
            <i class="fas fa-address-book w-5 mr-3 text-orange-400"></i>
            <span class="menu-text">Mes contacts</span>
        </a>

        <a href="index.php?page=listes/index"
           class="mx-3 mb-1 flex items-center px-4 py-3 rounded-xl transition-all duration-200
           <?= $currentPage == 'listes/index'
                ? 'bg-blue-600 text-white shadow-lg'
                : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
            <i class="fas fa-list w-5 mr-3 text-purple-400"></i>
            <span class="menu-text">Mes listes</span>
        </a>

        <a href="index.php?page=blacklist/index"
           class="mx-3 mb-1 flex items-center px-4 py-3 rounded-xl transition-all duration-200
           <?= $currentPage == 'blacklist/index'
                ? 'bg-blue-600 text-white shadow-lg'
                : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
            <i class="fas fa-ban w-5 mr-3 text-red-400"></i>
            <span class="menu-text">Blacklist</span>
        </a>

        <!-- Configuration -->
        <div class="px-5 pt-5 pb-2 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">
            <span class="menu-title">Configuration</span>
        </div>

       <!-- <a href="index.php?page=canaux/index"
           class="mx-3 mb-1 flex items-center px-4 py-3 rounded-xl transition-all duration-200
           <?= $currentPage == 'canaux/index'
                ? 'bg-blue-600 text-white shadow-lg'
                : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
            <i class="fas fa-plug w-5 mr-3 text-yellow-400"></i>
            <span class="menu-text">Canaux d'envoi</span>
        </a>-->

        <!--<a href="index.php?page=parametres/credits"
           class="mx-3 mb-1 flex items-center px-4 py-3 rounded-xl transition-all duration-200
           <?= $currentPage == 'parametres/credits'
                ? 'bg-blue-600 text-white shadow-lg'
                : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>"
            <i class="fas fa-coins w-5 mr-3 text-amber-400"></i>
            <span class="menu-text">Commander crédits</span>
        </a> -->

        <a href="index.php?page=parametres/compte"
           class="mx-3 mb-1 flex items-center px-4 py-3 rounded-xl transition-all duration-200
           <?= $currentPage == 'parametres/compte'
                ? 'bg-blue-600 text-white shadow-lg'
                : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
            <i class="fas fa-cog w-5 mr-3 text-slate-400"></i>
            <span class="menu-text">Paramétrage</span>
        </a>

        <!-- Administration -->
        <?php if ($isAdmin): ?>
            <div class="px-5 pt-5 pb-2 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">
                <span class="menu-title">Administration</span>
            </div>

            <a href="index.php?page=admin/users"
            class="mx-3 mb-1 flex items-center px-4 py-3 rounded-xl transition-all duration-200
            <?= $currentPage == 'admin/users'
                    ? 'bg-blue-600 text-white shadow-lg'
                    : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
                <i class="fas fa-users-cog w-5 mr-3 text-indigo-400"></i>
                <span class="menu-text">Gestion des comptes</span>
            </a>

        <?php endif; ?>
    </nav>

    <!-- Footer -->
    <div class="mt-auto border-t border-slate-800 p-4 bg-slate-950">
        <div class="text-center">
            <div class="text-xs text-slate-400">
                <i class="fas fa-shield-alt mr-1"></i>
                <span class="menu-text">Version 1.0</span>
            </div>
        </div>
    </div>

</aside>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            // Basculer la largeur du sidebar
            sidebar.classList.toggle('w-64');
            sidebar.classList.toggle('w-20');
            
            // Cacher/montrer tous les textes (menus et titres)
            const allTexts = sidebar.querySelectorAll('.menu-text, .menu-title, #logoText, #sousTitre');
            
            if (sidebar.classList.contains('w-20')) {
                allTexts.forEach(text => text.classList.add('hidden'));
                // Réduire les padding dans le header
                sidebar.querySelector('.p-6').classList.add('p-3');
                sidebar.querySelector('.p-6').classList.remove('p-6');
            } else {
                allTexts.forEach(text => text.classList.remove('hidden'));
                // Restaurer les padding
                sidebar.querySelector('.p-3').classList.add('p-6');
                sidebar.querySelector('.p-3').classList.remove('p-3');
            }
        });
    }
});
</script>

<style>
::-webkit-scrollbar {
    width: 6px;
}

::-webkit-scrollbar-track {
    background: #0f172a;
}

::-webkit-scrollbar-thumb {
    background: #475569;
    border-radius: 20px;
}

::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}

/* Transition fluide pour le texte */
.menu-text, .menu-title, #logoText, #sousTitre {
    transition: opacity 0.2s ease, visibility 0.2s ease;
}

/* Style du sidebar en mode réduit */
#sidebar.w-20 .menu-title {
    display: none;
}

#sidebar.w-20 a span {
    display: none;
}

#sidebar.w-20 .p-6 {
    padding: 0.75rem !important;
}

#sidebar.w-20 .w-14 {
    width: 2.5rem;
    height: 2.5rem;
}

#sidebar.w-20 .w-14 i {
    font-size: 0.875rem;
}

#sidebar.w-20 .mx-3 {
    margin-left: 0.5rem;
    margin-right: 0.5rem;
}

#sidebar.w-20 .px-4 {
    padding-left: 0.5rem;
    padding-right: 0.5rem;
    justify-content: center;
}

#sidebar.w-20 .mr-3 {
    margin-right: 0;
}

#sidebar.w-20 i {
    margin-right: 0 !important;
}

#sidebar.w-20 .px-5 {
    padding-left: 0.5rem;
    padding-right: 0.5rem;
    text-align: center;
}

#sidebar.w-20 .py-3 {
    justify-content: center;
}
</style>