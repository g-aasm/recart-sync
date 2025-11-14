<?php
/**
 * requests/auto_remove.php
 *
 * Remoção de mapeamentos automáticos e promoção para exceções detalhadas.
 *
 * Parâmetros (GET ou POST):
 *   - key  : "CNPJ/CPF#Departamento" OU "CNPJ/CPF"   (prioritário)
 *   - doc  : "CNPJ/CPF"                              (se não mandar key)
 *
 * Efeito:
 *   1) Remove do data/mappings/clients_auto.json:
 *        - se key="doc#dept": remove apenas essa chave
 *        - se key="doc"  OU doc="...": remove doc e doc#qualquerDept
 *   2) Varre data/printwayy_printers.json e gera exceções em
 *        data/mappings/client_exceptions.json
 *      no formato:
 *        {
 *          "docProblematico": "<doc do PW, com máscara original>",
 *          "departmentMatch": "<location.department>",
 *          "cityMatch": "<location.address.city>",
 *          "auvoClientId": 0,
 *          "auvoClientDescription": ""
 *        }
 *      - Uma entrada por combinação única (doc, dept, city).
 */

require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../log.php';

header('Content-Type: application/json; charset=utf-8');

$JOB = 'AUTO_REMOVE_PROMOTE';

// ---------- utils locais (auto-contidos) ----------
function only_digits(string $s): string { return preg_replace('/\D+/', '', $s) ?? ''; }

function norm_doc(?string $doc): string {
    if (!$doc) return '';
    $doc = str_replace(['\\/', '/', '.', '-', ' '], '', $doc);
    return only_digits($doc);
}

/** tenta abrir json (array ou objeto) */
function read_json_arr(string $path, $default = []) {
    if (!is_file($path)) return $default;
    $raw = @file_get_contents($path);
    $j = json_decode((string)$raw, true);
    return ($j === null ? $default : $j);
}

/** PW pode vir em {data:{data:[...]}} | {data:[...]} | [...] */
function read_pw_flexible(string $path): array {
    $j = read_json_arr($path, []);
    if (isset($j['data'])) {
        if (isset($j['data']['data']) && is_array($j['data']['data'])) return $j['data']['data'];
        if (is_array($j['data'])) return $j['data'];
    }
    return is_array($j) ? $j : [];
}

/** escolhe arquivo de exceções (corrige nome antigo com typo se existir) */
function exceptions_path(): string {
    $base = __DIR__ . '/../data/mappings/';
    @mkdir($base, 0775, true);
    $good = $base . 'client_exceptions.json';
    $typo = $base . 'client_excepetions.json';
    if (is_file($typo) && !is_file($good)) return $typo;
    return $good;
}

// ---------- entrada ----------
$keyParam = trim((string)($_POST['key'] ?? $_GET['key'] ?? ''));
$docParam = trim((string)($_POST['doc'] ?? $_GET['doc'] ?? ''));

// Normaliza insumos
$docNormalized = '';
$deptFromKey   = '';

if ($keyParam !== '') {
    // pode ser "doc#dept" ou "doc"
    $parts = explode('#', $keyParam, 2);
    $docNormalized = norm_doc($parts[0] ?? '');
    $deptFromKey   = isset($parts[1]) ? trim($parts[1]) : '';
} elseif ($docParam !== '') {
    $docNormalized = norm_doc($docParam);
}

if ($docNormalized === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Informe "key" (doc ou doc#dept) ou "doc".']);
    exit;
}

// ---------- caminhos ----------
$autoPath = __DIR__ . '/../data/mappings/clients_auto.json';
$excPath  = exceptions_path();
$pwPath   = __DIR__ . '/../data/printwayy_printers.json';

// ---------- carrega bases ----------
$auto = read_json_arr($autoPath, []);
if (!is_array($auto)) $auto = [];

$exceptions = read_json_arr($excPath, []);
if (!is_array($exceptions)) $exceptions = [];

// exceções aqui são lista de objetos; garantimos array
if (array_values($exceptions) === $exceptions) {
    // já é lista
} else {
    // se vier objeto (map antigo por engano), convertemos para lista vazia
    $exceptions = [];
}

$pw = read_pw_flexible($pwPath);

// ---------- 1) remover do clients_auto ----------
$removed = [];
if ($deptFromKey !== '') {
    // remove apenas essa chave exata
    $keyExact = $docNormalized . '#' . $deptFromKey;
    if (isset($auto[$keyExact])) {
        unset($auto[$keyExact]);
        $removed[] = $keyExact;
    }
    // também removemos o doc "puro" se desejar? manteremos somente a exata
} else {
    // remove doc puro e todos doc#qualquer
    $docKey = $docNormalized;
    if (isset($auto[$docKey])) {
        unset($auto[$docKey]);
        $removed[] = $docKey;
    }
    foreach (array_keys($auto) as $k) {
        if (str_starts_with($k, $docNormalized.'#')) {
            unset($auto[$k]);
            $removed[] = $k;
        }
    }
}
@mkdir(dirname($autoPath), 0775, true);
@file_put_contents($autoPath, json_encode($auto, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

// ---------- 2) criar exceções por (Departamento, Cidade) ----------
/**
 * Vamos percorrer todas as impressoras do PW cujo cpf/cnpj normalizado == $docNormalized.
 * Para cada combinação única (dept, city), criaremos uma entrada com:
 *  - docProblematico: doc no formato original (primeiro encontrado)
 *  - departmentMatch: dept
 *  - cityMatch      : city
 *  - auvoClientId   : 0
 *  - auvoClientDescription: ""
 */
$seen = []; // chave de dedupe: docNorm|dept|city
$created = 0;

foreach ($pw as $p) {
    $cpfRaw  = $p['location']['cpf']  ?? null;
    $cnpjRaw = $p['location']['cnpj'] ?? null;
    $docRaw  = $cnpjRaw ?: $cpfRaw;
    $docNorm = norm_doc($docRaw);

    if ($docNorm !== $docNormalized) continue;

    $dept = trim((string)($p['location']['department'] ?? ''));
    $city = trim((string)($p['location']['address']['city'] ?? ''));

    if ($deptFromKey !== '' && strcasecmp($dept,$deptFromKey) !== 0) {
        // se removeram só um dept via key=doc#dept, limite às entradas desse dept
        continue;
    }

    $dedupeKey = $docNorm . '|' . $dept . '|' . $city;
    if (isset($seen[$dedupeKey])) continue;
    $seen[$dedupeKey] = true;

    // já existe algo igual nas exceções?
    $exists = false;
    foreach ($exceptions as $e) {
        if (
            norm_doc($e['docProblematico'] ?? '') === $docNorm &&
            (string)($e['departmentMatch'] ?? '') === $dept &&
            (string)($e['cityMatch'] ?? '') === $city
        ) { $exists = true; break; }
    }
    if ($exists) continue;

    $exceptions[] = [
        'docProblematico'      => (string)($docRaw ?? ''), // mantém máscara do PW
        'departmentMatch'      => $dept,
        'cityMatch'            => $city,
        'auvoClientId'         => 0,
        'auvoClientDescription'=> ''
    ];
    $created++;
}

// grava exceções
@mkdir(dirname($excPath), 0775, true);
@file_put_contents($excPath, json_encode($exceptions, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

// ---------- logs + retorno ----------
if (!empty($removed)) {
    logMessage($JOB, 'Removido de clients_auto: '.implode(', ', $removed), 'OK');
} else {
    logMessage($JOB, 'Nenhuma chave removida de clients_auto para doc='.$docNormalized.' (já não existia?)', 'WARN');
}
logMessage($JOB, "Exceções criadas/novas: {$created} para doc={$docNormalized}".($deptFromKey? " dept={$deptFromKey}": ''), 'OK');

echo json_encode([
    'ok' => true,
    'removedKeys' => $removed,
    'exceptionsCreated' => $created
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
