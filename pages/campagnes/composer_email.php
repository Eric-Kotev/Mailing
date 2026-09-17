<?php

global $db;

// ============================================
// DÉTECTION PRÉCOCE DES REQUÊTES AJAX
// ============================================
$isAjaxUpload = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_upload_file']));
$isAjaxSyncListe = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_sync_liste_listmonk']));
$isAjaxCountLists = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_count_lists_status']));
$isAjax = ($isAjaxUpload || $isAjaxSyncListe || $isAjaxCountLists);

if ($isAjax) {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    header('Content-Type: application/json');

    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        error_log("PHP Error [ajax]: $errstr in $errfile:$errline");
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Erreur serveur interne.'
        ]);
        exit;
    });

    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            error_log("PHP Fatal Error [ajax]: " . $err['message'] . " in " . $err['file'] . ":" . $err['line']);
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            if (ob_get_length() === false || ob_get_length() === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Erreur fatale côté serveur. Consultez les logs.'
                ]);
            }
        }
    });
}

function respondJsonAndExit($data) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode($data);
    exit;
}

// ============================================
// CONFIGURATION LISTMONK
// ============================================
define('LISTMONK_API_BASE', 'http://164.68.103.147:9005/api');
define('LISTMONK_USERNAME', 'test');
define('LISTMONK_PASSWORD', 'lqXJrA1sfE1YobhQ0CyP9UiMpi1MOsb83p554Uuc1IRDKVRR');
define('LISTMONK_COUNT_CACHE_TTL', 30);

/**
 * Helper cURL Listmonk
 */
function makeListmonkRequest($url, $method = 'GET', $data = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, LISTMONK_USERNAME . ':' . LISTMONK_PASSWORD);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'response' => $response,
        'httpCode' => $httpCode,
        'error' => $curlError
    ];
}

/**
 * Recherche un abonné Listmonk par email.
 */
function getSubscriberIdByEmail($email) {
    $url = LISTMONK_API_BASE . '/subscribers?query=subscribers.email=\'' . addslashes($email) . '\'';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, LISTMONK_USERNAME . ':' . LISTMONK_PASSWORD);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);

    if (is_bool($data)) {
        if ($data === true) {
            $allResult = makeListmonkRequest(LISTMONK_API_BASE . '/subscribers', 'GET');
            if ($allResult['httpCode'] === 200) {
                $allData = json_decode($allResult['response'], true);
                if (isset($allData['data']) && is_array($allData['data'])) {
                    foreach ($allData['data'] as $subscriber) {
                        if (isset($subscriber['email']) && isset($subscriber['id']) &&
                            strtolower(trim($subscriber['email'])) === strtolower(trim($email))) {
                            return $subscriber['id'];
                        }
                    }
                }
            }
            return 'exists';
        }
        return null;
    }

    if (isset($data['data']) && is_array($data['data'])) {
        if (count($data['data']) > 0 && isset($data['data'][0]['id'])) {
            return $data['data'][0]['id'];
        }
    }

    if (isset($data['data']) && is_array($data['data']) && isset($data['data']['total'])) {
        if ($data['data']['total'] > 0) {
            if (isset($data['data']['results']) && is_array($data['data']['results']) && count($data['data']['results']) > 0) {
                return $data['data']['results'][0]['id'] ?? null;
            }
            return 'exists';
        }
    }

    return null;
}

/**
 * Récupère le nombre d'abonnés d'une liste Listmonk de manière FIABLE.
 *
 * Stratégie :
 *  1) GET /api/lists/{id} → lire UNIQUEMENT `data.subscriber_count`
 *  2) Si absent → GET /api/subscribers?list_id={id}&per_page=1 → lire `data.total`
 *  3) Cache session (30s) pour éviter de spammer l'API
 *.
 */
function getListmonkListSubscriberCount($listmonkId) {
    $listmonkId = (int)$listmonkId;
    if ($listmonkId <= 0) {
        return null;
    }

    $cacheKey = 'lm_count_' . $listmonkId;

    if (isset($_SESSION[$cacheKey]) && isset($_SESSION[$cacheKey]['ts'])) {
        if (time() - $_SESSION[$cacheKey]['ts'] < LISTMONK_COUNT_CACHE_TTL) {
            return $_SESSION[$cacheKey]['count'];
        }
    }

    // ============================================
    // MÉTHODE PRIVILÉGIÉE : /subscribers?list_id=X&per_page=1
    // C'est la SEULE source fiable du nombre d'abonnés ACTIFS
    // ============================================
    $subUrl = LISTMONK_API_BASE . '/subscribers?list_id=' . $listmonkId . '&per_page=1&page=1';
    $subResult = makeListmonkRequest($subUrl, 'GET');

    if (!$subResult['error'] && $subResult['httpCode'] === 200) {
        $subData = json_decode($subResult['response'], true);

        // Structure standard Listmonk : { data: { results: [], total: N } }
        if (isset($subData['data']['total']) && is_numeric($subData['data']['total'])) {
            $count = (int)$subData['data']['total'];
            $_SESSION[$cacheKey] = ['ts' => time(), 'count' => $count];
            return $count;
        }

        // Variante possible : { total: N }
        if (isset($subData['total']) && is_numeric($subData['total'])) {
            $count = (int)$subData['total'];
            $_SESSION[$cacheKey] = ['ts' => time(), 'count' => $count];
            return $count;
        }
    }

    // Fallback : /lists/{id} → subscriber_count (moins fiable)
    $result = makeListmonkRequest(LISTMONK_API_BASE . '/lists/' . $listmonkId, 'GET');

    if (!$result['error'] && $result['httpCode'] === 200) {
        $data = json_decode($result['response'], true);

        if (isset($data['data']['subscriber_count']) && is_numeric($data['data']['subscriber_count'])) {
            $count = (int)$data['data']['subscriber_count'];
            $_SESSION[$cacheKey] = ['ts' => time(), 'count' => $count];
            return $count;
        }
    }

    return null;
}

/**
 * Invalide le cache du count pour une liste Listmonk donnée.
 */
function invalidateListmonkCountCache($listmonkId) {
    if (!empty($listmonkId)) {
        unset($_SESSION['lm_count_' . (int)$listmonkId]);
    }
}

/**
 * Synchronise une liste locale vers Listmonk.
 */
function synchroniserListeVersListmonk($db, $id_liste, $id_compte) {
    $errors = [];
    $details = [];
    $syncedCount = 0;

    $listes = $db->select('liste', ['id_liste' => $id_liste, 'id_compte' => $id_compte]);
    if (empty($listes)) {
        return ['success' => false, 'message' => 'Liste introuvable', 'errors' => [], 'details' => [], 'stats' => [], 'listmonk_id' => null];
    }
    $liste = $listes[0];

    $listmonkId = !empty($liste['listmonk_id']) ? (int)$liste['listmonk_id'] : null;

    if (!$listmonkId) {
        $createData = [
            'name' => $liste['nom_liste'],
            'type' => 'private',
            'optin' => 'single',
            'description' => 'Liste synchronisée depuis l\'application'
        ];
        $createResult = makeListmonkRequest(LISTMONK_API_BASE . '/lists', 'POST', $createData);

        if ($createResult['error']) {
            return ['success' => false, 'message' => 'Erreur de connexion Listmonk: ' . $createResult['error'], 'errors' => [], 'details' => [], 'stats' => [], 'listmonk_id' => null];
        }

        if (!in_array($createResult['httpCode'], [200, 201])) {
            $respData = json_decode($createResult['response'], true);
            $msg = isset($respData['message']) ? $respData['message'] : ('HTTP ' . $createResult['httpCode']);
            return ['success' => false, 'message' => 'Impossible de créer la liste Listmonk: ' . $msg, 'errors' => [], 'details' => [], 'stats' => [], 'listmonk_id' => null];
        }

        $createResp = json_decode($createResult['response'], true);
        if (isset($createResp['data']['id'])) {
            $listmonkId = (int)$createResp['data']['id'];
        } elseif (isset($createResp['id'])) {
            $listmonkId = (int)$createResp['id'];
        }

        if (!$listmonkId) {
            return ['success' => false, 'message' => 'Listmonk n\'a pas retourné d\'ID pour la liste créée', 'errors' => [], 'details' => [], 'stats' => [], 'listmonk_id' => null];
        }

        $db->update('liste', ['listmonk_id' => $listmonkId], ['id_liste' => $id_liste]);
        $details[] = "✓ Liste créée sur Listmonk (ID: $listmonkId)";
    }

    $listeContacts = $db->select('liste_contact', ['id_liste' => $id_liste]);
    $contactsToSync = [];

    if (!empty($listeContacts) && is_array($listeContacts)) {
        foreach ($listeContacts as $lc) {
            $contact = $db->select('contact', ['id_contact' => $lc['id_contact'], 'id_compte' => $id_compte]);
            if (!empty($contact) && is_array($contact) && isset($contact[0]) && is_array($contact[0])) {
                $contactsToSync[] = $contact[0];
            }
        }
    }

    if (empty($contactsToSync)) {
        invalidateListmonkCountCache($listmonkId);
        return [
            'success' => true,
            'message' => "Liste créée/vérifiée sur Listmonk (ID: $listmonkId) mais aucun contact à synchroniser.",
            'errors' => [],
            'details' => $details,
            'stats' => ['total' => 0, 'created' => 0, 'existing' => 0, 'added_to_list' => 0, 'without_email' => 0],
            'listmonk_id' => $listmonkId
        ];
    }

    $subscriberIdsToAdd = [];
    $contactsWithoutEmail = [];

    foreach ($contactsToSync as $contact) {
        $email = trim($contact['email'] ?? '');
        if (empty($email)) {
            $contactsWithoutEmail[] = $contact['prenom'] . ' ' . $contact['nom'];
            continue;
        }

        $subscriberId = getSubscriberIdByEmail($email);

        if ($subscriberId === 'exists') {
            $details[] = "⚠️ {$email} existe déjà sur Listmonk (ID inconnu)";
            continue;
        } elseif ($subscriberId !== null && is_numeric($subscriberId)) {
            $subscriberIdsToAdd[] = $subscriberId;
            $details[] = "✓ {$email} existe déjà (ID: {$subscriberId})";
            continue;
        }

        $data = [
            'email' => $email,
            'name' => trim(($contact['prenom'] ?? '') . ' ' . ($contact['nom'] ?? '')),
            'status' => 'enabled',
            'lists' => [],
            'attribs' => new stdClass()
        ];

        $result = makeListmonkRequest(LISTMONK_API_BASE . '/subscribers', 'POST', $data);

        if ($result['error']) {
            $errors[] = "Erreur CURL pour {$email}: " . $result['error'];
            continue;
        }

        if (in_array($result['httpCode'], [200, 201])) {
            $responseData = json_decode($result['response'], true);
            if (isset($responseData['data']['id'])) {
                $subscriberIdsToAdd[] = $responseData['data']['id'];
                $details[] = "✓ {$email} créé (ID: {$responseData['data']['id']})";
            } else {
                $errors[] = "Erreur création {$email}: Réponse inattendue";
            }
        } elseif ($result['httpCode'] === 409) {
            $responseData = json_decode($result['response'], true);
            $foundId = null;

            if (isset($responseData['error']) && preg_match('/ID[:\s]+(\d+)/i', $responseData['error'], $matches)) {
                $foundId = $matches[1];
            }

            if (!$foundId) {
                $allResult = makeListmonkRequest(LISTMONK_API_BASE . '/subscribers', 'GET');
                if ($allResult['httpCode'] === 200) {
                    $allData = json_decode($allResult['response'], true);
                    if (isset($allData['data']) && is_array($allData['data'])) {
                        foreach ($allData['data'] as $sub) {
                            if (isset($sub['email']) && isset($sub['id']) &&
                                strtolower(trim($sub['email'])) === strtolower(trim($email))) {
                                $foundId = $sub['id'];
                                break;
                            }
                        }
                    }
                }
            }

            if ($foundId) {
                $subscriberIdsToAdd[] = $foundId;
                $details[] = "✓ {$email} existe déjà (ID: {$foundId})";
            } else {
                $errors[] = "Conflit 409 pour {$email}: impossible de récupérer l'abonné";
            }
        } else {
            $responseData = json_decode($result['response'], true);
            $errorMessage = isset($responseData['error']) ? $responseData['error'] : ("HTTP " . $result['httpCode']);
            $errors[] = "Erreur création {$email}: $errorMessage";
        }
    }

    if (!empty($subscriberIdsToAdd)) {
        $uniqueIds = array_values(array_unique($subscriberIdsToAdd));
        $batchSize = 100;
        $batches = array_chunk($uniqueIds, $batchSize);
        $addedToLists = 0;

        foreach ($batches as $batch) {
            $data = [
                'ids' => $batch,
                'action' => 'add',
                'target_list_ids' => [$listmonkId]
            ];

            $result = makeListmonkRequest(LISTMONK_API_BASE . '/subscribers/lists', 'PUT', $data);

            if (!in_array($result['httpCode'], [200, 201, 204])) {
                $dataWithStatus = $data;
                $dataWithStatus['status'] = 'unconfirmed';
                $result = makeListmonkRequest(LISTMONK_API_BASE . '/subscribers/lists', 'PUT', $dataWithStatus);
            }

            if (!in_array($result['httpCode'], [200, 201, 204])) {
                $dataWithStatus = $data;
                $dataWithStatus['status'] = 'enabled';
                $result = makeListmonkRequest(LISTMONK_API_BASE . '/subscribers/lists', 'PUT', $dataWithStatus);
            }

            if (in_array($result['httpCode'], [200, 201, 204])) {
                $addedToLists += count($batch);
                $details[] = "✓ Ajout de " . count($batch) . " abonné(s) à la liste Listmonk (ID: $listmonkId)";
            } else {
                $responseData = json_decode($result['response'], true);
                $errorMessage = isset($responseData['error']) ? $responseData['error'] : ("HTTP " . $result['httpCode']);
                $errors[] = "Erreur ajout à la liste pour le lot de " . count($batch) . " abonnés: $errorMessage";
            }
        }

        $syncedCount = $addedToLists;
    }

    if (!empty($contactsWithoutEmail)) {
        $errors[] = count($contactsWithoutEmail) . " contact(s) sans email: " . implode(', ', $contactsWithoutEmail);
    }

    // Invalider le cache du count
    invalidateListmonkCountCache($listmonkId);

    $stats = [
        'total' => count($contactsToSync),
        'created' => count(array_filter($details, function ($d) { return strpos($d, 'créé') !== false; })),
        'existing' => count(array_filter($details, function ($d) { return strpos($d, 'existe déjà') !== false; })),
        'added_to_list' => $syncedCount,
        'without_email' => count($contactsWithoutEmail)
    ];

    if ($syncedCount > 0) {
        $message = "$syncedCount contact(s) synchronisé(s) vers Listmonk";
        if (!empty($errors)) {
            $message .= " (" . count($errors) . " erreur(s))";
        }
        return [
            'success' => true,
            'message' => $message,
            'errors' => $errors,
            'details' => $details,
            'stats' => $stats,
            'listmonk_id' => $listmonkId
        ];
    }

    $errorMsg = "Aucun contact synchronisé.";
    if (!empty($errors)) {
        $errorMsg .= ' ' . implode('; ', $errors);
    }

    return [
        'success' => false,
        'message' => $errorMsg,
        'errors' => $errors,
        'details' => $details,
        'stats' => $stats,
        'listmonk_id' => $listmonkId
    ];
}

// ============================================
// AUTHENTIFICATION
// ============================================
if (empty($_SESSION['user_id'])) {
    if ($isAjax) {
        respondJsonAndExit([
            'success' => false,
            'message' => "Votre session a expiré. Veuillez recharger la page et vous reconnecter."
        ]);
    }
    header('Location: index.php?page=auth/login');
    exit;
}

$idCompte = $_SESSION['user_id'];

$campagneConfigId = $_POST['campagne_config_id'] ?? $_SESSION['campagne_config_id'] ?? $_GET['campagne_config_id'] ?? null;

if (!$campagneConfigId) {
    if ($isAjax) {
        respondJsonAndExit([
            'success' => false,
            'message' => "Identifiant de campagne manquant. Veuillez recharger la page."
        ]);
    }
    header('Location: index.php?page=campagnes/index');
    exit;
}

$campagneConfig = $db->select('campagne_config', [
    'id_campagne_config' => $campagneConfigId,
    'id_compte' => $idCompte
]);

if (empty($campagneConfig)) {
    if ($isAjax) {
        respondJsonAndExit([
            'success' => false,
            'message' => "Campagne non trouvée. Veuillez recharger la page."
        ]);
    }
    $_SESSION['flash_error'] = "Campagne non trouvée";
    header('Location: index.php?page=campagnes/index');
    exit;
}

$campagne = $campagneConfig[0];

$typeMessage = $_SESSION['type_message'] ?? null;
if ($typeMessage !== 'email') {
    if ($isAjax) {
        respondJsonAndExit([
            'success' => false,
            'message' => "Type de message non valide pour cette page. Veuillez recharger la page."
        ]);
    }
    $_SESSION['flash_error'] = "Type de message non valide pour cette page";
    header('Location: index.php?page=campagnes/choix_type&campagne_id=' . $campagneConfigId);
    exit;
}

// ============================================
// TRAITEMENT AJAX : SYNCHRONISATION LISTE
// ============================================
if ($isAjaxSyncListe) {
    $idListeToSync = $_POST['id_liste'] ?? null;

    if (!$idListeToSync) {
        respondJsonAndExit(['success' => false, 'message' => 'ID de liste manquant']);
    }

    $listesCheck = $db->select('liste', ['id_liste' => $idListeToSync, 'id_compte' => $idCompte]);
    if (empty($listesCheck)) {
        respondJsonAndExit(['success' => false, 'message' => 'Liste non trouvée']);
    }

       $syncResult = synchroniserListeVersListmonk($db, $idListeToSync, $idCompte);

    // Invalider le cache AVANT de recompter (au cas où Listmonk aurait indexé)
    if (!empty($syncResult['listmonk_id'])) {
        invalidateListmonkCountCache($syncResult['listmonk_id']);

        // Petit délai pour laisser Listmonk indexer la liste
        usleep(500000); // 500 ms

        $newCount = getListmonkListSubscriberCount($syncResult['listmonk_id']);
        $syncResult['listmonk_subscriber_count'] = $newCount;

        // Recalculer le nombre de contacts côté app pour la réponse
        $listeContacts = $db->select('liste_contact', ['id_liste' => $idListeToSync]);
        $nbContactsApp = 0;
        foreach ($listeContacts as $lc) {
            $c = $db->select('contact', ['id_contact' => $lc['id_contact']]);
            if (!empty($c) && !empty($c[0]['email'])) {
                $nbContactsApp++;
            }
        }
        $syncResult['nombre_contacts'] = $nbContactsApp;
        $syncResult['est_synchronisee'] = ($newCount !== null && $newCount === $nbContactsApp);
        $syncResult['raison_non_sync'] = $syncResult['est_synchronisee'] ? null : (($newCount === null) ? 'impossible_verifier' : 'contenu_different');
    }

    respondJsonAndExit($syncResult);
}

// ============================================
// TRAITEMENT AJAX : RECALCUL DU STATUT DES LISTES
// ============================================
if ($isAjaxCountLists) {
    $listesBrutes = $db->select('liste', ['id_compte' => $idCompte]);

    $emailTypeIdTmp = null;
    $typeMessageEmailTmp = $db->select('type_message', ['libelle_type' => 'Email']);
    if (empty($typeMessageEmailTmp)) {
        $typeMessageEmailTmp = $db->select('type_message', ['libelle_type' => 'email']);
    }
    if (!empty($typeMessageEmailTmp)) {
        $emailTypeIdTmp = $typeMessageEmailTmp[0]['id_type_message'];
    }

    $blacklistIdsTmp = [];
    if ($emailTypeIdTmp) {
        $blacklistTmp = $db->select('blacklist', ['id_type_message' => $emailTypeIdTmp]);
        foreach ($blacklistTmp as $b) {
            if (!empty($b['id_contact'])) {
                $blacklistIdsTmp[] = $b['id_contact'];
            }
        }
    }

    $result = [];

    foreach ($listesBrutes as $liste) {
        $listeContacts = $db->select('liste_contact', ['id_liste' => $liste['id_liste']]);
        $nbContacts = 0;
        foreach ($listeContacts as $lc) {
            if (!in_array($lc['id_contact'], $blacklistIdsTmp)) {
                $contact = $db->select('contact', ['id_contact' => $lc['id_contact']]);
                if (!empty($contact) && !empty($contact[0]['email'])) {
                    $nbContacts++;
                }
            }
        }

        $listmonkId = $liste['listmonk_id'] ?? null;
        $estSynchronisee = false;
        $raisonNonSync = null;
        $lmCount = null;

        if (empty($listmonkId)) {
            $raisonNonSync = 'jamais_creer';
        } else {
            $lmCount = getListmonkListSubscriberCount($listmonkId);

            if ($lmCount === null) {
                $raisonNonSync = 'impossible_verifier';
            } elseif ($lmCount === $nbContacts) {
                $estSynchronisee = true;
            } else {
                $raisonNonSync = 'contenu_different';
            }
        }

        $result[] = [
            'id_liste' => $liste['id_liste'],
            'nom_liste' => $liste['nom_liste'],
            'nombre_contacts' => $nbContacts,
            'listmonk_id' => $listmonkId,
            'listmonk_subscriber_count' => $lmCount,
            'est_synchronisee' => $estSynchronisee,
            'raison_non_sync' => $raisonNonSync
        ];
    }

    respondJsonAndExit(['success' => true, 'listes' => $result]);
}

$emailTypeId = null;
$typeMessageEmail = $db->select('type_message', ['libelle_type' => 'Email']);
if (empty($typeMessageEmail)) {
    $typeMessageEmail = $db->select('type_message', ['libelle_type' => 'email']);
}
if (!empty($typeMessageEmail)) {
    $emailTypeId = $typeMessageEmail[0]['id_type_message'];
}

// ============================================
// RÉCUPÉRATION DES ADRESSES EMAIL DE L'UTILISATEUR
// ============================================
$emailAccounts = $db->select('email_accounts', ['id_compte' => $idCompte]);
$fromAddresses = [];
foreach ($emailAccounts as $account) {
    if (!empty($account['from_address'])) {
        $fromAddresses[] = $account['from_address'];
    }
}
if (empty($fromAddresses)) {
    $fromAddresses[] = 'noreply@votre-domaine.com';
}

$blacklistIds = [];
if ($emailTypeId) {
    $blacklist = $db->select('blacklist', ['id_type_message' => $emailTypeId]);
    foreach ($blacklist as $b) {
        if (!empty($b['id_contact'])) {
            $blacklistIds[] = $b['id_contact'];
        }
    }
}

$tousContacts = $db->select('contact', ['id_compte' => $idCompte]);

$contacts = [];
$contactsSansEmail = [];
foreach ($tousContacts as $contact) {
    if (!in_array($contact['id_contact'], $blacklistIds)) {
        if (!empty($contact['email'])) {
            $contacts[] = $contact;
        } else {
            $contactsSansEmail[] = $contact;
        }
    }
}

// ============================================
// CONSTRUCTION DE LA LISTE AVEC COMPARAISON LISTMONK
// ============================================
$listesBrutes = $db->select('liste', ['id_compte' => $idCompte]);
$listes = [];

foreach ($listesBrutes as $liste) {
    $listeContacts = $db->select('liste_contact', ['id_liste' => $liste['id_liste']]);
    $nbContacts = 0;
    $nbSansEmail = 0;

    foreach ($listeContacts as $lc) {
        if (!in_array($lc['id_contact'], $blacklistIds)) {
            $contact = $db->select('contact', ['id_contact' => $lc['id_contact']]);
            if (!empty($contact) && !empty($contact[0]['email'])) {
                $nbContacts++;
            } else {
                $nbSansEmail++;
            }
        }
    }

    $listmonkId = $liste['listmonk_id'] ?? null;
    $estSynchronisee = false;
    $raisonNonSync = null;
    $lmCount = null;

    if (empty($listmonkId)) {
        $raisonNonSync = 'jamais_creer';
    } else {
        $lmCount = getListmonkListSubscriberCount($listmonkId);

        if ($lmCount === null) {
            $raisonNonSync = 'impossible_verifier';
        } elseif ($lmCount === $nbContacts) {
            $estSynchronisee = true;
        } else {
            $raisonNonSync = 'contenu_different';
        }
    }

    $listes[] = [
        'id_liste' => $liste['id_liste'],
        'nom_liste' => $liste['nom_liste'],
        'nombre_contacts' => $nbContacts,
        'nombre_sans_email' => $nbSansEmail,
        'listmonk_id' => $listmonkId,
        'listmonk_subscriber_count' => $lmCount,
        'est_synchronisee' => $estSynchronisee,
        'raison_non_sync' => $raisonNonSync
    ];
}

// ============================================
// TRI : synchronisées d'abord, puis non synchronisées
// ============================================
usort($listes, function ($a, $b) {
    if ($a['est_synchronisee'] === $b['est_synchronisee']) {
        return strcasecmp($a['nom_liste'], $b['nom_liste']);
    }
    return $a['est_synchronisee'] ? -1 : 1;
});

$error = '';
$success = '';
$uploadedMediaId = null;
$uploadedFileName = null;
$uploadError = null;

$formData = $_SESSION['form_data'] ?? [];
$formData['objet'] = $formData['objet'] ?? '';
$formData['corps'] = $formData['corps'] ?? '';
$formData['liste_id'] = $formData['liste_id'] ?? '';
$formData['from_email'] = $formData['from_email'] ?? $fromAddresses[0];
$formData['from_name'] = $formData['from_name'] ?? 'Votre Entreprise';

$uploadedMediaId = $_SESSION['uploaded_media_id'] ?? null;
$uploadedFileName = $_SESSION['uploaded_file_name'] ?? null;
$uploadedMediaUrl = $_SESSION['uploaded_media_url'] ?? null;
$uploadError = $_SESSION['upload_error'] ?? null;
$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;

unset($_SESSION['upload_error']);

// ============================================
// TRAITEMENT AJAX : UPLOAD DE FICHIER
// ============================================
if ($isAjaxUpload) {
    $hasFile = isset($_FILES['piece_jointe']) && $_FILES['piece_jointe']['error'] === UPLOAD_ERR_OK;

    if (!$hasFile) {
        $uploadErrCode = $_FILES['piece_jointe']['error'] ?? null;
        $msg = "Veuillez sélectionner un fichier à importer";
        if ($uploadErrCode === UPLOAD_ERR_INI_SIZE || $uploadErrCode === UPLOAD_ERR_FORM_SIZE) {
            $msg = "Le fichier dépasse la taille maximale autorisée par le serveur.";
        } elseif ($uploadErrCode === UPLOAD_ERR_PARTIAL) {
            $msg = "Le fichier n'a été que partiellement téléchargé. Réessayez.";
        } elseif ($uploadErrCode === UPLOAD_ERR_NO_TMP_DIR) {
            $msg = "Erreur serveur : dossier temporaire manquant.";
        } elseif ($uploadErrCode === UPLOAD_ERR_CANT_WRITE) {
            $msg = "Erreur serveur : impossible d'écrire le fichier sur le disque.";
        }
        respondJsonAndExit(['success' => false, 'message' => $msg]);
    }

    $file = $_FILES['piece_jointe'];

    if ($file['size'] > 10 * 1024 * 1024) {
        respondJsonAndExit(['success' => false, 'message' => "Le fichier est trop volumineux. Maximum 10 Mo."]);
    }

    $allowedTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain', 'text/csv'
    ];

    if (!function_exists('mime_content_type')) {
        error_log("=== ERREUR: extension 'fileinfo' non disponible sur ce serveur ===");
        respondJsonAndExit([
            'success' => false,
            'message' => "Configuration serveur incomplète (extension fileinfo manquante). Contactez l'administrateur."
        ]);
    }

    $mimeType = @mime_content_type($file['tmp_name']);
    if ($mimeType === false) {
        error_log("=== ERREUR: mime_content_type a échoué pour " . $file['tmp_name'] . " ===");
        respondJsonAndExit(['success' => false, 'message' => "Impossible de déterminer le type du fichier."]);
    }

    if (!in_array($file['type'], $allowedTypes) && !in_array($mimeType, $allowedTypes)) {
        respondJsonAndExit(['success' => false, 'message' => "Type de fichier non autorisé. Types autorisés: images, PDF, Word, Excel, CSV, TXT"]);
    }

    $uploadDir = __DIR__ . '/uploads/pieces_jointes/';
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
            error_log("=== ERREUR: impossible de créer le dossier $uploadDir (droits d'écriture ?) ===");
            respondJsonAndExit(['success' => false, 'message' => "Erreur serveur : impossible de créer le dossier d'upload. Vérifiez les droits d'écriture."]);
        }
    }
    if (!is_writable($uploadDir)) {
        error_log("=== ERREUR: dossier $uploadDir non accessible en écriture ===");
        respondJsonAndExit(['success' => false, 'message' => "Erreur serveur : dossier d'upload non accessible en écriture."]);
    }

    $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
    $filePath = $uploadDir . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        error_log("=== ERREUR: move_uploaded_file a échoué vers $filePath ===");
        respondJsonAndExit(['success' => false, 'message' => "Impossible de déplacer le fichier uploadé."]);
    }

    $apiUrl = LISTMONK_API_BASE . '/media';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, LISTMONK_USERNAME . ':' . LISTMONK_PASSWORD);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_VERBOSE, false);

    $fileInfo = new CURLFile($filePath, mime_content_type($filePath), $file['name']);
    $postFields = ['file' => $fileInfo];
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

    $responseData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("=== ERREUR CURL (upload media): $curlError ===");
        respondJsonAndExit(['success' => false, 'message' => "Erreur de connexion au serveur Listmonk: " . $curlError]);
    }

    if ($httpCode === 200 || $httpCode === 201) {
        $data = json_decode($responseData, true);
        $mediaId = null;
        $mediaUrl = null;

        if (isset($data['id'])) {
            $mediaId = $data['id'];
            if (isset($data['url'])) {
                $mediaUrl = $data['url'];
            } elseif (isset($data['data']['url'])) {
                $mediaUrl = $data['data']['url'];
            }
        } elseif (isset($data['data']['id'])) {
            $mediaId = $data['data']['id'];
            if (isset($data['data']['url'])) {
                $mediaUrl = $data['data']['url'];
            }
        } elseif (isset($data['result']['id'])) {
            $mediaId = $data['result']['id'];
            if (isset($data['result']['url'])) {
                $mediaUrl = $data['result']['url'];
            }
        }

        if ($mediaId) {
            $_SESSION['uploaded_media_id'] = $mediaId;
            $_SESSION['uploaded_file_name'] = $file['name'];
            $_SESSION['uploaded_media_url'] = $mediaUrl;

            respondJsonAndExit([
                'success' => true,
                'message' => "Fichier importé avec succès (ID: " . $mediaId . ")",
                'media_id' => $mediaId,
                'file_name' => $file['name'],
                'media_url' => $mediaUrl
            ]);
        } else {
            error_log("=== Upload OK mais pas d'ID média reçu. Réponse brute: " . $responseData . " ===");
            respondJsonAndExit([
                'success' => false,
                'message' => "Fichier uploadé mais aucun ID média reçu. Réponse: " . substr($responseData, 0, 200)
            ]);
        }
    } else {
        $msg = "Erreur Listmonk (HTTP " . $httpCode . "): " . substr($responseData, 0, 500);
        error_log("=== ERREUR LISTMONK (upload media): $msg ===");
        $_SESSION['upload_error'] = $msg;
        respondJsonAndExit(['success' => false, 'message' => $msg]);
    }
}

if (isset($_GET['remove_upload']) && $_GET['remove_upload'] == 1) {
    unset($_SESSION['uploaded_media_id']);
    unset($_SESSION['uploaded_file_name']);
    unset($_SESSION['uploaded_media_url']);
    unset($_SESSION['upload_error']);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=campagnes/composer&campagne_config_id=' . $campagneConfigId);
    exit;
}

function createListmonkCampaign($campaignData) {
    $apiUrl = LISTMONK_API_BASE . '/campaigns';

    $payload = [
        'name' => $campaignData['name'],
        'subject' => $campaignData['subject'],
        'lists' => [(int)$campaignData['list_id']],
        'type' => 'regular',
        'content_type' => 'richtext',
        'body' => $campaignData['body'],
        'from_email' => $campaignData['from_email'] ?? 'noreply@votre-domaine.com',
        'from_name' => $campaignData['from_name'] ?? 'Votre Entreprise',
        'messenger' => 'email',
        'enabled' => true
    ];

    if (!empty($campaignData['attachments']) && is_array($campaignData['attachments'])) {
        $payload['attachments'] = $campaignData['attachments'];
    }

    if (!empty($campaignData['send_at'])) {
        $payload['send_at'] = $campaignData['send_at'];
        $payload['status'] = 'scheduled';
    } else {
        $payload['status'] = 'draft';
    }

    error_log("=== PAYLOAD LISTMONK ===");
    error_log(json_encode($payload, JSON_PRETTY_PRINT));

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, LISTMONK_USERNAME . ':' . LISTMONK_PASSWORD);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => "Erreur CURL: $curlError"];
    }

    if ($httpCode === 200 || $httpCode === 201) {
        $data = json_decode($response, true);
        $campaignId = null;
        if (isset($data['id'])) {
            $campaignId = $data['id'];
        } elseif (isset($data['data']['id'])) {
            $campaignId = $data['data']['id'];
        } elseif (isset($data['result']['id'])) {
            $campaignId = $data['result']['id'];
        }

        return [
            'success' => true,
            'campaign_id' => $campaignId,
            'data' => $data
        ];
    } else {
        error_log("=== ERREUR LISTMONK RESPONSE ===");
        error_log($response);
        return ['success' => false, 'error' => "HTTP $httpCode: " . substr($response, 0, 500)];
    }
}

function updateListmonkCampaignStatus($campaignId, $status) {
    $apiUrl = LISTMONK_API_BASE . "/campaigns/{$campaignId}/status";

    $payload = ['status' => $status];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, LISTMONK_USERNAME . ':' . LISTMONK_PASSWORD);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200 || $httpCode === 201 || $httpCode === 204;
}

// ============================================
// TRAITEMENT FORMULAIRE : ENREGISTREMENT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_enregistrer'])) {
    $_SESSION['form_data'] = [
        'objet' => $_POST['objet'] ?? '',
        'corps' => $_POST['corps'] ?? '',
        'liste_id' => $_POST['liste_id'] ?? '',
        'from_email' => $_POST['from_email'] ?? $fromAddresses[0],
        'from_name' => $_POST['from_name'] ?? 'Votre Entreprise'
    ];

    $objet = trim($_POST['objet'] ?? '');
    $corps = trim($_POST['corps'] ?? '');
    $liste_id = $_POST['liste_id'] ?? null;
    $from_email = trim($_POST['from_email'] ?? '');
    $from_name = trim($_POST['from_name'] ?? '');
    $envoyer_maintenant = isset($_POST['envoyer_maintenant']) && $_POST['envoyer_maintenant'] === '1';
    $date_planification = $_POST['date_planification'] ?? null;
    $media_id = $_POST['media_id'] ?? null;

    if (empty($media_id) && !empty($_SESSION['uploaded_media_id'])) {
        $media_id = $_SESSION['uploaded_media_id'];
    }

    $mediaUrl = $_SESSION['uploaded_media_url'] ?? null;

    if (empty($from_email)) {
        $from_email = $fromAddresses[0];
    }
    if (empty($from_name)) {
        $from_name = 'Votre Entreprise';
    }

    if (empty($objet)) {
        $error = "Veuillez saisir un objet";
    } elseif (empty($corps)) {
        $error = "Veuillez saisir le corps du message";
    } elseif (empty($liste_id)) {
        $error = "Veuillez sélectionner une liste de diffusion";
    } else {
        $destinataires = [];
        $destinatairesNoms = [];
        $contactsSansEmailDansListe = 0;
        $listmonkListId = null;
        $listeSelectionnee = null;

        foreach ($listes as $l) {
            if ($l['id_liste'] == $liste_id) {
                $listmonkListId = $l['listmonk_id'];
                $listeSelectionnee = $l;
                break;
            }
        }

        if (!$listmonkListId) {
            $error = "Cette liste n'est pas liée à Listmonk. Veuillez d'abord synchroniser la liste.";
        } elseif ($listeSelectionnee && !$listeSelectionnee['est_synchronisee']) {
            $error = "Cette liste n'est pas synchronisée avec Listmonk (contenu différent). Veuillez la synchroniser avant d'envoyer la campagne.";
        } else {
            $listeContacts = $db->select('liste_contact', ['id_liste' => $liste_id]);
            foreach ($listeContacts as $lc) {
                if (!in_array($lc['id_contact'], $blacklistIds)) {
                    $contact = $db->select('contact', ['id_contact' => $lc['id_contact'], 'id_compte' => $idCompte]);
                    if (!empty($contact) && !empty($contact[0]['email'])) {
                        $destinataires[] = $contact[0];
                        $destinatairesNoms[] = $contact[0]['prenom'] . ' ' . $contact[0]['nom'] . ' (' . $contact[0]['email'] . ')';
                    } else {
                        $contactsSansEmailDansListe++;
                    }
                }
            }
        }

        if (empty($destinataires) && empty($error)) {
            if ($contactsSansEmailDansListe > 0) {
                $error = "Aucun destinataire valide. $contactsSansEmailDansListe contact(s) n'ont pas d'email.";
            } else {
                $error = "Aucun destinataire valide dans cette liste";
            }
        }

        if (empty($error) && $listmonkListId) {
            $bodyContent = $corps;

            if (!empty($media_id) && !empty($mediaUrl)) {
                $isImage = false;
                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                $extension = strtolower(pathinfo($uploadedFileName ?? '', PATHINFO_EXTENSION));
                if (in_array($extension, $imageExtensions)) {
                    $isImage = true;
                }

                if ($isImage) {
                    $bodyContent .= '<br><br><img src="' . $mediaUrl . '" alt="' . htmlspecialchars($uploadedFileName ?? 'Image') . '" style="max-width:100%;">';
                } else {
                    $bodyContent .= '<br><br><strong>Télecharger ici la Pièce jointe :</strong> <a href="' . htmlspecialchars($mediaUrl) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($uploadedFileName ?? 'Fichier') . '</a>';
                }
            }

            $campaignData = [
                'name' => $campagne['nom_campagne'] . ' - ' . date('Y-m-d H:i'),
                'subject' => $objet,
                'list_id' => $listmonkListId,
                'body' => $bodyContent,
                'from_email' => $from_email,
                'from_name' => $from_name
            ];

            if (!empty($media_id)) {
                $extension = strtolower(pathinfo($uploadedFileName ?? '', PATHINFO_EXTENSION));
                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                if (!in_array($extension, $imageExtensions)) {
                    $campaignData['attachments'] = [(int)$media_id];
                }
            }

            $hasSchedule = false;
            $scheduleDate = null;

            if (!empty($campagne['date_planification'])) {
                $scheduleDate = $campagne['date_planification'];
                $hasSchedule = true;
            } elseif (!empty($date_planification) && !$envoyer_maintenant) {
                $scheduleDate = $date_planification;
                $hasSchedule = true;
            }

            if ($hasSchedule && $scheduleDate) {
                $datetime = new DateTime($scheduleDate);
                $datetime->setTimezone(new DateTimeZone('+03:00'));
                $campaignData['send_at'] = $datetime->format('Y-m-d\TH:i:s.000000P');
            }

            $result = createListmonkCampaign($campaignData);
            $listmonkCampaignId = null;

            if ($result['success']) {
                $listmonkCampaignId = $result['campaign_id'];
                error_log("=== CAMPAGNE CRÉÉE SUR LISTMONK: ID=" . $listmonkCampaignId);
                if (!empty($media_id)) {
                    error_log("=== AVEC MÉDIA ID: " . $media_id);
                    error_log("=== URL DU MÉDIA: " . $mediaUrl);
                }

                if ($hasSchedule && $listmonkCampaignId) {
                    $statusUpdated = updateListmonkCampaignStatus($listmonkCampaignId, 'scheduled');
                    if ($statusUpdated) {
                        error_log("=== STATUT MIS À JOUR EN 'scheduled' POUR ID=" . $listmonkCampaignId);
                    } else {
                        error_log("=== ÉCHEC DE MISE À JOUR DU STATUT POUR ID=" . $listmonkCampaignId);
                    }
                }
            } else {
                $error = "Erreur Listmonk : " . $result['error'];
                error_log("=== ERREUR LISTMONK: " . $result['error']);
            }

            if (empty($error) || $listmonkCampaignId) {
                $finalStatut = 'pret_a_envoyer';
                if ($hasSchedule) {
                    $finalStatut = 'planifiee';
                }

                $campagneData = [
                    'id_compte' => $idCompte,
                    'id_campagne_config' => $campagneConfigId,
                    'type_campagne' => 'email',
                    'titre' => "Email: " . (strlen($objet) > 40 ? substr($objet, 0, 40) . '...' : $objet),
                    'message' => $bodyContent,
                    'objet' => $objet,
                    'destinataires' => json_encode($destinatairesNoms),
                    'nb_destinataires' => count($destinataires),
                    'nb_envoyes' => 0,
                    'nb_succes' => 0,
                    'nb_erreurs' => 0,
                    'statut' => $finalStatut,
                    'listmonk_campaign_id' => $listmonkCampaignId,
                    'from_email' => $from_email,
                    'from_name' => $from_name,
                    'created_at' => date('Y-m-d H:i:s')
                ];

                if (!empty($media_id)) {
                    $campagneData['listmonk_media_id'] = $media_id;
                    $campagneData['piece_jointe'] = json_encode([
                        'media_id' => $media_id,
                        'nom' => $uploadedFileName ?? 'Fichier joint',
                        'url' => $mediaUrl
                    ]);
                }

                try {
                    $db->insert('campagne', $campagneData);

                    $updateData = [
                        'statut' => $finalStatut,
                        'message_content' => $bodyContent,
                        'objet' => $objet,
                        'listmonk_campaign_id' => $listmonkCampaignId
                    ];

                    if (!empty($media_id)) {
                        $updateData['listmonk_media_id'] = $media_id;
                    }

                    $db->update('campagne_config', $updateData, ['id_campagne_config' => $campagneConfigId]);

                    unset($_SESSION['form_data']);
                    unset($_SESSION['uploaded_media_id']);
                    unset($_SESSION['uploaded_file_name']);
                    unset($_SESSION['uploaded_media_url']);

                    $successMsg = "Email enregistré avec succès !";
                    $successMsg .= "<br>" . count($destinataires) . " destinataire(s) dans la liste Listmonk";

                    if ($listmonkCampaignId) {
                        $successMsg .= "<br>ID Listmonk: <strong>" . $listmonkCampaignId . "</strong>";
                    }

                    if (!empty($media_id)) {
                        $extension = strtolower(pathinfo($uploadedFileName ?? '', PATHINFO_EXTENSION));
                        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                        if (in_array($extension, $imageExtensions)) {
                            $successMsg .= "<br>Image intégrée dans le message (ID: $media_id)";
                        } else {
                            $successMsg .= "<br>📎 Pièce jointe attachée (ID: $media_id)";
                        }
                    }

                    if ($hasSchedule) {
                        $dateDisplay = $scheduleDate;
                        $successMsg .= "<br>Campagne planifiée pour " . date('d/m/Y H:i', strtotime($dateDisplay));
                    } elseif ($listmonkCampaignId) {
                        $successMsg .= "<br>Campagne enregistrée en brouillon sur Listmonk (ID: $listmonkCampaignId)";
                    }

                    if ($contactsSansEmailDansListe > 0) {
                        $successMsg .= "<br><small>$contactsSansEmailDansListe contact(s) n'ont pas d'email et ont été exclus de l'affichage.</small>";
                    }
                    $success = $successMsg;

                    echo '<meta http-equiv="refresh" content="3;url=index.php?page=campagnes/details&id=' . $campagneConfigId . '">';

                } catch (Exception $e) {
                    $error = "Erreur lors de l'enregistrement en base : " . $e->getMessage();
                    error_log("=== ERREUR BASE DE DONNÉES: " . $e->getMessage());
                }
            }
        } elseif (empty($error)) {
            $error = "Aucune liste Listmonk liée à cette campagne.";
        }
    }
}

$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
unset($_SESSION['flash_message']);
unset($_SESSION['flash_error']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Composer l'email - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            margin: 0; background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
        }
        .container-full { max-width: 100%; margin: 0 auto; padding: 16px 32px; width: 100%; }

        /* TOAST */
        .toast-notification {
            position: fixed; top: 20px; right: 20px; z-index: 9999;
            animation: slideInRight 0.3s ease-out;
        }
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .toast-notification .toast-content {
            color: white; padding: 12px 20px; border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 14px; font-weight: 500; max-width: 500px;
        }
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        .toast-notification.info .toast-content { background: #3b82f6; }
        .toast-notification.warning .toast-content { background: #f59e0b; }

        /* STEP INDICATOR */
        .step-indicator {
            display: flex; align-items: center; justify-content: center;
            gap: 12px; margin-bottom: 24px; padding: 12px 24px;
            background: white; border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            flex-wrap: wrap; width: 100%;
        }
        .step { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #9ca3af; }
        .step .number {
            width: 28px; height: 28px; border-radius: 50%;
            background: #e5e7eb; color: #6b7280;
            display: flex; align-items: center; justify-content: center;
            font-weight: 600; font-size: 12px;
            transition: all 0.3s ease; flex-shrink: 0;
        }
        .step.active .number { background: #d97706; color: white; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3); }
        .step.done .number { background: #10b981; color: white; }
        .step.active { color: #1f2937; font-weight: 500; }
        .step.done { color: #6b7280; }
        .step-line { width: 40px; height: 2px; background: #e5e7eb; border-radius: 2px; flex-shrink: 0; }
        .step-line.done { background: #10b981; }

        /* EN-TÊTE */
        .header-section {
            display: flex; align-items: center; margin-bottom: 20px;
            padding: 16px 24px; background: white; border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            width: 100%; flex-wrap: wrap; gap: 12px;
        }
        .header-section .back-link {
            color: #6b7280; font-size: 14px; font-weight: 500;
            transition: color 0.2s; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 6px; flex-shrink: 0;
        }
        .header-section .back-link:hover { color: #374151; background: #f3f4f6; }
        .header-section .icon-wrapper { background: #fef3c7; padding: 10px 12px; border-radius: 12px; flex-shrink: 0; }
        .header-section .icon-wrapper i { color: #d97706; font-size: 22px; }
        .header-section .header-text { flex: 1; min-width: 150px; }
        .header-section .title { font-size: 22px; font-weight: 700; color: #1f2937; }
        .header-section .subtitle { font-size: 14px; color: #6b7280; margin-top: 2px; }

        /* CARD PRINCIPALE */
        .main-card {
            background: white; border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            padding: 24px 28px; width: 100%;
        }

        /* INFO CAMPAGNE */
        .campagne-info {
            background: #f3e8ff; border: 2px solid #d8b4fe;
            border-radius: 12px; padding: 14px 20px; margin-bottom: 20px;
            display: flex; flex-wrap: wrap; align-items: center;
            justify-content: space-between; gap: 10px; width: 100%;
        }
        .campagne-info .info-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .campagne-info .info-left .campagne-name { font-size: 15px; font-weight: 700; color: #5b21b6; }
        .campagne-info .info-left .email-badge {
            background: #d97706; color: white; padding: 3px 12px;
            border-radius: 20px; font-size: 12px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .campagne-info .info-left .info-badge {
            display: inline-flex; align-items: center; padding: 2px 10px;
            border-radius: 12px; font-size: 11px; font-weight: 500; gap: 4px;
        }
        .info-badge.success { background: #dcfce7; color: #166534; }
        .info-badge.warning { background: #fef3c7; color: #92400e; }
        .info-badge.danger { background: #fee2e2; color: #991b1b; }
        .info-badge.info { background: #dbeafe; color: #1e40af; }
        .campagne-info .info-right {
            font-size: 14px; color: #6b21a8;
            display: flex; align-items: center; gap: 6px;
        }

        /* FORMULAIRES */
        .form-label {
            display: block; font-size: 14px; font-weight: 600;
            color: #374151; margin-bottom: 5px;
        }
        .form-label i { margin-right: 6px; }
        .form-label .required { color: #ef4444; }
        .form-group { margin-bottom: 16px; }

        /* SENDER INFO */
        .sender-info {
            background: #f0fdf4; border: 2px solid #86efac;
            border-radius: 10px; padding: 16px 20px;
            margin-bottom: 16px; width: 100%;
        }
        .sender-info .sender-title {
            font-weight: 700; color: #166534;
            margin-bottom: 12px; font-size: 15px;
        }

        /* LISTE INFO */
        .liste-info {
            background: #eff6ff; border: 2px solid #93c5fd;
            border-radius: 10px; padding: 16px 20px;
            margin-bottom: 16px; width: 100%;
        }
        .liste-info .liste-title {
            font-weight: 700; color: #1e40af;
            margin-bottom: 12px; font-size: 15px;
        }

        /* ============================================
           BADGES DE SYNCHRONISATION (Select2 custom)
        ============================================ */
        .liste-status-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 8px; border-radius: 10px;
            font-size: 10.5px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.3px;
            margin-left: 8px; vertical-align: middle;
        }
        .liste-status-badge.badge-synced {
            background: #dcfce7; color: #15803d;
            border: 1px solid #86efac;
        }
        .liste-status-badge.badge-pending {
            background: #fef3c7; color: #b45309;
            border: 1px solid #fcd34d;
        }
        .liste-status-badge.badge-diff {
            background: #fee2e2; color: #b91c1c;
            border: 1px solid #fca5a5;
        }
        .liste-status-badge.badge-unknown {
            background: #f3f4f6; color: #4b5563;
            border: 1px solid #d1d5db;
        }
        .liste-status-badge i { font-size: 9px; }
        /* ============================================
        Badge dans la SÉLECTION affichée (champ fermé)
        → plus petit et aligné proprement
        ============================================ */
        .select2-container--default .select2-selection--single .select2-selection__rendered .liste-status-badge {
            font-size: 9px;
            padding: 1px 6px;
            border-radius: 8px;
            margin-left: 6px;
            letter-spacing: 0.2px;
            line-height: 1.2;
            vertical-align: middle;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered .liste-status-badge i {
            font-size: 8px;
        }

        /* ============================================
        Badge dans les OPTIONS de la liste déroulante
        → taille normale (comme avant)
        ============================================ */
        .select2-results__option .liste-status-badge {
            font-size: 10.5px;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: 8px;
        }
        .select2-results__option .liste-status-badge i {
            font-size: 9px;
        }

        /* ============================================
        Aligner proprement le contenu de la sélection
        ============================================ */
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            display: flex !important;
            align-items: center;
            line-height: normal !important;
            padding-top: 0;
            padding-bottom: 0;
            height: 100%;
        }
        .select2-container--default .select2-selection--single {
            display: flex;
            align-items: center;
        }

        /* Options du select2 */
        .select2-results__option.liste-opt-synced { border-left: 3px solid #10b981; }
        .select2-results__option.liste-opt-pending { border-left: 3px solid #f59e0b; }
        .select2-results__option.liste-opt-diff { border-left: 3px solid #ef4444; }
        .select2-results__option.liste-opt-unknown { border-left: 3px solid #9ca3af; }

        /* BOUTON SYNC LISTE */
        #syncListeBox {
            display: none; margin-top: 12px;
            padding: 12px 16px;
            background: #fef3c7; border: 2px solid #fcd34d;
            border-radius: 10px;
            align-items: center; justify-content: space-between;
            gap: 10px; flex-wrap: wrap;
            transition: all 0.2s ease;
        }
        #syncListeBox.visible { display: flex; }
        #syncListeBox.sync-different { background: #fee2e2; border-color: #fca5a5; }
        #syncListeBox .sync-text {
            font-size: 13px; color: #92400e; font-weight: 500;
            display: flex; align-items: center; gap: 6px;
        }
        #syncListeBox.sync-different .sync-text { color: #991b1b; }
        #syncListeBox .sync-text i { color: #d97706; }
        #syncListeBox.sync-different .sync-text i { color: #dc2626; }

        .btn-sync-liste {
            background: #d97706; color: white;
            padding: 8px 16px; border-radius: 8px;
            font-size: 13px; font-weight: 600;
            border: none; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.2s ease; white-space: nowrap;
            min-width: 170px; justify-content: center;
        }
        .btn-sync-liste:hover:not(:disabled) { background: #b45309; transform: translateY(-1px); }
        .btn-sync-liste:disabled { opacity: 0.7; cursor: not-allowed; transform: none !important; }

        /* SELECT2 */
        .select2-container--default .select2-selection--single {
            border: 2px solid #d1d5db; border-radius: 8px; min-height: 42px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 40px; padding-left: 12px; font-size: 14px; color: #1f2937;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px; width: 32px; }
        .select2-container--default .select2-selection--single .select2-selection__arrow b {
            border-width: 5px 5px 0 5px;
            border-color: #6b7280 transparent transparent transparent;
        }
        .select2-dropdown { border-radius: 8px; border-color: #d1d5db; font-size: 14px; }
        .select2-search__field {
            border-radius: 6px !important; border: 2px solid #d1d5db !important;
            padding: 6px 10px !important; font-size: 14px !important;
        }
        .select2-search__field:focus { border-color: #d97706 !important; }
        .select2-results__option { padding: 8px 12px !important; font-size: 14px !important; }
        /* Hover adouci sur les options du select */
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #f3f4f6 !important;
            color: #1f2937 !important;
        }
        .select2-container--default .select2-results__option--highlighted[aria-selected]:hover {
            background-color: #f3f4f6 !important;
            color: #1f2937 !important;
        }
        .select2-container--default .select2-results__option[aria-selected="true"] {
            background-color: #fef3c7 !important;
            color: #92400e !important;
            font-weight: 600;
        }
        .select2-results__option:hover {
            background-color: #f3f4f6 !important;
            color: #1f2937 !important;
        }

        /* SUMMERNOTE */
        .note-editor {
            border-radius: 8px !important;
            border: 2px solid #d1d5db !important;
            width: 100%;
        }
        .note-editor .note-toolbar {
            background: #f9fafb !important;
            border-radius: 8px 8px 0 0 !important;
            border-bottom: 1px solid #d1d5db !important;
        }
        .note-editor .note-editable { min-height: 300px !important; font-size: 14px; }
        .note-editor:focus-within {
            border-color: #d97706 !important;
            box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.1);
        }

        /* FILE UPLOAD */
        .file-upload-container {
            display: flex; gap: 12px; align-items: flex-start;
            flex-wrap: wrap; width: 100%;
        }
        .file-upload-container .file-input-area { flex: 1; min-width: 200px; }
        .file-upload-container .upload-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

        #fileUploadArea {
            border: 2px dashed #d1d5db; border-radius: 10px;
            padding: 20px; text-align: center;
            transition: all 0.2s ease; cursor: pointer;
            min-height: 80px; width: 100%;
        }
        #fileUploadArea:hover { border-color: #d97706; background-color: #fffbeb; }
        #fileUploadArea.drag-over { border-color: #d97706; background-color: #fef3c7; }
        #fileUploadArea .upload-icon { font-size: 32px; color: #9ca3af; margin-bottom: 4px; }
        #fileUploadArea .upload-title { font-size: 14px; color: #4b5563; font-weight: 500; }
        #fileUploadArea .upload-desc { font-size: 12px; color: #9ca3af; }

        .btn-upload {
            background: #3b82f6; color: white;
            padding: 10px 20px; border-radius: 8px;
            font-weight: 600; transition: all 0.2s ease;
            border: none; cursor: pointer; font-size: 14px;
            height: 44px; white-space: nowrap;
        }
        .btn-upload:hover:not(:disabled) { background: #2563eb; transform: translateY(-2px); }
        .btn-upload:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }
        .btn-upload.loading { background: #93c5fd; cursor: wait; }

        .btn-upload-remove {
            background: #ef4444; color: white;
            padding: 10px 20px; border-radius: 8px;
            font-weight: 600; transition: all 0.2s ease;
            border: none; cursor: pointer; font-size: 14px;
            height: 44px; white-space: nowrap;
            text-decoration: none; display: inline-flex;
            align-items: center; gap: 6px;
        }
        .btn-upload-remove:hover { background: #dc2626; transform: translateY(-2px); }

        .uploaded-file-info {
            background: #dcfce7; border: 2px solid #86efac;
            border-radius: 10px; padding: 12px 16px;
            margin-top: 8px; display: flex;
            align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 8px; width: 100%;
        }
        .uploaded-file-info .file-details { display: flex; align-items: center; gap: 10px; }
        .uploaded-file-info .file-details i { color: #16a34a; font-size: 24px; }
        .uploaded-file-info .file-details .media-id {
            font-size: 12px; color: #6b7280;
            background: #e5e7eb; padding: 2px 10px; border-radius: 12px;
        }

        /* PLANIFICATION */
        .planification-zone {
            background: #fef3c7; border: 2px solid #fcd34d;
            border-radius: 10px; padding: 16px 20px;
            margin-top: 12px; width: 100%;
        }

        /* BLACKLIST WARNING */
        .blacklist-warning {
            background: #fef2f2; border-left: 4px solid #ef4444;
            padding: 10px 14px; border-radius: 8px;
            margin-bottom: 12px; display: flex;
            align-items: center; gap: 8px; width: 100%;
        }
        .blacklist-warning i { color: #ef4444; font-size: 16px; flex-shrink: 0; }
        .blacklist-warning span { font-size: 13px; color: #991b1b; font-weight: 500; }

        /* SUCCESS / ERROR */
        .success-box {
            background: #f0fdf4; border-left: 4px solid #10b981;
            padding: 14px 18px; border-radius: 10px;
            margin-bottom: 16px; width: 100%;
        }
        .success-box i { color: #10b981; font-size: 18px; margin-right: 8px; }
        .success-box .success-text { color: #166534; font-size: 14px; font-weight: 500; }
        .success-box .success-link { margin-top: 8px; display: block; }
        .success-box .success-link a { color: #166534; font-weight: 600; text-decoration: underline; }

        .error-box {
            background: #fef2f2; border-left: 4px solid #ef4444;
            padding: 12px 16px; border-radius: 8px;
            margin-bottom: 14px; display: flex;
            align-items: center; gap: 10px; width: 100%;
        }
        .error-box i { color: #ef4444; font-size: 18px; flex-shrink: 0; }
        .error-box span { color: #991b1b; font-size: 14px; font-weight: 500; }

        /* ACTION BUTTONS */
        .action-buttons {
            display: flex; gap: 12px; justify-content: flex-end;
            margin-top: 24px; padding-top: 16px;
            border-top: 2px solid #f3f4f6;
            flex-wrap: wrap; width: 100%;
        }
        .btn-secondary {
            background: #10b981; color: white;
            padding: 11px 28px; border-radius: 8px;
            font-size: 15px; font-weight: 700;
            transition: all 0.3s ease; border: none;
            cursor: pointer; display: inline-flex;
            align-items: center; gap: 8px;
            min-width: 180px; justify-content: center;
        }
        .btn-secondary:hover {
            background: #059669; transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.3);
        }
        .btn-outline {
            background: transparent; color: #6b7280;
            padding: 11px 22px; border-radius: 8px;
            font-size: 14px; font-weight: 600;
            border: 2px solid #e5e7eb;
            cursor: pointer; transition: all 0.3s ease;
            display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none; min-width: 120px; justify-content: center;
        }
        .btn-outline:hover { background: #f9fafb; border-color: #d1d5db; color: #374151; }

        /* UTILITIES */
        .text-xs { font-size: 12px; }
        .text-sm { font-size: 14px; }
        .text-gray-500 { color: #6b7280; }
        .text-gray-400 { color: #9ca3af; }
        .text-gray-700 { color: #374151; }
        .text-gray-800 { color: #1f2937; }
        .text-green-600 { color: #16a34a; }
        .text-red-600 { color: #dc2626; }
        .text-yellow-600 { color: #d97706; }
        .text-blue-600 { color: #2563eb; }
        .w-full { width: 100%; }
        .hidden { display: none !important; }

        .grid-cols-2 {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;
        }

        /* SPINNER inline pour sync */
        .inline-spinner {
            display: inline-block;
            width: 12px; height: 12px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* RESPONSIVE */
        @media (max-width: 992px) {
            .container-full { padding: 14px 20px; }
            .main-card { padding: 20px; }
            .grid-cols-2 { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .container-full { padding: 12px 16px; }
            .header-section { padding: 14px 16px; gap: 8px; }
            .header-section .title { font-size: 19px; }
            .main-card { padding: 16px; }
            .campagne-info { flex-direction: column; align-items: flex-start; }
            .file-upload-container { flex-direction: column; }
            .action-buttons { flex-direction: column; }
            .action-buttons .btn-secondary,
            .action-buttons .btn-outline {
                width: 100%; justify-content: center; min-width: unset;
            }
            .step span:last-child { display: none; }
        }
        @media (max-width: 480px) {
            .container-full { padding: 8px 10px; }
            .main-card { padding: 12px; }
            .note-editor .note-editable { min-height: 200px !important; }
        }
    </style>
</head>
<body>

<div class="container-full">
    <!-- STEP INDICATOR -->
    <div class="step-indicator">
        <div class="step done">
            <span class="number"><i class="fas fa-check"></i></span>
            <span>Type</span>
        </div>
        <div class="step-line done"></div>
        <div class="step active">
            <span class="number">2</span>
            <span>Composition</span>
        </div>
        <div class="step-line"></div>
        <div class="step">
            <span class="number">3</span>
            <span>Envoi</span>
        </div>
    </div>

    <!-- EN-TÊTE -->
    <div class="header-section">
        <a href="javascript:history.back()" class="back-link">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="icon-wrapper">
            <i class="fas fa-envelope"></i>
        </div>
        <div class="header-text">
            <div class="title">Composer l'email</div>
            <div class="subtitle">Rédigez votre email et choisissez une liste de diffusion</div>
        </div>
    </div>

    <!-- CARD PRINCIPALE -->
    <div class="main-card">
        <!-- Info campagne -->
        <div class="campagne-info">
            <div class="info-left">
                <i class="fas fa-bullhorn" style="color: #7c3aed; font-size: 16px;"></i>
                <span class="campagne-name"><?= htmlspecialchars($campagne['nom_campagne']) ?></span>
                <span class="email-badge"><i class="fas fa-envelope"></i> Email</span>
                <?php if (!empty($campagne['listmonk_id'])): ?>
                    <span class="info-badge info">
                        <i class="fab fa-listmonk"></i> Listmonk ID: <?= $campagne['listmonk_id'] ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($campagne['date_planification'])): ?>
                    <span class="info-badge warning">
                        <i class="fas fa-calendar-alt"></i>
                        Planifiée le <?= date('d/m/Y H:i', strtotime($campagne['date_planification'])) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="info-right">
                <i class="fas fa-users"></i> <?= count($contacts) ?> contact(s) avec email
                <?php if (count($contactsSansEmail) > 0): ?>
                    <span class="info-badge warning" style="margin-left:4px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?= count($contactsSansEmail) ?> sans email
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success): ?>
            <div class="success-box">
                <i class="fas fa-check-circle"></i>
                <span class="success-text"><?= $success ?></span>
                <div class="success-link">
                    <a href="index.php?page=campagnes/details&id=<?= $campagneConfigId ?>">Voir la campagne →</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-box">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($flashMessage): ?>
            <div class="success-box">
                <i class="fas fa-check-circle"></i>
                <span class="success-text"><?= $flashMessage ?></span>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="error-box">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= $flashError ?></span>
            </div>
        <?php endif; ?>

        <?php if ($uploadError): ?>
            <div class="error-box">
                <i class="fas fa-exclamation-circle"></i>
                <span>❌ Erreur d'import: <?= htmlspecialchars($uploadError) ?></span>
            </div>
        <?php endif; ?>

        <?php if (count($tousContacts) - count($contacts) - count($contactsSansEmail) > 0): ?>
            <div class="blacklist-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span>
                    <?= (count($tousContacts) - count($contacts) - count($contactsSansEmail)) ?> contact(s) blacklistés pour les emails ne sont pas affichés.
                </span>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="composerForm">
            <input type="hidden" name="campagne_config_id" value="<?= $campagneConfigId ?>">
            <input type="hidden" name="action_enregistrer" value="1">
            <input type="hidden" name="media_id" id="media_id" value="<?= $uploadedMediaId ?>">

            <!-- INFORMATIONS EXPÉDITEUR -->
            <div class="sender-info">
                <div class="sender-title">
                    <i class="fas fa-user-circle"></i> Informations de l'expéditeur
                </div>
                <div class="grid-cols-2">
                    <div>
                        <label class="form-label">
                            <i class="fas fa-envelope"></i> Email expéditeur <span class="required">*</span>
                        </label>
                        <select name="from_email" id="from_email" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500">
                            <?php foreach ($fromAddresses as $email): ?>
                                <option value="<?= htmlspecialchars($email) ?>" 
                                    <?= ($formData['from_email'] == $email) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($email) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">
                            <i class="fas fa-user"></i> Nom expéditeur <span class="required">*</span>
                        </label>
                        <input type="text" name="from_name" id="from_name" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500"
                               placeholder="Votre Entreprise"
                               value="<?= htmlspecialchars($formData['from_name']) ?>">
                    </div>
                </div>
            </div>

            <!-- SÉLECTION DE LA LISTE -->
            <div class="liste-info">
                <div class="liste-title">
                    <i class="fas fa-list"></i> Liste de diffusion <span class="required">*</span>
                </div>
                <select name="liste_id" id="liste_id" class="w-full" style="width: 100%;" required>
                    <option value="">-- Sélectionnez une liste --</option>
                    <?php foreach ($listes as $liste): ?>
                        <?php
                            $badgeClass = 'badge-unknown';
                            $badgeIcon = 'fa-question-circle';
                            $badgeLabel = 'Inconnu';
                            $optClass = 'liste-opt-unknown';

                            if ($liste['est_synchronisee']) {
                                $badgeClass = 'badge-synced';
                                $badgeIcon = 'fa-check-circle';
                                $badgeLabel = 'Synchronisée';
                                $optClass = 'liste-opt-synced';
                            } elseif (($liste['raison_non_sync'] ?? '') === 'contenu_different') {
                                $badgeClass = 'badge-diff';
                                $badgeIcon = 'fa-exclamation-triangle';
                                $badgeLabel = 'Non-synchronisée';
                                $optClass = 'liste-opt-diff';
                            } elseif (($liste['raison_non_sync'] ?? '') === 'impossible_verifier') {
                                $badgeClass = 'badge-unknown';
                                $badgeIcon = 'fa-question-circle';
                                $badgeLabel = 'Vérif. impossible';
                                $optClass = 'liste-opt-unknown';
                            } else {
                                $badgeClass = 'badge-pending';
                                $badgeIcon = 'fa-clock';
                                $badgeLabel = 'Non synchronisée';
                                $optClass = 'liste-opt-pending';
                            }
                        ?>
                        <option value="<?= $liste['id_liste'] ?>" 
                                <?= ($formData['liste_id'] == $liste['id_liste']) ? 'selected' : '' ?>
                                data-listmonk-id="<?= $liste['listmonk_id'] ?>"
                                data-est-synchronisee="<?= $liste['est_synchronisee'] ? '1' : '0' ?>"
                                data-raison-non-sync="<?= htmlspecialchars($liste['raison_non_sync'] ?? '') ?>"
                                data-lm-count="<?= $liste['listmonk_subscriber_count'] ?? '' ?>"
                                data-app-count="<?= $liste['nombre_contacts'] ?>">
                            <?= htmlspecialchars($liste['nom_liste']) ?> — <?= $liste['nombre_contacts'] ?> contact(s)
                        </option>
                    <?php endforeach; ?>
                </select>

                <p class="text-xs text-gray-500 mt-2">
                    <i class="fas fa-info-circle"></i>
                    Seuls les contacts avec une adresse email valide seront inclus dans l'envoi.
                </p>

                <!-- Boîte de synchronisation -->
                <div id="syncListeBox">
                    <div class="sync-text">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span id="syncReason">Cette liste n'est pas encore synchronisée avec Listmonk.</span>
                    </div>
                    <button type="button" id="btnSyncListe" class="btn-sync-liste">
                        <i class="fas fa-sync-alt"></i> <span>Synchroniser cette liste</span>
                    </button>
                </div>

                <?php if (count($listes) === 0): ?>
                    <p class="text-sm text-red-600 mt-2">
                        <i class="fas fa-exclamation-triangle"></i>
                        Aucune liste disponible. <a href="index.php?page=listes/creer" class="text-blue-600 underline">Créez une liste</a> avant de continuer.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Objet -->
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-tag"></i> Objet <span class="required">*</span>
                </label>
                <input type="text" name="objet" id="objet" required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500"
                       placeholder="Objet de l'email..."
                       value="<?= htmlspecialchars($formData['objet']) ?>">
            </div>

            <!-- Corps -->
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-comment"></i> Corps du message <span class="required">*</span>
                </label>
                <textarea name="corps" id="corps" rows="10"
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500"
                          placeholder="Contenu de l'email..."><?= htmlspecialchars($formData['corps']) ?></textarea>
            </div>

            <!-- Pièce jointe -->
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-paperclip"></i> Pièce jointe <span class="text-gray-400 text-sm font-normal">(optionnel)</span>
                </label>

                <div class="file-upload-container">
                    <div class="file-input-area">
                        <div id="fileUploadArea">
                            <input type="file" name="piece_jointe" id="piece_jointe" class="hidden" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv">
                            <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                            <div class="upload-title" id="fileLabel">Cliquez ou glissez un fichier ici</div>
                            <div class="upload-desc">Images, PDF, Word, Excel, CSV, TXT</div>
                        </div>
                    </div>
                    <div class="upload-actions">
                        <button type="button" id="uploadButton" class="btn-upload" disabled>
                            <i class="fas fa-upload"></i> Importer
                        </button>
                        <?php if ($uploadedMediaId): ?>
                            <a href="?page=campagnes/composer&campagne_config_id=<?= $campagneConfigId ?>&remove_upload=1" class="btn-upload-remove">
                                <i class="fas fa-trash"></i> Supprimer
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($uploadedMediaId && $uploadedFileName): ?>
                    <div class="uploaded-file-info">
                        <div class="file-details">
                            <i class="fas fa-file"></i>
                            <div>
                                <div class="font-medium text-gray-800"><?= htmlspecialchars($uploadedFileName) ?></div>
                                <div class="text-xs text-gray-500">
                                    <i class="fas fa-check-circle text-green-600"></i> Importé sur Listmonk
                                    <span class="media-id">ID: <?= $uploadedMediaId ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Planification -->
            <div class="form-group">
                <div id="planificationZone" class="planification-zone" style="display: none;">
                    <label class="form-label">
                        <i class="fas fa-calendar-alt"></i> Date et heure de planification <span class="required">*</span>
                    </label>
                    <input type="datetime-local" name="date_planification" id="date_planification"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-yellow-500"
                           min="<?= date('Y-m-d\TH:i') ?>">
                </div>
            </div>

            <!-- Actions -->
            <div class="action-buttons">
                <a href="index.php?page=campagnes/choix_type&campagne_id=<?= $campagneConfigId ?>" class="btn-outline">
                    <i class="fas fa-times"></i> Annuler
                </a>
                <button type="submit" name="action_enregistrer" value="1"
                        onclick="document.querySelector('input[name=envoyer_maintenant][value=1]') && (document.querySelector('input[name=envoyer_maintenant][value=1]').checked = true); document.getElementById('date_planification').value = ''; this.form.submit();"
                        class="btn-secondary">
                    <i class="fas fa-paper-plane"></i> Enregistrer la campagne
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/fr.js"></script>

<script>
// ============================================
// DONNÉES DES LISTES (injectées par PHP)
// ============================================
const LISTES_DATA = <?= json_encode(array_map(function($l) {
    return [
        'id_liste' => (string)$l['id_liste'],
        'nom_liste' => $l['nom_liste'],
        'nombre_contacts' => $l['nombre_contacts'],
        'nombre_sans_email' => $l['nombre_sans_email'],
        'listmonk_id' => $l['listmonk_id'],
        'listmonk_subscriber_count' => $l['listmonk_subscriber_count'],
        'est_synchronisee' => $l['est_synchronisee'],
        'raison_non_sync' => $l['raison_non_sync'],
    ];
}, $listes), JSON_UNESCAPED_UNICODE) ?>;

$(document).ready(function() {
    // ============================================
    // SELECT2 avec rendu personnalisé (badges)
    // ============================================
    function renderListeOption(state) {
        if (!state.id) return state.text;

        const $opt = $(state.element);
        const data = $opt.data();

        if (!data) return state.text;

        const estSync = data.estSynchronisee === 1 || data.estSynchronisee === '1';
        const raison = data.raisonNonSync || '';

        let badgeClass = 'badge-unknown';
        let badgeIcon = 'fa-question-circle';
        let badgeLabel = 'Inconnu';

        if (estSync) {
            badgeClass = 'badge-synced';
            badgeIcon = 'fa-check-circle';
            badgeLabel = 'Synchronisée';
        } else if (raison === 'contenu_different') {
            badgeClass = 'badge-diff';
            badgeIcon = 'fa-exclamation-triangle';
            badgeLabel = 'Désynchronisée';
        } else if (raison === 'impossible_verifier') {
            badgeClass = 'badge-unknown';
            badgeIcon = 'fa-question-circle';
            badgeLabel = 'Vérif. impossible';
        } else {
            badgeClass = 'badge-pending';
            badgeIcon = 'fa-clock';
            badgeLabel = 'Non synchronisée';
        }

        const nom = $opt.text().split('—')[0].trim();
        const appCount = data.appCount || 0;

        return $(
            '<div style="display:flex;align-items:center;justify-content:space-between;width:100%;">' +
                '<span>' + nom + ' <span style="color:#6b7280;font-size:12px;"> ' + appCount + ' contact(s)</span></span>' +
                '<span class="liste-status-badge ' + badgeClass + '"><i class="fas ' + badgeIcon + '"></i> ' + badgeLabel + '</span>' +
            '</div>'
        );
    }

    $('#liste_id').select2({
        placeholder: "-- Sélectionnez une liste --",
        allowClear: true,
        width: '100%',
        language: 'fr',
        templateResult: renderListeOption,
        templateSelection: renderListeOption,
        escapeMarkup: function(m) { return m; }
    });

    // ============================================
    // MISE À JOUR DE LA BOÎTE DE SYNC
    // ============================================
    function updateSyncBox() {
        const selectedOption = $('#liste_id option:selected');
        const isSync = selectedOption.data('est-synchronisee') === 1 || selectedOption.data('est-synchronisee') === '1';
        const hasValue = $('#liste_id').val() !== '';
        const raison = selectedOption.data('raison-non-sync') || '';
        const syncBox = document.getElementById('syncListeBox');
        const reasonEl = document.getElementById('syncReason');

        if (!syncBox || !reasonEl) return;

        if (hasValue && !isSync) {
            syncBox.classList.remove('sync-different');
            if (raison === 'contenu_different') {
                const appCount = selectedOption.data('app-count');
                const lmCount = selectedOption.data('lm-count');
                reasonEl.textContent = "Le contenu de cette liste diffère de celui sur Listmonk (app : " + appCount + ", Listmonk : " + lmCount + ").";
                syncBox.classList.add('sync-different');
            } else if (raison === 'impossible_verifier') {
                reasonEl.textContent = "Impossible de vérifier la synchronisation avec Listmonk.";
            } else {
                reasonEl.textContent = "Cette liste n'est pas encore synchronisée avec Listmonk.";
            }
            syncBox.classList.add('visible');
        } else {
            syncBox.classList.remove('visible');
            syncBox.classList.remove('sync-different');
        }
    }

    $('#liste_id').on('change select2:select select2:clear', function() {
        updateSyncBox();
    });
    updateSyncBox();

    // ============================================
    // SUMMERNOTE
    // ============================================
    $('#corps').summernote({
        height: 300,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['fontname', ['fontname']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link', 'picture', 'video']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ],
        placeholder: 'Rédigez le contenu de votre email...',
        lang: 'fr-FR'
    });

    // ============================================
    // BOUTON SYNCHRONISER LA LISTE (sans confirm, avec spinner)
    // ============================================
    $('#btnSyncListe').on('click', function() {
        const btn = $(this);
        const idListe = $('#liste_id').val();

        if (!idListe) {
            showToast('Veuillez sélectionner une liste', 'warning');
            return;
        }

        const originalHtml = btn.html();
        btn.prop('disabled', true).html('<span class="inline-spinner"></span> Synchronisation...');

        const formData = new FormData();
        formData.append('action_sync_liste_listmonk', '1');
        formData.append('id_liste', idListe);
        formData.append('campagne_config_id', '<?= $campagneConfigId ?>');

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(res => res.text().then(text => ({ ok: res.ok, status: res.status, text })))
        .then(result => {
            let data;
            try {
                data = JSON.parse(result.text);
            } catch (e) {
                console.error('Réponse non-JSON (HTTP ' + result.status + '):', result.text);
                showToast('❌ Réponse serveur invalide.', 'error');
                btn.prop('disabled', false).html(originalHtml);
                return;
            }

                        if (data.success) {
                showToast('✅ ' + data.message, 'success');

                if (data.errors && data.errors.length > 0) {
                    console.warn('Avertissements:', data.errors);
                }

                // Petit délai pour laisser Listmonk indexer
                setTimeout(() => {
                    refreshListesStatus().then(() => {
                        btn.prop('disabled', false).html(originalHtml);
                        updateSyncBox();

                        // Second refresh (au cas où Listmonk aurait mis plus de temps)
                        setTimeout(() => {
                            refreshListesStatus().then(() => {
                                updateSyncBox();
                            });
                        }, 1500);
                    });
                }, 400);
            } else {
                showToast('❌ ' + (data.message || 'Erreur de synchronisation'), 'error');
                if (data.errors && data.errors.length > 0) console.warn('Erreurs:', data.errors);
                btn.prop('disabled', false).html(originalHtml);
            }
        })
        .catch(error => {
            console.error('Erreur fetch:', error);
            showToast('❌ Erreur réseau: ' + error.message, 'error');
            btn.prop('disabled', false).html(originalHtml);
        });
    });

        // ============================================
    // RAFRAÎCHISSEMENT DES STATUTS SANS RELOAD
    // (reconstruit les options + réinitialise Select2)
    // ============================================
    function refreshListesStatus() {
        const formData = new FormData();
        formData.append('action_count_lists_status', '1');
        formData.append('campagne_config_id', '<?= $campagneConfigId ?>');

        return fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.listes) return;

            const currentValue = $('#liste_id').val();

            // 1. Trier les listes : synchronisées d'abord (comme côté PHP)
            data.listes.sort((a, b) => {
                if (a.est_synchronisee === b.est_synchronisee) {
                    return a.nom_liste.localeCompare(b.nom_liste, 'fr', { sensitivity: 'base' });
                }
                return a.est_synchronisee ? -1 : 1;
            });

            // 2. Détruire Select2
            try { $('#liste_id').select2('destroy'); } catch(e) { /* déjà détruit */ }

            // 3. Reconstruire toutes les <option>
            const $select = $('#liste_id');
            $select.empty();
            $select.append('<option value="">-- Sélectionnez une liste --</option>');

            data.listes.forEach(l => {
                const $opt = $('<option></option>');
                $opt.attr('value', l.id_liste);
                $opt.attr('data-listmonk-id', l.listmonk_id || '');
                $opt.attr('data-est-synchronisee', l.est_synchronisee ? '1' : '0');
                $opt.attr('data-raison-non-sync', l.raison_non_sync || '');
                $opt.attr('data-lm-count', l.listmonk_subscriber_count !== null ? l.listmonk_subscriber_count : '');
                $opt.attr('data-app-count', l.nombre_contacts);
                $opt.text(l.nom_liste + ' — ' + l.nombre_contacts + ' contact(s)');
                $select.append($opt);
            });

            // 4. Restaurer la sélection
            if (currentValue) {
                $select.val(currentValue);
            }

            // 5. Réinitialiser Select2
            $select.select2({
                placeholder: "-- Sélectionnez une liste --",
                allowClear: true,
                width: '100%',
                language: 'fr',
                templateResult: renderListeOption,
                templateSelection: renderListeOption,
                escapeMarkup: function(m) { return m; }
            });

            // 6. Mettre à jour LISTES_DATA pour les prochains refresh
            LISTES_DATA.length = 0;
            data.listes.forEach(l => LISTES_DATA.push(l));

            // 7. Rafraîchir la boîte de sync
            setTimeout(() => {
                $('#liste_id').trigger('change');
                updateSyncBox();
            }, 50);
        })
        .catch(err => console.warn('Erreur refresh listes:', err));
    }

    // ============================================
    // GESTION DU FICHIER (upload AJAX)
    // ============================================
    const fileUploadArea = document.getElementById('fileUploadArea');
    const pieceJointeInput = document.getElementById('piece_jointe');
    const uploadButton = document.getElementById('uploadButton');
    const fileLabel = document.getElementById('fileLabel');
    let selectedFile = null;

    function handleFile(file) {
        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
        if (file.size > 10 * 1024 * 1024) {
            showToast('Le fichier est trop volumineux. Maximum 10 Mo.', 'error');
            resetFileUpload();
            return;
        }
        selectedFile = file;
        uploadButton.disabled = false;
        fileLabel.textContent = file.name + ' (' + sizeMB + ' Mo)';
        fileLabel.style.color = '#16a34a';
        const icon = fileUploadArea.querySelector('.upload-icon i');
        if (icon) icon.className = 'fas fa-file text-3xl text-green-500 mb-2';

        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        pieceJointeInput.files = dataTransfer.files;
    }

    function resetFileUpload() {
        pieceJointeInput.value = '';
        selectedFile = null;
        uploadButton.disabled = true;
        fileLabel.textContent = 'Cliquez ou glissez un fichier ici';
        fileLabel.style.color = '#6b7280';
        const icon = fileUploadArea.querySelector('.upload-icon i');
        if (icon) icon.className = 'fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2';
    }

    uploadButton.addEventListener('click', function() {
        if (!selectedFile) {
            showToast('Veuillez sélectionner un fichier', 'error');
            return;
        }

        uploadButton.disabled = true;
        uploadButton.classList.add('loading');
        uploadButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importation...';

        const formData = new FormData();
        formData.append('action_upload_file', '1');
        formData.append('piece_jointe', selectedFile);
        formData.append('campagne_config_id', '<?= $campagneConfigId ?>');

        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.text().then(text => ({ ok: res.ok, status: res.status, text })))
        .then(result => {
            uploadButton.disabled = false;
            uploadButton.classList.remove('loading');
            uploadButton.innerHTML = '<i class="fas fa-upload"></i> Importer';

            let data;
            try { data = JSON.parse(result.text); }
            catch (e) {
                console.error('Réponse non-JSON:', result.text);
                showToast('❌ Réponse serveur invalide.', 'error');
                return;
            }

            if (data.success) {
                document.getElementById('media_id').value = data.media_id;
                showToast('✅ ' + data.message, 'success');
                updateUploadedFileInfo(data.media_id, data.file_name);
                resetFileUpload();
            } else {
                showToast('❌ ' + data.message, 'error');
            }
        })
        .catch(error => {
            showToast('❌ Erreur de connexion: ' + error.message, 'error');
            uploadButton.disabled = false;
            uploadButton.classList.remove('loading');
            uploadButton.innerHTML = '<i class="fas fa-upload"></i> Importer';
        });
    });

    function updateUploadedFileInfo(mediaId, fileName) {
        const oldInfo = document.querySelector('.uploaded-file-info');
        if (oldInfo) oldInfo.remove();

        const infoDiv = document.createElement('div');
        infoDiv.className = 'uploaded-file-info';
        infoDiv.innerHTML = `
            <div class="file-details">
                <i class="fas fa-file"></i>
                <div>
                    <div class="font-medium text-gray-800">${escapeHtml(fileName)}</div>
                    <div class="text-xs text-gray-500">
                        <i class="fas fa-check-circle text-green-600"></i> Importé sur Listmonk
                        <span class="media-id">ID: ${mediaId}</span>
                    </div>
                </div>
            </div>
        `;
        const container = document.querySelector('.file-upload-container');
        if (container) container.parentNode.insertBefore(infoDiv, container.nextSibling);

        const removeBtn = document.querySelector('.btn-upload-remove');
        if (!removeBtn) {
            const actions = document.querySelector('.upload-actions');
            if (actions) {
                const newRemoveBtn = document.createElement('a');
                newRemoveBtn.href = '?page=campagnes/composer&campagne_config_id=<?= $campagneConfigId ?>&remove_upload=1';
                newRemoveBtn.className = 'btn-upload-remove';
                newRemoveBtn.innerHTML = '<i class="fas fa-trash"></i> Supprimer';
                actions.appendChild(newRemoveBtn);
            }
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    fileUploadArea.addEventListener('click', function(e) {
        if (e.target.closest('button')) return;
        pieceJointeInput.click();
    });

    pieceJointeInput.addEventListener('change', function(e) {
        if (e.target.files.length > 0) handleFile(e.target.files[0]);
    });

    fileUploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('drag-over');
    });
    fileUploadArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.classList.remove('drag-over');
    });
    fileUploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        if (e.dataTransfer.files.length > 0) handleFile(e.dataTransfer.files[0]);
    });

    // ============================================
    // VALIDATION FORMULAIRE
    // ============================================
    document.getElementById('composerForm').addEventListener('submit', function(e) {
        const listeId = document.getElementById('liste_id').value;
        const objet = document.getElementById('objet').value.trim();
        const corps = $('#corps').summernote('code');
        const fromEmail = document.getElementById('from_email').value.trim();
        const fromName = document.getElementById('from_name').value.trim();

        if (!fromEmail) { e.preventDefault(); showToast('Veuillez saisir l\'email de l\'expéditeur', 'error'); return false; }
        if (!fromName) { e.preventDefault(); showToast('Veuillez saisir le nom de l\'expéditeur', 'error'); return false; }
        if (!listeId) { e.preventDefault(); showToast('Veuillez sélectionner une liste de diffusion', 'error'); return false; }

        const selectedOption = $('#liste_id option:selected');
        const isSync = selectedOption.data('est-synchronisee') === 1 || selectedOption.data('est-synchronisee') === '1';

        if (!isSync) {
            e.preventDefault();
            showToast('Cette liste n\'est pas synchronisée avec Listmonk. Veuillez la synchroniser.', 'warning');
            return false;
        }

        if (!objet) { e.preventDefault(); showToast('Veuillez saisir un objet', 'error'); return false; }
        if (!corps || corps === '<p><br></p>' || corps === '<p>\u200b</p>') {
            e.preventDefault();
            showToast('Veuillez saisir le corps du message', 'error');
            return false;
        }

        $('#corps').val(corps);
    });

    // Planification
    document.querySelectorAll('input[name="envoyer_maintenant"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            const zone = document.getElementById('planificationZone');
            if (this.value === '0') zone.style.display = 'block';
            else { zone.style.display = 'none'; document.getElementById('date_planification').value = ''; }
        });
    });
});

// ============================================
// TOAST
// ============================================
function showToast(message, type = 'success') {
    document.querySelectorAll('.toast-notification').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const colors = { success: '#10b981', error: '#ef4444', info: '#3b82f6', warning: '#f59e0b' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s';
        setTimeout(() => toast.remove(), 500);
    }, 5000);
}
</script>

</body>
</html>