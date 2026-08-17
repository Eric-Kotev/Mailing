<?php
global $db;

$idCompte = $_SESSION['user_id'];
$campagneId = $_GET['id'] ?? null;

if (!$campagneId) {
    header('Location: index.php?page=campagnes/index');
    exit;
}

// Récupérer la campagne
$campagne = $db->select('campagne_config', ['id_campagne_config' => $campagneId, 'id_compte' => $idCompte]);
if (empty($campagne)) {
    header('Location: index.php?page=campagnes/index');
    exit;
}
$campagne = $campagne[0];

// Récupérer tous les envois liés à cette campagne
$allEnvois = $db->select('campagne', ['id_campagne_config' => $campagneId], '*', 'created_at DESC');

// Filtrer pour exclure les brouillons
$envois = array_filter($allEnvois, function($e) {
    return $e['statut'] !== 'brouillon';
});
$envois = array_values($envois);

$totalEnvois = count($envois);
$totalSucces = 0;
$totalErreurs = 0;
$totalWhatsApp = 0;
$totalSms = 0;
$totalEmail = 0;
$totalAPreparer = 0;
$totalPlanifies = 0;

foreach ($envois as $e) {
    $totalSucces += $e['nb_succes'];
    $totalErreurs += $e['nb_erreurs'];
    if ($e['type_campagne'] == 'whatsapp') {
        $totalWhatsApp++;
    } elseif ($e['type_campagne'] == 'email') {
        $totalEmail++;
    } else {
        $totalSms++;
    }
    if ($e['statut'] == 'pret_a_envoyer') {
        $totalAPreparer++;
    }
    if ($e['statut'] == 'planifiee') {
        $totalPlanifies++;
    }
}

// ============================================
// FONCTION DE DÉDUCTION DU CRÉDIT CLIENT AVEC TRANSACTION
// ============================================
// Soustrait du crédit du compte client (table compte, colonne credits_total)
// le montant correspondant au tarif de l'opérateur (table provider, colonne tarif)
// multiplié par le nombre d'envois réellement réussis.
function deduireCreditClient($idCompte, $idProvider, $quantite, $description = null) {
    global $db;

    if (empty($idProvider) || $quantite <= 0) {
        return;
    }

    // Récupérer l'opérateur
    $provider = $db->select('provider', ['id_provider' => $idProvider]);
    if (empty($provider)) {
        return;
    }

    // Récupérer le tarif personnalisé du client pour cet opérateur
    $tarifPersonnalise = $db->select('tarif', [
        'id_compte' => $idCompte,
        'id_provider' => $idProvider
    ]);

    // Déterminer le tarif à utiliser
    $tarif = !empty($tarifPersonnalise) 
        ? (float)$tarifPersonnalise[0]['prix'] 
        : (float)$provider[0]['tarif'];

    if ($tarif <= 0) {
        return;
    }

    $montant = $tarif * $quantite;

    // Récupérer et mettre à jour le compte
    $compte = $db->select('compte', ['id_compte' => $idCompte]);
    if (empty($compte)) {
        return;
    }

    $creditsActuels = (float)($compte[0]['credits_total'] ?? 0);
    $nouveauSolde = $creditsActuels - $montant;

    // Mettre à jour le crédit
    $db->update('compte', [
        'credits_total' => $nouveauSolde
    ], ['id_compte' => $idCompte]);

    // ============================================
    // ENREGISTRER LA TRANSACTION DE DÉBIT
    // ============================================
    $nomProvider = $provider[0]['nom_providers'] ?? 'Inconnu';
    $descriptionTransaction = $description ?? "Envoi de {$quantite} message(s) via {$nomProvider}";

    $transactionData = [
        'id_compte' => $idCompte,
        'id_provider' => $idProvider,
        'type_transaction' => 'debit',
        'montant' => $montant,
        'description' => $descriptionTransaction,
        'solde_avant' => $creditsActuels,
        'solde_apres' => $nouveauSolde,
        'created_at' => date('Y-m-d H:i:s')
    ];

    $db->insert('transactions', $transactionData);
}

// ============================================
// FONCTION DE RÉSOLUTION DU PROVIDER PAR FOURNISSEUR
// ============================================

function getProviderByFournisseur($fournisseurLabel) {
    global $db;
    
    // Ne pas filtrer par id_compte, récupérer tous les providers
    $providers = $db->select('provider', [], '*', 'description ASC');
    
    if (empty($providers)) {
        return null;
    }
    
    $target = mb_strtolower(trim($fournisseurLabel));
    
    foreach ($providers as $p) {
        if (mb_strtolower(trim($p['description'])) === $target) {
            return $p;
        }
    }
    
    return null;
}

// ============================================
// FONCTION DE MISE À JOUR DU STATUT GLOBAL
// ============================================
function mettreAJourStatutCampagne($idCampagneConfig, $idCompte) {
    global $db;
    
    $campagneConfig = $db->select('campagne_config', [
        'id_campagne_config' => $idCampagneConfig,
        'id_compte' => $idCompte
    ]);
    
    if (empty($campagneConfig)) {
        return;
    }
    
    $campagneActuelle = $campagneConfig[0];
    $statutActuel = $campagneActuelle['statut'] ?? 'brouillon';
    
    if ($statutActuel === 'planifiee') {
        $messages = $db->select('campagne', [
            'id_campagne_config' => $idCampagneConfig,
            'id_compte' => $idCompte
        ]);
        
        if (empty($messages)) {
            return;
        }
        
        $nbEnvoyes = 0;
        $nbEchoues = 0;
        $nbTotal = count($messages);
        
        foreach ($messages as $msg) {
            $statut = strtolower(trim($msg['statut']));
            if ($statut === 'envoye') {
                $nbEnvoyes++;
            } elseif ($statut === 'echoue') {
                $nbEchoues++;
            }
        }
        
        if ($nbEnvoyes == $nbTotal && $nbTotal > 0) {
            $db->update('campagne_config', [
                'statut' => 'envoyee',
                'sent_at' => date('Y-m-d H:i:s')
            ], [
                'id_campagne_config' => $idCampagneConfig,
                'id_compte' => $idCompte
            ]);
            return;
        }
        
        if ($nbEchoues == $nbTotal && $nbTotal > 0) {
            $db->update('campagne_config', [
                'statut' => 'echoue',
                'sent_at' => null
            ], [
                'id_campagne_config' => $idCampagneConfig,
                'id_compte' => $idCompte
            ]);
            return;
        }
        
        return;
    }
    
    $messages = $db->select('campagne', [
        'id_campagne_config' => $idCampagneConfig,
        'id_compte' => $idCompte
    ]);
    
    if (empty($messages)) {
        $db->update('campagne_config', [
            'statut' => 'brouillon'
        ], [
            'id_campagne_config' => $idCampagneConfig,
            'id_compte' => $idCompte
        ]);
        return;
    }
    
    $nbTotal = count($messages);
    $nbEnvoyes = 0;
    $nbEchoues = 0;
    $nbPret = 0;
    $nbBrouillon = 0;
    $nbPlanifie = 0;
    
    foreach ($messages as $msg) {
        $statut = strtolower(trim($msg['statut']));
        switch ($statut) {
            case 'envoye':
                $nbEnvoyes++;
                break;
            case 'echoue':
                $nbEchoues++;
                break;
            case 'pret_a_envoyer':
                $nbPret++;
                break;
            case 'brouillon':
                $nbBrouillon++;
                break;
            case 'planifiee':
                $nbPlanifie++;
                break;
        }
    }
    
    if ($nbEnvoyes == $nbTotal && $nbTotal > 0) {
        $statut = 'envoyee';
        $sent_at = date('Y-m-d H:i:s');
    } elseif ($nbEchoues == $nbTotal && $nbTotal > 0) {
        $statut = 'echoue';
        $sent_at = null;
    } elseif ($nbEnvoyes > 0 || $nbEchoues > 0) {
        $statut = 'partiel';
        $sent_at = null;
    } elseif ($nbPret > 0) {
        $statut = 'pret_a_envoyer';
        $sent_at = null;
    } elseif ($nbPlanifie > 0) {
        $statut = 'planifiee';
        $sent_at = null;
    } else {
        $statut = 'brouillon';
        $sent_at = null;
    }
    
    $updateData = ['statut' => $statut];
    if ($statut === 'envoyee') {
        $updateData['sent_at'] = $sent_at;
    } else {
        $updateData['sent_at'] = null;
    }
    
    $db->update('campagne_config', $updateData, [
        'id_campagne_config' => $idCampagneConfig,
        'id_compte' => $idCompte
    ]);
}

// ============================================
// FONCTION POUR FORMATER LES NUMÉROS (UNIQUEMENT FRANCE)
// ============================================
function formaterNumerosOctopush($destinataires) {
    $formatted = [];
    
    foreach ($destinataires as $dest) {
        $telephone = null;
        
        if (is_array($dest) && isset($dest['phone_number'])) {
            $telephone = $dest['phone_number'];
        } elseif (is_string($dest) && preg_match('/\(([^)]+)\)/', $dest, $matches)) {
            $telephone = $matches[1];
        } elseif (is_string($dest) && preg_match('/[0-9+\s]+/', $dest, $matches)) {
            $telephone = trim($matches[0]);
        }
        
        if (empty($telephone)) {
            continue;
        }
        
        $telephone = trim($telephone);
        
        if (substr($telephone, 0, 1) == '+') {
            $checkNumber = preg_replace('/[^0-9]/', '', $telephone);
            if (strlen($checkNumber) >= 9 && strlen($checkNumber) <= 15) {
                $formatted[] = $telephone;
            }
            continue;
        }
        
        $telephone = preg_replace('/[^0-9]/', '', $telephone);
        
        if (substr($telephone, 0, 1) == '0') {
            $telephone = '+33' . substr($telephone, 1);
        } else {
            if (strlen($telephone) == 10) {
                $telephone = '+33' . $telephone;
            } elseif (strlen($telephone) == 11 && substr($telephone, 0, 2) == '33') {
                $telephone = '+' . $telephone;
            } elseif (strlen($telephone) == 12 && substr($telephone, 0, 3) == '261') {
                $telephone = '+' . $telephone;
            } elseif (strlen($telephone) > 10 && substr($telephone, 0, 2) == '33') {
                $telephone = '+' . $telephone;
            } else {
                $telephone = '+33' . $telephone;
            }
        }
        
        $checkNumber = preg_replace('/[^0-9]/', '', $telephone);
        if (strlen($checkNumber) >= 9 && strlen($checkNumber) <= 15) {
            $formatted[] = $telephone;
        }
    }
    
    return $formatted;
}

// ============================================
// FONCTION POUR ENVOYER AVEC OCTOPUSH (AVEC DÉDUCTION DE CRÉDIT)
// ============================================
function envoyerOctopush($message, $destinataires, $apiLogin, $apiKey, $idCompte, $idProvider) {
    global $db;
    
    $url = 'https://api.octopush.com/v1/public/sms-campaign/send';
    
    $recipients = [];
    $formattedNumbers = formaterNumerosOctopush($destinataires);
    
    foreach ($formattedNumbers as $numero) {
        $recipients[] = ['phone_number' => $numero];
    }
    
    if (empty($recipients)) {
        return ['success' => false, 'error' => 'Aucun numéro de téléphone valide trouvé.'];
    }
    
    // Récupérer la configuration Octopush pour obtenir le type et le sender
    $config = $db->select('octopush_config', [
        'id_compte' => $idCompte,
        'est_active' => 1
    ]);
    
    $sender = 'IFB';
    $type = 'sms_premium';
    $purpose = 'alert';
    
    if (!empty($config)) {
        $sender = !empty($config[0]['sender_name']) ? $config[0]['sender_name'] : 'IFB';
        $type = !empty($config[0]['type']) ? $config[0]['type'] : 'sms_premium';
        $purpose = !empty($config[0]['purpose']) ? $config[0]['purpose'] : 'alert';
    }
    
    $data = [
        'text' => $message,
        'recipients' => $recipients,
        'sender' => $sender,
        'type' => $type,
        'purpose' => $purpose,
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'api-login: ' . $apiLogin,
        'api-key: ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Octopush cURL Error: " . $error);
        return ['success' => false, 'error' => 'Erreur cURL: ' . $error];
    }
    
    $responseData = json_decode($response, true);
    
    // Si l'envoi est réussi, déduire le crédit
    if ($httpCode === 200 || $httpCode === 201) {
        // Déduire le crédit du client (1 SMS par destinataire)
        $quantite = count($recipients);
        if (!empty($idProvider)) {
            $description = "Envoi Octopush - {$quantite} SMS";
            deduireCreditClient($idCompte, $idProvider, $quantite, $description);
        }
        
        return [
            'success' => true,
            'data' => $responseData,
            'http_code' => $httpCode,
            'sms_envoyes' => $quantite
        ];
    } else {
        $errorMsg = isset($responseData['message']) ? $responseData['message'] : $response;
        if (isset($responseData['errors'])) {
            $errorMsg .= ' - Détails: ' . json_encode($responseData['errors']);
        }
        
        error_log("Octopush Erreur API: " . $errorMsg);
        
        return [
            'success' => false,
            'error' => 'Erreur API (HTTP ' . $httpCode . '): ' . $errorMsg,
            'http_code' => $httpCode
        ];
    }
}

// ============================================
// FONCTION POUR METTRE À JOUR LE STATUT D'UNE CAMPAGNE LISTMONK
// ============================================
function updateListmonkCampaignStatus($campaignId, $status) {
    $apiUrl = "http://164.68.103.147:9005/api/campaigns/{$campaignId}/status";
    $username = 'test';
    $password = 'lqXJrA1sfE1YobhQ0CyP9UiMpi1MOsb83p554Uuc1IRDKVRR';
    
    $payload = ['status' => $status];
    
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'success' => $httpCode === 200 || $httpCode === 201 || $httpCode === 204,
        'http_code' => $httpCode,
        'response' => $response
    ];
}

// ============================================
// Mettre à jour le statut au chargement de la page
// ============================================
mettreAJourStatutCampagne($campagneId, $idCompte);
$campagne = $db->select('campagne_config', ['id_campagne_config' => $campagneId, 'id_compte' => $idCompte]);
if (!empty($campagne)) {
    $campagne = $campagne[0];
}

// ============================================
// TRAITEMENT DE L'ENVOI D'UN MESSAGE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_envoyer_message'])) {
    $id_campagne_historique = $_POST['id_campagne_historique'] ?? null;
    $isOctopush = isset($_POST['is_octopush']) && $_POST['is_octopush'] === '1';
    
    if (!$id_campagne_historique) {
        $_SESSION['flash_error'] = "Message non trouvé";
        header('Location: index.php?page=campagnes/details&id=' . $campagneId);
        exit;
    }
    
    try {
        $historique = $db->select('campagne', [
            'id_campagne' => $id_campagne_historique,
            'id_compte' => $idCompte
        ]);
        
        if (empty($historique)) {
            $_SESSION['flash_error'] = "Message non trouvé";
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        $campagneData = $historique[0];
        $typeMessage = $campagneData['type_campagne'] ?? 'sms';
        
        $destinataires = json_decode($campagneData['destinataires'] ?? '[]', true);
        if (empty($destinataires)) {
            $_SESSION['flash_error'] = "Aucun destinataire trouvé pour ce message";
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        $message = $campagneData['message'] ?? '';
        if (empty($message)) {
            $_SESSION['flash_error'] = "Aucun message trouvé";
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        $campagneConfig = $db->select('campagne_config', [
            'id_campagne_config' => $campagneId,
            'id_compte' => $idCompte
        ]);
        
        if (empty($campagneConfig)) {
            $_SESSION['flash_error'] = "Campagne non trouvée";
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        $campagne = $campagneConfig[0];
        
        if ($isOctopush) {
            $apiLogin = $_SESSION['octopush_api_login'] ?? null;
            $apiKey = $_SESSION['octopush_api_key'] ?? null;
            $sessionName = $_SESSION['octopush_session_name'] ?? 'Octopush API';
            
            $providerOctopush = getProviderByFournisseur('Octopush');
            $idProvider = $providerOctopush['id_provider'] ?? null;

            if (empty($apiLogin) || empty($apiKey)) {
                $campagneDb = $db->select('campagne', [
                    'id_campagne' => $id_campagne_historique,
                    'id_compte' => $idCompte
                ]);
                
                if (!empty($campagneDb) && !empty($campagneDb[0]['api_login']) && !empty($campagneDb[0]['api_key'])) {
                    $apiLogin = $campagneDb[0]['api_login'];
                    $apiKey = $campagneDb[0]['api_key'];
                } else {
                    $octopushConfigId = $campagneData['octopush_config_id'] ?? $campagne['octopush_config_id'] ?? null;
                    
                    if ($octopushConfigId) {
                        $config = $db->select('octopush_config', [
                            'id_config' => $octopushConfigId,
                            'id_compte' => $idCompte
                        ]);
                        
                        if (!empty($config)) {
                            $apiLogin = $config[0]['api_login'];
                            $apiKey = $config[0]['api_key'];
                            $sessionName = $config[0]['nom_config'];
                        }
                    }
                }
            }
            
            if (empty($apiLogin) || empty($apiKey)) {
                $_SESSION['flash_error'] = "❌ Identifiants Octopush manquants.";
                header('Location: index.php?page=campagnes/choix_session_octopush&campagne_id=' . $campagneId);
                exit;
            }
            
            $resultat = envoyerOctopush($message, $destinataires, $apiLogin, $apiKey, $idCompte, $idProvider);
            
            if ($resultat['success']) {
                $db->update('campagne', [
                    'statut' => 'envoye',
                    'nb_envoyes' => count($destinataires),
                    'nb_succes' => count($destinataires),
                    'nb_erreurs' => 0,
                    'appareil_utilise' => 'Octopush - ' . $sessionName,
                    'reponse_api' => json_encode($resultat['data']),
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id_campagne' => $id_campagne_historique]);
                
                $_SESSION['octopush_response'] = $resultat['data'];
                $_SESSION['flash_message'] = "✅ SMS envoyés avec succès via Octopush (Session: " . $sessionName . ")!";
            } else {
                $db->update('campagne', [
                    'statut' => 'echoue',
                    'nb_erreurs' => count($destinataires),
                    'erreur' => $resultat['error'],
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id_campagne' => $id_campagne_historique]);
                
                $_SESSION['flash_error'] = "❌ " . $resultat['error'];
            }
            
            mettreAJourStatutCampagne($campagneId, $idCompte);
            header('Location: index.php?page=campagnes/details&id=' . $campagneId);
            exit;
        }
        
        $min_delay = $_SESSION['min_delay'] ?? $campagne['min_delay'] ?? 60;
        $max_delay = $_SESSION['max_delay'] ?? $campagne['max_delay'] ?? 180;
        
        $pieceJointe = null;
        if (!empty($campagneData['piece_jointe'])) {
            $pieceJointe = json_decode($campagneData['piece_jointe'], true);
        }
        
        switch ($typeMessage) {
            case 'sms':
                $resultat = envoyerSMS($idCompte, $campagneId, $campagne, $campagneData, $message, $destinataires);
                break;
            case 'whatsapp':
                $resultat = envoyerWhatsApp($idCompte, $campagneId, $campagne, $campagneData, $message, $destinataires, $pieceJointe, $min_delay, $max_delay);
                break;
            case 'email':
                $resultat = envoyerEmail($idCompte, $campagneId, $campagne, $campagneData, $message, $destinataires);
                break;
            default:
                $_SESSION['flash_error'] = "Type de message non supporté: " . $typeMessage;
                header('Location: index.php?page=campagnes/details&id=' . $campagneId);
                exit;
        }
        
        if ($resultat['success']) {
            $_SESSION['flash_message'] = "✅ " . $resultat['message'];
        } else {
            $_SESSION['flash_error'] = "❌ Erreur lors de l'envoi : " . $resultat['error'];
        }
        
        mettreAJourStatutCampagne($campagneId, $idCompte);
        
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "❌ Erreur lors de l'envoi : " . $e->getMessage();
    }
    
    header('Location: index.php?page=campagnes/details&id=' . $campagneId);
    exit;
}

// ============================================
// FONCTIONS D'ENVOI (SMS, WhatsApp, Email)
// ============================================

function envoyerSMS($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires) {
    global $db;
    
    try {
        $device_id = $campagne['device_id'] ?? null;
        $appareilId = $campagne['appareil_id'] ?? null;
        
        // Résolution du provider par fournisseur (SMS API Gateway) plutôt que
        // via campagne_config.provider_id, qui peut être obsolète ou incorrect.
        $providerSms = getProviderByFournisseur('SMS API Gateway');
        $providerId = $providerSms['id_provider'] ?? null;
        
        if (!$providerId) {
            return ['success' => false, 'error' => 'Provider SMS API Gateway non configuré'];
        }
        
        if (empty($device_id)) {
            return ['success' => false, 'error' => 'device_id non configuré.'];
        }
        
        if (empty($appareilId)) {
            return ['success' => false, 'error' => 'appareil_id non configuré.'];
        }
        
        $appareil = $db->select('sms_appareils', [
            'id_appareil' => $appareilId,
            'id_compte' => $idCompte
        ]);
        
        if (empty($appareil)) {
            return ['success' => false, 'error' => 'Appareil non trouvé'];
        }
        
        $device_name = $appareil[0]['device_name'] ?? 'Appareil SMS';
        $api_username = $appareil[0]['api_username'];
        $api_password = $appareil[0]['api_password'];
        
        if (empty($api_username) || empty($api_password)) {
            return ['success' => false, 'error' => 'Identifiants API SMS manquants.'];
        }
        
        $recipients = [];
        foreach ($destinataires as $dest) {
            if (preg_match('/\(([^)]+)\)/', $dest, $matches)) {
                $telephone = $matches[1];
                $telephone = preg_replace('/[^0-9]/', '', $telephone);
                
                if (strlen($telephone) == 10 && substr($telephone, 0, 1) == '0') {
                    $telephone = '261' . substr($telephone, 1);
                }
                if (substr($telephone, 0, 3) != '261' && strlen($telephone) > 0) {
                    $telephone = '261' . $telephone;
                }
                $recipients[] = '+' . $telephone;
            }
        }
        
        if (empty($recipients)) {
            return ['success' => false, 'error' => 'Aucun numéro de téléphone valide trouvé'];
        }
        
        $apiUrl = 'http://164.68.103.147:8085/api.php/sendBulk';
        
        $data = [
            'text' => $message,
            'recipients' => $recipients,
            'api_username' => $api_username,
            'api_password' => $api_password,
            'device_id' => $device_id,
            'user_id' => 'campagne_' . $id_campagne . '_' . date('Ymd_His')
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $statut = ($httpCode === 200) ? 'envoye' : 'echoue';
        $nb_succes = ($httpCode === 200) ? count($recipients) : 0;
        $nb_erreurs = ($httpCode === 200) ? 0 : count($recipients);
        
        $db->update('campagne', [
            'statut' => $statut,
            'nb_envoyes' => count($recipients),
            'nb_succes' => $nb_succes,
            'nb_erreurs' => $nb_erreurs,
            'appareil_utilise' => $device_name . ' (' . $device_id . ')',
            'erreur' => ($httpCode !== 200) ? $response : null
        ], ['id_campagne' => $campagneData['id_campagne']]);
        
        if ($httpCode === 200) {
            // Déduction du crédit client : tarif de l'opérateur SMS x nombre d'envois réussis
            $description = "Envoi SMS via {$device_name} - {$nb_succes} message(s)";
            deduireCreditClient($idCompte, $providerId, $nb_succes, $description);
            
            return ['success' => true, 'message' => count($recipients) . ' SMS envoyés avec succès'];
        } else {
            return ['success' => false, 'error' => 'Erreur API (HTTP ' . $httpCode . '): ' . substr($response, 0, 200)];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function envoyerWhatsApp($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires, $pieceJointe = null, $min_delay = 60, $max_delay = 180) {
    global $db;
    
    try {
        // Résolution du provider par fournisseur (WAHA) plutôt que via
        // campagne_config.provider_id, qui peut être obsolète ou incorrect.
        $providerWaha = getProviderByFournisseur('WAHA');
        $providerId = $providerWaha['id_provider'] ?? null;
        
        $session = $db->select('whatsapp_sessions', [
            'id_compte' => $idCompte,
            'est_active' => true
        ]);
        
        if (empty($session)) {
            $session = $db->select('whatsapp_sessions', [
                'id_compte' => $idCompte
            ], '*', 'created_at DESC', 1);
            
            if (empty($session)) {
                return ['success' => false, 'error' => 'Aucune session WhatsApp configurée'];
            }
            
            $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $session[0]['id_session']]);
        }
        
        $whatsappSession = $session[0]['nom_session'];
        
        $apiUrl = 'http://164.68.103.147:8081/api/controller.php/messages/send-bulk';
        $apiKey = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
        
        $contacts = [];
        
        foreach ($destinataires as $dest) {
            $telephone = null;
            
            if (is_array($dest) && isset($dest['phone_number'])) {
                $telephone = $dest['phone_number'];
            } elseif (is_string($dest) && preg_match('/\(([^)]+)\)/', $dest, $matches)) {
                $telephone = $matches[1];
            } elseif (is_string($dest) && preg_match('/[0-9+\s]+/', $dest, $matches)) {
                $telephone = trim($matches[0]);
            }
            
            if (empty($telephone)) {
                continue;
            }
            
            $telephone = preg_replace('/[^0-9]/', '', $telephone);
            
            if (strlen($telephone) >= 9 && strlen($telephone) <= 10) {
                if (substr($telephone, 0, 1) == '0') {
                    $telephone = '261' . substr($telephone, 1);
                } elseif (strlen($telephone) == 9) {
                    $telephone = '261' . $telephone;
                } elseif (strlen($telephone) == 10 && substr($telephone, 0, 3) != '261') {
                    $telephone = '261' . $telephone;
                }
            } else {
                if (substr($telephone, 0, 3) != '261' && strlen($telephone) > 0) {
                    $telephone = '261' . $telephone;
                }
            }
            
            $contacts[] = $telephone;
        }
        
        if (empty($contacts)) {
            return ['success' => false, 'error' => 'Aucun numéro de téléphone valide trouvé.'];
        }
        
        $fichierData = null;
        if ($pieceJointe && isset($pieceJointe['url']) && !empty($pieceJointe['url'])) {
            $fileUrl = $pieceJointe['url'];
            $fileMimeType = $pieceJointe['mime_type'] ?? 'application/octet-stream';
            $fileName = $pieceJointe['nom'] ?? 'fichier';
            
            $ch = curl_init($fileUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $fileContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && !empty($fileContent)) {
                $fileData = base64_encode($fileContent);
                
                $mediaType = 'file';
                if (strpos($fileMimeType, 'image/') !== false) {
                    $mediaType = 'image';
                } elseif (strpos($fileMimeType, 'video/') !== false) {
                    $mediaType = 'video';
                } elseif (strpos($fileMimeType, 'audio/') !== false) {
                    $mediaType = 'voice';
                }
                
                $fichierData = [
                    'type' => $mediaType,
                    'payload' => [
                        'data' => $fileData,
                        'mimetype' => $fileMimeType,
                        'filename' => $fileName
                    ],
                    'fichier_pret' => true
                ];
            }
        }
        
        if ($fichierData && $fichierData['fichier_pret']) {
            $data = [
                'session' => $whatsappSession,
                'type' => $fichierData['type'],
                'contacts' => $contacts,
                'payload' => $fichierData['payload'],
                'min_delay' => (int)$min_delay,
                'max_delay' => (int)$max_delay
            ];
            
            if ($fichierData['type'] !== 'text' && !empty($message) && $fichierData['type'] !== 'voice') {
                $data['payload']['caption'] = $message;
            }
        } else {
            $data = [
                'session' => $whatsappSession,
                'type' => 'text',
                'contacts' => $contacts,
                'payload' => ['text' => $message],
                'min_delay' => (int)$min_delay,
                'max_delay' => (int)$max_delay
            ];
        }
        
        $jsonData = json_encode($data);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Controller-Key: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        
        if (empty($response) || $response === null) {
            $db->update('campagne', [
                'statut' => 'echoue',
                'nb_envoyes' => 0,
                'nb_succes' => 0,
                'nb_erreurs' => count($contacts),
                'appareil_utilise' => $whatsappSession,
                'reponse_api' => $response,
                'erreur' => 'Réponse vide ou invalide. HTTP Code: ' . $httpCode . ', cURL Error: ' . $curlError,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id_campagne' => $campagneData['id_campagne']]);
            
            return ['success' => false, 'error' => 'Réponse vide ou invalide.'];
        }
        
        $succes = 0;
        $echecs = 0;
        $erreurs = [];
        $details = [];
        $statut = 'echoue';
        $messageReponse = '';
        
        if ($httpCode === 200 && isset($responseData['ok']) && $responseData['ok'] === true) {
            $total = $responseData['total'] ?? count($contacts);
            $validCount = $responseData['valid_count'] ?? 0;
            $invalidCount = $responseData['invalid_count'] ?? 0;
            $invalidContacts = $responseData['invalid_contacts'] ?? [];
            
            if (isset($responseData['results']) && is_array($responseData['results'])) {
                foreach ($responseData['results'] as $result) {
                    if (isset($result['success']) && $result['success'] === true) {
                        $succes++;
                        $details[] = [
                            'contact' => $result['chatId'] ?? 'Inconnu',
                            'statut' => $result['status'] ?? 'sent',
                            'success' => true
                        ];
                    } else {
                        $echecs++;
                        $errorMsg = $result['error'] ?? 'Erreur inconnue';
                        $contactId = $result['chatId'] ?? 'Inconnu';
                        $erreurs[] = $contactId . ': ' . $errorMsg;
                        $details[] = [
                            'contact' => $contactId,
                            'statut' => $result['status'] ?? 'failed',
                            'success' => false,
                            'error' => $errorMsg
                        ];
                    }
                }
            }
            
            if (!empty($invalidContacts)) {
                foreach ($invalidContacts as $invalidContact) {
                    $echecs++;
                    $erreurs[] = $invalidContact . ': Numéro invalide';
                    $details[] = [
                        'contact' => $invalidContact,
                        'statut' => 'invalid',
                        'success' => false,
                        'error' => 'Numéro invalide'
                    ];
                }
            }
            
            $messageReponse = $responseData['message'] ?? 'Envoi terminé';
            
            if ($echecs == 0 && $succes > 0) {
                $statut = 'envoye';
                $messageReponse = "✅ " . $succes . " messages WhatsApp envoyés avec succès";
            } elseif ($succes > 0 && $echecs > 0) {
                $statut = 'partiel';
                $messageReponse = "⚠️ " . $succes . " messages envoyés, " . $echecs . " échecs";
            } else {
                $statut = 'echoue';
                $messageReponse = "❌ Tous les messages ont échoué";
            }
            
        } else {
            $echecs = count($contacts);
            $errorMsg = isset($responseData['message']) ? $responseData['message'] : $response;
            
            if (is_string($errorMsg) && strlen($errorMsg) > 200) {
                $errorMsg = substr($errorMsg, 0, 200) . '...';
            }
            
            $erreurs[] = 'Erreur API (HTTP ' . $httpCode . '): ' . $errorMsg;
            $statut = 'echoue';
            $messageReponse = "❌ Erreur API: " . $errorMsg;
        }
        
        $erreurFinale = !empty($erreurs) ? json_encode($erreurs) : null;
        
        $db->update('campagne', [
            'statut' => $statut,
            'nb_envoyes' => count($contacts),
            'nb_succes' => $succes,
            'nb_erreurs' => $echecs,
            'appareil_utilise' => $whatsappSession,
            'reponse_api' => $response,
            'erreur' => $erreurFinale,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id_campagne' => $campagneData['id_campagne']]);
        
        // Déduction du crédit client : tarif de l'opérateur WhatsApp x nombre d'envois réussis
        if ($succes > 0) {
            $description = "Envoi WhatsApp via {$whatsappSession} - {$succes} message(s)";
            deduireCreditClient($idCompte, $providerId, $succes, $description);
        }
        
        if ($statut === 'envoye') {
            return [
                'success' => true, 
                'message' => $messageReponse,
                'details' => $details,
                'statut' => $statut
            ];
        } elseif ($statut === 'partiel') {
            return [
                'success' => true, 
                'message' => $messageReponse,
                'details' => $details,
                'erreurs' => $erreurs,
                'statut' => $statut
            ];
        } else {
            return [
                'success' => false, 
                'error' => $messageReponse,
                'details' => $details,
                'erreurs' => $erreurs,
                'statut' => $statut
            ];
        }
        
    } catch (Exception $e) {
        try {
            $db->update('campagne', [
                'statut' => 'echoue',
                'nb_erreurs' => isset($contacts) ? count($contacts) : 0,
                'erreur' => 'Exception: ' . $e->getMessage(),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id_campagne' => $campagneData['id_campagne']]);
        } catch (Exception $dbError) {}
        
        return ['success' => false, 'error' => 'Exception: ' . $e->getMessage()];
    }
}

function envoyerEmail($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires) {
    global $db;
    
    try {
        // Résolution du provider par fournisseur (Listmonk) plutôt que via
        // campagne_config.provider_id, qui peut être obsolète ou incorrect.
        $providerListmonk = getProviderByFournisseur('Listmonk');
        $providerId = $providerListmonk['id_provider'] ?? null;
        
        $from_email = $campagneData['from_email'] ?? 'noreply@votre-domaine.com';
        $from_name = $campagneData['from_name'] ?? 'Votre Entreprise';
        $objet = $campagneData['objet'] ?? 'Email';
        $listmonkCampaignId = $campagneData['listmonk_campaign_id'] ?? null;
        
        if (!$listmonkCampaignId) {
            return ['success' => false, 'error' => 'ID de campagne Listmonk manquant.'];
        }
        
        $result = updateListmonkCampaignStatus($listmonkCampaignId, 'running');
        
        if ($result['success']) {
            $nbDestinataires = (int)$campagneData['nb_destinataires'];
            
            $db->update('campagne', [
                'statut' => 'envoye',
                'nb_envoyes' => $nbDestinataires,
                'nb_succes' => $nbDestinataires,
                'nb_erreurs' => 0,
                'appareil_utilise' => 'Listmonk (ID: ' . $listmonkCampaignId . ')'
            ], ['id_campagne' => $campagneData['id_campagne']]);
            
            // Déduction du crédit client : tarif de l'opérateur Email x nombre d'envois réussis
            $description = "Envoi Email via Listmonk - {$nbDestinataires} email(s)";
            deduireCreditClient($idCompte, $providerId, $nbDestinataires, $description);
            
            return ['success' => true, 'message' => $nbDestinataires . ' emails envoyés avec succès via Listmonk'];
        } else {
            $errorMsg = 'Erreur Listmonk (HTTP ' . $result['http_code'] . '): ' . substr($result['response'], 0, 200);
            
            $db->update('campagne', [
                'statut' => 'echoue',
                'erreur' => $errorMsg
            ], ['id_campagne' => $campagneData['id_campagne']]);
            
            return ['success' => false, 'error' => $errorMsg];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================
// FLASH MESSAGES
// ============================================
$flashMessage = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
$flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
$octopushResponse = isset($_SESSION['octopush_response']) ? $_SESSION['octopush_response'] : null;
unset($_SESSION['flash_message']);
unset($_SESSION['flash_error']);
unset($_SESSION['octopush_response']);

// ============================================
// VÉRIFIER SI LE PROVIDER EST OCTOPUSH
// ============================================
$isOctopush = isset($campagne['provider_id']) && !empty($campagne['provider_id']);
if ($isOctopush) {
    $provider = $db->select('provider', ['id_provider' => $campagne['provider_id']]);
    if (!empty($provider)) {
        $isOctopush = stripos($provider[0]['nom_providers'], 'octopush') !== false;
    } else {
        $isOctopush = false;
    }
}

$octopushSessionName = $_SESSION['octopush_session_name'] ?? null;
if (!$octopushSessionName && isset($campagne['octopush_config_id'])) {
    $config = $db->select('octopush_config', [
        'id_config' => $campagne['octopush_config_id'],
        'id_compte' => $idCompte
    ]);
    if (!empty($config)) {
        $octopushSessionName = $config[0]['nom_config'];
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($campagne['nom_campagne']) ?> - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================
           STYLES PRINCIPAUX - FULL WIDTH
        ============================================ */
        * { 
            box-sizing: border-box; 
            margin: 0;
            padding: 0;
        }
        
        body { 
            margin: 0; 
            background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
        }
        
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 16px 32px;
            width: 100%;
        }
        
        /* ============================================
           BADGES ET STATUTS
        ============================================ */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-brouillon { background: #f3f4f6; color: #4b5563; }
        .status-planifiee { background: #fef3c7; color: #92400e; }
        .status-envoyee { background: #dcfce7; color: #166534; }
        .status-pret_a_envoyer { background: #dbeafe; color: #1e40af; }
        .status-partiel { background: #fef3c7; color: #92400e; }
        .status-echoue { background: #fee2e2; color: #991b1b; }
        
        .badge-octopush {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 700;
            background: #f97316;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-left: 4px;
        }
        
        .badge-octopush-session {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fed7aa;
            margin-left: 4px;
        }
        .badge-octopush-session i {
            margin-right: 4px;
            color: #ea580c;
        }
        
        .stat-type {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .stat-type-whatsapp { background: #d1fae5; color: #065f46; }
        .stat-type-sms { background: #dbeafe; color: #1e40af; }
        .stat-type-email { background: #fef3c7; color: #92400e; }
        
        /* ============================================
           TOAST
        ============================================ */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideInRight 0.3s ease-out;
        }
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .toast-notification .toast-content {
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 14px;
            font-weight: 500;
        }
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        
        /* ============================================
           BOUTONS
        ============================================ */
        .btn-send-message {
            background: #10b981;
            color: white;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-send-message:hover { background: #059669; }
        .btn-send-message:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .btn-send-email {
            background: #3b82f6;
            color: white;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-send-email:hover { background: #2563eb; }
        .btn-send-email:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .btn-send-octopush {
            background: #f97316;
            color: white;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-send-octopush:hover { background: #ea580c; }
        
        /* ============================================
           STATS CARDS
        ============================================ */
        .stat-card {
            padding: 16px 20px;
            border-radius: 12px;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .stat-card .stat-number {
            font-size: 28px;
            font-weight: 800;
        }
        .stat-card .stat-label {
            font-size: 13px;
            color: #6b7280;
            margin-top: 2px;
        }
        
        /* ============================================
           TABLE
        ============================================ */
        .table-container {
            overflow-x: auto;
            width: 100%;
        }
        .table-container table {
            width: 100%;
            font-size: 14px;
            border-collapse: collapse;
            min-width: 700px;
        }
        .table-container th {
            padding: 10px 16px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            text-align: left;
            background: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
        }
        .table-container td {
            padding: 10px 16px;
            font-size: 14px;
            border-bottom: 1px solid #f3f4f6;
        }
        .table-container th.text-center,
        .table-container td.text-center {
            text-align: center;
        }
        
        .envoi-row {
            cursor: pointer;
            transition: background 0.15s;
        }
        .envoi-row:hover {
            background-color: #f9fafb;
        }
        
        /* ============================================
           FILTRES
        ============================================ */
        .filter-container {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-top: 12px;
        }
        .filter-container label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }
        .filter-container select {
            padding: 6px 14px;
            border: 1.5px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            cursor: pointer;
            min-width: 140px;
        }
        .filter-container select:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.12);
        }
        .filter-container .filter-info {
            font-size: 13px;
            color: #6b7280;
        }
        .filter-container .btn-clear-filter {
            background: #e5e7eb;
            color: #4b5563;
            padding: 6px 14px;
            border-radius: 6px;
            border: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-container .btn-clear-filter:hover {
            background: #d1d5db;
        }
        
        #searchInput {
            padding: 8px 12px 8px 38px;
            font-size: 14px;
            border-radius: 8px;
            border: 1.5px solid #d1d5db;
            width: 100%;
            background: white;
        }
        #searchInput:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        /* ============================================
           MODALES
        ============================================ */
        .modal-octopush {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }
        .modal-octopush.active {
            display: flex;
        }
        .modal-octopush .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 600px;
            width: 92%;
            max-height: 80vh;
            overflow-y: auto;
            padding: 0;
            animation: modalFadeIn 0.3s ease;
        }
        @keyframes modalFadeIn {
            from { transform: scale(0.9) translateY(-20px); opacity: 0; }
            to { transform: scale(1) translateY(0); opacity: 1; }
        }
        .modal-octopush .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            border-radius: 16px 16px 0 0;
        }
        .modal-octopush .modal-header h3 {
            margin: 0;
            font-size: 18px;
            color: #1f2937;
        }
        .modal-octopush .modal-header .close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #9ca3af;
            transition: color 0.2s;
        }
        .modal-octopush .modal-header .close:hover {
            color: #4b5563;
        }
        .modal-octopush .modal-body {
            padding: 24px;
        }
        .modal-octopush .modal-body .response-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .modal-octopush .modal-body .response-item:last-child {
            border-bottom: none;
        }
        .modal-octopush .modal-body .response-item .label {
            font-weight: 600;
            color: #6b7280;
        }
        .modal-octopush .modal-body .response-item .value {
            font-weight: 500;
            color: #1f2937;
            text-align: right;
        }
        .modal-octopush .modal-body .response-item .value.success {
            color: #10b981;
        }
        .modal-octopush .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #f8fafc;
            border-radius: 0 0 16px 16px;
        }
        .modal-octopush .modal-footer button {
            padding: 8px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        .modal-octopush .modal-footer .btn-confirm {
            background: #f97316;
            color: white;
        }
        .modal-octopush .modal-footer .btn-confirm:hover {
            background: #ea580c;
        }
        .modal-octopush .modal-footer .btn-cancel {
            background: #e5e7eb;
            color: #4b5563;
        }
        .modal-octopush .modal-footer .btn-cancel:hover {
            background: #d1d5db;
        }
        
        /* ============================================
           WHATSAPP RESULTS
        ============================================ */
        .whatsapp-result {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 4px 8px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 12px;
        }
        .whatsapp-result:last-child {
            border-bottom: none;
        }
        .whatsapp-result .contact {
            font-family: monospace;
            font-size: 12px;
        }
        .whatsapp-result .status-sent {
            color: #10b981;
            font-weight: 600;
        }
        .whatsapp-result .status-failed {
            color: #ef4444;
            font-weight: 600;
        }
        .whatsapp-result .status-invalid {
            color: #f59e0b;
            font-weight: 600;
        }
        
        .html-render {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            background: white;
            max-height: 400px;
            overflow-y: auto;
        }
        .html-render h1, .html-render h2, .html-render h3, 
        .html-render h4, .html-render h5, .html-render h6 {
            margin-top: 0.5em;
            margin-bottom: 0.5em;
        }
        .html-render p {
            margin-bottom: 0.75em;
        }
        .html-render ul, .html-render ol {
            margin-left: 1.5em;
            margin-bottom: 0.75em;
        }
        .html-render a {
            color: #3b82f6;
            text-decoration: underline;
        }
        .html-render img {
            max-width: 100%;
            height: auto;
        }
        .html-render table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 0.75em;
        }
        .html-render table td, .html-render table th {
            border: 1px solid #d1d5db;
            padding: 6px 12px;
        }
        .html-render blockquote {
            border-left: 4px solid #d1d5db;
            padding-left: 12px;
            margin-left: 0;
            color: #4b5563;
        }
        
        /* ============================================
           UTILITIES
        ============================================ */
        .flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .gap-2 { gap: 8px; }
        .gap-3 { gap: 12px; }
        .gap-4 { gap: 16px; }
        .mb-6 { margin-bottom: 24px; }
        .mt-1 { margin-top: 4px; }
        .mt-2 { margin-top: 8px; }
        .mr-2 { margin-right: 8px; }
        .mr-4 { margin-right: 16px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-xs { font-size: 12px; }
        .text-sm { font-size: 14px; }
        .text-lg { font-size: 18px; }
        .text-xl { font-size: 20px; }
        .text-2xl { font-size: 24px; }
        .font-semibold { font-weight: 600; }
        .font-bold { font-weight: 700; }
        .uppercase { text-transform: uppercase; }
        .tracking-wider { letter-spacing: 0.5px; }
        .whitespace-nowrap { white-space: nowrap; }
        .overflow-hidden { overflow: hidden; }
        .overflow-y-auto { overflow-y: auto; }
        .max-h-48 { max-height: 192px; }
        .max-h-60 { max-height: 240px; }
        
        .bg-white { background: white; }
        .bg-gray-50 { background: #f9fafb; }
        .bg-gray-100 { background: #f3f4f6; }
        .bg-purple-100 { background: #f3e8ff; }
        .bg-blue-50 { background: #eff6ff; }
        .bg-blue-100 { background: #dbeafe; }
        .bg-green-50 { background: #f0fdf4; }
        .bg-green-100 { background: #dcfce7; }
        .bg-red-50 { background: #fef2f2; }
        .bg-red-100 { background: #fee2e2; }
        .bg-yellow-50 { background: #fffbeb; }
        .bg-yellow-100 { background: #fef3c7; }
        .bg-orange-50 { background: #fff7ed; }
        
        .text-blue-600 { color: #2563eb; }
        .text-blue-700 { color: #1d4ed8; }
        .text-green-600 { color: #16a34a; }
        .text-green-700 { color: #15803d; }
        .text-red-600 { color: #dc2626; }
        .text-red-700 { color: #b91c1c; }
        .text-yellow-600 { color: #ca8a04; }
        .text-yellow-700 { color: #a16207; }
        .text-gray-400 { color: #9ca3af; }
        .text-gray-500 { color: #6b7280; }
        .text-gray-600 { color: #4b5563; }
        .text-gray-700 { color: #374151; }
        .text-gray-800 { color: #1f2937; }
        .text-purple-600 { color: #7c3aed; }
        
        .rounded-xl { border-radius: 12px; }
        .rounded-lg { border-radius: 8px; }
        .rounded-full { border-radius: 9999px; }
        .shadow-md { box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .shadow-lg { box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        
        .p-3 { padding: 12px; }
        .p-4 { padding: 16px; }
        .p-5 { padding: 20px; }
        .p-6 { padding: 24px; }
        .px-2 { padding-left: 8px; padding-right: 8px; }
        .px-4 { padding-left: 16px; padding-right: 16px; }
        .px-6 { padding-left: 24px; padding-right: 24px; }
        .py-2 { padding-top: 8px; padding-bottom: 8px; }
        .py-3 { padding-top: 12px; padding-bottom: 12px; }
        .py-4 { padding-top: 16px; padding-bottom: 16px; }
        .py-8 { padding-top: 32px; padding-bottom: 32px; }
        .py-12 { padding-top: 48px; padding-bottom: 48px; }
        
        .grid-cols-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .grid-cols-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .grid-cols-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .grid-cols-5 { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; }
        
        .border { border: 1px solid #e5e7eb; }
        .border-b { border-bottom: 1px solid #e5e7eb; }
        .border-t { border-top: 1px solid #e5e7eb; }
        .border-gray-200 { border-color: #e5e7eb; }
        .border-orange-200 { border-color: #fed7aa; }
        .border-l-4 { border-left-width: 4px; }
        .border-red-500 { border-color: #ef4444; }
        
        .divide-y > * + * { border-top: 1px solid #e5e7eb; }
        
        .relative { position: relative; }
        .absolute { position: absolute; }
        .fixed { position: fixed; }
        .inset-0 { top: 0; left: 0; right: 0; bottom: 0; }
        .z-50 { z-index: 50; }
        .z-9999 { z-index: 9999; }
        .top-1\/2 { top: 50%; }
        .left-3 { left: 12px; }
        .transform { transform: translateY(-50%); }
        .-translate-y-1\/2 { transform: translateY(-50%); }
        .scale-95 { transform: scale(0.95); }
        .opacity-0 { opacity: 0; }
        .transition-all { transition: all 0.3s ease; }
        .duration-300 { transition-duration: 300ms; }
        .w-full { width: 100%; }
        .min-w-full { min-width: 100%; }
        .max-w-xs { max-width: 320px; }
        .max-w-4xl { max-width: 896px; }
        .max-w-sm { max-width: 384px; }
        .mx-4 { margin-left: 16px; margin-right: 16px; }
        .mx-auto { margin-left: auto; margin-right: auto; }
        .truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        
        .sticky { position: sticky; }
        .top-0 { top: 0; }
        .bottom-0 { bottom: 0; }
        
        /* ============================================
           MODAL DÉTAILS
        ============================================ */
        #detailsModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        #detailsModal.flex {
            display: flex;
        }
        #detailsModal .modal-container {
            background: white;
            border-radius: 16px;
            width: 92%;
            max-width: 1024px;
            max-height: 90vh;
            overflow-y: auto;
            transition: all 0.3s ease;
        }
        #detailsModal .modal-container .modal-header-sticky {
            position: sticky;
            top: 0;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 16px 16px 0 0;
            z-index: 10;
        }
        #detailsModal .modal-container .modal-body-content {
            padding: 24px;
        }
        #detailsModal .modal-container .modal-footer-sticky {
            position: sticky;
            bottom: 0;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            padding: 12px 24px;
            display: flex;
            justify-content: flex-end;
            border-radius: 0 0 16px 16px;
        }
        
        /* ============================================
           RESPONSIVE
        ============================================ */
        @media (max-width: 1200px) {
            .container { padding: 16px 24px; }
            .grid-cols-5 { grid-template-columns: repeat(4, 1fr); }
        }
        
        @media (max-width: 992px) {
            .container { padding: 12px 20px; }
            .grid-cols-5 { grid-template-columns: repeat(3, 1fr); }
            .grid-cols-4 { grid-template-columns: repeat(2, 1fr); }
            .stat-card .stat-number { font-size: 24px; }
        }
        
        @media (max-width: 768px) {
            .container { padding: 12px 16px; }
            .grid-cols-5 { grid-template-columns: repeat(2, 1fr); }
            .grid-cols-4 { grid-template-columns: repeat(2, 1fr); }
            .grid-cols-3 { grid-template-columns: 1fr 1fr; }
            .grid-cols-2 { grid-template-columns: 1fr; }
            .filter-container { flex-direction: column; align-items: stretch; }
            .filter-container select { width: 100%; }
            .stat-card .stat-number { font-size: 22px; }
            .header-title { font-size: 20px; }
            .table-container table { min-width: 600px; font-size: 13px; }
            .table-container th, .table-container td { padding: 8px 12px; }
            #detailsModal .modal-container { width: 96%; margin: 10px; }
            #detailsModal .modal-container .modal-header-sticky { padding: 12px 16px; }
            #detailsModal .modal-container .modal-body-content { padding: 16px; }
            #detailsModal .modal-container .modal-footer-sticky { padding: 10px 16px; }
        }
        
        @media (max-width: 480px) {
            .container { padding: 8px 10px; }
            .grid-cols-5 { grid-template-columns: 1fr 1fr; gap: 8px; }
            .grid-cols-4 { grid-template-columns: 1fr 1fr; gap: 8px; }
            .grid-cols-3 { grid-template-columns: 1fr; }
            .stat-card { padding: 12px 16px; }
            .stat-card .stat-number { font-size: 20px; }
            .stat-card .stat-label { font-size: 11px; }
            .header-title { font-size: 18px; }
            .table-container table { min-width: 500px; font-size: 12px; }
            .table-container th, .table-container td { padding: 6px 10px; }
            .btn-send-message, .btn-send-email, .btn-send-octopush { 
                font-size: 10px; 
                padding: 3px 8px; 
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- ===== EN-TÊTE ===== -->
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div class="flex items-center">
            <a href="index.php?page=campagnes/creer" class="text-blue-600 hover:text-blue-800 mr-4 font-medium">
                <i class="fas fa-arrow-left"></i> Retour
            </a>
            <div class="bg-purple-100 p-3 rounded-full mr-4">
                <i class="fas fa-bullhorn text-purple-600 text-xl"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($campagne['nom_campagne']) ?></h1>
                <p class="text-sm text-gray-500">Gérez les messages de cette campagne</p>
                <?php if ($isOctopush && $octopushSessionName): ?>
                    <span class="badge-octopush-session">
                        <i class="fas fa-bolt"></i> Session: <?= htmlspecialchars($octopushSessionName) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <a href="index.php?page=campagnes/choix_type&campagne_id=<?= $campagneId ?>" 
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition font-semibold text-sm">
                <i class="fas fa-plus mr-2"></i>Nouveau message
            </a>
        </div>
    </div>

    <!-- ===== TOASTS ===== -->
    <?php if ($flashMessage): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?= addslashes($flashMessage) ?>', 'success');
            });
        </script>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?= addslashes($flashError) ?>', 'error');
            });
        </script>
    <?php endif; ?>

    <!-- ===== INFOS CAMPAGNE ===== -->
    <div class="bg-white rounded-xl shadow-md p-5 mb-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <label class="text-xs text-gray-500 uppercase font-semibold">Date de création</label>
                <div class="mt-1 font-medium"><?= date('d/m/Y H:i', strtotime($campagne['created_at'])) ?></div>
            </div>
            <?php if ($campagne['date_planification']): ?>
                <div>
                    <label class="text-xs text-gray-500 uppercase font-semibold">Planifiée le</label>
                    <div class="mt-1 font-medium"><?= date('d/m/Y H:i', strtotime($campagne['date_planification'])) ?></div>
                </div>
            <?php endif; ?>
            <div>
                <label class="text-xs text-gray-500 uppercase font-semibold">Statut</label>
                <div class="mt-1">
                    <span class="status-badge status-<?= $campagne['statut'] ?>">
                        <?php
                        $statusText = [
                            'brouillon' => 'Brouillon',
                            'planifiee' => 'Planifiée',
                            'envoyee' => 'Envoyée',
                            'pret_a_envoyer' => 'Prêt à envoyer',
                            'partiel' => 'Partiel',
                            'echoue' => 'Échoué'
                        ];
                        echo $statusText[$campagne['statut']] ?? $campagne['statut'];
                        ?>
                    </span>
                    <?php if ($isOctopush): ?>
                        <span class="badge-octopush"><i class="fas fa-bolt mr-1"></i>Octopush</span>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <label class="text-xs text-gray-500 uppercase font-semibold">Messages en attente</label>
                <div class="mt-1 text-lg font-bold text-orange-600"><?= $totalAPreparer ?></div>
            </div>
        </div>
    </div>

    <!-- ===== STATISTIQUES ===== -->
    <div class="grid grid-cols-5 gap-4 mb-6">
        <div class="stat-card text-center">
            <div class="stat-number text-blue-600"><?= $totalEnvois ?></div>
            <div class="stat-label">Messages</div>
        </div>
        <div class="stat-card text-center">
            <div class="stat-number text-green-600"><?= $totalSucces ?></div>
            <div class="stat-label">Destinataires touchés</div>
        </div>
        <div class="stat-card text-center">
            <div class="stat-number text-red-600"><?= $totalErreurs ?></div>
            <div class="stat-label">Échecs</div>
        </div>
        <div class="stat-card text-center">
            <div class="stat-number text-green-600"><?= $totalWhatsApp ?></div>
            <div class="stat-label">
                <span class="stat-type stat-type-whatsapp"><i class="fas fa-mobile-alt mr-1"></i> WhatsApp</span>
            </div>
        </div>
        <div class="stat-card text-center">
            <div class="stat-number text-blue-600"><?= $totalSms + $totalEmail ?></div>
            <div class="stat-label">
                <span class="stat-type stat-type-sms"><i class="fas fa-comment-dots mr-1"></i> SMS/Email</span>
            </div>
        </div>
    </div>

    <!-- ===== FILTRES ===== -->
    <div class="bg-white rounded-xl shadow-md p-5 mb-6">
        <div class="relative">
            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            <input type="text" id="searchInput" placeholder="Rechercher un message (date, contenu, statut...)" 
                   class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
        </div>
        
        <div class="filter-container">
            <label for="filterType"><i class="fas fa-filter mr-1"></i> Type :</label>
            <select id="filterType">
                <option value="all">Tous les types</option>
                <option value="whatsapp">📱 WhatsApp</option>
                <option value="sms">💬 SMS</option>
                <option value="email">✉️ Email</option>
            </select>
            
            <label for="filterStatus" class="ml-1"><i class="fas fa-check-circle mr-1"></i> Statut :</label>
            <select id="filterStatus">
                <option value="all">Tous les statuts</option>
                <option value="envoye">Envoyé</option>
                <option value="echoue">Échoué</option>
                <option value="partiel">Partiel</option>
                <option value="pret_a_envoyer">Prêt à envoyer</option>
                <option value="planifiee">Planifié</option>
            </select>
            
            <button id="clearFilters" class="btn-clear-filter">
                <i class="fas fa-times mr-1"></i> Effacer
            </button>
            
            <span class="filter-info">
                <span id="visibleCount"><?= $totalEnvois ?></span> message(s)
            </span>
        </div>
    </div>

    <!-- ===== LISTE DES ENVOIS ===== -->
    <div class="bg-white rounded-xl shadow-md overflow-hidden">
        <div class="p-4 border-b bg-gray-50">
            <h2 class="text-lg font-bold">Historique des envois</h2>
            <p class="text-sm text-gray-500">Cliquez sur un message pour voir les détails</p>
        </div>
        
        <?php if (empty($envois)): ?>
            <div class="text-center py-12">
                <i class="fas fa-envelope text-4xl text-gray-300 mb-3"></i>
                <p class="text-gray-500">Aucun message.</p>
                <a href="index.php?page=campagnes/choix_type&campagne_id=<?= $campagneId ?>" 
                   class="text-green-600 mt-2 inline-block font-semibold">
                    <i class="fas fa-plus mr-1"></i>Créer votre premier message
                </a>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Message</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Destinataires</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Statut</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="envoisTableBody">
                        <?php foreach ($envois as $envoi): 
                            $statutClass = 'text-gray-600';
                            $statutIcon = 'fa-circle';
                            $statutLabel = 'Inconnu';
                            
                            switch ($envoi['statut']) {
                                case 'envoye':
                                    $statutClass = 'text-green-600';
                                    $statutIcon = 'fa-check-circle';
                                    $statutLabel = 'Envoyé';
                                    break;
                                case 'partiel':
                                    $statutClass = 'text-yellow-600';
                                    $statutIcon = 'fa-exclamation-triangle';
                                    $statutLabel = 'Partiel';
                                    break;
                                case 'pret_a_envoyer':
                                    $statutClass = 'text-blue-600';
                                    $statutIcon = 'fa-clock';
                                    $statutLabel = 'Prêt à envoyer';
                                    break;
                                case 'planifiee':
                                    $statutClass = 'text-yellow-700';
                                    $statutIcon = 'fa-calendar-clock';
                                    $statutLabel = 'Planifié';
                                    break;
                                case 'echoue':
                                    $statutClass = 'text-red-600';
                                    $statutIcon = 'fa-exclamation-circle';
                                    $statutLabel = 'Échoué';
                                    break;
                                default:
                                    $statutClass = 'text-gray-600';
                                    $statutIcon = 'fa-circle';
                                    $statutLabel = $envoi['statut'] ?? 'Inconnu';
                            }
                            
                            if ($envoi['type_campagne'] == 'whatsapp') {
                                $typeClass = 'bg-green-100 text-green-700';
                                $typeIcon = 'fas fa-mobile-alt';
                                $typeLabel = 'WhatsApp';
                            } elseif ($envoi['type_campagne'] == 'email') {
                                $typeClass = 'bg-yellow-100 text-yellow-700';
                                $typeIcon = 'fas fa-envelope';
                                $typeLabel = 'Email';
                            } else {
                                $typeClass = 'bg-blue-100 text-blue-700';
                                $typeIcon = 'fas fa-comment-dots';
                                $typeLabel = 'SMS';
                            }
                            
                            $messageDisplay = strip_tags($envoi['message']);
                            if (strlen($messageDisplay) > 50) {
                                $messageDisplay = substr($messageDisplay, 0, 50) . '...';
                            }
                            
                            $showSendButton = false;
                            $buttonClass = 'btn-send-message';
                            $buttonIcon = 'fa-paper-plane';
                            $buttonText = 'Envoyer';
                            $isOctopushMessage = false;
                            
                            if ($envoi['statut'] == 'pret_a_envoyer') {
                                $showSendButton = true;
                                if ($isOctopush) {
                                    $isOctopushMessage = true;
                                    $buttonClass = 'btn-send-octopush';
                                    $buttonIcon = 'fa-bolt';
                                    $buttonText = 'Envoyer';
                                }
                            }
                            if ($envoi['statut'] == 'planifiee' && $envoi['type_campagne'] == 'email') {
                                $showSendButton = true;
                                $buttonClass = 'btn-send-email';
                                $buttonIcon = 'fa-envelope';
                                $buttonText = 'Envoyer Email';
                            }
                            
                            $envoiJson = htmlspecialchars(json_encode($envoi), ENT_QUOTES, 'UTF-8');
                        ?>
                            <tr class="envoi-row" 
                                data-id="<?= $envoi['id_campagne'] ?>"
                                data-type="<?= $envoi['type_campagne'] ?>"
                                data-status="<?= $envoi['statut'] ?>"
                                onclick="if(event.target.closest('button, form, a')) return; showDetailsFromRow(this)">
                                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                                    <?= date('d/m/Y H:i', strtotime($envoi['created_at'])) ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="<?= $typeClass ?> px-2 py-1 rounded-full text-xs font-semibold">
                                        <i class="<?= $typeIcon ?> mr-1"></i>
                                        <?= $typeLabel ?>
                                    </span>
                                    <?php if ($isOctopushMessage): ?>
                                        <span class="badge-octopush"><i class="fas fa-bolt mr-1"></i>Octopush</span>
                                    <?php endif; ?>
                                    <?php if ($isOctopush && $octopushSessionName): ?>
                                        <span class="badge-octopush-session" style="display:block;margin-top:4px;">
                                            <i class="fas fa-user"></i> <?= htmlspecialchars($octopushSessionName) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-gray-800 max-w-xs truncate" title="<?= htmlspecialchars($messageDisplay) ?>">
                                        <?= htmlspecialchars($messageDisplay) ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center font-medium"><?= $envoi['nb_destinataires'] ?></td>
                                <td class="px-4 py-3 text-center">
                                    <i class="fas <?= $statutIcon ?> <?= $statutClass ?> mr-1"></i>
                                    <span class="text-sm font-medium <?= $statutClass ?>"><?= $statutLabel ?></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <?php if ($showSendButton): ?>
                                            <?php if ($isOctopushMessage): ?>
                                                <form method="POST" style="display:inline;" onclick="event.stopPropagation();">
                                                    <input type="hidden" name="action_envoyer_message" value="1">
                                                    <input type="hidden" name="id_campagne_historique" value="<?= $envoi['id_campagne'] ?>">
                                                    <input type="hidden" name="is_octopush" value="1">
                                                    <button type="submit" class="<?= $buttonClass ?>" title="Envoyer le message via Octopush">
                                                        <i class="fas <?= $buttonIcon ?>"></i> <?= $buttonText ?>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display:inline;" onclick="event.stopPropagation();">
                                                    <input type="hidden" name="action_envoyer_message" value="1">
                                                    <input type="hidden" name="id_campagne_historique" value="<?= $envoi['id_campagne'] ?>">
                                                    <button type="submit" class="<?= $buttonClass ?>" title="Envoyer le message">
                                                        <i class="fas <?= $buttonIcon ?>"></i> <?= $buttonText ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <button onclick="event.stopPropagation(); showDetails(<?= $envoiJson ?>)" 
                                                class="text-blue-600 hover:text-blue-800" title="Voir détails">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== MODAL OCTOPUSH ===== -->
<div id="octopushModal" class="modal-octopush <?= $octopushResponse ? 'active' : '' ?>">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-bolt text-orange-500 mr-2"></i>Accusé de réception</h3>
            <button class="close" onclick="closeOctopushModal()">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($octopushResponse): ?>
                <div style="text-align:center;margin-bottom:16px;">
                    <i class="fas fa-check-circle" style="font-size:48px;color:#10b981;"></i>
                    <p style="color:#10b981;font-weight:600;margin-top:8px;">✅ Envoi effectué avec succès</p>
                    <?php if ($octopushSessionName): ?>
                        <p style="color:#9a3412;font-size:13px;margin-top:4px;">
                            <i class="fas fa-user mr-1"></i> Session: <?= htmlspecialchars($octopushSessionName) ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="response-item">
                    <span class="label">Ticket SMS</span>
                    <span class="value"><?= htmlspecialchars($octopushResponse['sms_ticket'] ?? '-') ?></span>
                </div>
                <div class="response-item">
                    <span class="label">Nombre de contacts</span>
                    <span class="value"><?= htmlspecialchars($octopushResponse['number_of_contacts'] ?? '-') ?></span>
                </div>
                <div class="response-item">
                    <span class="label">Coût total</span>
                    <span class="value"><?= isset($octopushResponse['total_cost']) ? number_format($octopushResponse['total_cost'], 2) . ' €' : '-' ?></span>
                </div>
                <div class="response-item">
                    <span class="label">Nombre de SMS nécessaires</span>
                    <span class="value"><?= htmlspecialchars($octopushResponse['number_of_sms_needed'] ?? '-') ?></span>
                </div>
                <div class="response-item">
                    <span class="label">Crédit restant</span>
                    <span class="value"><?= isset($octopushResponse['residual_credit']) ? number_format($octopushResponse['residual_credit'], 2) . ' €' : '-' ?></span>
                </div>
                <div class="response-item">
                    <span class="label">Message</span>
                    <span class="value success"><?= htmlspecialchars($octopushResponse['message'] ?? 'Succès') ?></span>
                </div>
            <?php else: ?>
                <div style="text-align:center;padding:20px;">
                    <i class="fas fa-info-circle" style="font-size:48px;color:#3b82f6;"></i>
                    <p style="color:#6b7280;margin-top:12px;">Aucune réponse disponible</p>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button onclick="closeOctopushModal()" class="btn-cancel">Fermer</button>
        </div>
    </div>
</div>

<!-- ===== MODAL DÉTAILS ===== -->
<div id="detailsModal">
    <div class="modal-container" id="modalContainer">
        <div class="modal-header-sticky">
            <div class="flex items-center">
                <div id="modalIcon" class="w-10 h-10 rounded-full flex items-center justify-center mr-3">
                    <i id="modalIconImg" class="text-xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800" id="modalTitle"></h3>
            </div>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition-colors text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="modal-body-content" id="modalContent">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-500"></i>
                <p class="text-gray-500 mt-2">Chargement...</p>
            </div>
        </div>
        
        <div class="modal-footer-sticky">
            <button onclick="closeModal()" class="px-5 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition font-medium">
                Fermer
            </button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
// ===== TOAST =====
function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `<div class="toast-content">${message}</div>`;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s';
        setTimeout(() => toast.remove(), 500);
    }, 3000);
}

// ===== MODAL OCTOPUSH =====
function closeOctopushModal() {
    const modal = document.getElementById('octopushModal');
    modal.classList.remove('active');
}

// ===== FILTRES =====
const searchInput = document.getElementById('searchInput');
const filterType = document.getElementById('filterType');
const filterStatus = document.getElementById('filterStatus');
const envoisRows = document.querySelectorAll('.envoi-row');
const visibleCountSpan = document.getElementById('visibleCount');

function applyFilters() {
    const searchTerm = searchInput.value.toLowerCase().trim();
    const typeFilter = filterType.value;
    const statusFilter = filterStatus.value;
    let visibleCount = 0;
    
    envoisRows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const type = row.dataset.type || '';
        const status = row.dataset.status || '';
        let show = true;
        
        if (status === 'brouillon') {
            row.style.display = 'none';
            return;
        }
        
        if (searchTerm !== '' && !text.includes(searchTerm)) show = false;
        if (show && typeFilter !== 'all' && type !== typeFilter) show = false;
        if (show && statusFilter !== 'all' && status !== statusFilter) show = false;
        
        if (show) { row.style.display = ''; visibleCount++; } 
        else { row.style.display = 'none'; }
    });
    
    visibleCountSpan.textContent = visibleCount;
    
    const noResult = document.getElementById('noResultMessage');
    if (visibleCount === 0 && envoisRows.length > 0) {
        if (!noResult) {
            const tbody = document.getElementById('envoisTableBody');
            const tr = document.createElement('tr');
            tr.id = 'noResultMessage';
            tr.innerHTML = `
                <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                    <i class="fas fa-search text-3xl mb-2 block"></i>
                    Aucun message ne correspond aux filtres sélectionnés.
                    <div class="mt-2">
                        <button onclick="resetFilters()" class="text-purple-600 hover:text-purple-800 font-semibold">
                            <i class="fas fa-undo mr-1"></i> Réinitialiser
                        </button>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        }
    } else {
        if (noResult) noResult.remove();
    }
}

function resetFilters() {
    searchInput.value = '';
    filterType.value = 'all';
    filterStatus.value = 'all';
    applyFilters();
}

searchInput.addEventListener('input', applyFilters);
filterType.addEventListener('change', applyFilters);
filterStatus.addEventListener('change', applyFilters);
document.getElementById('clearFilters').addEventListener('click', resetFilters);

// ===== MODAL DÉTAILS =====
function showDetailsFromRow(row) {
    const envoi = {
        id_campagne: row.dataset.id,
        type_campagne: row.dataset.type,
        statut: row.dataset.status,
    };
    // Récupérer les données depuis la ligne
    const cells = row.querySelectorAll('td');
    // On va chercher les données via une requête AJAX ou depuis les attributs data
    // Pour simplifier, on utilise les données stockées dans la ligne
    const envoiData = row._envoiData;
    if (envoiData) {
        showDetails(envoiData);
    } else {
        // Fallback: afficher un message
        alert('Détails non disponibles');
    }
}

function showDetails(envoi) {
    const modal = document.getElementById('detailsModal');
    const modalContainer = document.getElementById('modalContainer');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    const modalIcon = document.getElementById('modalIcon');
    const modalIconImg = document.getElementById('modalIconImg');
    
    if (envoi.type_campagne === 'whatsapp') {
        modalIcon.className = 'w-10 h-10 rounded-full bg-green-100 flex items-center justify-center mr-3';
        modalIconImg.className = 'fab fa-mobile-alt text-green-600 text-xl';
    } else if (envoi.type_campagne === 'email') {
        modalIcon.className = 'w-10 h-10 rounded-full bg-yellow-100 flex items-center justify-center mr-3';
        modalIconImg.className = 'fas fa-envelope text-yellow-600 text-xl';
    } else {
        modalIcon.className = 'w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center mr-3';
        modalIconImg.className = 'fas fa-comment-dots text-blue-600 text-xl';
    }
    
    modalTitle.textContent = envoi.titre || 'Détails du message';
    
    let destinataires = [];
    try { destinataires = JSON.parse(envoi.destinataires); } 
    catch(e) { destinataires = [envoi.destinataires]; }
    
    let destHtml = '';
    if (destinataires && destinataires.length > 0) {
        destHtml = '<div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto">';
        for (let i = 0; i < Math.min(destinataires.length, 20); i++) {
            destHtml += '<div class="flex items-center p-2 bg-gray-50 rounded-lg">' +
                        '<i class="fas fa-user-circle text-gray-400 mr-2"></i>' +
                        '<span class="text-sm">' + escapeHtml(destinataires[i]) + '</span>' +
                        '</div>';
        }
        if (destinataires.length > 20) {
            destHtml += '<div class="text-center text-gray-500 text-sm col-span-2">+ ' + (destinataires.length - 20) + ' autres</div>';
        }
        destHtml += '</div>';
    } else {
        destHtml = '<p class="text-gray-500 italic">Aucun destinataire enregistré</p>';
    }
    
    let statusBadge;
    switch (envoi.statut) {
        case 'envoye':
            statusBadge = '<span class="bg-green-100 text-green-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-check-circle mr-1"></i>Envoyé</span>';
            break;
        case 'partiel':
            statusBadge = '<span class="bg-yellow-100 text-yellow-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i>Partiel</span>';
            break;
        case 'pret_a_envoyer':
            statusBadge = '<span class="bg-blue-100 text-blue-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-clock mr-1"></i>Prêt à envoyer</span>';
            break;
        case 'planifiee':
            statusBadge = '<span class="bg-yellow-100 text-yellow-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-calendar-clock mr-1"></i>Planifié</span>';
            break;
        case 'echoue':
            statusBadge = '<span class="bg-red-100 text-red-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-exclamation-circle mr-1"></i>Échoué</span>';
            break;
        default:
            statusBadge = '<span class="bg-gray-100 text-gray-700 px-2.5 py-1 rounded-full text-xs font-semibold">' + escapeHtml(envoi.statut) + '</span>';
    }
    
    let typeBadge;
    if (envoi.type_campagne === 'whatsapp') {
        typeBadge = '<span class="bg-green-100 text-green-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-mobile-alt mr-1"></i>WhatsApp</span>';
    } else if (envoi.type_campagne === 'email') {
        typeBadge = '<span class="bg-yellow-100 text-yellow-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-envelope mr-1"></i>Email</span>';
    } else {
        typeBadge = '<span class="bg-blue-100 text-blue-700 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-comment-dots mr-1"></i>SMS</span>';
    }
    
    let messageContent = escapeHtml(envoi.message || '-');
    let isHtml = false;
    
    if (envoi.message && (
        envoi.message.includes('<p>') || 
        envoi.message.includes('<div>') || 
        envoi.message.includes('<br>') ||
        envoi.message.includes('<strong>') ||
        envoi.message.includes('<em>') ||
        envoi.message.includes('<ul>') ||
        envoi.message.includes('<ol>') ||
        envoi.message.includes('<a href') ||
        envoi.message.includes('<img')
    )) {
        isHtml = true;
        messageContent = envoi.message;
    }
    
    let messageHtml = '';
    if (isHtml) {
        messageHtml = `
            <div class="html-render">
                ${messageContent}
            </div>
        `;
    } else {
        messageHtml = `
            <div class="bg-gray-50 rounded-lg p-3 max-h-32 overflow-y-auto">
                <p class="text-sm text-gray-700 whitespace-pre-wrap">${messageContent}</p>
            </div>
        `;
    }
    
    let apiResponseHtml = '';
    if (envoi.reponse_api) {
        try {
            const apiData = JSON.parse(envoi.reponse_api);
            if (apiData.sms_ticket !== undefined) {
                apiResponseHtml = `
                    <div>
                        <div class="text-xs text-gray-500 font-semibold mb-1">Réponse API Octopush</div>
                        <div class="bg-gray-50 rounded-lg p-3">
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <div><span class="text-gray-500">Ticket:</span> ${escapeHtml(apiData.sms_ticket || '-')}</div>
                                <div><span class="text-gray-500">Contacts:</span> ${apiData.number_of_contacts || 0}</div>
                                <div><span class="text-gray-500">Coût total:</span> ${apiData.total_cost ? apiData.total_cost + ' €' : '-'}</div>
                                <div><span class="text-gray-500">SMS nécessaires:</span> ${apiData.number_of_sms_needed || 0}</div>
                                <div><span class="text-gray-500">Crédit restant:</span> ${apiData.residual_credit ? apiData.residual_credit + ' €' : '-'}</div>
                            </div>
                        </div>
                    </div>
                `;
            }
        } catch(e) {}
    }
    
    let whatsappDetailsHtml = '';
    if (envoi.type_campagne === 'whatsapp' && envoi.reponse_api) {
        try {
            const apiData = JSON.parse(envoi.reponse_api);
            if (apiData.ok === true && apiData.results) {
                const successCount = apiData.results.filter(r => r.success === true).length;
                const failCount = apiData.results.filter(r => r.success !== true).length;
                
                whatsappDetailsHtml = `
                    <div>
                        <div class="text-xs text-gray-500 font-semibold mb-1">Détails de l'envoi WhatsApp</div>
                        <div class="bg-gray-50 rounded-lg p-3">
                            <div class="grid grid-cols-2 gap-2 text-sm mb-2">
                                <div><span class="text-gray-500">Session:</span> <span class="font-medium">${escapeHtml(apiData.session || '-')}</span></div>
                                <div><span class="text-gray-500">Type:</span> <span class="font-medium">${escapeHtml(apiData.type || '-')}</span></div>
                                <div><span class="text-gray-500">Total contacts:</span> <span class="font-medium">${apiData.total || 0}</span></div>
                                <div><span class="text-gray-500">Valides:</span> <span class="font-medium text-green-600">${apiData.valid_count || 0}</span></div>
                                <div><span class="text-gray-500">Invalides:</span> <span class="font-medium text-red-600">${apiData.invalid_count || 0}</span></div>
                                <div><span class="text-gray-500">Succès:</span> <span class="font-medium text-green-600">${successCount}</span></div>
                                <div><span class="text-gray-500">Échecs:</span> <span class="font-medium text-red-600">${failCount}</span></div>
                            </div>
                            ${apiData.results && apiData.results.length > 0 ? `
                            <div class="border-t border-gray-200 pt-2 mt-2">
                                <div class="text-xs text-gray-500 font-semibold mb-1">Résultats par contact (${apiData.results.length})</div>
                                <div class="max-h-40 overflow-y-auto">
                                    ${apiData.results.map(r => `
                                        <div class="whatsapp-result">
                                            <span class="contact">${escapeHtml(r.chatId || '-')}</span>
                                            <span class="${r.success ? 'status-sent' : 'status-failed'}">
                                                ${r.success ? '✅ ' + (r.status || 'envoyé') : '❌ ' + (r.error || 'échec')}
                                            </span>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                            ` : ''}
                            ${apiData.invalid_contacts && apiData.invalid_contacts.length > 0 ? `
                            <div class="border-t border-gray-200 pt-2 mt-2">
                                <div class="text-xs text-red-500 font-semibold mb-1">Contacts invalides</div>
                                <div class="text-sm text-red-600">
                                    ${apiData.invalid_contacts.map(c => escapeHtml(c)).join(', ')}
                                </div>
                            </div>
                            ` : ''}
                            ${apiData.message ? `
                            <div class="border-t border-gray-200 pt-2 mt-2">
                                <div class="text-xs text-gray-500 font-semibold mb-1">Message API</div>
                                <div class="text-sm text-gray-700">${escapeHtml(apiData.message)}</div>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            }
        } catch(e) {}
    }
    
    let sessionInfo = '';
    if (envoi.appareil_utilise && envoi.appareil_utilise.includes('Octopush')) {
        sessionInfo = `
            <div class="bg-orange-50 border border-orange-200 rounded-lg p-3">
                <div class="flex items-center gap-2">
                    <i class="fas fa-bolt text-orange-500"></i>
                    <span class="font-semibold text-orange-800">Session Octopush:</span>
                    <span class="text-orange-700">${escapeHtml(envoi.appareil_utilise)}</span>
                </div>
            </div>
        `;
    }
    
    modalContent.innerHTML = `
        <div class="space-y-4">
            ${sessionInfo}
            
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 font-semibold mb-1">Date d'envoi</div>
                    <div class="font-medium">${formatDate(envoi.created_at)}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 font-semibold mb-1">Statut</div>
                    <div>${statusBadge}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 font-semibold mb-1">Appareil / Session</div>
                    <div class="text-sm font-medium">${escapeHtml(envoi.appareil_utilise || '-')}</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <div class="text-xs text-gray-500 font-semibold mb-1">Type</div>
                    <div>${typeBadge}</div>
                </div>
            </div>
            
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-1">Message ${isHtml ? '(HTML)' : ''}</div>
                ${messageHtml}
            </div>
            
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-1">Statistiques d'envoi</div>
                <div class="grid grid-cols-3 gap-3">
                    <div class="bg-blue-50 rounded-lg p-3 text-center">
                        <div class="text-2xl font-bold text-blue-600">${envoi.nb_destinataires || 0}</div>
                        <div class="text-xs text-gray-500">Total</div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-3 text-center">
                        <div class="text-2xl font-bold text-green-600">${envoi.nb_succes || 0}</div>
                        <div class="text-xs text-gray-500">Succès</div>
                    </div>
                    <div class="bg-red-50 rounded-lg p-3 text-center">
                        <div class="text-2xl font-bold text-red-600">${envoi.nb_erreurs || 0}</div>
                        <div class="text-xs text-gray-500">Échecs</div>
                    </div>
                </div>
            </div>
            
            ${apiResponseHtml}
            ${whatsappDetailsHtml}
            
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-1">Destinataires (${envoi.nb_destinataires || 0})</div>
                <div class="bg-gray-50 rounded-lg p-3">
                    ${destHtml}
                </div>
            </div>
            ${envoi.erreur ? `
            <div>
                <div class="text-xs text-red-500 font-semibold mb-1">Message d'erreur</div>
                <div class="bg-red-50 border-l-4 border-red-500 rounded-lg p-3">
                    <p class="text-sm text-red-700">${escapeHtml(envoi.erreur)}</p>
                </div>
            </div>
            ` : ''}
        </div>
    `;
    
    modal.classList.add('flex');
    const container = document.getElementById('modalContainer');
    container.classList.remove('scale-95', 'opacity-0');
}

function closeModal() {
    const modal = document.getElementById('detailsModal');
    const modalContainer = document.getElementById('modalContainer');
    
    modalContainer.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.remove('flex');
    }, 300);
}

function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('fr-FR') + ' ' + date.toLocaleTimeString('fr-FR');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        closeOctopushModal();
    }
});

document.getElementById('detailsModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.getElementById('octopushModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeOctopushModal();
});

// Initialisation des filtres
document.addEventListener('DOMContentLoaded', function() {
    applyFilters();
});
</script>

</body>
</html>