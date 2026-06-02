<?php
// Démarrer la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Désactiver l'affichage des erreurs temporairement pour la redirection
error_reporting(0);

global $db;

$id = $_GET['id'] ?? null;

if ($id) {
    try {
        // Vérifier que le contact appartient bien à l'utilisateur
        $contacts = $db->select('contact', ['id_contact' => $id, 'id_compte' => $_SESSION['user_id']]);
        if ($contacts) {
            $db->delete('contact', $id, 'id_contact');
            $_SESSION['flash_message'] = "Contact supprimé avec succès";
        } else {
            $_SESSION['flash_error'] = "Contact non trouvé";
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Erreur lors de la suppression : " . $e->getMessage();
    }
}

// Redirection propre
header('Location: index.php?page=contacts/index');
exit;
?>