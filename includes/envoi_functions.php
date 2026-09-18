<?php
// ============================================
// includes/campagnes_functions.php
// Fonctions métier pour les campagnes : envoi, crédit, statuts
// ============================================

// Sécurité : empêcher l'accès direct via URL
if (!defined('APP_NAME') && !isset($db)) {
    die('Accès direct interdit');
}

// ============================================
// VÉRIFICATION DU CRÉDIT CLIENT
// ============================================
if (!function_exists('verifierCreditClient')) {
    function verifierCreditClient($idCompte, $idProvider, $quantite) {
        global $db;
        
        if (empty($idProvider) || $quantite <= 0) {
            return [
                'suffisant' => false,
                'solde' => 0,
                'cout_total' => 0,
                'message' => 'Paramètres invalides pour la vérification du crédit'
            ];
        }
        
        $provider = $db->select('provider', ['id_provider' => $idProvider]);
        if (empty($provider)) {
            return [
                'suffisant' => false,
                'solde' => 0,
                'cout_total' => 0,
                'message' => 'Opérateur non trouvé'
            ];
        }
        
        $tarifPersonnalise = $db->select('tarif', [
            'id_compte' => $idCompte,
            'id_provider' => $idProvider
        ]);
        
        $tarif = !empty($tarifPersonnalise) 
            ? (float)$tarifPersonnalise[0]['prix'] 
            : (float)$provider[0]['tarif'];
        
        if ($tarif <= 0) {
            return [
                'suffisant' => false,
                'solde' => 0,
                'cout_total' => 0,
                'message' => 'Tarif invalide pour cet opérateur'
            ];
        }
        
        $montant = $tarif * $quantite;
        
        $compte = $db->select('compte', ['id_compte' => $idCompte]);
        if (empty($compte)) {
            return [
                'suffisant' => false,
                'solde' => 0,
                'cout_total' => $montant,
                'message' => 'Compte client non trouvé'
            ];
        }
        
        $creditsActuels = (float)($compte[0]['credits_total'] ?? 0);
        $suffisant = $creditsActuels >= $montant;
        
        return [
            'suffisant' => $suffisant,
            'solde' => $creditsActuels,
            'cout_total' => $montant,
            'tarif_unitaire' => $tarif,
            'quantite' => $quantite,
            'message' => $suffisant 
                ? "Crédit suffisant : {$creditsActuels}€ disponible(s) pour un coût de {$montant}€" 
                : "Crédit insuffisant : {$creditsActuels}€ disponible(s) pour un coût de {$montant}€"
        ];
    }
}

// ============================================
// DÉDUCTION DU CRÉDIT CLIENT
// ============================================
if (!function_exists('deduireCreditClient')) {
    function deduireCreditClient($idCompte, $idProvider, $quantite, $description = null) {
        global $db;

        if (empty($idProvider) || $quantite <= 0) {
            return false;
        }

        $verification = verifierCreditClient($idCompte, $idProvider, $quantite);
        if (!$verification['suffisant']) {
            return false;
        }

        $provider = $db->select('provider', ['id_provider' => $idProvider]);
        if (empty($provider)) {
            return false;
        }

        $tarifPersonnalise = $db->select('tarif', [
            'id_compte' => $idCompte,
            'id_provider' => $idProvider
        ]);

        $tarif = !empty($tarifPersonnalise) 
            ? (float)$tarifPersonnalise[0]['prix'] 
            : (float)$provider[0]['tarif'];

        if ($tarif <= 0) {
            return false;
        }

        $montant = $tarif * $quantite;

        // Déduction atomique côté PostgreSQL : aucune race condition possible
        try {
            $rpcResult = $db->rpc('deduire_credit', [
                'p_id_compte' => (string)$idCompte,
                'p_montant'   => $montant
            ]);
        } catch (Exception $e) {
            // Solde insuffisant détecté au moment exact de l'UPDATE
            error_log("deduire_credit RPC error: " . $e->getMessage());
            return false;
        }

        // La fonction RPC retourne directement la valeur NUMERIC (nouveau solde)
        $nouveauSolde = is_array($rpcResult) ? (float)($rpcResult[0] ?? $rpcResult) : (float)$rpcResult;
        $creditsActuels = $nouveauSolde + $montant;

        $nomProvider = $provider[0]['nom_providers'] ?? 'Inconnu';
        $descriptionTransaction = $description ?? "Envoi de {$quantite} message(s) via {$nomProvider}";

        $transactionData = [
            'id_compte_auteur' => $_SESSION['user_id'] ?? null,
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
        
        return true;
    }
}

// ============================================
// RÉSOLUTION DU PROVIDER PAR FOURNISSEUR
// ============================================
if (!function_exists('getProviderByFournisseur')) {
    function getProviderByFournisseur($fournisseurLabel) {
        global $db;
        
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
}

// ============================================
// MISE À JOUR DU STATUT GLOBAL DE LA CAMPAGNE
// ============================================
if (!function_exists('mettreAJourStatutCampagne')) {
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
        
        // Récupérer tous les messages
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
        $nbPartiel = 0;
        $nbPret = 0;
        $nbBrouillon = 0;
        $nbPlanifie = 0;
        
        foreach ($messages as $msg) {
            $statut = strtolower(trim($msg['statut']));
            switch ($statut) {
                case 'envoye':         $nbEnvoyes++;  break;
                case 'echoue':         $nbEchoues++;  break;
                case 'partiel':        $nbPartiel++;  break;
                case 'pret_a_envoyer': $nbPret++;     break;
                case 'brouillon':      $nbBrouillon++;break;
                case 'planifiee':      $nbPlanifie++; break;
            }
        }
        
        // Détermination du statut global
        if ($nbEnvoyes == $nbTotal && $nbTotal > 0) {
            $statut = 'envoyee';
            $sent_at = date('Y-m-d H:i:s');
        } elseif ($nbEchoues == $nbTotal && $nbTotal > 0) {
            $statut = 'echoue';
            $sent_at = null;
        } elseif ($nbEnvoyes > 0 || $nbEchoues > 0 || $nbPartiel > 0) {
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
        $updateData['sent_at'] = ($statut === 'envoyee') ? $sent_at : null;
        
        $db->update('campagne_config', $updateData, [
            'id_campagne_config' => $idCampagneConfig,
            'id_compte' => $idCompte
        ]);
    }
}

// ============================================
// FORMATAGE DES NUMÉROS (FRANCE)
// ============================================
if (!function_exists('formaterNumerosOctopush')) {
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
            
            if (empty($telephone)) continue;
            
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
}

// ============================================
// RÉCUPÉRATION DU STATUT RÉEL DES SMS (passerelle /status.php)
// ============================================
if (!function_exists('recupererStatutSMS')) {
    function recupererStatutSMS($messageIds, $apiUsername, $apiPassword) {
        if (empty($messageIds) || empty($apiUsername) || empty($apiPassword)) {
            return [
                'success' => false,
                'error' => 'Paramètres manquants pour la récupération du statut'
            ];
        }
        
        $url = 'http://164.68.103.147:8085/status.php';
        
        $data = [
            'message_ids' => array_values($messageIds),
            'api_username' => $apiUsername,
            'api_password' => $apiPassword
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("Status SMS cURL Error: " . $curlError);
            return ['success' => false, 'error' => 'Erreur cURL: ' . $curlError];
        }
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'Erreur HTTP ' . $httpCode . ': ' . substr($response, 0, 200)];
        }
        
        $responseData = json_decode($response, true);
        
        if (!is_array($responseData)) {
            return ['success' => false, 'error' => 'Réponse non-JSON'];
        }
        
        if (($responseData['status'] ?? '') !== 'ok') {
            return ['success' => false, 'error' => 'Statut API non OK: ' . ($responseData['status'] ?? 'inconnu')];
        }
        
        $results = [];
        $nbDelivered = 0;
        $nbPending = 0;
        $nbFailed = 0;
        $nbOther = 0;
        
        foreach (($responseData['results'] ?? []) as $result) {
            $state = $result['state'] ?? 'Unknown';
            $recipients = $result['recipients'] ?? [];
            
            foreach ($recipients as $recipient) {
                $recipientState = $recipient['state'] ?? $state;
                $phoneNumber = $recipient['phoneNumber'] ?? 'Inconnu';
                
                $normalizedState = strtolower($recipientState);
                if (in_array($normalizedState, ['delivered', 'delivred'])) {
                    $nbDelivered++;
                    $statusCategory = 'delivered';
                } elseif (in_array($normalizedState, ['pending', 'sent', 'queued', 'submitted'])) {
                    $nbPending++;
                    $statusCategory = 'pending';
                } elseif (in_array($normalizedState, ['failed', 'undelivered', 'rejected', 'expired'])) {
                    $nbFailed++;
                    $statusCategory = 'failed';
                } else {
                    $nbOther++;
                    $statusCategory = 'other';
                }
                
                $results[] = [
                    'id' => $result['id'] ?? '',
                    'phone' => $phoneNumber,
                    'state' => $recipientState,
                    'state_raw' => $state,
                    'category' => $statusCategory,
                    'http_code' => $result['http_code'] ?? null,
                    'success' => $result['success'] ?? false,
                    'error' => $result['error'] ?? null
                ];
            }
        }
        
        return [
            'success' => true,
            'count' => $responseData['count'] ?? count($results),
            'results' => $results,
            'stats' => [
                'delivered' => $nbDelivered,
                'pending' => $nbPending,
                'failed' => $nbFailed,
                'other' => $nbOther,
                'total' => count($results)
            ]
        ];
    }
}

// ============================================
// EXTRACTION DU MAPPING message_id => vrai_numéro
// ============================================
if (!function_exists('extraireMappingRecipients')) {
    function extraireMappingRecipients($reponseStatut) {
        $mapping = [];
        
        if (empty($reponseStatut['results']) || !is_array($reponseStatut['results'])) {
            return $mapping;
        }
        
        foreach ($reponseStatut['results'] as $result) {
            $msgId = $result['id'] ?? null;
            if (empty($msgId)) continue;
            
            $phone = $result['phone'] ?? null;
            
            if ($phone && preg_match('/^\+?[0-9]{8,15}$/', $phone)) {
                $mapping[$msgId] = $phone;
            }
        }
        
        return $mapping;
    }
}

// ============================================
// RÉCUPÉRATION DU STATUT D'UNE CAMPAGNE LISTMONK
// ============================================
if (!function_exists('recupererStatutListmonk')) {
    function recupererStatutListmonk($campaignId) {
        if (empty($campaignId)) {
            return ['success' => false, 'error' => 'ID de campagne manquant'];
        }
        
        $apiUrl = "http://164.68.103.147:9005/api/campaigns/" . intval($campaignId);
        $username = 'test';
        $password = 'lqXJrA1sfE1YobhQ0CyP9UiMpi1MOsb83p554Uuc1IRDKVRR';
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("Listmonk getStatus cURL Error (#{$curlErrno}): " . $curlError);
            return ['success' => false, 'error' => 'Erreur cURL #' . $curlErrno . ': ' . $curlError];
        }
        
        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'Erreur HTTP ' . $httpCode . ': ' . substr($response, 0, 200)];
        }
        
        $responseData = json_decode($response, true);
        
        if (!is_array($responseData)) {
            return ['success' => false, 'error' => 'Réponse non-JSON'];
        }
        
        $data = $responseData['data'] ?? null;
        
        if (!$data || !is_array($data)) {
            return ['success' => false, 'error' => 'Données de campagne Listmonk manquantes'];
        }
        
        $statusLabels = [
            'draft' => 'Brouillon',
            'scheduled' => 'Planifiée',
            'running' => 'En cours',
            'paused' => 'En pause',
            'cancelled' => 'Annulée',
            'finished' => 'Terminée'
        ];
        
        $statusRaw = $data['status'] ?? 'unknown';
        $statusLabel = $statusLabels[$statusRaw] ?? $statusRaw;
        
        return [
            'success' => true,
            'data' => [
                'id' => $data['id'] ?? null,
                'name' => $data['name'] ?? '',
                'subject' => $data['subject'] ?? '',
                'status' => $statusRaw,
                'status_label' => $statusLabel,
                'sent' => (int)($data['sent'] ?? 0),
                'to_send' => (int)($data['to_send'] ?? 0),
                'views' => (int)($data['views'] ?? 0),
                'clicks' => (int)($data['clicks'] ?? 0),
                'bounces' => (int)($data['bounces'] ?? 0),
            ]
        ];
    }
}

// ============================================
// MISE À JOUR DU STATUT D'UNE CAMPAGNE LISTMONK
// ============================================
if (!function_exists('updateListmonkCampaignStatus')) {
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("Listmonk updateStatus cURL Error (#{$curlErrno}): " . $curlError . " | URL: " . $apiUrl);
        }
        
        return [
            'success' => ($httpCode === 200 || $httpCode === 201 || $httpCode === 204) && empty($curlError),
            'http_code' => $httpCode,
            'response' => $response,
            'curl_error' => $curlError,
            'curl_errno' => $curlErrno
        ];
    }
}

// ============================================
// ENVOI SMS VIA OCTOPUSH
// ============================================
if (!function_exists('envoyerOctopush')) {
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
        
        $quantite = count($recipients);
        $verification = verifierCreditClient($idCompte, $idProvider, $quantite);
        
        if (!$verification['suffisant']) {
            return [
                'success' => false, 
                'error' => '❌ Crédit insuffisant pour envoyer ' . $quantite . ' SMS. ' . $verification['message'],
                'solde' => $verification['solde'],
                'cout_total' => $verification['cout_total'],
                'credit_insuffisant' => true
            ];
        }
        
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
        
        if ($httpCode === 200 || $httpCode === 201) {
            $smsTicket = $responseData['sms_ticket'] ?? null;
            
            if (!empty($idProvider)) {
                $description = "Envoi Octopush - {$quantite} SMS";
                deduireCreditClient($idCompte, $idProvider, $quantite, $description);
            }
            
            return [
                'success' => true,
                'data' => $responseData,
                'http_code' => $httpCode,
                'sms_envoyes' => $quantite,
                'credits_utilises' => $verification['cout_total'],
                'sms_ticket' => $smsTicket
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
                'http_code' => $httpCode,
                'credit_insuffisant' => false
            ];
        }
    }
}

// ============================================
// ENVOI SMS VIA API GATEWAY INTERNE
// ============================================
if (!function_exists('envoyerSMS')) {
    function envoyerSMS($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires) {
        global $db;
        
        try {
            $device_id = $campagne['device_id'] ?? null;
            $appareilId = $campagne['appareil_id'] ?? null;
            
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
            
            $quantite = count($recipients);
            $verification = verifierCreditClient($idCompte, $providerId, $quantite);
            
            if (!$verification['suffisant']) {
                return [
                    'success' => false, 
                    'error' => '❌ Crédit insuffisant pour envoyer ' . $quantite . ' SMS. ' . $verification['message'],
                    'solde' => $verification['solde'],
                    'cout_total' => $verification['cout_total'],
                    'credit_insuffisant' => true
                ];
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
            $curlError = curl_error($ch);
            curl_close($ch);
            
            $responseData = json_decode($response, true);
            
            // Extraction des message_ids
            $messageIds = [];
            if (is_array($responseData)) {
                if (isset($responseData['message_ids']) && is_array($responseData['message_ids'])) {
                    $messageIds = $responseData['message_ids'];
                } elseif (isset($responseData['results']) && is_array($responseData['results'])) {
                    foreach ($responseData['results'] as $r) {
                        if (isset($r['id'])) $messageIds[] = $r['id'];
                        if (isset($r['message_id'])) $messageIds[] = $r['message_id'];
                    }
                } elseif (isset($responseData['ids']) && is_array($responseData['ids'])) {
                    $messageIds = $responseData['ids'];
                } elseif (isset($responseData['data']) && is_array($responseData['data'])) {
                    foreach ($responseData['data'] as $r) {
                        if (isset($r['id'])) $messageIds[] = $r['id'];
                        if (isset($r['message_id'])) $messageIds[] = $r['message_id'];
                    }
                }
            }
            
            // Capture immédiate du mapping message_id => vrai_numéro
            $recipientsMapping = [];
            if ($httpCode === 200 && !empty($messageIds)) {
                $statutImmediat = recupererStatutSMS($messageIds, $api_username, $api_password);
                if ($statutImmediat['success']) {
                    $recipientsMapping = extraireMappingRecipients($statutImmediat);
                }
            }
            
            $statut = ($httpCode === 200) ? 'envoye' : 'echoue';
            $nb_succes = ($httpCode === 200) ? count($recipients) : 0;
            $nb_erreurs = ($httpCode === 200) ? 0 : count($recipients);
            
            $reponseApiData = [
                'api_response' => $responseData,
                'message_ids' => $messageIds,
                'recipients_mapping' => $recipientsMapping,
                'api_username' => $api_username,
                'api_password' => $api_password,
                'device_id' => $device_id,
                'appareil_id' => $appareilId,
                'sent_at' => date('Y-m-d H:i:s')
            ];
            
            $db->update('campagne', [
                'statut' => $statut,
                'nb_envoyes' => count($recipients),
                'nb_succes' => $nb_succes,
                'nb_erreurs' => $nb_erreurs,
                'appareil_utilise' => $device_name . ' (' . $device_id . ')',
                'appareil_id' => $appareilId,
                'reponse_api' => json_encode($reponseApiData),
                'erreur' => ($httpCode !== 200) ? $response : null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id_campagne' => $campagneData['id_campagne']]);
            
            if ($httpCode === 200) {
                $description = "Envoi SMS via {$device_name} - {$nb_succes} message(s)";
                deduireCreditClient($idCompte, $providerId, $nb_succes, $description);
                
                $nbMapping = count($recipientsMapping);
                $extraInfo = $nbMapping > 0 ? " ({$nbMapping} numéro(s) associé(s))" : "";
                
                return [
                    'success' => true, 
                    'message' => count($recipients) . ' SMS envoyés avec succès' . $extraInfo . '. Coût: ' . number_format($verification['cout_total'], 3) . '€'
                ];
            } else {
                return ['success' => false, 'error' => 'Erreur API (HTTP ' . $httpCode . '): ' . substr($response, 0, 200)];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// ============================================
// ENVOI WHATSAPP VIA WAHA (bulk)
// ============================================
if (!function_exists('envoyerWhatsApp')) {
    function envoyerWhatsApp($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires, $pieceJointe = null, $min_delay = 60, $max_delay = 180) {
        global $db;
        
        try {
            $providerWaha = getProviderByFournisseur('WAHA');
            $providerId = $providerWaha['id_provider'] ?? null;
            
            if (!$providerId) {
                return ['success' => false, 'error' => 'Provider WAHA non configuré'];
            }
            
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
                
                if (empty($telephone)) continue;
                
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
            
            $quantite = count($contacts);
            $verification = verifierCreditClient($idCompte, $providerId, $quantite);
            
            if (!$verification['suffisant']) {
                return [
                    'success' => false, 
                    'error' => '❌ Crédit insuffisant pour envoyer ' . $quantite . ' messages WhatsApp. ' . $verification['message'],
                    'solde' => $verification['solde'],
                    'cout_total' => $verification['cout_total'],
                    'credit_insuffisant' => true
                ];
            }
            
            $apiUrl = 'http://164.68.103.147:8081/api/controller.php/messages/send-bulk';
            $apiKey = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
            
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
            
            if (empty($response) || $response === null) {
                $db->update('campagne', [
                    'statut' => 'echoue',
                    'nb_envoyes' => 0,
                    'nb_succes' => 0,
                    'nb_erreurs' => count($contacts),
                    'appareil_utilise' => $whatsappSession,
                    'reponse_api' => $response,
                    'erreur' => 'Réponse vide. HTTP: ' . $httpCode . ' cURL: ' . $curlError,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id_campagne' => $campagneData['id_campagne']]);
                
                return ['success' => false, 'error' => 'Réponse vide ou invalide. HTTP: ' . $httpCode];
            }
            
            $responseData = json_decode($response, true);
            
            if (!is_array($responseData)) {
                $db->update('campagne', [
                    'statut' => 'echoue',
                    'nb_envoyes' => 0,
                    'nb_succes' => 0,
                    'nb_erreurs' => count($contacts),
                    'appareil_utilise' => $whatsappSession,
                    'reponse_api' => $response,
                    'erreur' => 'Réponse non-JSON. HTTP: ' . $httpCode,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id_campagne' => $campagneData['id_campagne']]);
                
                return ['success' => false, 'error' => 'Réponse API non-JSON (HTTP ' . $httpCode . ')'];
            }
            
            $succes = 0;
            $echecs = 0;
            $erreurs = [];
            $details = [];
            $statut = 'echoue';
            $messageReponse = '';
            
            $invalidContacts = $responseData['invalid_contacts'] ?? [];
            $restriction = $responseData['account_restriction'] ?? null;
            $messageApi = $responseData['message'] ?? '';
            
            $resultsArray = null;
            
            if (isset($responseData['results'])) {
                $resultsRaw = $responseData['results'];
                
                if (is_array($resultsRaw) 
                    && isset($resultsRaw['results']) 
                    && is_array($resultsRaw['results'])) {
                    
                    $resultsArray = $resultsRaw['results'];
                    
                    if (empty($invalidContacts) && isset($resultsRaw['invalid_contacts'])) {
                        $invalidContacts = $resultsRaw['invalid_contacts'];
                    }
                    if ($restriction === null && isset($resultsRaw['account_restriction'])) {
                        $restriction = $resultsRaw['account_restriction'];
                    }
                }
                elseif (is_array($resultsRaw) && !empty($resultsRaw)) {
                    $firstKey = array_key_first($resultsRaw);
                    if (is_int($firstKey)) {
                        $resultsArray = $resultsRaw;
                    }
                }
            }
            
            if (!empty($resultsArray)) {
                foreach ($resultsArray as $result) {
                    if (isset($result['success']) && $result['success'] === true) {
                        $succes++;
                        $details[] = [
                            'phone' => $result['phone'] ?? 'Inconnu',
                            'chatId' => $result['chatId'] ?? 'Inconnu',
                            'firstName' => $result['firstName'] ?? '',
                            'statut' => $result['status'] ?? 'sent',
                            'success' => true,
                            'error' => null
                        ];
                    } else {
                        $echecs++;
                        $errorMsg = $result['error'] ?? 'Erreur inconnue';
                        $phone = $result['phone'] ?? 'Inconnu';
                        $erreurs[] = $phone . ': ' . $errorMsg;
                        $details[] = [
                            'phone' => $phone,
                            'chatId' => $result['chatId'] ?? 'Inconnu',
                            'firstName' => $result['firstName'] ?? '',
                            'statut' => $result['status'] ?? 'failed',
                            'success' => false,
                            'error' => $errorMsg
                        ];
                    }
                }
            }
            
            if (!empty($invalidContacts) && is_array($invalidContacts)) {
                foreach ($invalidContacts as $invalidContact) {
                    $echecs++;
                    $erreurs[] = $invalidContact . ': Numéro invalide';
                    $details[] = [
                        'phone' => $invalidContact,
                        'chatId' => $invalidContact,
                        'firstName' => '',
                        'statut' => 'invalid',
                        'success' => false,
                        'error' => 'Numéro invalide'
                    ];
                }
            }
            
            if (empty($details)) {
                $echecs = count($contacts);
                $errorMsg = !empty($messageApi) ? $messageApi : 'Réponse inexploitable';
                $erreurs[] = 'Erreur API (HTTP ' . $httpCode . '): ' . $errorMsg;
                
                foreach ($contacts as $contact) {
                    $details[] = [
                        'phone' => $contact,
                        'chatId' => $contact,
                        'firstName' => '',
                        'statut' => 'error',
                        'success' => false,
                        'error' => $errorMsg
                    ];
                }
            }
            
            if ($succes > 0 && $echecs === 0) {
                $statut = 'envoye';
                $messageReponse = "✅ " . $succes . " message(s) WhatsApp envoyé(s) avec succès";
            } elseif ($succes > 0 && $echecs > 0) {
                $statut = 'partiel';
                $messageReponse = "⚠️ " . $succes . " message(s) envoyé(s), " . $echecs . " échec(s)";
            } else {
                $statut = 'echoue';
                $messageReponse = "❌ Aucun message n'a pu être envoyé";
            }
            
            if ($restriction) {
                $restrictionLabels = [
                    'capping' => 'Limite WhatsApp atteinte (capping)',
                    'ban' => 'Compte WhatsApp restreint',
                    'rate_limit' => 'Trop de requêtes (rate limit)'
                ];
                $restrictionLabel = $restrictionLabels[$restriction] ?? $restriction;
                
                if ($succes > 0) {
                    $messageReponse = "⚠️ " . $succes . " envoyé(s), " . $echecs . " échec(s) — " . $restrictionLabel;
                } else {
                    $messageReponse .= " — " . $restrictionLabel;
                }
            } elseif (!empty($messageApi) && $succes > 0 && $echecs > 0) {
                $messageReponse .= " — " . $messageApi;
            }
            
            $erreurFinale = !empty($erreurs) ? json_encode($erreurs) : null;
            
            $reponseComplete = [
                'global' => $responseData,
                'details' => $details,
                'statut_global' => $statut,
                'message_global' => $messageReponse,
                'nb_succes' => $succes,
                'nb_echecs' => $echecs,
                'restriction' => $restriction ?? null
            ];
            
            $db->update('campagne', [
                'statut' => $statut,
                'nb_envoyes' => count($contacts),
                'nb_succes' => $succes,
                'nb_erreurs' => $echecs,
                'appareil_utilise' => $whatsappSession,
                'reponse_api' => json_encode($reponseComplete),
                'erreur' => $erreurFinale,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id_campagne' => $campagneData['id_campagne']]);
            
            if ($succes > 0) {
                $description = "Envoi WhatsApp via {$whatsappSession} - {$succes} message(s)";
                deduireCreditClient($idCompte, $providerId, $succes, $description);
            }
            
            if ($succes > 0) {
                return [
                    'success' => true, 
                    'message' => $messageReponse,
                    'details' => $details,
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
}

// ============================================
// ENVOI EMAIL VIA LISTMONK
// ✅ CORRIGÉ : stocke maintenant reponse_api complet + updated_at
// ============================================
if (!function_exists('envoyerEmail')) {
    function envoyerEmail($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires) {
        global $db;
        
        try {
            $providerListmonk = getProviderByFournisseur('Listmonk');
            $providerId = $providerListmonk['id_provider'] ?? null;
            
            if (!$providerId) {
                return ['success' => false, 'error' => 'Provider Listmonk non configuré'];
            }
            
            $from_email = $campagneData['from_email'] ?? 'noreply@votre-domaine.com';
            $from_name = $campagneData['from_name'] ?? 'Votre Entreprise';
            $objet = $campagneData['objet'] ?? 'Email';
            $listmonkCampaignId = $campagneData['listmonk_campaign_id'] ?? null;
            
            if (!$listmonkCampaignId) {
                return ['success' => false, 'error' => 'ID de campagne Listmonk manquant.'];
            }
            
            $nbDestinataires = (int)($campagneData['nb_destinataires'] ?? count($destinataires));
            
            $verification = verifierCreditClient($idCompte, $providerId, $nbDestinataires);
            
            if (!$verification['suffisant']) {
                return [
                    'success' => false, 
                    'error' => '❌ Crédit insuffisant pour envoyer ' . $nbDestinataires . ' emails. ' . $verification['message'],
                    'solde' => $verification['solde'],
                    'cout_total' => $verification['cout_total'],
                    'credit_insuffisant' => true
                ];
            }
            
            // ============================================
            // DÉMARRER LA CAMPAGNE LISTMONK
            // ============================================
            $result = updateListmonkCampaignStatus($listmonkCampaignId, 'running');
            
            if ($result['success']) {
                // ============================================
                // RÉCUPÉRER LES STATS IMMÉDIATEMENT APRÈS L'ENVOI
                // (comme pour WhatsApp : on capture l'état initial)
                // ============================================
                $statsInitiales = recupererStatutListmonk($listmonkCampaignId);
                
                // ============================================
                // CONSTRUIRE UN reponse_api COMPLET
                // (comme pour WhatsApp et SMS)
                // ============================================
                $reponseComplete = [
                    'listmonk_campaign_id' => $listmonkCampaignId,
                    'listmonk_stats' => $statsInitiales['success'] ? $statsInitiales['data'] : null,
                    'listmonk_stats_at' => date('Y-m-d H:i:s'),
                    'nb_destinataires' => $nbDestinataires,
                    'from_email' => $from_email,
                    'from_name' => $from_name,
                    'objet' => $objet,
                    'sent_at' => date('Y-m-d H:i:s')
                ];
                
                $db->update('campagne', [
                    'statut' => 'envoye',
                    'nb_envoyes' => $nbDestinataires,
                    'nb_succes' => $nbDestinataires,
                    'nb_erreurs' => 0,
                    'appareil_utilise' => 'Listmonk (ID: ' . $listmonkCampaignId . ')',
                    'reponse_api' => json_encode($reponseComplete),
                    'erreur' => null,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id_campagne' => $campagneData['id_campagne']]);
                
                $description = "Envoi Email via Listmonk - {$nbDestinataires} email(s)";
                deduireCreditClient($idCompte, $providerId, $nbDestinataires, $description);
                
                return [
                    'success' => true, 
                    'message' => $nbDestinataires . ' emails envoyés avec succès via Listmonk. Coût: ' . number_format($verification['cout_total'], 3) . '€'
                ];
            } else {
                $errorMsg = 'Erreur Listmonk';
                if (!empty($result['curl_error'])) {
                    $errorMsg .= ' (cURL #' . $result['curl_errno'] . '): ' . $result['curl_error'];
                } elseif (!empty($result['http_code'])) {
                    $errorMsg .= ' (HTTP ' . $result['http_code'] . '): ' . substr($result['response'], 0, 300);
                } else {
                    $errorMsg .= ' : aucune réponse du serveur. Vérifiez que Listmonk est accessible sur http://164.68.103.147:9005';
                }
                
                $db->update('campagne', [
                    'statut' => 'echoue',
                    'erreur' => $errorMsg,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id_campagne' => $campagneData['id_campagne']]);
                
                return ['success' => false, 'error' => $errorMsg];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}