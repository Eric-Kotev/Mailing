<?php
global $db;

$idCompte = $_SESSION['user_id'];

// Statistiques
$totalContacts = count($db->select('contact', ['id_compte' => $idCompte]));
$totalListes = count($db->select('liste', ['id_compte' => $idCompte]));

// Récupérer les dernières campagnes
$campagnes = $db->select('campagne', ['id_compte' => $idCompte], '*', 'created_at DESC');
$dernieresCampagnes = array_slice($campagnes, 0, 5);

// Derniers contacts ajoutés
$contacts = $db->select('contact', ['id_compte' => $idCompte], '*', 'created_at DESC');
$derniersContacts = array_slice($contacts, 0, 5);

// Crédits disponibles
$credits = $_SESSION['user_credits'] ?? 0;
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
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Message</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Dest.</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($dernieresCampagnes)): ?>
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                Aucune campagne pour le moment. 
                                <a href="index.php?page=campagnes/choix" class="text-blue-600">Créer une campagne →</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dernieresCampagnes as $campagne): ?>
                            <?php
                            $statutColors = [
                                'envoye' => 'bg-green-100 text-green-800',
                                'en_cours' => 'bg-blue-100 text-blue-800',
                                'echoue' => 'bg-red-100 text-red-800'
                            ];
                            $color = $statutColors[$campagne['statut']] ?? 'bg-gray-100 text-gray-800';
                            
                            $statutText = [
                                'envoye' => 'Envoyé',
                                'en_cours' => 'En cours',
                                'echoue' => 'Échoué'
                            ];
                            $statut = $statutText[$campagne['statut']] ?? $campagne['statut'];
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                                    <?= date('d/m/Y H:i', strtotime($campagne['created_at'])) ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if ($campagne['type_campagne'] == 'whatsapp'): ?>
                                        <span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs whitespace-nowrap">
                                            <i class="fab fa-whatsapp mr-1"></i> WhatsApp
                                        </span>
                                    <?php else: ?>
                                        <span class="bg-blue-100 text-blue-700 px-2 py-1 rounded-full text-xs whitespace-nowrap">
                                            <i class="fas fa-comment-dots mr-1"></i> SMS
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm text-gray-800 max-w-md truncate">
                                        <?= htmlspecialchars(mb_substr($campagne['message'], 0, 50)) ?>
                                        <?= strlen($campagne['message']) > 50 ? '...' : '' ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 text-center whitespace-nowrap">
                                    <?= $campagne['nb_destinataires'] ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-1 rounded-full text-xs <?= $color ?> whitespace-nowrap">
                                        <?= $statut ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($dernieresCampagnes) && count($campagnes) > 5): ?>
            <div class="p-4 border-t text-center">
                <a href="index.php?page=campagnes/historique" class="text-blue-600 text-sm hover:underline">
                    Voir toutes les campagnes →
                </a>
            </div>
        <?php endif; ?>
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
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Téléphone</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($derniersContacts)): ?>
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                Aucun contact. 
                                <a href="index.php?page=contacts/ajouter" class="text-blue-600">Ajouter un contact →</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($derniersContacts as $contact): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm text-gray-800">
                                    <?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?= htmlspecialchars($contact['email'] ?? '-') ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?= htmlspecialchars($contact['telephone'] ?? '-') ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                                    <?= date('d/m/Y', strtotime($contact['created_at'] ?? $contact['date_inscription'] ?? 'now')) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>