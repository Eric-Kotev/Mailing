<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// Vérifier que l'utilisateur est connecté
requireLogin();

global $db;

$id_contact = $_POST['id_contact'] ?? null;

if ($id_contact) {
    try {
        // Supprimer toutes les entrées de blacklist pour ce contact
        $db->deleteWithConditions('blacklist', ['id_contact' => $id_contact]);
        $_SESSION['flash_message'] = "Contact retiré de la blacklist avec succès !";
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Erreur lors du retrait de la blacklist : " . $e->getMessage();
    }
} else {
    $_SESSION['flash_error'] = "ID de contact invalide.";
}

header('Location: index.php?page=contacts/index');
exit;
?>