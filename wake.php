<?php

header('Content-Type: application/json; charset=UTF-8');

$configPath = __DIR__ . '/wol-config.json';

function normalizeMacAddress($macAddress) {
    return strtolower(str_replace('-', ':', trim((string)$macAddress)));
}

function formatSocketError($socket = null) {
    $code = $socket ? socket_last_error($socket) : socket_last_error();
    $message = socket_strerror($code);
    return $code > 0 ? "Socket error {$code}: {$message}" : $message;
}

function sendMagicPacket($macAddress, $broadcastIp = '255.255.255.255', $port = 9) {
    $normalizedMacAddress = normalizeMacAddress($macAddress);

    if (!isValidMacAddress($normalizedMacAddress)) {
        return [
            'success' => false,
            'message' => 'Invalid MAC address format'
        ];
    }

    $macBinary = pack('H*', str_replace(':', '', $normalizedMacAddress));
    $packet = str_repeat(chr(0xFF), 6) . str_repeat($macBinary, 16);

    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$sock) {
        return [
            'success' => false,
            'message' => 'Unable to create socket',
            'details' => formatSocketError()
        ];
    }

    if (!socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1)) {
        $details = formatSocketError($sock);
        socket_close($sock);
        return [
            'success' => false,
            'message' => 'Unable to set socket options',
            'details' => $details
        ];
    }

    $sent = socket_sendto($sock, $packet, strlen($packet), 0, $broadcastIp, $port);
    $details = $sent === false ? formatSocketError($sock) : null;
    socket_close($sock);

    if ($sent === false) {
        return [
            'success' => false,
            'message' => 'Packet send failed',
            'details' => $details,
            'target' => [
                'mac' => $normalizedMacAddress,
                'broadcast' => $broadcastIp,
                'port' => $port
            ]
        ];
    }

    return [
        'success' => true,
        'message' => "Magic packet successfully sent to {$normalizedMacAddress} from the server",
        'target' => [
            'mac' => $normalizedMacAddress,
            'broadcast' => $broadcastIp,
            'port' => $port,
            'bytes_sent' => $sent
        ]
    ];
}

function isValidMacAddress($macAddress) {
    return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $macAddress);
}

function isValidIpv4Address($address) {
    return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function findBroadcastAddressByMac($configPath, $macAddress) {
    if (!file_exists($configPath)) {
        return '255.255.255.255';
    }

    $content = file_get_contents($configPath);
    if ($content === false || trim($content) === '') {
        return '255.255.255.255';
    }

    $config = json_decode($content, true);
    if (!is_array($config) || !isset($config['devices']) || !is_array($config['devices'])) {
        return '255.255.255.255';
    }

    foreach ($config['devices'] as $device) {
        if (
            isset($device['mac']) &&
            normalizeMacAddress((string)$device['mac']) === normalizeMacAddress($macAddress)
        ) {
            $broadcast = $device['broadcast'] ?? '255.255.255.255';
            return isValidIpv4Address($broadcast) ? $broadcast : '255.255.255.255';
        }
    }

    return '255.255.255.255';
}

$macAddress = '';
$broadcastIp = '255.255.255.255';

if (isset($_POST['macSelect']) && $_POST['macSelect'] !== 'other') {
    $macAddress = normalizeMacAddress($_POST['macSelect']);
} elseif (isset($_POST['mac']) && isValidMacAddress($_POST['mac'])) {
    $macAddress = normalizeMacAddress($_POST['mac']);
}

if ($macAddress) {
    if (isset($_POST['broadcast']) && isValidIpv4Address($_POST['broadcast'])) {
        $broadcastIp = $_POST['broadcast'];
    } else {
        $broadcastIp = findBroadcastAddressByMac($configPath, $macAddress);
    }

    http_response_code(200);
    echo json_encode(sendMagicPacket($macAddress, $broadcastIp), JSON_UNESCAPED_SLASHES);
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'No valid MAC address provided'
    ], JSON_UNESCAPED_SLASHES);
}
