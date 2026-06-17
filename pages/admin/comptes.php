<?php
requireAdmin();

global $db;

// Récupérer tous les comptes
$comptes = $db->select('compte', [], '*', 'date_creation=order.desc');

// Activer/Désactiver un compte
if (isset($_GET['toggle'])) {
    $id = $_GET['toggle'];
    $compte = $db->select('compte', ['id_compte' => $id]);
    if ($compte) {
        $newStatus = !$compte[0]['actif'];
        $db->update('compte', $id, 'id_compte', ['actif' => $newStatus]);
        $_SESSION['flash_message'] = "Compte " . ($newStatus ? "activé" : "désactivé");
        header('Location: index.php?page=admin/comptes');
        exit;
    }
}

// Modifier les crédits
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_credits'])) {
    $id_compte = $_POST['id_compte'];
    $montant = floatval($_POST['montant']);
    
    if ($montant > 0) {
        $compte = $db->select('compte', ['id_compte' => $id_compte], 'credits_total');
        if ($compte) {
            $nouveauxCredits = $compte[0]['credits_total'] + $montant;
            $db->update('compte', $id_compte, 'id_compte', ['credits_total' => $nouveauxCredits]);
            
            $db->insert('credit', [
                'id_compte' => $id_compte,
                'type_mouvement' => 'CREDIT',
                'montant' => $montant,
                'description' => 'Ajout manuel par administrateur'
            ]);
            
            $_SESSION['flash_message'] = "$montant € ajoutés au compte";
            header('Location: index.php?page=admin/comptes');
            exit;
        }
    }
}
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Gestion des comptes</h1>
            <p class="text-gray-500">Gérez les comptes, crédits et accès</p>
        </div>
        <a href="index.php?page=admin/comptes/ajouter" 
           class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-plus mr-2"></i>Nouveau compte
        </a>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded">
            <?= $_SESSION['flash_message'] ?>
            <?php unset($_SESSION['flash_message']); ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entreprise</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Utilisateur</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Identifiant</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Crédits</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rôle</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($comptes as $compte): ?>
                        <?php
                        $statusColor = $compte['actif'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
                        $statusText = $compte['actif'] ? 'Actif' : 'Suspendu';
                        $roleColor = $compte['role'] == 'admin' ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800';
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 font-medium"><?= htmlspecialchars($compte['entreprise']) ?></td>
                            <td class="px-6 py-4"><?= htmlspecialchars($compte['prenom'] . ' ' . $compte['nom']) ?></td>
                            <td class="px-6 py-4"><?= htmlspecialchars($compte['user']) ?></td>
                            <td class="px-6 py-4 font-bold">
                                <?= number_format($compte['credits_total'], 2) ?> €
                                <button onclick="showCreditModal('<?= $compte['id_compte'] ?>', '<?= addslashes($compte['entreprise']) ?>')" 
                                        class="ml-2 text-blue-600 hover:text-blue-800 text-sm">
                                    <i class="fas fa-plus-circle"></i>
                                </button>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 rounded text-xs <?= $roleColor ?>"><?= strtoupper($compte['role']) ?></span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 rounded text-xs <?= $statusColor ?>"><?= $statusText ?></span>
                            </td>
                            <td class="px-6 py-4"><?= date('d/m/Y', strtotime($compte['date_creation'])) ?></td>
                            <td class="px-6 py-4 space-x-2">
                                <a href="?page=admin/comptes&toggle=<?= $compte['id_compte'] ?>" 
                                   class="text-<?= $compte['actif'] ? 'red' : 'green' ?>-600 hover:text-<?= $compte['actif'] ? 'red' : 'green' ?>-800"
                                   onclick="return confirm('<?= $compte['actif'] ? 'Suspendre' : 'Activer' ?> ce compte ?')">
                                    <i class="fas fa-<?= $compte['actif'] ? 'ban' : 'check-circle' ?>"></i>
                                </a>
                                <?php if ($compte['role'] != 'admin' || $compte['user'] != 'admin'): ?>
                                    <a href="?page=admin/comptes/supprimer&id=<?= $compte['id_compte'] ?>" 
                                       class="text-red-600 hover:text-red-800"
                                       onclick="return confirm('Supprimer ce compte ?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal crédits -->
<div id="creditModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
        <div class="p-6 border-b">
            <h3 class="text-lg font-bold">Ajouter des crédits</h3>
            <p class="text-sm text-gray-500" id="modalEntreprise"></p>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="id_compte" id="modalIdCompte">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Montant (€)</label>
                <input type="number" name="montant" step="0.01" min="0.01" required 
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
            </div>
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeModal()" 
                        class="px-4 py-2 border rounded-lg hover:bg-gray-50">Annuler</button>
                <button type="submit" name="ajouter_credits" 
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Ajouter</button>
            </div>
        </form>
    </div>
</div>

<script>
function showCreditModal(id, entreprise) {
    document.getElementById('modalIdCompte').value = id;
    document.getElementById('modalEntreprise').innerText = entreprise;
    document.getElementById('creditModal').classList.remove('hidden');
    document.getElementById('creditModal').classList.add('flex');
}

function closeModal() {
    document.getElementById('creditModal').classList.add('hidden');
    document.getElementById('creditModal').classList.remove('flex');
}

document.getElementById('creditModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>