<?php
// Coleta suprimentos atuais de cada impressora PrintWayy
// v0.2 - estrutura por impressora, armazenando retorno bruto da API

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../log.php';

set_time_limit(0);

$now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$ts  = $now->format('d/m/Y H:i:s');

logMessage('PRINTWAYY', "Iniciando coleta de suprimentos (cron) em $ts", 'INFO');

// 1. Carrega lista de impressoras previamente salvas
$printersFile = __DIR__ . '/../data/printwayy_printers.json';
if (!file_exists($printersFile)) {
    logError('PRINTWAYY', 'Arquivo printwayy_printers.json não encontrado (cron suprimentos)');
    updateStatus('last_supplies_run', "FALHA $ts");
    exit;
}

// Esse arquivo segue wrapWithMeta -> ["meta", "data"]
// e dentro de "data" normalmente tem ["count", "data"]
$printersJson = json_decode(file_get_contents($printersFile), true);
$printerList = $printersJson['data']['data'] ?? $printersJson['data'] ?? [];

if (!is_array($printerList) || count($printerList) === 0) {
    logError('PRINTWAYY', 'Nenhuma impressora no arquivo local (cron suprimentos)');
    updateStatus('last_supplies_run', "FALHA $ts");
    exit;
}

// 2. Configuração PrintWayy
$apiBase = rtrim(getConfig('PRINTWAYY_URL', 'https://api.printwayy.com'), '/');
$apiKey  = getConfig('PRINTWAYY_TOKEN', '');

if (!$apiKey) {
    logError('PRINTWAYY','PRINTWAYY_TOKEN ausente em config/env (cron suprimentos)');
    updateStatus('last_supplies_run', "FALHA $ts");
    exit;
}

// 3. Loop por impressora
// Estrutura alvo final:
// [
//   {
//      "printerId": "xxxx-xxxx",
//      "supplies": [ ...resposta bruta do PrintWayy para current-supplies... ]
//   },
//   ...
// ]

$allSupplies  = [];
$successCount = 0;
$errorCount   = 0;
$ignoredCount = 0;

foreach ($printerList as $printer) {

    $printerId = $printer['id']   ?? null;
    $type      = strtolower($printer['type'] ?? '');

    if (!$printerId) {
        continue;
    }

    // Evitar requisição inútil em impressora type=unknown
    if ($type === 'unknown') {
        $ignoredCount++;
        continue;
    }

    $url = $apiBase . "/devices/v1/printers/" . urlencode($printerId) . "/current-supplies";

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
        logError('PRINTWAYY', "Erro busca suprimentos ID: $printerId (HTTP $httpcode $err)");
        sleep(1);
        continue;
    }

    $jsonSupplies = json_decode($response, true);

    // Vamos aceitar qualquer formato de retorno contanto que seja array ou objeto,
    // mas vamos guardar como veio dentro de "supplies".
    // Se vier null/invalid, conta como erro dessa impressora.
    if ($jsonSupplies === null && json_last_error() !== JSON_ERROR_NONE) {
        $errorCount++;
        logError('PRINTWAYY', "Resposta inválida suprimentos ID: $printerId (JSON decode fail)");
        sleep(1);
        continue;
    }

    // Montar o bloco dessa impressora, mantendo o retorno bruto
    $allSupplies[] = [
        "printerId" => $printerId,
        "supplies"  => $jsonSupplies
    ];

    $successCount++;
    usleep(250000); // Respeita a API
}

// 4. Montar payload final
// Aqui totalItems = número de impressoras que tiveram suprimentos coletados com sucesso
$totalItems = count($allSupplies);

$payloadForFile = [
    "count" => $totalItems,
    "data"  => $allSupplies
];

$wrapped = wrapWithMeta('printwayy_supplies', $payloadForFile);

// 5. Salvar em /data/printwayy_supplies.json
$filePath = __DIR__ . '/../data/printwayy_supplies.json';
file_put_contents(
    $filePath,
    json_encode($wrapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// 6. Log final + status
if ($totalItems > 0) {
    logMessage(
        'PRINTWAYY',
        "Suprimentos atualizados (cron): $totalItems impressoras -> /data/printwayy_supplies.json | OK=$successCount FAIL=$errorCount IGN=$ignoredCount",
        'OK'
    );
    updateStatus('last_supplies_run', $ts);
} else {
    logError(
        'PRINTWAYY',
        "Nenhum suprimento retornado (cron) | OK=$successCount FAIL=$errorCount IGN=$ignoredCount"
    );
    updateStatus('last_supplies_run', "FALHA $ts");
}
