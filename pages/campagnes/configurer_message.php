<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Récupérer les données du formulaire
$campagneConfigId = $_POST['campagne_config_id'] ?? null;
$typeMessage = $_POST['type_message'] ?? null;

if (!$campagneConfigId || !$typeMessage) {
    $_SESSION['flash_error'] = "Données manquantes";
    header('Location: index.php?page=campagnes/index');
    exit;
}

// Vérifier que la campagne appartient à l'utilisateur
$campagneConfig = $db->select('campagne_config', [
    'id_campagne_config' => $campagneConfigId,
    'id_compte' => $idCompte
]);

if (empty($campagneConfig)) {
    $_SESSION['flash_error'] = "Campagne non trouvée";
    header('Location: index.php?page=campagnes/index');
    exit;
}

// Stocker le type de message en session
$_SESSION['type_message'] = $typeMessage;
$_SESSION['campagne_config_id'] = $campagneConfigId;

// Rediriger vers la page de composition correspondante
switch ($typeMessage) {
    case 'sms':
        header('Location: index.php?page=campagnes/composer_sms');
        break;
    case 'whatsapp':
        header('Location: index.php?page=campagnes/composer_whatsapp');
        break;
    case 'email':
        header('Location: index.php?page=campagnes/composer_email');
        break;
    default:
        $_SESSION['flash_error'] = "Type de message non supporté";
        header('Location: index.php?page=campagnes/index');
        break;
}
exit;