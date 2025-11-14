<?php
require_once __DIR__.'/../session.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../helpers.php';

// roda o ciclo completo
logMessage('SYSTEM','Ciclo completo de sincronização iniciado','OK');

$steps = [
    'printwayy_printers.php',
    'printwayy_counters.php',
    'printwayy_supplies.php',
    'auvo_clients.php',
    'auvo_categories.php',
    'auvo_equipments.php'
];

$hadError = false;
$totalItems = [];

foreach($steps as $step){
    ob_start();
    include __DIR__.'/'.$step;
    $respRaw = ob_get_clean();
    $resp = json_decode($respRaw,true);

    if (!$resp || !isset($resp['ok']) || $resp['ok']!==true){
        $hadError = true;
        logError('SYSTEM','Ciclo interrompido em '.$step);
        break;
    } else {
        $totalItems[$step] = $resp['count'] ?? null;
    }
}

// atualiza status.json
$errCount = count(getErrorLog());
updateStatus('lastErrorCount',$errCount);
$now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
updateStatus('lastFullSync',$now->format('d/m/Y H:i:s'));

if(!$hadError){
    logMessage('SYSTEM','Ciclo completo de sincronização finalizado','OK');
    sendJsonResponse([
        "ok"=>true,
        "finished"=>true,
        "totals"=>$totalItems
    ]);
} else {
    sendJsonResponse([
        "ok"=>false,
        "finished"=>false,
        "error"=>"Interrompido em alguma etapa"
    ]);
}
