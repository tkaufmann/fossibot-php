<?php

/**
 * ABOUTME: Simple MQTT over WebSocket client for PHP
 * ABOUTME: Handles connection, subscription, and message publishing to MQTT broker
 */
class MqttWebSocketClient {
    
    private $host;
    private $port;
    private $path;
    private $clientId;
    private $username;
    private $password;
    private $socket;
    private $connected = false;
    private $subscriptions = [];
    private $messageHandlers = [];
    
    public function __construct($host, $port = 8083, $path = '/mqtt') {
        $this->host = $host;
        $this->port = $port;
        $this->path = $path;
        $this->clientId = 'php_client_' . bin2hex(random_bytes(8));
    }
    
    public function setCredentials($username, $password) {
        $this->username = $username;
        $this->password = $password;
    }
    
    public function connect() {
        echo "Connecting to MQTT WebSocket: ws://{$this->host}:{$this->port}{$this->path}\n";
        
        // Create WebSocket connection
        $context = stream_context_create();
        $this->socket = stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if (!$this->socket) {
            throw new Exception("Failed to connect: $errstr ($errno)");
        }
        
        // WebSocket handshake
        $key = base64_encode(random_bytes(16));
        $headers = [
            "GET {$this->path} HTTP/1.1",
            "Host: {$this->host}:{$this->port}",
            "Upgrade: websocket",
            "Connection: Upgrade",
            "Sec-WebSocket-Key: {$key}",
            "Sec-WebSocket-Version: 13",
            "Sec-WebSocket-Protocol: mqtt"
        ];
        
        $request = implode("\r\n", $headers) . "\r\n\r\n";
        fwrite($this->socket, $request);
        
        // Read handshake response
        $response = '';
        while (($line = fgets($this->socket)) !== false) {
            $response .= $line;
            if (trim($line) === '') break;
        }
        
        if (strpos($response, '101 Switching Protocols') === false) {
            throw new Exception("WebSocket handshake failed: " . $response);
        }
        
        echo "WebSocket connected successfully\n";
        
        // Send MQTT CONNECT packet
        $this->sendMqttConnect();
        
        // Read CONNACK
        $this->readMqttResponse();
        
        $this->connected = true;
        echo "MQTT connected successfully\n";
        
        return true;
    }
    
    private function sendMqttConnect() {
        $protocolName = 'MQTT';
        $protocolLevel = 4;
        $connectFlags = 0x02; // Clean session
        
        if ($this->username) {
            $connectFlags |= 0x80; // Username flag
            if ($this->password) {
                $connectFlags |= 0x40; // Password flag
            }
        }
        
        $keepAlive = 60;
        
        // Build CONNECT packet
        $payload = '';
        $payload .= pack('n', strlen($this->clientId)) . $this->clientId;
        
        if ($this->username) {
            $payload .= pack('n', strlen($this->username)) . $this->username;
            if ($this->password) {
                $payload .= pack('n', strlen($this->password)) . $this->password;
            }
        }
        
        $variableHeader = '';
        $variableHeader .= pack('n', strlen($protocolName)) . $protocolName;
        $variableHeader .= pack('C', $protocolLevel);
        $variableHeader .= pack('C', $connectFlags);
        $variableHeader .= pack('n', $keepAlive);
        
        $packet = $variableHeader . $payload;
        $remainingLength = strlen($packet);
        
        // MQTT fixed header
        $fixedHeader = pack('C', 0x10); // CONNECT packet type
        $fixedHeader .= $this->encodeRemainingLength($remainingLength);
        
        $mqttPacket = $fixedHeader . $packet;
        
        $this->sendWebSocketFrame($mqttPacket, 0x2); // Binary frame
    }
    
    private function encodeRemainingLength($length) {
        $bytes = '';
        do {
            $byte = $length % 128;
            $length = intval($length / 128);
            if ($length > 0) {
                $byte |= 0x80;
            }
            $bytes .= pack('C', $byte);
        } while ($length > 0);
        
        return $bytes;
    }
    
    private function decodeRemainingLength($data, &$offset) {
        $multiplier = 1;
        $length = 0;
        
        do {
            if ($offset >= strlen($data)) {
                return false;
            }
            $byte = ord($data[$offset++]);
            $length += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) != 0);
        
        return $length;
    }
    
    private function sendWebSocketFrame($data, $opcode = 0x1) {
        $frame = '';
        $frame .= pack('C', 0x80 | $opcode); // FIN bit + opcode
        
        $dataLength = strlen($data);
        if ($dataLength < 126) {
            $frame .= pack('C', 0x80 | $dataLength); // MASK bit + length
        } elseif ($dataLength < 65536) {
            $frame .= pack('C', 0x80 | 126); // MASK bit + 126
            $frame .= pack('n', $dataLength);
        } else {
            $frame .= pack('C', 0x80 | 127); // MASK bit + 127
            $frame .= pack('N', 0) . pack('N', $dataLength);
        }
        
        // Generate mask key
        $maskKey = random_bytes(4);
        $frame .= $maskKey;
        
        // Mask the data
        for ($i = 0; $i < $dataLength; $i++) {
            $data[$i] = $data[$i] ^ $maskKey[$i % 4];
        }
        
        $frame .= $data;
        
        fwrite($this->socket, $frame);
    }
    
    private function readMqttResponse() {
        // Read WebSocket frame
        $frame = $this->readWebSocketFrame();
        if ($frame === false) {
            throw new Exception("Failed to read MQTT response");
        }
        
        // Parse MQTT packet
        if (strlen($frame) < 2) {
            throw new Exception("Invalid MQTT packet");
        }
        
        $packetType = (ord($frame[0]) >> 4) & 0x0F;
        
        if ($packetType == 2) { // CONNACK
            if (strlen($frame) >= 4) {
                $returnCode = ord($frame[3]);
                if ($returnCode == 0) {
                    return true; // Connection accepted
                } else {
                    throw new Exception("MQTT connection refused: code $returnCode");
                }
            }
        }
        
        throw new Exception("Unexpected MQTT response");
    }
    
    private function readWebSocketFrame() {
        $firstByte = fread($this->socket, 1);
        if ($firstByte === false || strlen($firstByte) == 0) {
            return false;
        }
        
        $secondByte = fread($this->socket, 1);
        if ($secondByte === false) {
            return false;
        }
        
        $payloadLength = ord($secondByte) & 0x7F;
        
        if ($payloadLength == 126) {
            $extendedLength = fread($this->socket, 2);
            $payloadLength = unpack('n', $extendedLength)[1];
        } elseif ($payloadLength == 127) {
            $extendedLength = fread($this->socket, 8);
            // For simplicity, we'll assume the length fits in 32 bits
            $payloadLength = unpack('N', substr($extendedLength, 4))[1];
        }
        
        $payload = '';
        if ($payloadLength > 0) {
            $payload = fread($this->socket, $payloadLength);
        }
        
        return $payload;
    }
    
    public function subscribe($topic) {
        if (!$this->connected) {
            throw new Exception("Not connected to MQTT broker");
        }
        
        $packetId = rand(1, 65535);
        $this->subscriptions[$packetId] = $topic;
        
        // Build SUBSCRIBE packet
        $payload = pack('n', $packetId); // Packet identifier
        $payload .= pack('n', strlen($topic)) . $topic; // Topic filter
        $payload .= pack('C', 0); // QoS level 0
        
        $remainingLength = strlen($payload);
        $fixedHeader = pack('C', 0x82); // SUBSCRIBE packet type with QoS 1
        $fixedHeader .= $this->encodeRemainingLength($remainingLength);
        
        $packet = $fixedHeader . $payload;
        $this->sendWebSocketFrame($packet, 0x2);
        
        echo "Subscribed to topic: $topic\n";
    }
    
    public function publish($topic, $message, $qos = 0) {
        if (!$this->connected) {
            throw new Exception("Not connected to MQTT broker");
        }
        
        // Convert message to bytes if it's an array
        if (is_array($message)) {
            $message = implode('', array_map('chr', $message));
        }
        
        // Build PUBLISH packet
        $variableHeader = pack('n', strlen($topic)) . $topic;
        
        if ($qos > 0) {
            $packetId = rand(1, 65535);
            $variableHeader .= pack('n', $packetId);
        }
        
        $payload = $message;
        $packet = $variableHeader . $payload;
        $remainingLength = strlen($packet);
        
        $flags = 0x30; // PUBLISH packet type
        if ($qos == 1) {
            $flags |= 0x02;
        }
        
        $fixedHeader = pack('C', $flags);
        $fixedHeader .= $this->encodeRemainingLength($remainingLength);
        
        $mqttPacket = $fixedHeader . $packet;
        $this->sendWebSocketFrame($mqttPacket, 0x2);
        
        echo "Published to topic: $topic (QoS $qos)\n";
    }
    
    public function onMessage($callback) {
        $this->messageHandlers[] = $callback;
    }
    
    public function loop($timeout = 30) {
        $endTime = time() + $timeout;
        
        while (time() < $endTime && $this->connected) {
            $read = [$this->socket];
            $write = null;
            $except = null;
            
            $ready = stream_select($read, $write, $except, 1);
            
            if ($ready > 0) {
                $frame = $this->readWebSocketFrame();
                if ($frame !== false) {
                    $this->handleMqttMessage($frame);
                }
            }
        }
    }
    
    private function handleMqttMessage($frame) {
        if (strlen($frame) < 2) return;
        
        $packetType = (ord($frame[0]) >> 4) & 0x0F;
        
        if ($packetType == 3) { // PUBLISH
            $offset = 1;
            $remainingLength = $this->decodeRemainingLength($frame, $offset);
            
            if ($remainingLength === false) return;
            
            // Parse topic
            if ($offset + 2 > strlen($frame)) return;
            $topicLength = unpack('n', substr($frame, $offset, 2))[1];
            $offset += 2;
            
            if ($offset + $topicLength > strlen($frame)) return;
            $topic = substr($frame, $offset, $topicLength);
            $offset += $topicLength;
            
            // Get payload
            $payload = substr($frame, $offset);
            
            // Call message handlers
            foreach ($this->messageHandlers as $handler) {
                call_user_func($handler, $topic, $payload);
            }
        }
    }
    
    public function disconnect() {
        if ($this->connected && $this->socket) {
            // Send DISCONNECT packet
            $packet = pack('C', 0xE0) . pack('C', 0); // DISCONNECT with 0 remaining length
            $this->sendWebSocketFrame($packet, 0x2);
            
            fclose($this->socket);
            $this->connected = false;
            echo "MQTT disconnected\n";
        }
    }
    
    public function isConnected() {
        return $this->connected;
    }
}