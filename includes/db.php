<?php
require_once __DIR__ . '/../config.php';

class Database {
    private $url;
    private $apiKey;
    
    public function __construct() {
        $this->url = rtrim(SUPABASE_URL, '/');
        $this->apiKey = SUPABASE_KEY;
    }

    public function deleteWithConditions($table, $conditions) {
        $query = "";
        foreach ($conditions as $col => $value) {
            if (!empty($query)) {
                $query .= "&";
            }
            //Convertir les IDs en string pour les UUID
            if (preg_match('/^id_?[a-zA-Z]*$/', $col) || $col === 'id' || strpos($col, '_id') !== false) {
                $value = (string)$value;
            }
            if ($col === 'user') {
                $col = '"user"';
            }
            $query .= $col . "=eq." . urlencode($value);
        }
        
        $endpoint = $table . '?' . $query;
        return $this->request('DELETE', $endpoint);
    }
    
    private function request($method, $endpoint, $data = null) {
        if (!function_exists('curl_init')) {
            throw new Exception("L'extension PHP curl n'est pas activée.");
        }
        
        $ch = curl_init();
        $url = $this->url . '/rest/v1/' . $endpoint;
        
        // Nettoyer l'URL pour éviter les problèmes
        $url = str_replace(' ', '%20', $url);
        
        $headers = [
            'apikey: ' . $this->apiKey,
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Prefer: return=representation'
        ];
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        if (DEBUG_MODE) {
            error_log("Supabase API Call: " . $method . " " . $url);
            if ($data) {
                error_log("Data: " . json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        if ($curlError) {
            curl_close($ch);
            throw new Exception("CURL Error: " . $curlError . " - URL: " . $url);
        }
        
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("API Error (" . $httpCode . "): " . $response);
        }
        
        return json_decode($response, true);
    }
    
    public function select($table, $conditions = [], $select = '*', $orderBy = null, $limit = null, $offset = null) {
        $query = "select=" . $select;
        
        // Ajouter les conditions
        foreach ($conditions as $col => $value) {
            //Convertir les IDs en string pour les UUID
            if (preg_match('/^id_?[a-zA-Z]*$/', $col) || $col === 'id' || strpos($col, '_id') !== false) {
                $value = (string)$value;
            }
            
            if ($col === 'user') {
                $query .= '&"user"=eq.' . urlencode($value);
            } else {
                // Gérer les conditions spéciales
                if (strpos($col, '!=') !== false) {
                    $col = str_replace('!=', '', $col);
                    $query .= "&" . $col . "=neq." . urlencode($value);
                } elseif (strpos($col, '>=') !== false) {
                    $col = str_replace('>=', '', $col);
                    $query .= "&" . $col . "=gte." . urlencode($value);
                } elseif (strpos($col, '<=') !== false) {
                    $col = str_replace('<=', '', $col);
                    $query .= "&" . $col . "=lte." . urlencode($value);
                } elseif (strpos($col, '>') !== false) {
                    $col = str_replace('>', '', $col);
                    $query .= "&" . $col . "=gt." . urlencode($value);
                } elseif (strpos($col, '<') !== false) {
                    $col = str_replace('<', '', $col);
                    $query .= "&" . $col . "=lt." . urlencode($value);
                } else {
                    $query .= "&" . $col . "=eq." . urlencode($value);
                }
            }
        }
        
        // Ajouter l'ordre
        if ($orderBy) {
            $orderBy = trim($orderBy);
            
            if (preg_match('/^[a-zA-Z0-9_]+\.(desc|asc)$/i', $orderBy)) {
                $query .= "&order=" . $orderBy;
            } else {
                $parts = explode(' ', $orderBy);
                $column = $parts[0];
                $direction = isset($parts[1]) ? strtoupper($parts[1]) : 'ASC';
                
                if ($direction !== 'ASC' && $direction !== 'DESC') {
                    $direction = 'ASC';
                }
                
                $query .= "&order=" . $column . "." . strtolower($direction);
            }
        }
        
        // Ajouter la limite
        if ($limit) {
            $query .= "&limit=" . intval($limit);
        }
        
        // Ajouter l'offset
        if ($offset) {
            $query .= "&offset=" . intval($offset);
        }
        
        $endpoint = $table . '?' . $query;
        return $this->request('GET', $endpoint);
    }
    
    public function insert($table, $data) {
        return $this->request('POST', $table, $data);
    }
    
    /**
     * Insère un enregistrement et retourne l'ID créé
     */
    public function insertAndGetId($table, $data) {
        $endpoint = $table;
        
        $postData = [];
        foreach ($data as $key => $value) {
            $postData[$key] = $value;
        }
        
        $result = $this->request('POST', $endpoint, $postData);
        
        error_log("insertAndGetId - Résultat: " . json_encode($result));
        
        if (!empty($result) && isset($result[0])) {
            $row = $result[0];
            $possibleKeys = ['id_custom_field', 'id_contact', 'id', 'id_value'];
            foreach ($possibleKeys as $key) {
                if (isset($row[$key])) {
                    return $row[$key];
                }
            }
            
            $firstKey = array_key_first($row);
            if ($firstKey) {
                return $row[$firstKey];
            }
        }
        
        if (!empty($result) && isset($result['id_custom_field'])) {
            return $result['id_custom_field'];
        }
        if (!empty($result) && isset($result['id'])) {
            return $result['id'];
        }
        
        return null;
    }
    
    public function update($table, $data, $conditions) {
        $query = "";
        foreach ($conditions as $col => $value) {
            if (!empty($query)) {
                $query .= "&";
            }
            //Convertir les IDs en string pour les UUID
            if (preg_match('/^id_?[a-zA-Z]*$/', $col) || $col === 'id' || strpos($col, '_id') !== false) {
                $value = (string)$value;
            }
            if ($col === 'user') {
                $col = '"user"';
            }
            $query .= $col . "=eq." . urlencode($value);
        }
        
        $endpoint = $table . '?' . $query;
        return $this->request('PATCH', $endpoint, $data);
    }
    
    public function delete($table, $id, $idField) {
        //Convertir l'ID en string pour les UUID
        $id = (string)$id;
        $endpoint = $table . '?' . $idField . '=eq.' . urlencode($id);
        return $this->request('DELETE', $endpoint);
    }
    
    /**
     * Exécute une fonction RPC sur Supabase
     */
    public function rpc($function, $params = []) {
        $endpoint = 'rpc/' . $function;
        return $this->request('POST', $endpoint, $params);
    }
}

$db = new Database();

// ============================================
// FONCTIONS UTILITAIRES DE BASE
// ============================================

function getCompteByUser($user) {
    global $db;
    $result = $db->select('compte', ['user' => $user]);
    return $result ? $result[0] : null;
}

function getContactsByCompte($idCompte) {
    global $db;
    //Convertir en string pour UUID
    $idCompte = (string)$idCompte;
    return $db->select('contact', ['id_compte' => $idCompte]);
}

function getListesByCompte($idCompte) {
    global $db;
    //Convertir en string pour UUID
    $idCompte = (string)$idCompte;
    return $db->select('liste', ['id_compte' => $idCompte]);
}

function getCreditsDisponibles($id) {
    global $db;
    
    //Convertir l'ID en string pour les UUID
    $id = (string)$id;
    
    // Récupérer depuis la table compte
    $result = $db->select('compte', ['id_compte' => $id], 'credits_total');
    return $result ? floatval($result[0]['credits_total'] ?? 0) : 0;
}

function getTypesMessage() {
    global $db;
    
    try {
        $result = $db->select('type_message');
        if (!empty($result)) {
            return $result;
        }
        
        try {
            $db->insert('type_message', ['libelle_type' => 'SMS']);
            $db->insert('type_message', ['libelle_type' => 'Email']);
            $db->insert('type_message', ['libelle_type' => 'WhatsApp']);
            $db->insert('type_message', ['libelle_type' => 'Audio']);
            $result = $db->select('type_message');
            return $result;
        } catch (Exception $e) {
            // Ignorer
        }
        
        return $result ?: [];
    } catch (Exception $e) {
        error_log("getTypesMessage error: " . $e->getMessage());
        return [];
    }
}

function getWhatsAppSession($idCompte) {
    global $db;
    //Convertir en string pour UUID
    $idCompte = (string)$idCompte;
    $result = $db->select('whatsapp_sessions', [
        'id_compte' => $idCompte,
        'est_active' => true
    ]);
    return $result ? $result[0]['nom_session'] : null;
}

function getAllWhatsAppSessions($idCompte) {
    global $db;
    //Convertir en string pour UUID
    $idCompte = (string)$idCompte;
    return $db->select('whatsapp_sessions', ['id_compte' => $idCompte], '*', 'created_at.desc');
}

function setActiveWhatsAppSession($idCompte, $sessionName) {
    global $db;
    //Convertir en string pour UUID
    $idCompte = (string)$idCompte;
    
    // Désactiver toutes les sessions
    $db->update('whatsapp_sessions', ['est_active' => false], ['id_compte' => $idCompte]);
    
    // Activer la session choisie
    $db->update('whatsapp_sessions', ['est_active' => true], [
        'id_compte' => $idCompte,
        'nom_session' => $sessionName
    ]);
}
?>