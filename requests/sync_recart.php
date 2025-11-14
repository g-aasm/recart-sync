<?php
require_once __DIR__.'/../session.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../helpers.php';

// Sincronização Recart ↔ Auvo (placeholder v0.2)
//
// Ideia futura: cruzar dados do PrintWayy (contadores, suprimentos)
// com os equipamentos do Auvo e atualizar o Auvo via API.
// Aqui, por enquanto, só vamos logar o evento e responder OK
// para que o botão do painel já funcione.

$now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$ts  = $now->format('d/m/Y H:i:s');

// Loga início da sincronização manual
logMessage('SYNC', "Sincronização manual iniciada em $ts", 'INFO');

// Validação leve: conferir se já existem dados coletados
$missing = [];

$pathsToCheck = [
    'printwayy_printers.json'   => __DIR__ . '/../data/printwayy_printers.json',
    'auvo_clients.json'         => __DIR__ . '/../data/auvo_clients.json',
    'auvo_categories.json'      => __DIR__ . '/../data/auvo_categories.json',
    'auvo_equipments.json'      => __DIR__ . '/../data/auvo_equipments.json',
    // contadores/suprimentos são pesados e vêm via cron, então não
    // vamos travar a sincronização se faltarem, só avisar
    'printwayy_counters.json'   => __DIR__ . '/../data/printwayy_counters.json',
    'printwayy_supplies.json'   => __DIR__ . '/../data/printwayy_supplies.json',
];

foreach ($pathsToCheck as $label => $absPath) {
    if (!file_exists($absPath)) {
        $missing[] = $label;
    }
}

// Loga se está faltando base
if (!empty($missing)) {
    logError('SYNC', 'Sincronização parcial: arquivos ausentes: '.implode(', ', $missing));
    $statusMsg = 'Sincronização parcial: dados incompletos (placeholder).';
} else {
    logMessage('SYNC', 'Sincronização executada (placeholder, sem envio ao Auvo ainda)','OK');
    $statusMsg = 'Sincronização executada (placeholder).';
}

// Retorno para o painel
sendJsonResponse([
    "ok" => true,
    "message" => $statusMsg,
    "missing" => $missing
]);
