<?php
require_once __DIR__.'/../session.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../helpers.php';

// TODO: implementar lógica real de captura.
// Por enquanto devolve stub:
logMessage('PRINTWAYY', 'Suprimentos PrintWayy chamado (stub)','OK');
sendJsonResponse([
    "ok"=>false,
    "message"=>"not implemented yet"
]);
