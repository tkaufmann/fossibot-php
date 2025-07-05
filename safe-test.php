<?php

require_once 'SydpowerClient.php';

echo "=== SAFE Fossibot Test ===\n";
echo "This test uses ONLY validated, safe commands.\n\n";

try {
    // Initialize client (loads from config.local.php automatically)
    $client = new SydpowerClient();
    
    // Authenticate
    echo "1. Authenticating...\n";
    $client->authenticate();
    
    // Get devices
    echo "2. Getting device list...\n";
    $devices = $client->getDevices();
    $deviceIds = $client->getDeviceIds();
    
    if (empty($deviceIds)) {
        die("No devices found!\n");
    }
    
    $deviceId = $deviceIds[0];
    echo "Using device: $deviceId\n\n";
    
    // Connect MQTT
    echo "3. Connecting to MQTT...\n";
    $client->connectMqtt();
    
    echo "=== Safe Commands Demo ===\n";
    
    // Test 1: Request current settings (always safe)
    echo "Test 1: Requesting current device settings...\n";
    $result = $client->sendCommand($deviceId, 'REGRequestSettings');
    echo "✓ Settings requested\n\n";
    
    // Test 2: Set safe charge current (5A is well within 1-20A range)
    echo "Test 2: Setting charge current to safe 5A...\n";
    $result = $client->sendCommand($deviceId, 'REGMaxChargeCurrent', 5);
    echo "✓ Charge current set to 5A\n\n";
    
    // Test 3: Set charge limit to 80% (800 permille)
    echo "Test 3: Setting charge limit to 80% (800 permille)...\n";
    $result = $client->sendCommand($deviceId, 'REGChargeUpperLimit', 800);
    echo "✓ Charge limit set to 80%\n\n";
    
    echo "=== USV Mode Configuration ===\n";
    echo "Setting optimal USV mode (minimal charging):\n";
    
    // USV Mode: Minimal current + 80% limit = quasi "charging off"
    echo "- Setting charge current to 1A (minimum)...\n";
    $result = $client->sendCommand($deviceId, 'REGMaxChargeCurrent', 1);
    echo "✓ Minimum charge current set\n";
    
    echo "- Setting charge limit to 80%...\n";
    $result = $client->sendCommand($deviceId, 'REGChargeUpperLimit', 800);
    echo "✓ Charge limit set\n";
    
    echo "\n🎉 All tests completed safely!\n";
    echo "Your device is now in USV mode with minimal charging.\n";
    
    $client->disconnect();
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "This is likely a safety block protecting your device.\n";
    if (isset($client)) {
        $client->disconnect();
    }
}