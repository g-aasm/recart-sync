<?php
require_once __DIR__.'/../session.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../helpers.php';

// 1. Garantir token válido do Auvo
$token = getAuvoToken();
if(!$token){
    logError('AUVO','Sem token Auvo válido para buscar equipamentos');
    sendJsonResponse(["ok"=>false,"error"=>"no valid token"]);
}

// 2. Configurações de paginação local
$apiBase     = rtrim(getConfig('AUVO_API_URL',''),'/');
$pageSize    = 100;
$order       = 0;
$page        = 1;
$paramFilter = '';
$maxPages    = 1000;

// 3. Aqui vamos guardar TODAS as páginas retornadas
$allPages = [];

// 4. Loop de paginação
while (true) {

    if ($page > $maxPages) {
        // trava de segurança pra não cair em loop infinito
        logError('AUVO','Loop de paginação muito grande em equipments (page > maxPages)');
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

    // Se deu erro de transporte HTTP/cURL
    if ($err || $httpcode !== 200) {
        logError('AUVO', "Erro ao buscar equipamentos página $page: HTTP $httpcode $err");
        break;
    }

    // Decodifica a página atual
    $json = json_decode($response, true);

    // Se a resposta não for um array válido, para
    if (!is_array($json)) {
        logError('AUVO', "Resposta inválida (não é JSON) na página $page");
        break;
    }

    // Pega a lista de equipamentos da página
    $listThisPage = [];
    if (
        isset($json['result']) &&
        isset($json['result']['entityList']) &&
        is_array($json['result']['entityList'])
    ) {
        $listThisPage = $json['result']['entityList'];
    }

    // Se essa página não trouxe nada, acabou a paginação
    if (count($listThisPage) === 0) {
        break;
    }

    // Guarda essa página no array mestre
    // IMPORTANTE: aqui a gente empilha, não sobrescreve
    $allPages[] = $listThisPage;

    // Se essa página trouxe menos que o limite, significa que acabou
    if (count($listThisPage) < $pageSize) {
        break;
    }

    // Próxima página
    $page++;
}

// 5. Agora consolidar TODAS as páginas em UMA lista só
$mergedEntityList = [];
foreach ($allPages as $pageList) {
    // $pageList deve ser um array de equipamentos
    if (is_array($pageList)) {
        $mergedEntityList = array_merge($mergedEntityList, $pageList);
    }
}

// 6. Contar total final
$totalItems = count($mergedEntityList);

// 7. Montar payload final padronizado
//    (esse é o corpo que vamos salvar no .json)
$payloadForFile = [
    "count" => $totalItems,
    "data"  => $mergedEntityList
];

// 8. Envelopar com metadados
$wrapped = wrapWithMeta('auvo_equipments', $payloadForFile);

// 9. Salvar em /data/auvo_equipments.json
$filePath = __DIR__ . '/../data/auvo_equipments.json';
file_put_contents(
    $filePath,
    json_encode($wrapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// 10. Atualizar logs e responder
if ($totalItems > 0) {

    // sucesso real
    logMessage(
        'AUVO',
        "Equipamentos atualizados ($totalItems itens) -> /data/auvo_equipments.json",
        'OK'
    );

    sendJsonResponse([
        "ok"    => true,
        "file"  => "auvo_equipments.json",
        "count" => $totalItems
    ]);

} else {

    // nenhum equipamento consolidado
    logError('AUVO', "Nenhum equipamento retornado do Auvo");

    sendJsonResponse([
        "ok"    => false,
        "error" => "Nenhum equipamento retornado"
    ]);
}
