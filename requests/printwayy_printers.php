<?php
require_once __DIR__.'/../session.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../helpers.php';

$apiBase = rtrim(getConfig('PRINTWAYY_API_URL',''),'/');
$pwKey = getConfig('PRINTWAYY_TOKEN','');

$top = 100;
$skip = 0;
$maxLoops = 1000;
$all = [];

for($loop=0;$loop<$maxLoops;$loop++){
    
    $url = $apiBase . "/devices/v1/printers?top=".$top."&skip=".$skip;

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
            'printwayy-key: '.$pwKey
        ],
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($err || $httpcode !== 200){
        logError('PRINTWAYY',"Erro ao buscar impressoras skip=$skip: HTTP $httpcode $err");
        break;
    }

    $json = json_decode($response,true);
    if(!is_array($json["data"]) || count($json["data"])===0){
        // acabou
        break;
    }

    $all = array_merge($all,$json["data"]);
    
    if(count($json["data"]) < $top){
        // última página
        break;
    }

    $skip += $top;
}

// salvar
$filePath = __DIR__.'/../data/printwayy_printers.json';

// Aqui calculamos o total REAL de impressoras coletadas
$totalItems = 0;

// Caso 1: você montou $all como lista plana [ {...}, {...} ]
if (is_array($all) && isset($all[0])) {
    $totalItems = count($all);

// Caso 2: você montou $all como ["count" => 427, "data" => [ ... ]]
} elseif (is_array($all) && isset($all['count']) && isset($all['data'])) {
    $totalItems = (int)$all['count'];
} else {
    $totalItems = 0;
}

// Agora passamos esse formato padronizado pra salvar
$wrapped = wrapWithMeta('printwayy_printers', [
    "count" => $totalItems,
    "data"  => (isset($all['data']) ? $all['data'] : $all)
]);

file_put_contents(
    $filePath,
    json_encode($wrapped, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
);

// LOG E RESPOSTA AGORA USAM O totalItems
if ($totalItems > 0){
    logMessage(
        'PRINTWAYY',
        "Impressoras atualizadas ($totalItems itens) -> /data/printwayy_printers.json",
        'OK'
    );

    sendJsonResponse([
        "ok"    => true,
        "file"  => "printwayy_printers.json",
        "count" => $totalItems
    ]);
} else {
    logError('PRINTWAYY',"Nenhuma impressora retornada do PrintWayy");
    sendJsonResponse([
        "ok"=>false,
        "error"=>"Nenhuma impressora retornada"
    ]);
}

