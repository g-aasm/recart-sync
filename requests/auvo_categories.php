<?php
require_once __DIR__.'/../session.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../helpers.php';

// 1. Garantir token válido do Auvo
$token = getAuvoToken();
if(!$token){
    logError('AUVO','Sem token Auvo válido para buscar categorias');
    sendJsonResponse(["ok"=>false,"error"=>"no valid token"]);
}

// 2. Configurações de paginação
$apiBase     = rtrim(getConfig('AUVO_API_URL',''),'/');
$pageSize    = 100;
$order       = 0;
$page        = 1;
$paramFilter = '';
$maxPages    = 1000;

// 3. Array pra guardar TODAS as páginas de categorias
$allPages = [];

// 4. Loop de paginação
while (true) {

    if ($page > $maxPages) {
        // trava de segurança em caso de loop infinito
        logError('AUVO','Loop de paginação muito grande em categories (page > maxPages)');
        break;
    }

    $url = $apiBase
        . "/equipmentCategories/?paramFilter=" . urlencode($paramFilter)
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

    // Se deu erro HTTP/cURL
    if ($err || $httpcode !== 200) {
        logError('AUVO', "Erro ao buscar categorias página $page: HTTP $httpcode $err");
        break;
    }

    $json = json_decode($response, true);

    // resposta inválida?
    if (!is_array($json)) {
        logError('AUVO', "Resposta inválida (não é JSON) em categorias, página $page");
        break;
    }

    // lista desta página
    $listThisPage = [];

    // Se o Auvo devolver naquele padrão result.entityList:
    if (
        isset($json['result']) &&
        isset($json['result']['entityList']) &&
        is_array($json['result']['entityList'])
    ) {
        $listThisPage = $json['result']['entityList'];
    }
    // Se no seu ambiente, equipmentCategories já retorna um array simples
    // (sem result.entityList), você pode ativar esse fallback:
    elseif (isset($json['entityList']) && is_array($json['entityList'])) {
        $listThisPage = $json['entityList'];
    }
    // Se for um array raiz direto:
    elseif (isset($json[0])) {
        $listThisPage = $json;
    }

    // Se essa página não trouxe nada, acabou
    if (count($listThisPage) === 0) {
        break;
    }

    // guarda a lista dessa página
    $allPages[] = $listThisPage;

    // Se retornou menos que o pageSize, última página
    if (count($listThisPage) < $pageSize) {
        break;
    }

    $page++;
}

// 5. Consolidar todas as páginas
$mergedCategoryList = [];
foreach ($allPages as $pageList) {
    if (is_array($pageList)) {
        $mergedCategoryList = array_merge($mergedCategoryList, $pageList);
    }
}

// 6. Contagem final
$totalItems = count($mergedCategoryList);

// 7. Montar payload final padronizado
$payloadForFile = [
    "count" => $totalItems,
    "data"  => $mergedCategoryList
];

// 8. Envelopar com meta
$wrapped = wrapWithMeta('auvo_categories', $payloadForFile);

// 9. Salvar arquivo
$filePath = __DIR__ . '/../data/auvo_categories.json';
file_put_contents(
    $filePath,
    json_encode($wrapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// 10. Resposta e log
if ($totalItems > 0) {

    logMessage(
        'AUVO',
        "Categorias atualizadas ($totalItems itens) -> /data/auvo_categories.json",
        'OK'
    );

    sendJsonResponse([
        "ok"    => true,
        "file"  => "auvo_categories.json",
        "count" => $totalItems
    ]);

} else {

    logError('AUVO', "Nenhuma categoria retornada do Auvo");

    sendJsonResponse([
        "ok"    => false,
        "error" => "Nenhuma categoria retornada"
    ]);

}
