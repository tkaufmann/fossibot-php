<?php

/**
 * ABOUTME: PHP client for Sydpower API communication and device control
 * ABOUTME: Handles authentication, device management, and Modbus command execution
 */
class SydpowerClient {
    private $username;
    private $password;
    private $endpoint = "https://api.next.bspapp.com/client";
    private $clientSecret = "5rCEdl/nx7IgViBe4QYRiQ==";
    private $spaceId = "mp-6c382a98-49b8-40ba-b761-645d83e8ee74";
    
    private $deviceId;
    private $authorizeToken;
    private $accessToken;
    private $mqttAccessToken;
    private $devices = [];
    private $mqttClient;
    private $mqttConnected = false;
    private $tokenCache;
    
    public function __construct($username = null, $password = null) {
        // Try to load from config if no credentials provided
        if ($username === null || $password === null) {
            $configPath = __DIR__ . '/config.local.php';
            if (file_exists($configPath)) {
                $config = require $configPath;
                $this->username = $config['username'] ?? throw new Exception("Username not found in config.local.php");
                $this->password = $config['password'] ?? throw new Exception("Password not found in config.local.php");
            } else {
                throw new Exception("No credentials provided and config.local.php not found");
            }
        } else {
            $this->username = $username;
            $this->password = $password;
        }
        
        $this->deviceId = strtoupper(bin2hex(random_bytes(16)));
        
        // Include required classes
        require_once __DIR__ . '/MqttWebSocketClient.php';
        require_once __DIR__ . '/ModbusHelper.php';
        require_once __DIR__ . '/TokenCache.php';
        
        $this->tokenCache = new TokenCache($this->username);
    }
    
    private function generateClientInfo() {
        return json_encode([
            'PLATFORM' => 'app',
            'OS' => 'android',
            'APPID' => '__UNI__55F5E7F',
            'DEVICEID' => $this->deviceId,
            'channel' => 'google',
            'scene' => 1001,
            'appId' => '__UNI__55F5E7F',
            'appLanguage' => 'en',
            'appName' => 'BrightEMS',
            'appVersion' => '1.2.3',
            'appVersionCode' => 123,
            'appWgtVersion' => '1.2.3',
            'browserName' => 'chrome',
            'browserVersion' => '130.0.6723.86',
            'deviceBrand' => 'Samsung',
            'deviceId' => $this->deviceId,
            'deviceModel' => 'SM-A426B',
            'deviceType' => 'phone',
            'osName' => 'android',
            'osVersion' => '10',
            'romName' => 'Android',
            'romVersion' => '10',
            'ua' => 'Mozilla/5.0 (Linux; Android 10; SM-A426B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.86 Mobile Safari/537.36',
            'uniPlatform' => 'app',
            'uniRuntimeVersion' => '4.24',
            'locale' => 'en',
            'LOCALE' => 'en'
        ], JSON_UNESCAPED_SLASHES);
    }
    
    private function sign($data) {
        // Sort keys alphabetically
        $sortedKeys = array_keys($data);
        sort($sortedKeys);
        
        $queryString = '';
        foreach ($sortedKeys as $key) {
            // Match JavaScript behavior: e[t] && (check for truthy values)
            if ($data[$key]) {
                $queryString .= "&" . $key . "=" . $data[$key];
            }
        }
        $queryString = substr($queryString, 1); // Remove first &
        
        $signature = hash_hmac('md5', $queryString, $this->clientSecret);
        
        return $signature;
    }
    
    private function apiRequest($route, $params = '{}', $useToken = false) {
        $method = "serverless.function.runtime.invoke";
        $clientInfo = $this->generateClientInfo();
        
        switch ($route) {
            case 'auth':
                $method = "serverless.auth.user.anonymousAuthorize";
                break;
            case 'login':
                $params = json_encode([
                    'functionTarget' => 'router',
                    'functionArgs' => [
                        '$url' => 'user/pub/login',
                        'data' => [
                            'locale' => 'en',
                            'username' => $this->username,
                            'password' => $this->password
                        ],
                        'clientInfo' => json_decode($clientInfo, true)
                    ]
                ], JSON_UNESCAPED_SLASHES);
                break;
            case 'mqtt':
                $params = json_encode([
                    'functionTarget' => 'router',
                    'functionArgs' => [
                        '$url' => 'common/emqx.getAccessToken',
                        'data' => ['locale' => 'en'],
                        'clientInfo' => json_decode($clientInfo, true),
                        'uniIdToken' => $this->accessToken
                    ]
                ], JSON_UNESCAPED_SLASHES);
                break;
            case 'devices':
                $params = json_encode([
                    'functionTarget' => 'router',
                    'functionArgs' => [
                        '$url' => 'client/device/kh/getList',
                        'data' => [
                            'locale' => 'en',
                            'pageIndex' => 1,
                            'pageSize' => 100
                        ],
                        'clientInfo' => json_decode($clientInfo, true),
                        'uniIdToken' => $this->accessToken
                    ]
                ], JSON_UNESCAPED_SLASHES);
                break;
        }
        
        $data = [
            'method' => $method,
            'params' => $params,
            'spaceId' => $this->spaceId,
            'timestamp' => time() * 1000
        ];
        
        if ($useToken && $this->authorizeToken) {
            $data['token'] = $this->authorizeToken;
        }
        
        $headers = [
            'Content-Type: application/json',
            'x-serverless-sign: ' . $this->sign($data),
            'User-Agent: Mozilla/5.0 (Linux; Android 10; SM-A426B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.86 Mobile Safari/537.36'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: {$httpCode}");
        }
        
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }
        
        return $result;
    }
    
    private function apiCallWithRetry($callback, $maxRetries = 1) {
        $attempt = 0;
        while ($attempt <= $maxRetries) {
            try {
                return $callback();
            } catch (Exception $e) {
                $attempt++;
                
                // Check if this looks like an authentication error
                $isAuthError = (
                    strpos($e->getMessage(), 'HTTP Error: 403') !== false ||
                    strpos($e->getMessage(), 'HTTP Error: 401') !== false ||
                    strpos($e->getMessage(), 'Unauthorized') !== false ||
                    strpos($e->getMessage(), 'Invalid token') !== false ||
                    strpos($e->getMessage(), 'Invalid API response structure') !== false
                );
                
                if ($isAuthError && $attempt <= $maxRetries) {
                    echo "⚠️  Authentication error detected (attempt $attempt/$maxRetries): {$e->getMessage()}\n";
                    echo "🔄 Clearing token cache and re-authenticating...\n";
                    
                    // Clear cache and force fresh authentication
                    $this->tokenCache->clearCache();
                    $this->accessToken = null;
                    $this->mqttAccessToken = null;
                    
                    // Re-authenticate
                    $this->authenticate();
                    
                    echo "✅ Re-authentication completed, retrying operation...\n";
                    continue; // Retry the operation
                } else {
                    // Not an auth error or max retries exceeded
                    throw $e;
                }
            }
        }
    }
    
    public function authenticate() {
        // Try to use cached tokens first
        $cachedTokens = $this->tokenCache->getValidTokens();
        if ($cachedTokens) {
            $this->accessToken = $cachedTokens['accessToken'];
            $this->mqttAccessToken = $cachedTokens['mqttAccessToken'];
            echo "Using cached authentication tokens\n\n";
            return true;
        }
        
        echo "1. Fetching anonymous token...\n";
        $authResponse = $this->apiRequest('auth');
        $this->authorizeToken = $authResponse['data']['accessToken'];
        echo "   Got token: " . substr($this->authorizeToken, 0, 20) . "...\n";
        
        echo "2. Logging in with credentials...\n";
        $loginResponse = $this->apiRequest('login', '{}', true);
        $this->accessToken = $loginResponse['data']['token'];
        echo "   Got access token: " . substr($this->accessToken, 0, 20) . "...\n";
        
        echo "3. Fetching MQTT token...\n";
        $mqttResponse = $this->apiRequest('mqtt', '{}', true);
        $this->mqttAccessToken = $mqttResponse['data']['access_token'];
        echo "   Got MQTT token: " . substr($this->mqttAccessToken, 0, 20) . "...\n";
        
        // Cache the tokens
        $this->tokenCache->saveTokens($this->accessToken, $this->mqttAccessToken);
        
        echo "Authentication successful!\n\n";
        return true;
    }
    
    public function getDevices() {
        return $this->apiCallWithRetry(function() {
            echo "Fetching device list...\n";
            $devicesResponse = $this->apiRequest('devices', '{}', true);
            
            // Check if response has expected structure
            if (!isset($devicesResponse['data']) || !isset($devicesResponse['data']['rows'])) {
                throw new Exception("Invalid API response structure - possible token issue");
            }
            
            foreach ($devicesResponse['data']['rows'] as $device) {
                $deviceId = str_replace(':', '', $device['device_id']);
                $this->devices[$deviceId] = $device;
                echo "   Found device: {$device['device_name']} (ID: {$deviceId})\n";
            }
            
            echo "\n";
            return $this->devices;
        });
    }
    
    public function connectMqtt() {
        return $this->apiCallWithRetry(function() {
            if (!$this->mqttAccessToken) {
                throw new Exception("MQTT access token not available. Call authenticate() first.");
            }
            
            echo "Connecting to MQTT...\n";
            $this->mqttClient = new MqttWebSocketClient('mqtt.sydpower.com', 8083, '/mqtt');
            $this->mqttClient->setCredentials($this->mqttAccessToken, 'helloyou');
            
            try {
                $this->mqttClient->connect();
                $this->mqttConnected = true;
            } catch (Exception $e) {
                // MQTT connection failed - might be token issue
                if (strpos($e->getMessage(), 'Connection refused') !== false || 
                    strpos($e->getMessage(), 'Authentication') !== false) {
                    throw new Exception("HTTP Error: 401"); // Trigger auth retry
                }
                throw $e;
            }
            
            // Subscribe to device response topics
            $deviceIds = $this->getDeviceIds();
            foreach ($deviceIds as $deviceId) {
                $this->mqttClient->subscribe("{$deviceId}/device/response/state");
                $this->mqttClient->subscribe("{$deviceId}/device/response/client/+");
            }
            
            // Set up message handler
            $this->mqttClient->onMessage(function($topic, $payload) {
                $this->handleMqttMessage($topic, $payload);
            });
            
            echo "MQTT connected and subscribed!\n\n";
            return true;
        });
    }
    
    private function handleMqttMessage($topic, $payload) {
        $deviceMac = explode('/', $topic)[0];
        
        // Convert payload to array of integers
        $arr = [];
        for ($i = 0; $i < strlen($payload); $i++) {
            $arr[] = ord($payload[$i]);
        }
        
        // Following MODBUS protocol, removing 6 first control indexes
        $c = array_slice($arr, 6);
        
        // Transform to 16-bit registers
        $registers = [];
        for ($i = 0; $i < count($c); $i += 2) {
            if (isset($c[$i + 1])) {
                $registers[] = ModbusHelper::highLowToInt($c[$i], $c[$i + 1]);
            }
        }
        
        // Update device status based on message type
        if (count($registers) == 81 && strpos($topic, 'device/response/client/04') !== false) {
            $this->updateDeviceStatus04($deviceMac, $registers);
        } elseif (count($registers) == 81 && strpos($topic, 'device/response/client/data') !== false) {
            $this->updateDeviceStatusData($deviceMac, $registers);
        }
    }
    
    private function updateDeviceStatus04($deviceMac, $registers) {
        if (!isset($this->devices[$deviceMac])) {
            $this->devices[$deviceMac] = [];
        }
        
        $activeOutputs = str_pad(decbin($registers[41]), 16, '0', STR_PAD_LEFT);
        $activeOutputs = str_split(strrev($activeOutputs)); // Reverse for correct bit order
        
        $this->devices[$deviceMac]['soc'] = round(($registers[56] / 1000) * 100, 1);
        $this->devices[$deviceMac]['totalInput'] = $registers[6];
        $this->devices[$deviceMac]['totalOutput'] = $registers[39];
        // Corrected bit assignments based on real device testing:
        // Fixed: Original bit assignments were incorrect for F2400 devices
        $this->devices[$deviceMac]['acOutput'] = $activeOutputs[11] == '1';  // Bit[11] = AC Output
        $this->devices[$deviceMac]['usbOutput'] = $activeOutputs[9] == '1';  // Bit[9] = USB Output  
        $this->devices[$deviceMac]['dcOutput'] = $activeOutputs[10] == '1';  // Bit[10] = DC Output
        $this->devices[$deviceMac]['ledOutput'] = $activeOutputs[3] == '1';
        
        echo "Status update for {$deviceMac}: SOC={$this->devices[$deviceMac]['soc']}%, Input={$registers[6]}W, Output={$registers[39]}W\n";
    }
    
    private function updateDeviceStatusData($deviceMac, $registers) {
        if (!isset($this->devices[$deviceMac])) {
            $this->devices[$deviceMac] = [];
        }
        
        $this->devices[$deviceMac]['maximumChargingCurrent'] = $registers[20];
        $this->devices[$deviceMac]['acSilentCharging'] = $registers[57] == 1;
        $this->devices[$deviceMac]['usbStandbyTime'] = $registers[59];
        $this->devices[$deviceMac]['acStandbyTime'] = $registers[60];
        $this->devices[$deviceMac]['dcStandbyTime'] = $registers[61];
        $this->devices[$deviceMac]['screenRestTime'] = $registers[62];
        $this->devices[$deviceMac]['stopChargeAfter'] = $registers[63];
        $this->devices[$deviceMac]['dischargeLowerLimit'] = $registers[66];
        $this->devices[$deviceMac]['acChargingUpperLimit'] = $registers[67];
        $this->devices[$deviceMac]['wholeMachineUnusedTime'] = $registers[68];
        
        echo "Settings update for {$deviceMac}: MaxCurrent={$registers[20]}A, ChargeLimit={$registers[67]}\n";
    }
    
    public function getDeviceIds() {
        return array_keys($this->devices);
    }
    
    public function sendCommand($deviceId, $command, $value = null) {
        if (!$this->mqttConnected) {
            throw new Exception("MQTT not connected. Call connectMqtt() first.");
        }
        
        // CRITICAL: Whitelist of safe commands to prevent device damage
        $safeCommands = [
            'REGRequestSettings',
            'REGMaxChargeCurrent',
            'REGChargeUpperLimit', 
            'REGStopChargeAfter',
            'REGEnableUSBOutput',
            'REGDisableUSBOutput',
            'REGEnableDCOutput',
            'REGDisableDCOutput',
            'REGEnableACOutput',
            'REGDisableACOutput'
            // DANGEROUS COMMANDS INTENTIONALLY EXCLUDED:
            // - REGSleepTime (value 0 bricks device!)
            // - Any register modification not explicitly tested
        ];
        
        if (!in_array($command, $safeCommands, true)) {
            throw new Exception("CRITICAL: Command '$command' is not in the safe commands whitelist. BLOCKING to prevent device damage!");
        }
        
        try {
            $modbusMessage = null;
            
            // Generate appropriate Modbus command with validation
            switch ($command) {
                case 'REGRequestSettings':
                    $modbusMessage = ModbusHelper::getRequestSettingsCommand();
                    break;
                    
                case 'REGMaxChargeCurrent':
                    if ($value === null) throw new Exception("Value required for {$command}");
                    echo "Validating charge current: $value (allowed: 1-20A)\n";
                    $modbusMessage = ModbusHelper::getMaxChargeCurrentCommand($value);
                    break;
                    
                case 'REGChargeUpperLimit':
                    if ($value === null) throw new Exception("Value required for {$command}");
                    echo "Validating charge limit: $value (allowed: 0-1000 permille, divisible by 5/10)\n";
                    $modbusMessage = ModbusHelper::getChargeUpperLimitCommand($value);
                    break;
                    
                case 'REGStopChargeAfter':
                    if ($value === null) throw new Exception("Value required for {$command}");
                    echo "Validating stop charge timer: $value minutes (must be >= 0)\n";
                    $modbusMessage = ModbusHelper::getStopChargeAfterCommand($value);
                    break;
                    
                case 'REGEnableUSBOutput':
                    echo "Enabling USB output (safe command)\n";
                    $modbusMessage = ModbusHelper::getUSBOutputCommand(true);
                    break;
                    
                case 'REGDisableUSBOutput':
                    echo "Disabling USB output (safe command)\n";
                    $modbusMessage = ModbusHelper::getUSBOutputCommand(false);
                    break;
                    
                case 'REGEnableDCOutput':
                    echo "Enabling DC output (safe command)\n";
                    $modbusMessage = ModbusHelper::getDCOutputCommand(true);
                    break;
                    
                case 'REGDisableDCOutput':
                    echo "Disabling DC output (safe command)\n";
                    $modbusMessage = ModbusHelper::getDCOutputCommand(false);
                    break;
                    
                case 'REGEnableACOutput':
                    echo "Enabling AC output (safe command)\n";
                    $modbusMessage = ModbusHelper::getACOutputCommand(true);
                    break;
                    
                case 'REGDisableACOutput':
                    echo "Disabling AC output (safe command)\n";
                    $modbusMessage = ModbusHelper::getACOutputCommand(false);
                    break;
            }
            
            if ($modbusMessage === null) {
                throw new Exception("Failed to generate Modbus message for {$command}");
            }
            
            // Send via MQTT
            $topic = "{$deviceId}/client/request/data";
            $this->mqttClient->publish($topic, $modbusMessage, 1);
            
            echo "Command sent: {$command}" . ($value !== null ? " = {$value}" : "") . "\n";
            return ['success' => "Command {$command} sent"];
            
        } catch (Exception $e) {
            echo "Error sending command: " . $e->getMessage() . "\n";
            return ['error' => $e->getMessage()];
        }
    }
    
    public function getDeviceStatus($deviceId) {
        if (isset($this->devices[$deviceId])) {
            return $this->devices[$deviceId];
        }
        return false;
    }
    
    public function requestDeviceSettings($deviceId) {
        // Request fresh settings from device
        $this->sendCommand($deviceId, 'REGRequestSettings');
    }
    
    public function listenForUpdates($timeout = 30) {
        if (!$this->mqttConnected) {
            throw new Exception("MQTT not connected. Call connectMqtt() first.");
        }
        
        echo "Listening for MQTT messages for {$timeout} seconds...\n";
        $this->mqttClient->loop($timeout);
    }
    
    public function disconnect() {
        if ($this->mqttClient && $this->mqttConnected) {
            $this->mqttClient->disconnect();
            $this->mqttConnected = false;
        }
    }
    
    public function getTokenInfo() {
        return $this->tokenCache->getTokenInfo();
    }
    
    public function clearTokenCache() {
        $this->tokenCache->clearCache();
    }
}