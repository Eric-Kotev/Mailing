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

// Si on a le prénom et nom séparément, on les utilise, sinon on utilise user_name
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
?>

<header class="bg-white shadow-sm">
    <div class="flex justify-between items-center px-6 py-3">
        <div class="flex items-center">
            <button id="sidebarToggle" class="text-gray-500 hover:text-gray-700 mr-4">
                <i class="fas fa-bars text-xl"></i>
            </button>
        </div>
        
        <div class="flex items-center space-x-4">
            
            <!-- Info utilisateur avec "Bonjour" -->
            <div class="text-right">
                <div class="text-xl font-semibold text-gray-800">
                    Bonjour, <?= htmlspecialchars($displayName) ?>
                </div>
                <div class="text-xs text-gray-500">
                    <?= htmlspecialchars($_SESSION['user_entreprise'] ?? '') ?>
                </div>
            </div>
            
            <!-- Crédits -->
            <div class="bg-green-100 text-green-800 px-3 py-1 rounded-full">
                <i class="fas fa-coins mr-1"></i>
                <?= number_format($credits, 2) ?> €
            </div>

            <!-- Avatar / Logo -->
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
            
            <!-- Déconnexion -->
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
</style>