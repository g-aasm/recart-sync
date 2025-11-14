<?php
// Coleta lista de impressoras PrintWayy (todas páginas)

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../log.php';

set_time_limit(0);

$now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$ts  = $now->format('d/m/Y H:i:s');

logMessage('PRINTWAYY', "Iniciando coleta de impressoras (cron) em $ts", 'INFO');

$apiBase = rtrim(getConfig('PRINTWAYY_URL', 'https://api.printwayy.com'), '/');
$apiKey  = getConfig('PRINTWAYY_TOKEN', '');

if (!$apiKey) {
    logError('PRINTWAYY', 'PRINTWAYY_TOKEN ausente em config/env');
    updateStatus('last_printers_run', "FALHA $ts");
    exit;
}

$top       = 100;
$skip      = 0;
$maxLoops  = 1000;
$loopCount = 0;

$all = [];

while (true) {
    if ($loopCount++ > $maxLoops) {
        logError('PRINTWAYY', 'Loop de paginação exagerado em printers (cron)');
        break;
    }

    $url = $apiBase . "/devices/v1/printers?top={$top}&skip={$skip}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'printwayy-key: '.$apiKey
        ],
    ]);

    $response  = curl_exec($ch);
    $err       = curl_error($ch);
    $httpcode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $httpcode !== 200) {
        logError('PRINTWAYY', "Erro ao buscar impressoras skip=$skip: HTTP $httpcode $err");
        break;
    }

    $json = json_decode($response, true);

    if (!isset($json['data']) || !is_array($json['data'])) {
        logError('PRINTWAYY', "Resposta inválida em printers skip=$skip");
        break;
    }

    if (count($json['data']) === 0) {
        break;
    }

    // acumula
    $all = array_merge($all, $json['data']);

    if (count($json['data']) < $top) {
        break;
    }

    $skip += $top;
}


//corrigindo falha printwayy para AMG sem CNPJ
// O ID do cliente que você quer encontrar
$targetCustomerId = "a68f9e2c-ebad-467a-af86-73619567e6d5";
// O novo valor de CNPJ a ser atribuído
$newCnpjValue = "11.224.676/0003-47";

// O ID do cliente que você quer encontrar
$targetCustomerId2 = "015256d7-b8be-411d-90f3-5293fc75f051";
// O novo valor de CNPJ a ser atribuído
$newCnpjValue2 = "20.313.664/0001-18";

foreach ($all as &$item) {
    // Verifique se 'customer' existe E se 'id' dentro de 'customer' existe/não é nulo.
    if (isset($item["customer"]["id"]) && $item["customer"]["id"] === $targetCustomerId) {
        $item["location"]["cnpj"] = $newCnpjValue;
    }
    // Verifique se 'customer' existe E se 'id' dentro de 'customer' existe/não é nulo.
    if (isset($item["customer"]["id"]) && $item["customer"]["id"] === $targetCustomerId2) {
        $item["location"]["cnpj"] = $newCnpjValue2;
    }
}

$totalItems = count($all);

$payloadForFile = [
    "count" => $totalItems,
    "data"  => $all
];

$wrapped = wrapWithMeta('printwayy_printers', $payloadForFile);

$filePath = __DIR__ . '/../data/printwayy_printers.json';
file_put_contents(
    $filePath,
    json_encode($wrapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

if ($totalItems > 0) {
    logMessage('PRINTWAYY', "Impressoras atualizadas (cron): $totalItems itens -> /data/printwayy_printers.json", 'OK');
    updateStatus('last_printers_run', $ts);
} else {
    logError('PRINTWAYY', "Nenhuma impressora retornada (cron)");
    updateStatus('last_printers_run', "FALHA $ts");
}
