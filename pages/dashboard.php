<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Statistiques
$totalContacts = count($db->select('contact', ['id_compte' => $idCompte]));
$totalListes = count($db->select('liste', ['id_compte' => $idCompte]));

// Dernières campagnes
$campagnes = $db->select('campagne', ['id_compte' => $idCompte], '*', 'date_creation=order.desc');
$dernieresCampagnes = array_slice($campagnes, 0, 5);

// Derniers contacts ajoutés
$contacts = $db->select('contact', ['id_compte' => $idCompte], '*', 'date_inscription=order.desc');
$derniersContacts = array_slice($contacts, 0, 5);

// Crédits disponibles
$credits = $_SESSION['user_credits'];
?>
<div class="space-y-6">
    <!-- En-tête -->
    <div>
        <h1 class="text-2xl font-bold text-gray-800">Dashboard</h1>
        <p class="text-gray-500">Bienvenue sur votre plateforme d'envoi multi-canal</p>
    </div>

    <!-- Cartes statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm">Contacts</p>
                    <p class="text-3xl font-bold text-gray-800"><?= $totalContacts ?></p>
                </div>
                <div class="bg-blue-100 p-3 rounded-full">
                    <i class="fas fa-address-book text-blue-600 text-xl"></i>
                </div>
            </div>
            <a href="index.php?page=contacts/index" class="text-blue-600 text-sm mt-2 inline-block">Gérer →</a>
        </div>

        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm">Listes</p>
                    <p class="text-3xl font-bold text-gray-800"><?= $totalListes ?></p>
                </div>
                <div class="bg-green-100 p-3 rounded-full">
                    <i class="fas fa-list text-green-600 text-xl"></i>
                </div>
            </div>
            <a href="index.php?page=listes/index" class="text-green-600 text-sm mt-2 inline-block">Gérer →</a>
        </div>

        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-yellow-500">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm">Crédits disponibles</p>
                    <p class="text-3xl font-bold text-gray-800"><?= number_format($credits, 2) ?> €</p>
                </div>
                <div class="bg-yellow-100 p-3 rounded-full">
                    <i class="fas fa-coins text-yellow-600 text-xl"></i>
                </div>
            </div>
            <a href="index.php?page=parametres/credits" class="text-yellow-600 text-sm mt-2 inline-block">Recharger →</a>
        </div>
    </div>

    <!-- Dernières campagnes -->
    <div class="bg-white rounded-lg shadow">
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-lg font-bold">📊 Dernières campagnes</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($dernieresCampagnes)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                                Aucune campagne pour le moment. 
                                <a href="index.php?page=campagnes/nouvelle" class="text-blue-600">Créer une campagne →</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dernieresCampagnes as $campagne): ?>
                            <?php
                            $statutColors = [
                                'PROGRAMMEE' => 'bg-yellow-100 text-yellow-800',
                                'EN_COURS' => 'bg-blue-100 text-blue-800',
                                'ENVOYEE' => 'bg-green-100 text-green-800',
                                'ANNULEE' => 'bg-red-100 text-red-800',
                                'ECHEC' => 'bg-red-100 text-red-800'
                            ];
                            $color = $statutColors[$campagne['statut']] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4"><?= htmlspecialchars($campagne['nom_campagne']) ?></td>
                                <td class="px-6 py-4"><?= $campagne['id_type_message'] == 1 ? 'SMS' : ($campagne['id_type_message'] == 2 ? 'Email' : 'WhatsApp') ?></td>
                                <td class="px-6 py-4"><span class="px-2 py-1 rounded text-xs <?= $color ?>"><?= $campagne['statut'] ?></span></td>
                                <td class="px-6 py-4"><?= date('d/m/Y H:i', strtotime($campagne['date_creation'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Derniers contacts -->
    <div class="bg-white rounded-lg shadow">
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-lg font-bold">👥 Derniers contacts ajoutés</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Téléphone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($derniersContacts)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                                Aucun contact. 
                                <a href="index.php?page=contacts/ajouter" class="text-blue-600">Ajouter un contact →</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($derniersContacts as $contact): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4"><?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?></td>
                                <td class="px-6 py-4"><?= htmlspecialchars($contact['email'] ?? '-') ?></td>
                                <td class="px-6 py-4"><?= htmlspecialchars($contact['telephone'] ?? '-') ?></td>
                                <td class="px-6 py-4"><?= date('d/m/Y', strtotime($contact['date_inscription'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>