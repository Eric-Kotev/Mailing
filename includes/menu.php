<?php
$currentPage = $_GET['page'] ?? 'dashboard';

// Vérifier si l'utilisateur est admin (fonction intégrée)
function isAdminForMenu() {
    global $db;
    
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    try {
        // On évite d'utiliser $db directement si non défini, on utilise la fonction globale
        if (function_exists('isAdmin')) {
            return isAdmin();
        }
        // Fallback
        $user = $db->select('compte', ['id_compte' => $_SESSION['user_id']], 'role');
        return ($user && $user[0]['role'] === 'admin');
    } catch (Exception $e) {
        return false;
    }
}

$isAdmin = isAdminForMenu();
?>

<aside class="w-64 bg-gray-800 text-white flex-shrink-0 transition-all duration-300" id="sidebar">
    <div class="p-4 border-b border-gray-700">
        <h2 class="text-xl font-bold text-center"><?= APP_NAME ?></h2>
        <p class="text-xs text-gray-400 text-center mt-1">Multi-canal</p>
    </div>
    
    <nav class="mt-4">
        <!-- Dashboard -->
        <a href="index.php?page=dashboard" 
           class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition <?= $currentPage == 'dashboard' ? 'bg-gray-700 text-white' : '' ?>">
            <i class="fas fa-tachometer-alt w-5 mr-3"></i>
            <span>Tableau de bord</span>
        </a>
        
        <!-- Section Campagnes -->
        <div class="px-4 py-2 text-xs text-gray-500 uppercase mt-2">Campagnes</div>
        
        <a href="index.php?page=campagnes/index" 
           class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition <?= $currentPage == 'campagnes/index' ? 'bg-gray-700 text-white' : '' ?>">
            <i class="fas fa-history w-5 mr-3"></i>
            <span>Historique</span>
        </a>
        
        <a href="index.php?page=campagnes/nouvelle" 
           class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition <?= $currentPage == 'campagnes/nouvelle' ? 'bg-gray-700 text-white' : '' ?>">
            <i class="fas fa-plus-circle w-5 mr-3"></i>
            <span>Nouvelle campagne</span>
        </a>
        
        <!-- Section Contacts -->
        <div class="px-4 py-2 text-xs text-gray-500 uppercase mt-2">Contacts</div>
        
        <a href="index.php?page=contacts/index" 
           class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition <?= $currentPage == 'contacts/index' ? 'bg-gray-700 text-white' : '' ?>">
            <i class="fas fa-address-book w-5 mr-3"></i>
            <span>Mes contacts</span>
        </a>
        
        <!-- <a href="index.php?page=contacts/import" 
           class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition <?= $currentPage == 'contacts/import' ? 'bg-gray-700 text-white' : '' ?>">
            <i class="fas fa-upload w-5 mr-3"></i>
            <span>Importer CSV</span>
        </a>-->
        
        <a href="index.php?page=listes/index" 
           class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition <?= $currentPage == 'listes/index' ? 'bg-gray-700 text-white' : '' ?>">
            <i class="fas fa-list w-5 mr-3"></i>
            <span>Mes listes</span>
        </a>
        
        <a href="index.php?page=blacklist/index" 
           class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition <?= $currentPage == 'blacklist/index' ? 'bg-gray-700 text-white' : '' ?>">
            <i class="fas fa-ban w-5 mr-3"></i>
            <span>Blacklist</span>
        </a>
        
        <!-- Section Configuration -->
        <div class="px-4 py-2 text-xs text-gray-500 uppercase mt-2">Configuration</div>
        
        <a href="index.php?page=canaux/index" 
           class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition <?= $currentPage == 'canaux/index' ? 'bg-gray-700 text-white' : '' ?>">
            <i class="fas fa-plug w-5 mr-3"></i>
            <span>Canaux d'envoi</span>
        </a>
        
        <a href="index.php?page=parametres/credits" 
           class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition <?= $currentPage == 'parametres/credits' ? 'bg-gray-700 text-white' : '' ?>">
            <i class="fas fa-coins w-5 mr-3"></i>
            <span>Commander crédits</span>
        </a>
        
        <a href="index.php?page=parametres/compte" 
           class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition <?= $currentPage == 'parametres/compte' ? 'bg-gray-700 text-white' : '' ?>">
            <i class="fas fa-cog w-5 mr-3"></i>
            <span>Paramétrage</span>
        </a>
        
        <!-- Section Administration (visible seulement pour les admins) -->
        <?php if ($isAdmin): ?>
            <div class="px-4 py-2 text-xs text-gray-500 uppercase mt-2">Administration</div>
            
            <a href="index.php?page=admin/users" 
               class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition <?= $currentPage == 'admin/users' ? 'bg-gray-700 text-white' : '' ?>">
                <i class="fas fa-users-cog w-5 mr-3"></i>
                <span>Gestion des utilisateurs</span>
            </a>
        <?php endif; ?>
    </nav>
    
    <!-- Footer du menu -->
    <div class="absolute bottom-0 w-64 p-4 border-t border-gray-700">
        <div class="text-xs text-gray-400 text-center">
            <i class="fas fa-shield-alt mr-1"></i> Version 1.0
        </div>
    </div>
</aside>

<script>
// Script pour replier/fermer le menu
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('w-64');
            sidebar.classList.toggle('w-16');
            
            // Cacher/montrer les textes
            const spans = sidebar.querySelectorAll('nav span');
            if (sidebar.classList.contains('w-16')) {
                spans.forEach(span => span.classList.add('hidden'));
            } else {
                spans.forEach(span => span.classList.remove('hidden'));
            }
        });
    }
});
</script>