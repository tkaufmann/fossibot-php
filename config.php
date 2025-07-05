<?php

// Configuration file for Fossibot PHP POC
// Copy this to config.local.php and enter your credentials

return [
    'username' => 'your-email@example.com',
    'password' => 'your-password',
    
    // Optional: Device ID if you know it
    'device_id' => null,
    
    // Bridge settings (Node.js server must be running)
    'bridge_host' => 'localhost',
    'bridge_port' => 3000
];