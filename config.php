<?php
date_default_timezone_set('America/Sao_Paulo');

function loadEnv() {
    static $CONFIG = null;
    if ($CONFIG !== null) return $CONFIG;

    $CONFIG = [];
    $envPath = __DIR__ . '/.env';
    if (!file_exists($envPath)) {
        return $CONFIG;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (!str_contains($line, '=')) continue;
        list($key, $val) = explode('=', $line, 2);
        $CONFIG[trim($key)] = trim($val);
    }

    // runtime_settings.json overrides some env values (like SYNC_INTERVAL_MINUTES)
    $runtimePath = __DIR__ . '/data/runtime_settings.json';
    if (file_exists($runtimePath)) {
        $runtime = json_decode(file_get_contents($runtimePath), true);
        if (is_array($runtime)) {
            foreach ($runtime as $k=>$v) {
                $CONFIG[$k] = $v;
            }
        }
    }

    return $CONFIG;
}

function getConfig($key, $default=null) {
    $conf = loadEnv();
    return $conf[$key] ?? $default;
}

// ---- A U V O   T O K E N  -------------------------------------------------

function getTokenFilePath() {
    return __DIR__ . '/data/auvo_token.json';
}

// returns array ["accessToken"=>..., "expiration"=>...] or null
function readAuvoTokenFile() {
    $path = getTokenFilePath();
    if (!file_exists($path)) return null;
    $raw = file_get_contents($path);
    if (!$raw) return null;
    $json = json_decode($raw, true);
    if (!is_array($json)) return null;
    if (!isset($json['accessToken']) || !isset($json['expiration'])) return null;
    return $json;
}

function saveAuvoToken($token, $expiration) {
    $payload = [
        "accessToken" => $token,
        "expiration" => $expiration
    ];
    file_put_contents(getTokenFilePath(), json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    return $payload;
}

// try to renew token by calling Auvo login endpoint
function refreshAuvoToken() {
    $apiBase = rtrim(getConfig('AUVO_API_URL',''),'/');
    $apiKey = getConfig('AUVO_API_KEY','');
    $apiToken = getConfig('AUVO_API_TOKEN','');

    if (!$apiBase || !$apiKey || !$apiToken) {
        return null;
    }

    $url = $apiBase . "/login/?apiKey=" . urlencode($apiKey) . "&apiToken=" . urlencode($apiToken);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $httpcode !== 200) {
        return null;
    }

    $json = json_decode($response, true);
    if (!isset($json['result']) || !isset($json['result']['authenticated']) || !$json['result']['authenticated']) {
        return null;
    }

    $accessToken = $json['result']['accessToken'] ?? null;
    $expiration  = $json['result']['expiration'] ?? null;

    if (!$accessToken || !$expiration) {
        return null;
    }

    saveAuvoToken($accessToken, $expiration);
    return [
        "accessToken" => $accessToken,
        "expiration" => $expiration
    ];
}

// returns valid accessToken string or null
function getAuvoToken() {
    $tok = readAuvoTokenFile();

    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    if ($tok) {
        $exp = DateTime::createFromFormat('Y-m-d H:i:s', $tok['expiration'], new DateTimeZone('America/Sao_Paulo'));
        if ($exp && $now < $exp) {
            // token still valid
            return $tok['accessToken'];
        }
    }

    // need refresh
    $newTok = refreshAuvoToken();
    if ($newTok) {
        return $newTok['accessToken'];
    }

    return null;
}

// status.json helpers
function getStatusFilePath(){
    return __DIR__ . '/data/status.json';
}
function getStatus(){
    $p = getStatusFilePath();
    if (!file_exists($p)) {
        $default = [
            "autoSyncEnabled" => false,
            "lastFullSync" => null,
            "lastErrorCount" => 0,
            "syncIntervalMinutes" => (int) getConfig('SYNC_INTERVAL_MINUTES',15)
        ];
        file_put_contents($p, json_encode($default, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        return $default;
    }
    $raw = file_get_contents($p);
    $json = json_decode($raw,true);
    if (!is_array($json)) $json=[];
    $json += [
        "autoSyncEnabled" => false,
        "lastFullSync" => null,
        "lastErrorCount" => 0,
        "syncIntervalMinutes" => (int) getConfig('SYNC_INTERVAL_MINUTES',15)
    ];
    return $json;
}
/*
function updateStatus($key,$value){
    $st = getStatus();
    $st[$key]=$value;
    file_put_contents(getStatusFilePath(), json_encode($st, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    return $st;
}
    */
?>
