<?php
require_once __DIR__.'/../session.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../helpers.php';

$token = getAuvoToken();
if($token){
    $tokFile = json_decode(file_get_contents(__DIR__.'/../data/auvo_token.json'),true);
    logMessage('AUVO','Token Auvo válido até '.$tokFile['expiration'],'OK');
    sendJsonResponse([
        "ok"=>true,
        "tokenStatus"=>"valid",
        "expiration"=>$tokFile['expiration'] ?? null
    ]);
} else {
    logError('AUVO','Falha ao obter/renovar token Auvo');
    sendJsonResponse([
        "ok"=>false,
        "tokenStatus"=>"error",
        "message"=>"Falha ao renovar token Auvo"
    ]);
}
