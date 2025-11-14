<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../log.php';

set_time_limit(0);

$now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$ts  = $now->format('d/m/Y H:i:s');

logMessage('AUVO', "Iniciando coleta de equipamentos (cron) em $ts", 'INFO');

$token = getAuvoToken();
if(!$token){
    logError('AUVO','Sem token Auvo válido (cron equipamentos)');
    updateStatus('last_auvo_equipments_run', "FALHA $ts");
    exit;
}

$apiBase     = rtrim(getConfig('AUVO_API_URL',''),'/');
$pageSize    = 100;
$order       = 0;
$page        = 1;
$paramFilter = '';
$maxPages    = 1000;

$allPages = [];

while (true) {
    if ($page > $maxPages) {
        logError('AUVO','Loop paginação muito grande em equipamentos (cron)');
        break;
    }

    $url = $apiBase
        . "/equipments/?paramFilter=" . urlencode($paramFilter)
        . "&page=" . $page
        . "&pageSize=" . $pageSize
        . "&order=" . $order;

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
            'Content-Type: application/json',
            'Authorization: Bearer '.$token
        ],
    ]);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $httpcode !== 200) {
        logError('AUVO', "Erro buscar equipamentos página $page: HTTP $httpcode $err");
        break;
    }

    $json = json_decode($response, true);

    $listThisPage = [];
    if (isset($json['result']['entityList']) && is_array($json['result']['entityList'])) {
        $listThisPage = $json['result']['entityList'];
    } elseif (isset($json['entityList']) && is_array($json['entityList'])) {
        $listThisPage = $json['entityList'];
    } elseif (isset($json[0])) {
        $listThisPage = $json;
    }

    if (count($listThisPage) === 0) {
        break;
    }

    $allPages[] = $listThisPage;

    if (count($listThisPage) < $pageSize) {
        break;
    }

    $page++;
}

$merged = [];
foreach ($allPages as $chunk) {
    $merged = array_merge($merged, $chunk);
}

$totalItems = count($merged);

$payloadForFile = [
    "count" => $totalItems,
    "data"  => $merged
];
$wrapped = wrapWithMeta('auvo_equipments', $payloadForFile);

$filePath = __DIR__ . '/../data/auvo_equipments.json';
file_put_contents(
    $filePath,
    json_encode($wrapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

if ($totalItems > 0) {
    logMessage('AUVO', "Equipamentos atualizados (cron): $totalItems itens -> /data/auvo_equipments.json", 'OK');
    updateStatus('last_auvo_equipments_run', $ts);
} else {
    logError('AUVO', "Nenhum equipamento retornado (cron)");
    updateStatus('last_auvo_equipments_run', "FALHA $ts");
}
