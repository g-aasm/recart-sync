<?php
require_once __DIR__.'/../session.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../helpers.php';

// 1. Garantir token válido do Auvo
$token = getAuvoToken();
if(!$token){
    logError('AUVO','Sem token Auvo válido para buscar clientes');
    sendJsonResponse(["ok"=>false,"error"=>"Sem token Auvo válido"]);
}

// 2. Configurações da API e paginação
$apiBase     = rtrim(getConfig('AUVO_API_URL',''),'/');
$pageSize    = 100;
$order       = 0;
$page        = 1;
$paramFilter = '';
$maxPages    = 1000;

// 3. Array acumulador
$allPages = [];

// 4. Loop de paginação
while (true) {
    if ($page > $maxPages) {
        logError('AUVO','Loop de paginação muito grande em clientes (page > maxPages)');
        break;
    }

    $url = $apiBase
        . "/customers/?paramFilter=" . urlencode($paramFilter)
        . "&page=" . $page
        . "&pageSize=" . $pageSize
        . "&order=" . $order
        . "&selectfields=";

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

    // Erros HTTP ou de rede
    if ($err || $httpcode !== 200) {
        logError('AUVO', "Erro ao buscar clientes página $page: HTTP $httpcode $err");
        break;
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        logError('AUVO', "Resposta inválida (não é JSON) em clientes, página $page");
        break;
    }

    // Extrair lista de clientes
    $listThisPage = [];

    // Caso padrão do Auvo (result.entityList)
    if (isset($json['result']['entityList']) && is_array($json['result']['entityList'])) {
        $listThisPage = $json['result']['entityList'];
    }
    // Fallback se vier diferente
    elseif (isset($json['entityList']) && is_array($json['entityList'])) {
        $listThisPage = $json['entityList'];
    }
    elseif (isset($json[0])) {
        $listThisPage = $json;
    }

    // Se não veio nada, acabou
    if (count($listThisPage) === 0) {
        break;
    }

    $allPages[] = $listThisPage;

    // Se retornou menos que o limite, última página
    if (count($listThisPage) < $pageSize) {
        break;
    }

    $page++;
}

// 5. Consolidar todas as páginas
$mergedClientList = [];
foreach ($allPages as $pageList) {
    if (is_array($pageList)) {
        $mergedClientList = array_merge($mergedClientList, $pageList);
    }
}

// 6. Contagem final
$totalItems = count($mergedClientList);

// 7. Montar payload padronizado
$payloadForFile = [
    "count" => $totalItems,
    "data"  => $mergedClientList
];

// 8. Envelopar com meta
$wrapped = wrapWithMeta('auvo_clients', $payloadForFile);

// 9. Salvar arquivo JSON
$filePath = __DIR__ . '/../data/auvo_clients.json';
file_put_contents(
    $filePath,
    json_encode($wrapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// 10. Registrar log e retornar resultado
if ($totalItems > 0) {
    logMessage('AUVO', "Clientes atualizados ($totalItems itens) -> /data/auvo_clients.json", 'OK');
    sendJsonResponse([
        "ok"    => true,
        "file"  => "auvo_clients.json",
        "count" => $totalItems
    ]);
} else {
    logError('AUVO', "Nenhum cliente retornado do Auvo");
    sendJsonResponse([
        "ok"    => false,
        "error" => "Nenhum cliente retornado"
    ]);
}
