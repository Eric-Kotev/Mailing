<?php
global $db;

$idCompte = $_SESSION['user_id'];
$campagnes = $db->select('campagne', ['id_compte' => $idCompte], '*', 'date_creation=order.desc');

$statutColors = [
    'PROGRAMMEE' => 'bg-yellow-100 text-yellow-800',
    'EN_COURS' => 'bg-blue-100 text-blue-800',
    'ENVOYEE' => 'bg-green-100 text-green-800',
    'ANNULEE' => 'bg-red-100 text-red-800',
    'ECHEC' => 'bg-red-100 text-red-800'
];

$typeLabels = [
    1 => 'SMS',
    2 => 'Email',
    3 => 'WhatsApp',
    4 => 'Audio'
];

// Récupérer les noms des listes
foreach ($campagnes as &$campagne) {
    $liste = $db->select('liste', ['id_liste' => $campagne['id_liste']]);
    $campagne['nom_liste'] = $liste ? $liste[0]['nom_liste'] : 'N/A';
}
?>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Campagnes</h1>
            <p class="text-gray-500">Gérez vos campagnes d'envoi</p>
        </div>
        <a href="index.php?page=campagnes/nouvelle" 
           class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-plus mr-2"></i>Nouvelle campagne
        </a>
    </div>

    <?php if (empty($campagnes)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <i class="fas fa-rocket text-5xl text-gray-300 mb-4"></i>
            <p class="text-gray-500">Aucune campagne pour le moment.</p>
            <a href="index.php?page=campagnes/nouvelle" class="text-blue-600 mt-2 inline-block">
                Créer votre première campagne →
            </a>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Liste</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Planifiée le</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campagnes as $campagne): 
                        $color = $statutColors[$campagne['statut']] ?? 'bg-gray-100 text-gray-800';
                        $type = $typeLabels[$campagne['id_type_message']] ?? '?';
                    ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 font-medium"><?= htmlspecialchars($campagne['nom_campagne']) ?></td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 rounded text-xs bg-purple-100 text-purple-800"><?= $type ?></span>
                            </td>
                            <td class="px-6 py-4"><?= htmlspecialchars($campagne['nom_liste']) ?></td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 rounded text-xs <?= $color ?>"><?= $campagne['statut'] ?></span>
                            </td>
                            <td class="px-6 py-4"><?= $campagne['date_planification'] ? date('d/m/Y H:i', strtotime($campagne['date_planification'])) : 'Immédiat' ?></td>
                            <td class="px-6 py-4 space-x-2">
                                <a href="index.php?page=campagnes/details&id=<?= $campagne['id_campagne'] ?>" 
                                   class="text-blue-600 hover:text-blue-800" title="Détails">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($campagne['statut'] == 'PROGRAMMEE'): ?>
                                    <a href="?page=campagnes/annuler&id=<?= $campagne['id_campagne'] ?>" 
                                       class="text-red-600 hover:text-red-800" title="Annuler"
                                       onclick="return confirm('Annuler cette campagne ?')">
                                        <i class="fas fa-times"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>