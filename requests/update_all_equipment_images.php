<?php
/**
 * requests/update_all_equipment_images.php
 *
 * Dispara a rotina que:
 *  - Lê data/auvo_equipments.json
 *  - Lê data/config/models_catalog.json
 *  - Para cada equipamento cujo "name" exista no catálogo com "imageBase64",
 *    envia PATCH para atualizar "urlImage" no Auvo.
 *
 * Este request apenas chama o cron PHP (sincrono ou assíncrono, conforme preferir).
 */

declare(strict_types=1);

// Hardening básico
if (php_sapi_name() === 'cli-server') {
    // ok em dev
}
header('Content-Type: text/html; charset=utf-8');

$root = dirname(__DIR__);                  // raiz do projeto (onde estão /cron e /data)
$runner = $root . '/cron/cron_auvo_update_urlimage.php';

// Escolha 1: EXECUÇÃO SINCRONA (include)
// - mais portátil em hospedagens que bloqueiam shell_exec.
// - a página vai levar alguns segundos/minutos, conforme quantidade de PATCH.
// Basta descomentar o include e comentar a Execução 2.

// try {
//     require_once $runner;
//     // O cron possui uma função main(); chamamos diretamente.
//     if (function_exists('cron_update_all_equipment_urlimage')) {
//         cron_update_all_equipment_urlimage();
//     } else {
//         throw new RuntimeException('Função cron_update_all_equipment_urlimage() não encontrada no cron.');
//     }
//     echo '<script>alert("Atualização concluída. Verifique o log em logs/cron_auvo_update_urlimage.log."); window.history.back();</script>';
//     exit;
// } catch (Throwable $e) {
//     http_response_code(500);
//     echo '<pre>Falha ao executar cron: ' . htmlspecialchars($e->getMessage()) . '</pre>';
//     exit;
// }

// Escolha 2: EXECUÇÃO ASSÍNCRONA VIA CLI (se seu host permitir)
// - retorna logo para a UI, o processo segue em background
// - o progresso/resultado fica no log
$php = PHP_BINARY; // php atual
$cmd = escapeshellcmd($php) . ' ' . escapeshellarg($runner) . ' > /dev/null 2>&1 &';

@exec($cmd, $out, $ret);

// Feedback rápido para o usuário
echo '<script>alert("Rotina iniciada em background. Acompanhe o log em logs/cron_auvo_update_urlimage.log"); window.history.back();</script>';
