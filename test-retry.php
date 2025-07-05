<?php

require_once 'SydpowerClient.php';

echo "=== Testing Auto-Retry Functionality ===\n";
echo "This test simulates token invalidation scenarios.\n\n";

try {
    // Initialize client (loads from config.local.php automatically)
    $client = new SydpowerClient();
    
    // Step 1: Normal authentication
    echo "1. Initial authentication:\n";
    $client->authenticate();
    
    // Step 2: Get devices (should work)
    echo "2. Getting devices (should work):\n";
    $devices = $client->getDevices();
    $deviceIds = $client->getDeviceIds();
    
    if (empty($deviceIds)) {
        die("No devices found!\n");
    }
    
    $deviceId = $deviceIds[0];
    echo "Using device: $deviceId\n\n";
    
    // Step 3: Connect MQTT (should work)
    echo "3. Connecting to MQTT (should work):\n";
    $client->connectMqtt();
    
    // Step 4: Clear token cache to simulate invalidation
    echo "4. Simulating token invalidation (clearing cache):\n";
    $client->clearTokenCache();
    echo "✓ Token cache cleared\n\n";
    
    // Step 5: Try to get devices again (should trigger auto-retry)
    echo "5. Getting devices again (should trigger auto-retry):\n";
    $devices = $client->getDevices();
    echo "✓ Auto-retry successful! Got " . count($devices) . " devices\n\n";
    
    // Step 6: Send a command (should work after auto-retry)
    echo "6. Sending command (should work after auto-retry):\n";
    $result = $client->sendCommand($deviceId, 'REGRequestSettings');
    echo "✓ Command sent successfully\n\n";
    
    echo "🎉 Auto-retry mechanism working correctly!\n";
    echo "The system can automatically recover from token invalidation.\n";
    
    $client->disconnect();
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    if (isset($client)) {
        $client->disconnect();
    }
}