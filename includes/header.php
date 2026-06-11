<?php
// En-tête du BackOffice
global $db;

// Mettre à jour les crédits en session
$credits = getCreditsDisponibles($_SESSION['user_id']);
$_SESSION['user_credits'] = $credits;
?>

<header class="bg-white shadow-sm">
    <div class="flex justify-between items-center px-6 py-3">
        <div class="flex items-center">
            <button id="sidebarToggle" class="text-gray-500 hover:text-gray-700 mr-4">
                <i class="fas fa-bars text-xl"></i>
            </button>
        </div>
        
        <div class="flex items-center space-x-4">
            <!-- Crédits -->
            <div class="bg-green-100 text-green-800 px-3 py-1 rounded-full">
                <i class="fas fa-coins mr-1"></i>
                <?= number_format($credits, 2) ?> €
            </div>
            
            <!-- Info utilisateur -->
            <div class="text-right">
                <div class="text-sm font-semibold text-gray-800">
                    <?= htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur') ?>
                </div>
                <div class="text-xs text-gray-500">
                    <?= htmlspecialchars($_SESSION['user_entreprise'] ?? '') ?>
                </div>
            </div>
            
            <!-- Avatar -->
            <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold shadow-sm">
                <?= strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1)) ?>
            </div>
            
            <!-- Déconnexion -->
            <a href="logout.php" class="text-gray-500 hover:text-red-600 transition" title="Déconnexion">
                <i class="fas fa-sign-out-alt text-xl"></i>
            </a>
        </div>
    </div>
</header>