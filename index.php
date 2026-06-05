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

// Pages autorisées
$allowedPages = [
    'dashboard',
    'contacts/index', 'contacts/ajouter', 'contacts/modifier', 'contacts/supprimer', 'contacts/import', 'contacts/unblacklist',
    'listes/index', 'listes/ajouter', 'listes/details', 'listes/supprimer',
    'campagnes/index', 'campagnes/choix','campagnes/config_whatsapp','campagnes/nouvelle','campagnes/envoyer_whatsapp',
    'canaux/index', 'canaux/ajouter', 'canaux/supprimer',
    'blacklist/index',
    'parametres/compte', 'parametres/credits',
    'admin/users', 'admin/users/edit', 'admin/users/add_credits',
];

if (!in_array($page, $allowedPages)) {
    $page = 'dashboard';
}

// Mise à jour des crédits en session
$userCredits = getCreditsDisponibles($_SESSION['user_id']);
$_SESSION['user_credits'] = $userCredits;

// ============================================
// TRAITEMENT DES PAGES QUI FONT DES REDIRECTIONS
// ============================================
// Pour les pages comme ajouter.php, supprimer.php, etc.
// On inclut la page MAIS on capture son contenu
// Si elle fait une redirection (header), on arrête tout

ob_start();

$filePath = 'pages/' . $page . '.php';
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

// Si le contenu est vide et qu'il n'y a pas de redirection, on affiche quand même
// (c'est le cas pour les pages qui ne font que du HTML)
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - BackOffice</title>
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
        <?php include 'includes/menu.php'; ?>
        
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