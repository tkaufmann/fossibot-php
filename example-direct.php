<?php

require_once 'SydpowerClient.php';
require_once 'ModbusHelper.php';

// Check if config.local.php exists
if (!file_exists('config.local.php')) {
    die("Please copy config.php to config.local.php and enter your credentials!\n");
}

echo "=== Fossibot PHP POC (Direct MQTT) ===\n\n";

try {
    // Initialize client (loads from config.local.php automatically)
    $client = new SydpowerClient();
    
    // Authenticate
    $client->authenticate();
    
    // Get devices
    $devices = $client->getDevices();
    $deviceIds = $client->getDeviceIds();
    
    if (empty($deviceIds)) {
        die("No devices found!\n");
    }
    
    // Use first device  
    $deviceId = $deviceIds[0];
    echo "Using device: {$deviceId}\n\n";
    
    // Connect to MQTT
    $client->connectMqtt();
    
    // Interactive command loop
    echo "=== Interactive Control ===\n";
    echo "Available commands:\n";
    echo "  1 - Request settings\n";
    echo "  2 - Set charge current (1-20A)\n";
    echo "  3 - Set charge limit (0-1000 permille)\n";
    echo "  4 - Enable/Disable USB output\n";
    echo "  5 - Enable/Disable DC output\n";
    echo "  6 - Enable/Disable AC output\n";
    echo "  7 - Configure USV mode (1A + 80% limit)\n";
    echo "  8 - Listen for updates (30 seconds)\n";
    echo "  q - Quit\n\n";
    
    while (true) {
        echo "Enter command: ";
        $input = trim(fgets(STDIN));
        
        if ($input === 'q') {
            break;
        }
        
        switch ($input) {
            case '1':
                echo "Requesting device settings...\n";
                $client->sendCommand($deviceId, 'REGRequestSettings');
                break;
                
            case '2':
                echo "Enter charge current (1-20A): ";
                $current = (int)trim(fgets(STDIN));
                if ($current >= 1 && $current <= 20) {
                    $client->sendCommand($deviceId, 'REGMaxChargeCurrent', $current);
                } else {
                    echo "Invalid current. Must be 1-20A.\n";
                }
                break;
                
            case '3':
                echo "Enter charge limit (0-1000 permille): ";
                $limit = (int)trim(fgets(STDIN));
                if ($limit >= 0 && $limit <= 1000) {
                    $client->sendCommand($deviceId, 'REGChargeUpperLimit', $limit);
                } else {
                    echo "Invalid limit. Must be 0-1000 permille.\n";
                }
                break;
                
            case '4':
                echo "Enable USB output? (y/n): ";
                $enable = trim(fgets(STDIN)) === 'y';
                $command = $enable ? 'REGEnableUSBOutput' : 'REGDisableUSBOutput';
                $client->sendCommand($deviceId, $command);
                break;
                
            case '5':
                echo "Enable DC output? (y/n): ";
                $enable = trim(fgets(STDIN)) === 'y';
                $command = $enable ? 'REGEnableDCOutput' : 'REGDisableDCOutput';
                $client->sendCommand($deviceId, $command);
                break;
                
            case '6':
                echo "Enable AC output? (y/n): ";
                $enable = trim(fgets(STDIN)) === 'y';
                $command = $enable ? 'REGEnableACOutput' : 'REGDisableACOutput';
                $client->sendCommand($deviceId, $command);
                break;
                
            case '7':
                echo "Configuring USV mode (1A + 80% limit)...\n";
                $client->sendCommand($deviceId, 'REGMaxChargeCurrent', 1);
                $client->sendCommand($deviceId, 'REGChargeUpperLimit', 800);
                echo "USV mode configured!\n";
                break;
                
            case '8':
                echo "Listening for updates...\n";
                $client->listenForUpdates(30);
                break;
                
            default:
                echo "Unknown command. Try 1-8 or q.\n";
        }
        
        echo "\n";
    }
    
    $client->disconnect();
    echo "Disconnected.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if (isset($client)) {
        $client->disconnect();
    }
}