<?php
/**
 * cron/cron_auvo_upload_patch.php  (v0.2 – token fix)
 *
 * Atualiza equipamentos no Auvo consumindo out/auvo_patch_payload.json.
 * Formato esperado de cada item:
 *   { "id": 123456, "patch": [ { "path":"...", "value":... }, ... ] }
 *
 * - Envia 1 por vez (PATCH /v2/equipments/{id}).
 * - Rate limit ~3-4 req/s (sleep entre chamadas).
 * - Backoff em 403 (limite de taxa).
 * - Usa o mesmo token flow do seu projeto (tenta incluir cron_auvo_equipments.php).
 * - Se não achar a função de token, usa fallback com várias chaves .env.
 * - Logs por item e resumo final.
 */

declare(strict_types=1);
set_time_limit(0);


require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../log.php';

$JOB = 'CRON_AUVO_UPLOAD_PATCH';

// ---------------- Lock ----------------
$lockDir  = __DIR__ . '/../data/runtime/locks';
@mkdir($lockDir, 0775, true);
$lockFile = $lockDir . '/cron_auvo_upload_patch.lock';
$lock = fopen($lockFile, 'c+');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    logMessage($JOB, 'Já existe uma execução em andamento; abortando.', 'WARN');
    if (php_sapi_name() !== 'cli') { http_response_code(200); }
    exit;
}
/*
// ---------------- Util: env multi-key ----------------
function env_multi(array $keys, string $default = ''): string {
    foreach ($keys as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '') return $v;
        if (isset($GLOBALS['ENV'][$k]) && $GLOBALS['ENV'][$k] !== '') return (string)$GLOBALS['ENV'][$k];
    }
    return $default;
}

// ---------------- Token cache helpers ----------------
function auvoTokenCachePath(): string {
    return __DIR__ . '/../data/runtime/auvo_token.json';
}
function auvoTokenLoadCache(): ?array {
    $p = auvoTokenCachePath();
    if (!is_file($p)) return null;
    $j = json_decode((string)@file_get_contents($p), true);
    return is_array($j) ? $j : null;
}
function auvoTokenSaveCache(array $tok): void {
    @mkdir(dirname(auvoTokenCachePath()), 0775, true);
    @file_put_contents(auvoTokenCachePath(), json_encode($tok, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
*/
// ---------------- Resolver de Token (usa sua função se existir) ----------------
/**
 * Tenta usar a função de token do seu projeto (cron_auvo_equipments.php).
 * Nomes aceitos (qualquer um): auvoLoginOrRefresh, getAuvoAccessToken,
 * auvo_get_token, loginAuvo, auvoEnsureToken, get_auvo_token.
 * Se nada existir, faz fallback via /v2/login usando chaves .env.
 */

// ---------------- Carrega payload ----------------
$payloadPath = __DIR__ . '/../out/auvo_patch_payload.json';
if (!is_file($payloadPath)) {
    logError($JOB, "Arquivo não encontrado: {$payloadPath}");
    goto ENDJOB;
}
$raw = @file_get_contents($payloadPath);
$j = json_decode((string)$raw, true);
if ($j === null) {
    logError($JOB, 'JSON inválido em out/auvo_patch_payload.json');
    goto ENDJOB;
}
// aceita {meta,data:[...]} ou lista direta
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

// ---------------- Execução ----------------

$token = getAuvoToken();
if(!$token){
    logError('AUVO','Sem token Auvo válido (cron equipamentos)');
    updateStatus('last_auvo_equipments_run', "FALHA $ts");
    exit;
}
if (!$token) goto ENDJOB;

$base = 'https://api.auvo.com.br/v2/equipments/';
$success = 0; $fail = 0; $total = count($items);

// ~3–4 req/s
$delayMicro = 280000;

logMessage($JOB, "Iniciando PATCH de {$total} equipamento(s)...", 'OK');

foreach ($items as $idx => $it) {
    $id    = $it['id']    ?? null;
    $patch = $it['patch'] ?? null;

    if (!$id || !is_array($patch) || !$patch) {
        $fail++;
        logError($JOB, "Item inválido (sem id/patch) na posição {$idx}");
        continue;
    }

    // Auvo exige body como ARRAY JSON (ex.: [ {...}, {...} ])
    $body = json_encode($patch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $url  = $base . urlencode((string)$id);

    $try = 0;
RETRY:
    $try++;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
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
        // tenta renovar via resolveAuvoToken novamente (pode chamar o seu fluxo)
        $token = getAuvoToken();
        if ($token) { usleep(200000); goto RETRY; }
    }
    if ($code === 403 && $try < 6) {
        // rate limit — backoff progressivo
        $wait = 1000000 * min(5, $try); // 1..5s
        usleep($wait);
        goto RETRY;
    }

    if ($err || $code < 200 || $code >= 300) {
        $fail++;
        logError($JOB, "PATCH falhou [HTTP {$code}] id={$id} resp=" . substr((string)$resp,0,300));
    } else {
        $success++;
        //logMessage($JOB, "PATCH OK id={$id}", 'OK');
    }

    usleep($delayMicro);
}

logMessage($JOB, "Resumo PATCH: total={$total} ok={$success} erro={$fail}", ($fail>0?'WARN':'OK'));

ENDJOB:
if (isset($lock) && $lock) { flock($lock, LOCK_UN); fclose($lock); }
if (php_sapi_name() === 'cli') echo "[{$JOB}] done\n"; else http_response_code(200);