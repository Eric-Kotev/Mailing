<?php
// Fichier principal du BackOffice
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/admin.php';
require_once 'config.php';

// Démarrer la session si ce n'est pas déjà fait
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();

$page = $_GET['page'] ?? 'dashboard';

// ============================================
// GESTION DES RÔLES
// ============================================
$userRole = $_SESSION['user_role'] ?? 'user';
$isAdmin = ($userRole === 'admin');
$isClient = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'client';

// Pages autorisées pour les utilisateurs normaux (Manager, User, Client)
$allowedPages = [
    'dashboard',
    'contacts/index', 'contacts/get_contact', 'contacts/supprimer', 'contacts/unblacklist',
    'listes/index', 'listes/details', 'listes/supprimer',
    'campagnes/index', 'campagnes/choix','campagnes/choix_type','campagnes/composer_whatsapp','campagnes/composer_email','campagnes/composer_sms','campagnes/configurer_message','campagnes/choix_provider_sms','campagnes/choix_appareil_sms','campagnes/choix_provider_whatsapp','campagnes/choix_session_whatsapp','campagnes/config_whatsapp','campagnes/nouvelle','campagnes/envoyer_whatsapp','campagnes/envoyer_sms','campagnes/historique','campagnes/creer','campagnes/index','campagnes/details',
    'canaux/index', 'canaux/ajouter', 'canaux/supprimer',
    'blacklist/index',
    'parametres/compte', 'parametres/credits',
];

// Pages ADMIN seulement
$adminPages = [
    'admin/users', 
    'admin/users/edit', 
    'admin/users/add_credits', 
    'admin/custom_fields',
    'admin/dashboard',
    'admin/operators',
    'admin/clients',
    'admin/client-detail',
    'admin/comptes',
];

// Pages CLIENT seulement (accessible via le menu utilisateur)
$clientPages = [
    'client/dashboard',
    'client/campagnes',
    'client/credits',
    'client/profile',
    'client/details',
];

// Si l'utilisateur est admin, ajouter les pages admin aux pages autorisées
if ($isAdmin) {
    $allowedPages = array_merge($allowedPages, $adminPages);
}

// Si l'utilisateur est client, ajouter les pages client
if ($isClient) {
    $allowedPages = array_merge($allowedPages, $clientPages);
}

// Vérifier si la page demandée est autorisée
if (!in_array($page, $allowedPages)) {
    if ($isAdmin) {
        $page = 'admin/dashboard';
    } elseif ($isClient) {
        $page = 'client/dashboard';
    } else {
        $page = 'dashboard';
    }
}

// Rediriger les admins vers le dashboard admin par défaut
if ($isAdmin && $page === 'dashboard' && file_exists('pages/admin/dashboard.php')) {
    $page = 'admin/dashboard';
}

// Rediriger les clients vers le dashboard client par défaut
if ($isClient && $page === 'dashboard' && file_exists('pages/client/dashboard.php')) {
    $page = 'client/dashboard';
}

// Mise à jour des crédits en session
$userCredits = getCreditsDisponibles($_SESSION['user_id']);
$_SESSION['user_credits'] = $userCredits;

// ============================================
// TRAITEMENT DES PAGES QUI FONT DES REDIRECTIONS
// ============================================
ob_start();

// Déterminer le chemin du fichier
if (strpos($page, 'admin/') === 0 && $isAdmin) {
    // Pages admin
    $filePath = 'pages/' . $page . '.php';
    if (!file_exists($filePath)) {
        $filePath = 'admin/' . str_replace('admin/', '', $page) . '.php';
    }
} elseif (strpos($page, 'client/') === 0 && $isClient) {
    // Pages client
    $filePath = 'pages/' . $page . '.php';
    if (!file_exists($filePath)) {
        $filePath = 'pages/client/dashboard.php';
    }
} else {
    // Pages normales
    $filePath = 'pages/' . $page . '.php';
}

if (file_exists($filePath)) {
    include $filePath;
} else {
    echo "<div class='bg-red-100 text-red-700 p-4 rounded'>Page non trouvée : " . htmlspecialchars($page) . "</div>";
}

// Si la page a fait une redirection, le buffer est vide et on ne doit pas afficher le layout
$pageContent = ob_get_clean();

// Si la page a envoyé une redirection, on ne continue pas
if (headers_sent()) {
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - <?= $isAdmin ? 'Administration' : 'BackOffice' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .sidebar-transition {
            transition: all 0.3s ease;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen">
        <!-- SIDEBAR (Menu de gauche) -->
        <?php 
        //  2 MENUS UNIQUEMENT :
        // - Admin → menu_admin.php
        // - Tous les autres (Manager, User, Client) → menu.php
        if ($isAdmin) {
            include 'includes/menu_admin.php';
        } else {
            include 'includes/menu.php';
        }
        ?>
        
        <!-- CONTENU PRINCIPAL -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- HEADER -->
            <?php include 'includes/header.php'; ?>
            
            <!-- CONTENU -->
            <main class="flex-1 overflow-y-auto p-6">
                <?= $pageContent ?>
            </main>
        </div>
    </div>
</body>
</html>