<?php

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

$configPath = __DIR__ . '/wol-config.json';

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function isValidMacAddress($macAddress) {
    return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $macAddress) === 1;
}

function normalizeMacAddress($macAddress) {
    return strtolower(str_replace('-', ':', trim($macAddress)));
}

function normalizeBroadcastAddress($address) {
    return trim((string)$address);
}

function isValidIpv4Address($address) {
    return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function loadConfig($configPath) {
    if (!file_exists($configPath)) {
        return ['devices' => []];
    }

    $content = file_get_contents($configPath);
    if ($content === false || trim($content) === '') {
        return ['devices' => []];
    }

    $config = json_decode($content, true);
    if (!is_array($config)) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to parse configuration'
        ]);
    }

    if (!isset($config['devices']) || !is_array($config['devices'])) {
        $config['devices'] = [];
    }

    return $config;
}

function saveConfig($configPath, $config) {
    if (file_exists($configPath) && !is_writable($configPath)) {
        respond(500, [
            'success' => false,
            'message' => 'Configuration file is not writable'
        ]);
    }

    if (!file_exists($configPath) && !is_writable(dirname($configPath))) {
        respond(500, [
            'success' => false,
            'message' => 'Configuration directory is not writable'
        ]);
    }

    $openError = null;
    set_error_handler(function ($severity, $message) use (&$openError) {
        $openError = $message;
        return true;
    });
    $handle = fopen($configPath, 'c+');
    restore_error_handler();

    if ($handle === false) {
        respond(500, [
            'success' => false,
            'message' => $openError ? "Failed to open configuration file: $openError" : 'Failed to open configuration file'
        ]);
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        respond(500, [
            'success' => false,
            'message' => 'Failed to lock configuration file'
        ]);
    }

    ftruncate($handle, 0);
    rewind($handle);

    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || fwrite($handle, $json . PHP_EOL) === false) {
        flock($handle, LOCK_UN);
        fclose($handle);
        respond(500, [
            'success' => false,
            'message' => 'Failed to write configuration file'
        ]);
    }

    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    respond(200, [
        'success' => true,
        'devices' => loadConfig($configPath)['devices']
    ]);
}

$rawInput = file_get_contents('php://input');
$input = [];

if ($method === 'POST' && isset($_POST['action'])) {
    $input = $_POST;
} else {
    $input = json_decode($rawInput ?: '{}', true);
}

if (!is_array($input)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid request body'
    ]);
}

$config = loadConfig($configPath);
$devices = $config['devices'];

if ($method === 'POST' && ($input['action'] ?? 'add') === 'add') {
    $name = trim((string)($input['name'] ?? ''));
    $mac = normalizeMacAddress((string)($input['mac'] ?? ''));
    $broadcast = normalizeBroadcastAddress($input['broadcast'] ?? '255.255.255.255');

    if ($name === '' || !isValidMacAddress($mac) || !isValidIpv4Address($broadcast)) {
        respond(400, [
            'success' => false,
            'message' => 'Name, valid MAC address, and valid broadcast address are required'
        ]);
    }

    foreach ($devices as $device) {
        if (normalizeMacAddress((string)$device['mac']) === $mac) {
            respond(409, [
                'success' => false,
                'message' => 'That MAC address is already registered'
            ]);
        }
    }

    $devices[] = [
        'name' => $name,
        'mac' => $mac,
        'broadcast' => $broadcast
    ];

    $config['devices'] = array_values($devices);
    saveConfig($configPath, $config);

    respond(201, [
        'success' => true,
        'message' => 'Device added',
        'devices' => $config['devices']
    ]);
}

if (
    ($method === 'POST' && ($input['action'] ?? '') === 'delete') ||
    $method === 'DELETE'
) {
    $mac = normalizeMacAddress((string)($input['mac'] ?? ''));

    if (!isValidMacAddress($mac)) {
        respond(400, [
            'success' => false,
            'message' => 'Valid MAC address is required'
        ]);
    }

    $filtered = array_values(array_filter($devices, function ($device) use ($mac) {
        return normalizeMacAddress((string)$device['mac']) !== $mac;
    }));

    if (count($filtered) === count($devices)) {
        respond(404, [
            'success' => false,
            'message' => 'Device not found'
        ]);
    }

    $config['devices'] = $filtered;
    saveConfig($configPath, $config);

    respond(200, [
        'success' => true,
        'message' => 'Device deleted',
        'devices' => $config['devices']
    ]);
}

respond(405, [
    'success' => false,
    'message' => 'Method not allowed'
]);
