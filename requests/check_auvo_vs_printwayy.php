<?php
// requests/check_auvo_vs_printwayy.php
declare(strict_types=1);

require_once __DIR__.'/../session.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../helpers.php';

// Sempre responda JSON (o front faz response.json())
ini_set('display_errors','0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
while (ob_get_level() > 0) { ob_end_clean(); }

// Garante que o script não seja morto se o usuário fechar a página
ignore_user_abort(true);
@set_time_limit(0);

$ok = false; $checked = 0; $orphans = 0; $noise = null;

try {
    // Importa o cron e chama a função diretamente (sem exec)
    require_once __DIR__.'/../cron/cron_check_auvo_vs_printwayy.php';
    if (function_exists('check_auvo_vs_printwayy')) {
        $res = check_auvo_vs_printwayy(); // já loga e escreve out/auvo_orphans.json
        $checked = (int)($res['checked'] ?? 0);
        $orphans = (int)($res['orphans'] ?? 0);
        $ok = true;
        logMessage('SYNC-CHECK', "Checagem manual via painel concluída: checados=$checked, órfãos=$orphans", 'INFO');
    } else {
        // fallback final: registra erro visível no log
        $noise = 'Função check_auvo_vs_printwayy() não encontrada.';
        logMessage('SYNC-CHECK', $noise, 'ERROR');
    }
} catch (Throwable $e) {
    $noise = 'ERR '.$e->getMessage();
    logMessage('SYNC-CHECK', $noise, 'ERROR');
}

// Retorna um JSON mínimo (o front não mostra nada; só evita erro de parse)
echo json_encode([
    "ok"      => $ok,
    "checked" => $checked,
    "orphans" => $orphans,
    "noise"   => $noise
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
