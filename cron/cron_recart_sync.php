<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../log.php';

set_time_limit(0);

$now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$ts  = $now->format('d/m/Y H:i:s');

logMessage('SYNC', "Sincronização automática iniciada (cron) em $ts", 'INFO');

// Futuro:
// - ler printwayy_*
// - ler auvo_*
// - casar dados
// - enviar atualização pro Auvo
// - logar cada ação/erro

logMessage('SYNC', "Sincronização automática concluída (placeholder)", 'OK');
updateStatus('last_sync_run', $ts);
