<?php
// Coleta contadores de cada impressora PrintWayy
// v0.2 - agrupando contadores por impressora

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../log.php';

set_time_limit(0);

$now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$ts  = $now->format('d/m/Y H:i:s');

logMessage('PRINTWAYY', "Iniciando coleta de contadores (cron) em $ts", 'INFO');

// 1. Carrega impressoras previamente salvas
$printersFile = __DIR__ . '/../data/printwayy_printers.json';
if (!file_exists($printersFile)) {
    logError('PRINTWAYY', 'Arquivo printwayy_printers.json não encontrado (cron contadores)');
    updateStatus('last_counters_run', "FALHA $ts");
    exit;
}

// esse arquivo tem estrutura wrapWithMeta -> ["meta", "data"]
// e dentro de "data" normalmente tem ["count", "data"]
$printersJson = json_decode(file_get_contents($printersFile), true);
$printerList = $printersJson['data']['data'] ?? $printersJson['data'] ?? [];

if (!is_array($printerList) || count($printerList) === 0) {
    logError('PRINTWAYY', 'Nenhuma impressora no arquivo local (cron contadores)');
    updateStatus('last_counters_run', "FALHA $ts");
    exit;
}

// 2. Config PrintWayy
$apiBase = rtrim(getConfig('PRINTWAYY_URL', 'https://api.printwayy.com'), '/');
$apiKey  = getConfig('PRINTWAYY_TOKEN', '');

if (!$apiKey) {
    logError('PRINTWAYY','PRINTWAYY_TOKEN ausente em config/env (cron contadores)');
    updateStatus('last_counters_run', "FALHA $ts");
    exit;
}

// 3. Loop nas impressoras
$allCounters   = []; // cada item = { printerId: "...", counters: [ ... raw printwayy ... ] }
$successCount  = 0;
$errorCount    = 0;
$ignoredCount  = 0;

foreach ($printerList as $printer) {
    $printerId = $printer['id']   ?? null;
    $type      = strtolower($printer['type'] ?? '');

    if (!$printerId) {
        // sem ID, não tem como buscar contador
        continue;
    }

    // Ignorar type=unknown pra não desperdiçar requisição
    if ($type === 'unknown') {
        $ignoredCount++;
        continue;
    }

    $url = $apiBase . "/devices/v1/printers/" . urlencode($printerId) . "/counters";

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
        sleep(1);
        continue;
    }

    $jsonCounters = json_decode($response, true);

    // Garantir que o retorno é um array. A API PrintWayy pra /counters retorna
    // uma lista de objetos de contador (PB, colorido, scan, etc.)
    if (!is_array($jsonCounters)) {
        $errorCount++;
        logError('PRINTWAYY', "Resposta inválida contadores ID: $printerId");
        sleep(1);
        continue;
    }

    // >>> AQUI ENTRA A MUDANÇA IMPORTANTE <<<
    // Em vez de salvar cada contador separado, guardamos UM bloco por impressora,
    // e apenas adicionamos o printerId junto.
    $allCounters[] = [
        "printerId" => $printerId,
        "counters"  => $jsonCounters // bruto, sem alterar keys nem quebrar por tipo
    ];

    $successCount++;
    usleep(250000); // respeita API, reduz risco de bloqueio
}

// 4. Montar payload final
// Agora totalItems = número de impressoras processadas com sucesso
$totalItems = count($allCounters);

$payloadForFile = [
    "count" => $totalItems,
    "data"  => $allCounters
];

$wrapped = wrapWithMeta('printwayy_counters', $payloadForFile);

// 5. Salvar arquivo final
$filePath = __DIR__ . '/../data/printwayy_counters.json';
file_put_contents(
    $filePath,
    json_encode($wrapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// 6. Log e status
if ($totalItems > 0) {
    logMessage(
        'PRINTWAYY',
        "Contadores atualizados (cron): $totalItems impressoras -> /data/printwayy_counters.json | OK=$successCount FAIL=$errorCount IGN=$ignoredCount",
        'OK'
    );
    updateStatus('last_counters_run', $ts);
} else {
    logError(
        'PRINTWAYY',
        "Nenhum contador retornado (cron) | OK=$successCount FAIL=$errorCount IGN=$ignoredCount"
    );
    updateStatus('last_counters_run', "FALHA $ts");
}
