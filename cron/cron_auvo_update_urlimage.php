<?php
/**
 * cron/cron_auvo_update_urlimage.php
 *
 * Lê:
 *   - data/auvo_equipments.json
 *   - data/config/models_catalog.json
 *
 * Para cada equipamento:
 *   - Usa o campo "name" para buscar no catálogo (models_catalog.json)
 *   - Se houver "imageBase64", envia PATCH para /v2/equipments/{id} com path=urlImage
 *
 * Controle de taxa:
 *   - Limita a ~3 requisições/seg (usleep 350ms) para evitar 429.
 *
 * Autenticação:
 *   - Tenta reutilizar helpers existentes (se houver, p.ex. do seu cron_auvo_equipments.php)
 *   - Caso não exista, usa AUVO_API_KEY / AUVO_API_TOKEN do .env/config.php
 *
 * Log:
 *   - Escreve em logs/cron_auvo_update_urlimage.log
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

// Se você já tem essa verificação, pode manter:
$token = function_exists('getAuvoToken') ? getAuvoToken() : (getenv('AUVO_BEARER') ?: getenv('AUVO_API_TOKEN') ?: null);
if (!$token) {
    logError('AUVO', 'Sem token Auvo válido (cron equipamentos)');
    updateStatus('last_auvo_equipments_run', "FALHA " . date('d/m/Y H:i:s'));
    exit;
}

function cron_update_all_equipment_urlimage(): void
{
    $ROOT = dirname(__DIR__);
    $DATA_DIR = $ROOT . '/data';
    $CFG_DIR  = $ROOT . '/data/config';
    $LOG_DIR  = $ROOT . '/logs';
    if (!is_dir($LOG_DIR)) { @mkdir($LOG_DIR, 0775, true); }

    $logFile = $LOG_DIR . '/cron_auvo_update_urlimage.log';

    $ts = date('d/m/Y H:i:s');
    logx($logFile, "[$ts][RUN] Iniciando atualização de urlImage…");

    // Arquivos de origem
    $equipmentsJson = $DATA_DIR . '/auvo_equipments.json';
    $catalogJson    = $CFG_DIR  . '/models_catalog.json';

    if (!is_file($equipmentsJson)) {
        logx($logFile, "[ERROR] Arquivo ausente: $equipmentsJson");
        return;
    }
    if (!is_file($catalogJson)) {
        logx($logFile, "[ERROR] Arquivo ausente: $catalogJson");
        return;
    }

    $equipments = json_decode((string)file_get_contents($equipmentsJson), true);
    $catalog    = json_decode((string)file_get_contents($catalogJson), true);

    if (!is_array($equipments) || !isset($equipments['data']['data']) || !is_array($equipments['data']['data'])) {
        logx($logFile, "[ERROR] JSON inválido em $equipmentsJson");
        return;
    }
    if (!is_array($catalog)) {
        logx($logFile, "[ERROR] JSON inválido em $catalogJson");
        return;
    }

    // Contadores
    $total = 0;
    $matched = 0;
    $patched = 0;
    $skippedNoImage = 0;
    $skippedSame = 0;
    $errors = 0;

    $items = $equipments['data']['data'];
    $total = count($items);

    // Prepara Auth
    $headers = auvo_auth_headers();
    if (empty($headers)) {
        logx($logFile, "[AUVO_AUTH][ERROR] Cabeçalhos de autenticação vazios. Verifique .env/config.php");
        return;
    }

    // Loop principal
    foreach ($items as $eq) {
        $id   = $eq['id']   ?? null;
        $name = $eq['name'] ?? '';
        $curr = $eq['urlImage'] ?? '';

        if (!$id || !$name) {
            $errors++;
            logx($logFile, "[WARN] Equipamento sem id/name válido. id=" . json_encode($id) . " name=" . json_encode($name));
            continue;
        }

        if (!isset($catalog[$name]) || empty($catalog[$name]['imageBase64'])) {
            $skippedNoImage++;
            logx($logFile, "[SKIP] Sem imageBase64 no catálogo para modelo='{$name}' (id={$id})");
            continue;
        }

        $newBase64 = (string)$catalog[$name]['imageBase64'];
        $matched++;

        if ($curr === $newBase64) {
            $skippedSame++;
            logx($logFile, "[SKIP] Já está igual (urlImage) modelo='{$name}' id={$id}");
            continue;
        }

        $payload = json_encode([
            [
                "path"  => "base64Image",
                "value" => $newBase64
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $url = 'https://api.auvo.com.br/v2/equipments/' . rawurlencode((string)$id);

        [$ok, $httpCode, $resp] = http_patch($url, $headers, $payload);
        if ($ok && $httpCode >= 200 && $httpCode < 300) {
            $patched++;
            logx($logFile, "[OK][PATCH] id={$id} modelo='{$name}' -> urlImage atualizado.");
        } else {
            $errors++;
            logx($logFile, "[ERROR][PATCH] id={$id} modelo='{$name}' http={$httpCode} resp=" . substr($resp ?? '', 0, 500));
        }

        // Throttle: ~3 req/seg
        usleep(350_000);
    }

    $ts2 = date('d/m/Y H:i:s');
    logx($logFile, "[$ts2][DONE] Total={$total} | Matched={$matched} | Patched={$patched} | Skip(noImage)={$skippedNoImage} | Skip(igual)={$skippedSame} | Errors={$errors}");
}

/* ===================== Helpers ===================== */

function logx(string $file, string $line): void {
    @file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
}

/**
 * Tenta obter os headers de autenticação para o Auvo.
 * Estratégia:
 *  1) Se existir função reutilizável do seu projeto (ex: get_auvo_headers / auvo_auth_headers), usa.
 *  2) Caso contrário, lê variáveis do .env/config.php:
 *     - AUVO_API_KEY
 *     - AUVO_API_TOKEN
 *     - (Opcional) AUVO_BEARER pronto (se existir)
 */

// helpers/auvo_auth.php

/**
 * Monta os headers de autenticação para a API Auvo.
 * Ordem:
 *  - getAuvoToken() (se existir)
 *  - variáveis de ambiente (fallback)
 */
function auvo_auth_headers(): array
{
    // Inclui config.php antes para não ter problemas de escopo/defs
    $cfgFile = __DIR__ . '/../config.php';
    if (is_file($cfgFile)) {
        require_once $cfgFile;
    }

    $headers = ['Content-Type: application/json'];

    // 1) Preferir getAuvoToken(), se existir
    $bearer = null;
    if (function_exists('getAuvoToken')) {
        $bearer = getAuvoToken();   // deve retornar string "xxxxx" (sem "Bearer ")
    }

    // 2) Fallback via ambiente
    if (!$bearer) {
        $bearer = getenv('AUVO_BEARER') ?: getenv('AUVO_API_TOKEN') ?: '';
    }

    if (!$bearer) {
        // deixe o chamador decidir o que fazer; aqui só não monta Authorization
        // (se preferir, você pode lançar Exception)
        return $headers;
    }

    $headers[] = 'Authorization: Bearer ' . $bearer;

    // opcional: x-api-key se você usa em algum ambiente
    $apiKey = getenv('AUVO_API_KEY');
    if ($apiKey) {
        $headers[] = 'x-api-key: ' . $apiKey;
    }

    return $headers;
}


/**
 * Envia PATCH genérico
 * @return array [ok(bool), httpCode(int|null), body(string|null)]
 */
function http_patch(string $url, array $headers, string $body): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $ok = ($resp !== false && $http >= 200 && $http < 300);
    if ($resp === false) $resp = $err ?: null;

    return [$ok, $http ?: null, $resp];
}

/* Execução direta via CLI */
if (php_sapi_name() === 'cli') {
    cron_update_all_equipment_urlimage();
}
