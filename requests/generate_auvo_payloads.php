<?php
declare(strict_types=1);

require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../log.php';
require_once __DIR__ . '/../cron/cron_build_sync_payloads.php';

@set_time_limit(240);
@ignore_user_abort(true);

header('Content-Type: application/json; charset=utf-8');

try {
    $res = build_auvo_payloads(); // ["new" => int, "update" => int]
    logMessage('SYNC-BUILD', 'Gerado via UI: new='.$res['new'].' update='.$res['update'], 'OK');

    echo json_encode([
        "ok"     => true,
        "new"    => (int)$res['new'],
        "update" => (int)$res['update'],
        "message"=> "Payloads gerados com sucesso"
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    logMessage('SYNC-BUILD', 'Erro via UI: '.$e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode([
        "ok"    => false,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
