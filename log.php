<?php
date_default_timezone_set('America/Sao_Paulo');

function _timestampNow(){
    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    return $now->format('d/m/Y H:i:s');
}

function logMessage($origin, $message, $status='OK'){
    $line = "["._timestampNow()."]"."[".$origin."]"."[".$status."] ".$message.PHP_EOL;
    file_put_contents(__DIR__ . '/data/system.log', $line, FILE_APPEND);
    if ($status === 'ERROR'){
        file_put_contents(__DIR__ . '/data/error.log', $line, FILE_APPEND);
    }
}

function logError($origin,$message){
    logMessage($origin,$message,'ERROR');
}

function getSystemLog(){
    $p = __DIR__.'/data/system.log';
    if (!file_exists($p)) return [];
    $lines = file($p, FILE_IGNORE_NEW_LINES);
    return $lines ?: [];
}

function getErrorLog(){
    $p = __DIR__.'/data/error.log';
    if (!file_exists($p)) return [];
    $lines = file($p, FILE_IGNORE_NEW_LINES);
    return $lines ?: [];
}

function clearErrorLog(){
    file_put_contents(__DIR__.'/data/error.log','');
    logMessage('SYSTEM','Error log resetado manualmente pelo Admin','OK');
}
?>
