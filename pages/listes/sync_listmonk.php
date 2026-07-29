<?php
// Fichier: sync_listmonk.php
// Ce fichier est dans /pages/listes/

session_start();

// Désactiver l'affichage des erreurs
error_reporting(0);

// Nettoyer tout buffer existant
while (ob_get_level()) {
    ob_end_clean();
}

// Forcer le type de contenu JSON
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Fonction pour envoyer une réponse JSON et exit
function sendJsonResponse($data) {
    echo json_encode($data);
    exit;
}

// Vérifier la session
if (!isset($_SESSION['user_id'])) {
    sendJsonResponse(['success' => false, 'error' => 'Session expirée']);
}

// Vérifier la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['synchroniser_listmonk'])) {
    sendJsonResponse(['success' => false, 'error' => 'Requête invalide']);
}

// Vérification AJAX
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    sendJsonResponse(['success' => false, 'error' => 'Requête AJAX requise']);
}

try {
    // ============================================
    // INCLUSION DE LA BASE DE DONNÉES
    // ============================================
    $dbPath = null;
    $possiblePaths = [
        __DIR__ . '/../../includes/db.php',
    ];
    
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $dbPath = $path;
            break;
        }
    }
    
    if (!$dbPath) {
        sendJsonResponse(['success' => false, 'error' => 'Fichier de base de données non trouvé']);
    }
    
    require_once $dbPath;
    
    if (!isset($db)) {
        sendJsonResponse(['success' => false, 'error' => 'Connexion à la base de données non disponible']);
    }
    
    // Récupérer l'ID de la liste
    $id_liste = $_POST['id_liste'] ?? null;
    
    if (!$id_liste) {
        sendJsonResponse(['success' => false, 'error' => 'ID de liste manquant']);
    }
    
    // Récupérer la liste
    $listes = $db->select('liste', ['id_liste' => $id_liste, 'id_compte' => $_SESSION['user_id']]);
    if (!$listes) {
        sendJsonResponse(['success' => false, 'error' => 'Liste non trouvée']);
    }
    $liste = $listes[0];
    
    // Vérifier que la liste a un listmonk_id
    if (empty($liste['listmonk_id'])) {
        sendJsonResponse(['success' => false, 'error' => 'Cette liste n\'est pas liée à une liste Listmonk']);
    }
    
    $listmonkId = (int)$liste['listmonk_id'];
    
    // Récupérer les contacts de la liste
    $listeContacts = $db->select('liste_contact', ['id_liste' => $id_liste]);
    $contactsToSync = [];
    
    if (!empty($listeContacts) && is_array($listeContacts)) {
        foreach ($listeContacts as $lc) {
            $contact = $db->select('contact', ['id_contact' => $lc['id_contact'], 'id_compte' => $_SESSION['user_id']]);
            if (!empty($contact) && is_array($contact) && isset($contact[0]) && is_array($contact[0])) {
                $contactsToSync[] = $contact[0];
            }
        }
    }
    
    if (empty($contactsToSync)) {
        sendJsonResponse(['success' => false, 'error' => 'Aucun contact à synchroniser']);
    }
    
    // ============================================
    // CONFIGURATION LISTMONK
    // ============================================
    $apiBaseUrl = 'http://164.68.103.147:9005/api';
    $username = 'test';
    $password = 'lqXJrA1sfE1YobhQ0CyP9UiMpi1MOsb83p554Uuc1IRDKVRR';
    
    // ============================================
    // FONCTION CURL SIMPLIFIÉE
    // ============================================
    function callListmonk($url, $method = 'GET', $data = null) {
        global $username, $password;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
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
    
    // ============================================
    // RÉCUPÉRER TOUS LES ABONNÉS EXISTANTS
    // ============================================
    $allSubscribers = [];
    $result = callListmonk($apiBaseUrl . '/subscribers');
    
    if ($result['error']) {
        sendJsonResponse(['success' => false, 'error' => 'Erreur de connexion à Listmonk: ' . $result['error']]);
    }
    
    if ($result['httpCode'] === 200) {
        $data = json_decode($result['response'], true);
        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $subscriber) {
                if (isset($subscriber['email']) && isset($subscriber['id'])) {
                    $allSubscribers[strtolower(trim($subscriber['email']))] = $subscriber['id'];
                }
            }
        }
    }
    
    // ============================================
    // TRAITEMENT DES CONTACTS
    // ============================================
    $subscriberIdsToAdd = [];
    $errors = [];
    $details = [];
    $contactsWithoutEmail = [];
    
    foreach ($contactsToSync as $contact) {
        $email = trim($contact['email'] ?? '');
        if (empty($email)) {
            $contactsWithoutEmail[] = $contact['prenom'] . ' ' . $contact['nom'];
            continue;
        }
        
        $emailLower = strtolower($email);
        
        // Vérifier si l'email existe déjà dans la liste récupérée
        if (isset($allSubscribers[$emailLower])) {
            $subscriberIdsToAdd[] = $allSubscribers[$emailLower];
            $details[] = "✓ {$email} existe déjà (ID: {$allSubscribers[$emailLower]})";
            continue;
        }
        
        // Essayer de créer l'abonné
        $data = [
            'email' => $email,
            'name' => trim($contact['prenom'] . ' ' . $contact['nom']),
            'status' => 'enabled',
            'lists' => [],
            'attribs' => new stdClass()
        ];
        
        $result = callListmonk($apiBaseUrl . '/subscribers', 'POST', $data);
        
        if ($result['error']) {
            $errors[] = "Erreur CURL pour {$email}: " . $result['error'];
            continue;
        }
        
        // Si la création réussit
        if ($result['httpCode'] === 200 || $result['httpCode'] === 201) {
            $responseData = json_decode($result['response'], true);
            if (isset($responseData['data']['id'])) {
                $subscriberIdsToAdd[] = $responseData['data']['id'];
                $details[] = "✓ {$email} créé (ID: {$responseData['data']['id']})";
                $allSubscribers[$emailLower] = $responseData['data']['id'];
            } else {
                $errors[] = "Erreur création {$email}: Réponse inattendue";
            }
            continue;
        }
        
        // Si erreur 409 (conflit - email existe déjà)
        if ($result['httpCode'] === 409) {
            // Méthode 1: Extraire l'ID du message d'erreur
            $responseData = json_decode($result['response'], true);
            $subscriberId = null;
            
            // Chercher l'ID dans le message d'erreur
            if (isset($responseData['error']) && preg_match('/ID[:\s]+(\d+)/i', $responseData['error'], $matches)) {
                $subscriberId = $matches[1];
                $details[] = "✓ {$email} existe déjà (ID: {$subscriberId}) - extrait de l'erreur";
            }
            
            // Si pas trouvé, faire une recherche spécifique
            if (!$subscriberId) {
                // Utiliser la méthode que vous avez donnée
                $searchUrl = $apiBaseUrl . '/subscribers?query=subscribers.email=\'' . addslashes($email) . '\'';
                $searchResult = callListmonk($searchUrl, 'GET');
                
                if ($searchResult['httpCode'] === 200) {
                    $searchData = json_decode($searchResult['response'], true);
                    
                    // Vérifier différents formats de réponse
                    if (isset($searchData['data']) && is_array($searchData['data'])) {
                        // Format: data est un tableau d'abonnés
                        if (isset($searchData['data'][0]['id'])) {
                            $subscriberId = $searchData['data'][0]['id'];
                            $details[] = "✓ {$email} existe déjà (ID: {$subscriberId}) - trouvé par recherche";
                        }
                        // Format: data est un objet avec results
                        elseif (isset($searchData['data']['results']) && is_array($searchData['data']['results']) && isset($searchData['data']['results'][0]['id'])) {
                            $subscriberId = $searchData['data']['results'][0]['id'];
                            $details[] = "✓ {$email} existe déjà (ID: {$subscriberId}) - trouvé par recherche";
                        }
                        // Format: data a un champ total
                        elseif (isset($searchData['data']['total']) && $searchData['data']['total'] > 0) {
                            // Chercher dans items ou results
                            if (isset($searchData['data']['items']) && is_array($searchData['data']['items']) && isset($searchData['data']['items'][0]['id'])) {
                                $subscriberId = $searchData['data']['items'][0]['id'];
                                $details[] = "✓ {$email} existe déjà (ID: {$subscriberId}) - trouvé par recherche";
                            }
                        }
                    }
                    // Si la réponse est un booléen (true/false)
                    elseif (is_bool($searchData)) {
                        if ($searchData === true) {
                            // L'email existe mais on n'a pas d'ID
                            // On va essayer de récupérer l'ID en faisant une recherche sans query
                            $allResult = callListmonk($apiBaseUrl . '/subscribers', 'GET');
                            if ($allResult['httpCode'] === 200) {
                                $allData = json_decode($allResult['response'], true);
                                if (isset($allData['data']) && is_array($allData['data'])) {
                                    foreach ($allData['data'] as $sub) {
                                        if (strtolower(trim($sub['email'])) === $emailLower) {
                                            $subscriberId = $sub['id'];
                                            $details[] = "✓ {$email} existe déjà (ID: {$subscriberId}) - trouvé dans la liste complète";
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            if ($subscriberId) {
                $subscriberIdsToAdd[] = $subscriberId;
                $allSubscribers[$emailLower] = $subscriberId;
            } else {
                $errors[] = "Conflit 409 pour {$email}: impossible de récupérer l'ID de l'abonné";
            }
            continue;
        }
        
        // Autres erreurs
        $responseData = json_decode($result['response'], true);
        $errorMessage = isset($responseData['error']) ? $responseData['error'] : "HTTP " . $result['httpCode'];
        $errors[] = "Erreur création {$email}: $errorMessage";
    }
    
    // ============================================
    // AJOUTER À LA LISTE LISTMONK
    // ============================================
    $syncedCount = 0;
    
    if (!empty($subscriberIdsToAdd)) {
        $uniqueIds = array_unique($subscriberIdsToAdd);
        
        $data = [
            'ids' => $uniqueIds,
            'action' => 'add',
            'target_list_ids' => [$listmonkId],
            'status' => 'enabled'
        ];
        
        $result = callListmonk($apiBaseUrl . '/subscribers/lists', 'PUT', $data);
        
        if ($result['error']) {
            $errors[] = "Erreur CURL pour l'ajout à la liste: " . $result['error'];
        } else if ($result['httpCode'] === 200 || $result['httpCode'] === 201 || $result['httpCode'] === 204) {
            $syncedCount = count($uniqueIds);
            $details[] = "✓ Ajout de " . count($uniqueIds) . " abonnés à la liste (ID: $listmonkId)";
        } else {
            $responseData = json_decode($result['response'], true);
            $errorMessage = isset($responseData['error']) ? $responseData['error'] : "HTTP " . $result['httpCode'];
            $errors[] = "Erreur ajout à la liste: $errorMessage";
        }
    }
    
    if (!empty($contactsWithoutEmail)) {
        $errors[] = count($contactsWithoutEmail) . " contact(s) sans email: " . implode(', ', $contactsWithoutEmail);
    }
    
    // ============================================
    // RÉPONSE
    // ============================================
    if ($syncedCount > 0) {
        $message = "$syncedCount contact(s) synchronisé(s) vers Listmonk";
        if (!empty($errors)) {
            $message .= " (" . count($errors) . " erreur(s))";
        }
        sendJsonResponse([
            'success' => true,
            'message' => $message,
            'errors' => $errors,
            'details' => $details,
            'stats' => [
                'total' => count($contactsToSync),
                'created' => count(array_filter($details, function($d) { return strpos($d, 'créé') !== false; })),
                'existing' => count(array_filter($details, function($d) { return strpos($d, 'existe déjà') !== false; })),
                'added_to_list' => $syncedCount,
                'without_email' => count($contactsWithoutEmail)
            ]
        ]);
    } else {
        $errorMsg = "Aucun contact synchronisé. ";
        if (!empty($errors)) {
            $errorMsg .= implode('; ', $errors);
        }
        sendJsonResponse([
            'success' => false, 
            'error' => $errorMsg, 
            'errors' => $errors, 
            'details' => $details
        ]);
    }
    
} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Erreur interne: ' . $e->getMessage()
    ]);
}