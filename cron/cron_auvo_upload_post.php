<?php
/**
 * cron/cron_auvo_upload_post.php  (v0.2)
 * 
 * Cria equipamentos no Auvo consumindo out/auvo_post_payload.json.
 * - Envia 1 por vez (POST /v2/equipments).
 * - Rate limit ~3-4 req/s (sleep entre chamadas).
 * - Backoff em 403 (limite de taxa).
 * - Renova token em 401/expiração.
 * - Logs por item e resumo final.
 */

declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../log.php';

$JOB = 'CRON_AUVO_UPLOAD_POST';

// ---------- Lock (evita concorrência) ----------
$lockDir  = __DIR__ . '/../data/runtime/locks';
@mkdir($lockDir, 0775, true);
$lockFile = $lockDir . '/cron_auvo_upload_post.lock';
$lock = fopen($lockFile, 'c+');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    logMessage($JOB, 'Já existe uma execução em andamento; abortando.', 'WARN');
    if (php_sapi_name() !== 'cli') { http_response_code(200); }
    exit;
}



// ---------- Carrega payload ----------
$payloadPath = __DIR__ . '/../out/auvo_post_payload.json';
if (!is_file($payloadPath)) {
    logError($JOB, "Arquivo não encontrado: {$payloadPath}");
    goto ENDJOB;
}

$raw = @file_get_contents($payloadPath);
$j = json_decode((string)$raw, true);
if ($j === null) {
    logError($JOB, 'JSON inválido em out/auvo_post_payload.json');
    goto ENDJOB;
}
// aceita dois formatos: lista direta ou {meta, data:[]}
$items = [];
if (isset($j['data']) && is_array($j['data'])) {
    $items = $j['data'];
} elseif (is_array($j)) {
    $items = $j;
}
if (!$items) {
    logMessage($JOB, 'Nada a enviar (lista vazia).', 'OK');
    goto ENDJOB;
}

// ---------- Execução ----------
$token = getAuvoToken();
if(!$token){
    logError('AUVO','Sem token Auvo válido (cron equipamentos)');
    updateStatus('last_auvo_equipments_run', "FALHA $ts");
    exit;
}
if (!$token) goto ENDJOB;

$endpoint = 'https://api.auvo.com.br/v2/equipments/';
$success = 0; $fail = 0; $total = count($items);

// rate-limit simples: 250–300ms por chamada (~3-4/s)
$delayMicro = 280000;

logMessage($JOB, "Iniciando POST de {$total} equipamento(s)...", 'OK');

foreach ($items as $idx => $equip) {
    // saneia: precisa ser objeto com campos do Auvo
    if (!is_array($equip)) { $fail++; continue; }

    $body = json_encode($equip, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $try = 0;
RETRY:
    $try++;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 401 && $try < 3) {
        // token expirou — renova e tenta de novo
        $token = getAuvoToken();
        if ($token) { usleep(200000); goto RETRY; }
    }
    if ($code === 403 && $try < 6) {
        // limite de taxa — backoff progressivo
        $wait = 1000000 * min(5, $try); // 1s..5s
        usleep($wait);
        goto RETRY;
    }

    if ($err || $code < 200 || $code >= 300) {
        $fail++;
        $sig = $equip['identifier'] ?? ($equip['name'] ?? 'sem_identificador');
        logError($JOB, "POST falhou [HTTP {$code}] ident={$sig} resp=" . substr((string)$resp,0,300));
    } else {
        $success++;
        $sig = $equip['identifier'] ?? ($equip['name'] ?? 'sem_identificador');
        logMessage($JOB, "POST OK ident={$sig}", 'OK');
    }

    usleep($delayMicro);
}

logMessage($JOB, "Resumo POST: total={$total} ok={$success} erro={$fail}", ($fail>0?'WARN':'OK'));

ENDJOB:
if (isset($lock) && $lock) { flock($lock, LOCK_UN); fclose($lock); }
if (php_sapi_name() === 'cli') echo "[{$JOB}] done\n"; else http_response_code(200);
