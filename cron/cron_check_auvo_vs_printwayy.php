<?php
/**
 * Verifica equipamentos que estão no Auvo mas não existem no PrintWayy,
 * loga como ERROR e gera out/auvo_orphans.json para revisão.
 *
 * Uso CLI:
 *   php cron/cron_check_auvo_vs_printwayy.php
 */

declare(strict_types=1);
@set_time_limit(0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../log.php';
require_once __DIR__ . '/../helpers.php';

function check_auvo_vs_printwayy(): array
{
    date_default_timezone_set('America/Sao_Paulo');

    $base   = __DIR__ . '/..';
    $dataDir = $base . '/data';
    $outDir  = $base . '/out';
    if (!is_dir($outDir)) mkdir($outDir, 0775, true);

    // helpers locais
    $readJson = function(string $path, $default = null) {
        if (!file_exists($path)) return $default;
        $raw = file_get_contents($path);
        $j = json_decode($raw, true);
        return is_array($j) ? $j : $default;
    };
    $norm_serial = fn($s) => strtoupper(preg_replace('/\s+/', '', trim((string)$s)));

    // --- Carregar Auvo ---
    $auvoRaw  = $readJson("$dataDir/auvo_equipments.json", []);
    $auvoList = $auvoRaw['data']['data'] ?? $auvoRaw['data'] ?? $auvoRaw ?? [];
    if (!is_array($auvoList)) $auvoList = [];

    // --- Carregar PrintWayy ---
    $pwRaw   = $readJson("$dataDir/printwayy_printers.json", []);
    $pwList  = $pwRaw['data']['data'] ?? $pwRaw['data'] ?? $pwRaw ?? [];
    if (!is_array($pwList)) $pwList = [];

    // Set de seriais do PrintWayy
    $pwSerials = [];
    foreach ($pwList as $p) {
        $sn = $norm_serial($p['serialNumber'] ?? '');
        if ($sn !== '') $pwSerials[$sn] = true;
    }

    $checked = 0;
    $orphans = [];

    foreach ($auvoList as $e) {
        $checked++;
        $idAuvo  = $e['id'] ?? null;
        $name    = trim((string)($e['name'] ?? ''));
        $serial  = $norm_serial($e['identifier'] ?? '');
        if ($serial === '') continue; // sem serial não dá pra comparar

        if (!isset($pwSerials[$serial])) {
            // órfão no Auvo (não encontrado no PrintWayy)
            $associatedCustomerId = $e['associatedCustomerId'] ?? null;
            $categoryId           = $e['categoryId'] ?? null;

            logMessage(
                'SYNC-CHECK',
                "Equipamento **no Auvo** sem correspondente **no PrintWayy**"
                ." | idAuvo=$idAuvo | serial=$serial | name=\"$name\""
                ." | categoryId=$categoryId | associatedCustomerId=$associatedCustomerId",
                'ERROR'
            );

            $orphans[] = [
                "id"                   => $idAuvo,
                "name"                 => $name,
                "identifier"           => $serial,
                "categoryId"           => $categoryId,
                "associatedCustomerId" => $associatedCustomerId,
            ];
        }
    }

    // Salva lista para auditoria
    file_put_contents(
        "$outDir/auvo_orphans.json",
        json_encode([
            "meta" => [
                "source"      => "auvo_vs_printwayy_orphans",
                "generatedAt" => date('d/m/Y H:i:s'),
                "checked"     => $checked,
                "orphans"     => count($orphans)
            ],
            "data" => $orphans
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
    );

    // resumo
    $msg = "Comparação Auvo→PrintWayy finalizada: checados=$checked, órfãos=".count($orphans)
         .", arquivo out/auvo_orphans.json";
    logMessage('SYNC-CHECK', $msg, 'OK');

    return ["checked"=>$checked, "orphans"=>count($orphans)];
}

// Execução direta via CLI
if (php_sapi_name() === 'cli') {
    $r = check_auvo_vs_printwayy();
    echo "[SYNC-CHECK] checked={$r['checked']} orphans={$r['orphans']}\n";
}