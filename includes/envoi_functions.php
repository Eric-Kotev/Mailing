<?php
// ============================================
// FONCTION DE MISE À JOUR DU STATUT GLOBAL
// ============================================
function mettreAJourStatutCampagne($idCampagneConfig, $idCompte) {
    global $db;
    
    // Récupérer tous les messages de la campagne
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
    
    foreach ($messages as $msg) {
        switch ($msg['statut']) {
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
        }
    }
    
    // Déterminer le statut global
    if ($nbEnvoyes == $nbTotal) {
        $statut = 'envoyee';
        $sent_at = date('Y-m-d H:i:s');
    } elseif ($nbEchoues == $nbTotal) {
        $statut = 'echoue';
        $sent_at = null;
    } elseif ($nbEnvoyes > 0 || $nbEchoues > 0) {
        $statut = 'partiel';
        $sent_at = null;
    } elseif ($nbPret > 0) {
        $statut = 'pret_a_envoyer';
        $sent_at = null;
    } else {
        $statut = 'brouillon';
        $sent_at = null;
    }
    
    // Mettre à jour la campagne config
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
// FONCTIONS D'ENVOI
// ============================================

function envoyerSMS($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires) {
    global $db;
    
    try {
        // Récupérer les infos depuis la campagne
        $device_id = $campagne['device_id'] ?? null;
        $appareilId = $campagne['appareil_id'] ?? null;
        $providerId = $campagne['provider_id'] ?? null;
        
        if (!$providerId) {
            return ['success' => false, 'error' => 'Provider non configuré'];
        }
        
        if (empty($device_id)) {
            return ['success' => false, 'error' => 'device_id non configuré. Veuillez recréer le message.'];
        }
        
        if (empty($appareilId)) {
            return ['success' => false, 'error' => 'appareil_id non configuré. Veuillez recréer le message.'];
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
            return ['success' => false, 'error' => 'Identifiants API SMS manquants pour cet appareil.'];
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
        
        $apiUrl = 'http://164.68.103.147:8081/api/controller.php';
        $apiKey = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
        
        $contacts = [];
        foreach ($destinataires as $dest) {
            if (preg_match('/\(([^)]+)\)/', $dest, $matches)) {
                $telephone = $matches[1];
                $telephone = preg_replace('/[^0-9]/', '', $telephone);
                if (strlen($telephone) == 10 && substr($telephone, 0, 1) == '0') {
                    $telephone = '261' . substr($telephone, 1);
                }
                if (substr($telephone, 0, 3) != '261') {
                    $telephone = '261' . $telephone;
                }
                $contacts[] = $telephone;
            }
        }
        
        if (empty($contacts)) {
            return ['success' => false, 'error' => 'Aucun numéro de téléphone valide trouvé'];
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
        
        $succes = 0;
        $echecs = 0;
        $erreurs = [];
        
        foreach ($contacts as $index => $contact) {
            if ($index > 0) {
                $delay = rand($min_delay, $max_delay);
                sleep($delay);
            }
            
            if ($fichierData && $fichierData['fichier_pret']) {
                $data = [
                    'session' => $whatsappSession,
                    'type' => $fichierData['type'],
                    'contacts' => [$contact],
                    'payload' => $fichierData['payload'],
                    'min_delay' => 0,
                    'max_delay' => 0
                ];
                
                if ($fichierData['type'] !== 'text' && !empty($message) && $fichierData['type'] !== 'voice') {
                    $data['payload']['caption'] = $message;
                }
            } else {
                $data = [
                    'session' => $whatsappSession,
                    'type' => 'text',
                    'contacts' => [$contact],
                    'payload' => ['text' => $message],
                    'min_delay' => 0,
                    'max_delay' => 0
                ];
            }
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl . '/messages/send-bulk');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Controller-Key: ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 || $httpCode === 201) {
                $succes++;
            } else {
                $echecs++;
                $erreurs[] = $contact . ': ' . substr($response, 0, 100);
            }
        }
        
        if ($echecs > 0 && $succes > 0) {
            $statut = 'partiel';
        } elseif ($echecs > 0 && $succes == 0) {
            $statut = 'echoue';
        } else {
            $statut = 'envoye';
        }
        
        $db->update('campagne', [
            'statut' => $statut,
            'nb_envoyes' => count($contacts),
            'nb_succes' => $succes,
            'nb_erreurs' => $echecs,
            'appareil_utilise' => $whatsappSession,
            'erreur' => !empty($erreurs) ? json_encode($erreurs) : null
        ], ['id_campagne' => $campagneData['id_campagne']]);
        
        if ($echecs == 0) {
            return ['success' => true, 'message' => $succes . ' messages WhatsApp envoyés avec succès'];
        } elseif ($succes > 0) {
            return ['success' => true, 'message' => $succes . ' messages envoyés, ' . $echecs . ' échecs'];
        } else {
            return ['success' => false, 'error' => 'Tous les messages ont échoué'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function envoyerEmail($idCompte, $id_campagne, $campagne, $campagneData, $message, $destinataires) {
    return ['success' => false, 'error' => 'Envoi d\'email non encore implémenté'];
}