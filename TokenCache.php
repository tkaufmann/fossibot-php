<?php

/**
 * ABOUTME: Token caching system for Sydpower authentication
 * ABOUTME: Stores and validates JWT tokens to minimize API calls
 */
class TokenCache {
    
    private $cacheFile;
    
    public function __construct($username) {
        // Create user-specific cache file
        $cacheDir = __DIR__ . '/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0700, true);
        }
        $this->cacheFile = $cacheDir . '/tokens_' . md5($username) . '.json';
    }
    
    public function getValidTokens() {
        if (!file_exists($this->cacheFile)) {
            return null;
        }
        
        try {
            $data = json_decode(file_get_contents($this->cacheFile), true);
            if (!$data || !isset($data['accessToken'], $data['mqttAccessToken'])) {
                return null;
            }
            
            // Check if MQTT token is still valid (with 1 hour buffer)
            $mqttPayload = $this->decodeJWT($data['mqttAccessToken']);
            if (!$mqttPayload || !isset($mqttPayload['exp'])) {
                return null;
            }
            
            $timeLeft = $mqttPayload['exp'] - time();
            if ($timeLeft < 3600) { // Less than 1 hour left
                echo "MQTT token expires in " . round($timeLeft/60) . " minutes, refreshing...\n";
                return null;
            }
            
            echo "Using cached tokens (MQTT valid for " . round($timeLeft/3600, 1) . " hours)\n";
            return $data;
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    public function saveTokens($accessToken, $mqttAccessToken) {
        $data = [
            'accessToken' => $accessToken,
            'mqttAccessToken' => $mqttAccessToken,
            'timestamp' => time(),
            'cached_at' => date('Y-m-d H:i:s')
        ];
        
        file_put_contents($this->cacheFile, json_encode($data, JSON_PRETTY_PRINT));
        echo "Tokens cached successfully\n";
    }
    
    public function clearCache() {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
            echo "Token cache cleared\n";
        }
    }
    
    private function decodeJWT($jwt) {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;
        
        $payload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)), true);
        return $payload;
    }
    
    public function getTokenInfo() {
        $tokens = $this->getValidTokens();
        if (!$tokens) {
            return "No valid tokens cached";
        }
        
        $mqttPayload = $this->decodeJWT($tokens['mqttAccessToken']);
        if ($mqttPayload && isset($mqttPayload['exp'])) {
            $timeLeft = $mqttPayload['exp'] - time();
            $expiry = date('Y-m-d H:i:s', $mqttPayload['exp']);
            return "MQTT token expires: $expiry (in " . round($timeLeft/3600, 1) . " hours)";
        }
        
        return "Token info unavailable";
    }
}