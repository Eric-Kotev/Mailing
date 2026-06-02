<?php
requireAdmin();
global $db;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_credits'])) {
    $id_compte = $_POST['id_compte'];
    $montant = floatval($_POST['montant']);
    
    if ($montant > 0) {
        $compte = $db->select('compte', ['id_compte' => $id_compte], 'credits_total');
        if ($compte) {
            $nouveauxCredits = $compte[0]['credits_total'] + $montant;
            $db->update('compte', ['credits_total' => $nouveauxCredits], ['id_compte' => $id_compte]);
            
            $db->insert('credit', [
                'id_compte' => $id_compte,
                'type_mouvement' => 'CREDIT',
                'montant' => $montant,
                'description' => 'Ajout manuel par administrateur'
            ]);
            
            $_SESSION['flash_message'] = "$montant € ajoutés au compte";
        }
    }
}

header('Location: index.php?page=admin/users');
exit;
?>