<?php
require_once __DIR__.'/../session.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../helpers.php';

// 1. Carregar lista de impressoras salvas
$printersFile = __DIR__ . '/../data/printwayy_printers.json';
if (!file_exists($printersFile)) {
    logError('PRINTWAYY', 'Arquivo printwayy_printers.json não encontrado. Rode primeiro a captura de impressoras.');
    sendJsonResponse(["ok" => false, "error" => "Arquivo printwayy_printers.json não encontrado"]);
}

$printersJson = json_decode(file_get_contents($printersFile), true);
$printerList = $printersJson['data']['data'] ?? $printersJson['data'] ?? [];

if (!is_array($printerList) || count($printerList) === 0) {
    logError('PRINTWAYY', 'Nenhuma impressora encontrada no arquivo printwayy_printers.json');
    sendJsonResponse(["ok" => false, "error" => "Nenhuma impressora encontrada"]);
}

// 2. Configuração da API PrintWayy
$apiBase = rtrim(getConfig('PRINTWAYY_URL', 'https://api.printwayy.com'), '/');
$apiKey  = getConfig('PRINTWAYY_TOKEN', '');

if (!$apiKey) {
    logError('PRINTWAYY', 'PRINTWAYY_TOKEN ausente em config/env');
    sendJsonResponse(["ok" => false, "error" => "PRINTWAYY_TOKEN ausente"]);
}

// 3. Preparar variáveis de controle
$allCounters  = [];
$successCount = 0;
$errorCount   = 0;
$ignoredCount = 0;

// 4. Percorrer todas as impressoras
foreach ($printerList as $printer) {

    $printerId = $printer['id'] ?? null;
    $type      = strtolower($printer['type'] ?? '');

    // ignora impressoras sem ID
    if (!$printerId) {
        continue;
    }


    // ignora as do tipo "unknown"
    if ($type === 'unknown') {
        $ignoredCount++;
        continue;
    }

    // monta URL da requisição de contadores
    $url = $apiBase . "/devices/v1/printers/" . urlencode($printerId) . "/counters";

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
            'accept: application/json',
            'printwayy-key: ' . $apiKey
        ],
    ]);

    $response  = curl_exec($ch);
    $err       = curl_error($ch);
    $httpcode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $httpcode !== 200) {
        $errorCount++;
        logError('PRINTWAYY', "Erro busca contadores ID: $printerId (HTTP $httpcode $err)");
        continue;
    }

    $jsonCounters = json_decode($response, true);

    if (!is_array($jsonCounters)) {
        $errorCount++;
        logError('PRINTWAYY', "Erro busca contadores ID: $printerId (resposta inválida)");
        continue;
    }

    // Cada item pode ser diferente conforme o modelo, então mantemos a estrutura original
    // e apenas inserimos o printerId
    foreach ($jsonCounters as $c) {
        if (!is_array($c)) continue;

        $c['printerId'] = $printerId;
        $allCounters[]  = $c;
    }

    $successCount++;
}

// 5. Montar payload final
$totalItems = count($allCounters);
$payloadForFile = [
    "count" => $totalItems,
    "data"  => $allCounters
];

// 6. Envelopar com metadados
$wrapped = wrapWithMeta('printwayy_counters', $payloadForFile);

// 7. Salvar arquivo
$filePath = __DIR__ . '/../data/printwayy_counters.json';
file_put_contents(
    $filePath,
    json_encode($wrapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// 8. Log e resposta final
if ($totalItems > 0) {
    logMessage(
        'PRINTWAYY',
        "Contadores atualizados ($totalItems itens) -> /data/printwayy_counters.json",
        'OK'
    );
    sendJsonResponse([
        "ok"            => true,
        "file"          => "printwayy_counters.json",
        "count"         => $totalItems,
        "printers_ok"   => $successCount,
        "printers_fail" => $errorCount,
        "printers_skip" => $ignoredCount
    ]);
} else {
    logError('PRINTWAYY', "Nenhum contador retornado do PrintWayy (falhas: $errorCount / ignoradas: $ignoredCount)");
    sendJsonResponse([
        "ok"            => false,
        "error"         => "Nenhum contador retornado",
        "printers_ok"   => $successCount,
        "printers_fail" => $errorCount,
        "printers_skip" => $ignoredCount
    ]);
}
