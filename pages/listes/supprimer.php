<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $db;

$id = $_GET['id'] ?? null;

if ($id) {
    try {
        // Vérifier que la liste appartient à l'utilisateur
        $listes = $db->select('liste', ['id_liste' => $id, 'id_compte' => $_SESSION['user_id']]);
        if ($listes) {
            // Supprimer d'abord les associations
            $db->deleteWithConditions('liste_contact', ['id_liste' => $id]);
            // Puis supprimer la liste
            $db->delete('liste', $id, 'id_liste');
            $_SESSION['flash_message'] = "Liste supprimée avec succès";
        } else {
            $_SESSION['flash_error'] = "Liste non trouvée";
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Erreur lors de la suppression";
    }
}

ob_clean();
header('Location: index.php?page=listes/index');
exit;
?>