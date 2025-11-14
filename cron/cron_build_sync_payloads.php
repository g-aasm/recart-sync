<?php
/**
 * cron/cron_build_sync_payloads.php  (v0.2 – mapeamento por arquivos)
 *
 * Gera:
 *  - out/auvo_post_payload.json
 *  - out/auvo_patch_payload.json
 *
 * Resolução de associatedCustomerId:
 *  1) data/mappings/clients_exceptions.json
 *  2) data/mappings/clients_auto.json
 *  3) se não achar → 0 + log
 *
 * Formatos dos mapeamentos:
 *  - Por documento simples:
 *      { "25729197000136": 19952169 }
 *  - Por documento + departamento:
 *      { "25729197000136#Usina": 19952169 }
 */

declare(strict_types=1);
set_time_limit(0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../log.php';

$JOB = 'CRON_BUILD_SYNC_PAYLOADS';

function readJsonFlexible(string $path, $default = []) {
    if (!is_file($path)) return $default;
    $raw = @file_get_contents($path);
    $j = json_decode((string)$raw, true);
    if ($j === null) return $default;
    if (isset($j['data'])) {
        if (isset($j['data']['data']) && is_array($j['data']['data'])) return $j['data']['data'];
        if (isset($j['data']['entityList']) && is_array($j['data']['entityList'])) return $j['data']['entityList'];
        if (is_array($j['data'])) return $j['data'];
    }
    return $j;
}

function writeJsonWithMeta(string $path, array $data): void {
    @mkdir(dirname($path), 0775, true);
    $meta = [
        'generatedAt' => (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i:s'),
        'count'       => count($data),
    ];
    $payload = ['meta'=>$meta, 'data'=>$data];
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function br_dt_from_iso(?string $iso): ?string {
    if (!$iso) return null;
    try {
        $dt = new DateTime($iso, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        return $dt->format('H:i d/m/Y');
    } catch (\Throwable $e) { return null; }
}

function only_digits(string $s): string {
    return preg_replace('/\D+/', '', $s) ?? '';
}
function normalize_doc(?string $docRaw): string {
    if (!$docRaw) return '';
    $doc = str_replace(['\\/', '/', '.', '-', ' '], '', $docRaw);
    return only_digits($doc);
}

function map_category_id(?string $color): int {
    $c = strtolower((string)$color);
    if (in_array($c, ['monochrome','black','mono'], true)) return 44958; // Monocromática
    if (in_array($c, ['colorful','color'], true))          return 44959; // Colorida
    return 44961;                                                  // Desconhecido
}

/** resolve associatedCustomerId SOMENTE lendo arquivos de mapeamento */
function resolve_associated_customer_id(array $pwPrinter, array $exceptionsMap, array $autoMap): int {

    if (!empty($pwPrinter["location"]["department"])) {
        $dept = $pwPrinter["location"]["department"];
    }
    $associatedId = 0;

    if (!empty($pwPrinter["location"]["cnpj"])) { // Se a variável $cnpj NÃO estiver vazia...
        // Remove os caracteres não numéricos e atribui o resultado a $documento
        $doc = preg_replace("/[^0-9]/", "", $pwPrinter["location"]["cnpj"]);
    } else if (!empty($pwPrinter["location"]["cpf"])) { // Se a variável $cnpj estiver vazia, verifica se $cpf NÃO está vazia
        // Remove os caracteres não numéricos e atribui o resultado a $documento
        $doc = preg_replace("/[^0-9]/", "", $pwPrinter["location"]["cpf"]);
    } else {
        $doc = null;
        if(!empty($pwPrinter["customer"]["name"])){
            logError('ASSOC_CUSTOMER', "Documento não encotrado PW " . $pwPrinter["customer"]["name"] . ".");
        }
    }

    foreach ($exceptionsMap as $item) {
        // Pega o valor da chave "docProblematico"
        $doc_problematico_formatado = $item['docProblematico'];
        // Remove os caracteres não numéricos do valor
        $doc_apenas_numeros = preg_replace("/[^0-9]/", "", $doc_problematico_formatado);
        
        // Faz a comparação com a variável $documento
        if ($doc_apenas_numeros === $doc) {
            if ($dept === $item['departmentMatch']) {
                $associatedId = $item['auvoClientId'];
            }
            break;
        }
    }

    if($associatedId === 0){
        foreach ($autoMap["data"]["data"] as $item) {
            // Remove os caracteres não numéricos do valor
            $doc_apenas_numeros = preg_replace("/[^0-9]/", "", $item["cpfCnpj"]);
            
            if($doc_apenas_numeros === $doc){
                $associatedId = $item['id'];
                break;
            }
        }
    }

    return (int)$associatedId;
}

function build_counter_specs(array $counterBlock): array {
    $out = [];
    $lst = $counterBlock['counters'] ?? $counterBlock;
    if (!is_array($lst)) return $out;
    foreach ($lst as $c) {
        $type  = strtolower((string)($c['type'] ?? ''));
        $total = $c['totalCount'] ?? $c['count'] ?? null;
        if ($total === null) continue;
        $label = match (true) {
            in_array($type, ['blackandwhite','mono','bw'], true) => 'Contador Geral P&B',
            in_array($type, ['color','colorful','fullcolor'], true) => 'Contador Geral Colorido',
            in_array($type, ['a3blackandwhite'], true) => 'Contador Grandes Formatos P&B',
            in_array($type, ['a3color'], true) => 'Contador Grandes Formatos Colorido',
            in_array($type, ['scan','scanner'], true) => 'Scan',
            default => 'Counter: ' . ($c['type'] ?? 'desconhecido'),
        };
        $out[] = ['name'=>$label, 'specification'=>(string)$total];
    }
    return $out;
}

function build_toner_specs(array $supplyBlock): array {
    $out = [];
    $lst = $supplyBlock['supplies'] ?? $supplyBlock;
    
    if (!is_array($lst)) return $out;
    foreach ($lst as $item) {
        if (!is_array($item) && !is_object($item)) {
            continue;
        }
        foreach ($item as $s) {
            $type = '';
            // Verifica se $s['type'] existe e não é um array
            if (isset($s['type']) && !is_array($s['type'])) {
                $type = (string) $s['type'];
            }
            if (strcasecmp($type, 'Toner') !== 0 && strcasecmp($type, 'Tinta') !== 0) continue;
            $color = strtolower((string)($s['color'] ?? ''));
            $level = $s['level']["description"] ?? null;
            $colorLabel = match ($color) {
                'black'   => 'Preto',
                'cyan'    => 'Ciano',
                'magenta' => 'Magenta',
                'yellow'  => 'Amarelo',
                default   => ucfirst($color ?: 'Desconhecido'),
            };
            $specLabel = "Suprimento - " . ($type === 'Tinta' ? 'Tinta' : 'Toner') . " {$colorLabel} (%)";
            $level = $s['level']["description"] ?? null;
            // Verifica se $level é um array
            if (is_array($level)) {
                // Transforma o array em uma string, por exemplo, separando os elementos por vírgula
                $level = implode(', ', $level);
            }
            $out[] = ['name'=>$specLabel, 'specification'=>($level === null ? '-' : (string)$level.'')];

        }
    }
    return $out;
}

function build_core_specs(array $pw): array {
    $specs = [];
    $dept   = trim((string)($pw['location']['department'] ?? ''));
    $obs    = trim((string)($pw['observation'] ?? ''));
    $ip     = (string)($pw['ipAddress'] ?? '');
    $mac    = (string)($pw['macAddress'] ?? '');
    $isBackup = (bool)($pw['isBackup'] ?? false);
    $inst   = trim((string)($pw['installationPoint'] ?? ''));

    $status = (string)($pw['status'] ?? '');
    $sit    = match (strtolower($status)) {
        'online'      => 'Comunicação ok',
        'countmanual' => 'Contador manual',
        'offline'     => 'Sem comunicação',
        'indealer'    => 'Em estoque',
        default       => ucfirst($status ?: 'Desconhecido'),
    };

    if ($dept !== '')  $specs[] = ['name'=>'Departamento','specification'=>$dept];
    if ($obs  !== '')  $specs[] = ['name'=>'Observações','specification'=>$obs];
    if ($ip   !== '')  $specs[] = ['name'=>'IP','specification'=>$ip];
    if ($mac  !== '')  $specs[] = ['name'=>'MAC','specification'=>$mac];
    $specs[] = ['name'=>'Backup','specification'=> $isBackup ? 'Sim' : 'Não'];
    if ($inst !== '')  $specs[] = ['name'=>'Ponto Instalação','specification'=>$inst];
    $specs[] = ['name'=>'Situação','specification'=>$sit];

    $lastPw = br_dt_from_iso($pw['lastCommunication'] ?? null);
    if ($lastPw) $specs[] = ['name'=>'Última comunicação PrintWayy','specification'=>$lastPw];

    $nowBR = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('H:i d/m/Y');
    $specs[] = ['name'=>'Última comunicação Auvo','specification'=>$nowBR];

    return $specs;
}

function index_by_printer_id(array $blocks): array {
    $out = [];
    foreach ($blocks as $b) {
        $pid = $b['printer']['id'] ?? ($b['printerId'] ?? null);
        if (!$pid) continue;
        $out[$pid] = $b;
    }
    return $out;
}
function index_auvo_by_serial(array $auvoEquipments): array {
    $map = [];
    foreach ($auvoEquipments as $e) {
        $serial = trim((string)($e['identifier'] ?? ''));
        if ($serial !== '') $map[$serial] = (int)($e['id'] ?? 0);
    }
    return $map;
}

// --------- Carregar bases ---------
$dir = __DIR__ . '/../data';

$pwPrinters  = readJsonFlexible($dir.'/printwayy_printers.json', []);
$pwCounters  = readJsonFlexible($dir.'/printwayy_counters.json', []);
$pwSupplies  = readJsonFlexible($dir.'/printwayy_supplies.json', []);
$auvoEquips  = readJsonFlexible($dir.'/auvo_equipments.json', []);


$exceptionsPath = $dir.'/config/client_exceptions.json';
$auvoClientsPath  = $dir.'/auvo_clients.json';

$exceptionsMap = is_file($exceptionsPath) ? (json_decode((string)file_get_contents($exceptionsPath), true) ?: []) : [];
$autoMap       = is_file($auvoClientsPath)       ? (json_decode((string)file_get_contents($auvoClientsPath), true) ?: [])       : [];

// normalização
if (isset($pwPrinters['data']) && is_array($pwPrinters['data'])) $pwPrinters = $pwPrinters['data'];

$countersIdx = index_by_printer_id($pwCounters);
$suppliesIdx = index_by_printer_id($pwSupplies);
$auvoBySerial= index_auvo_by_serial($auvoEquips);

// --------- Montagem ---------
$postItems  = [];
$patchItems = [];
$total = 0;

foreach ($pwPrinters as $p) {
    $total++;

    $printerId = (string)($p['id'] ?? '');
    $serial    = trim((string)($p['serialNumber'] ?? ''));
    $manuf     = trim((string)($p['manufacturer'] ?? ''));
    $model     = trim((string)($p['model'] ?? ''));
    $name      = trim($manuf.' '.$model);
    if ($name === '') $name = $model ?: ($manuf ?: 'Sem nome');

    $categoryId = map_category_id($p['color'] ?? null);
    $status = strtolower((string)($p['status'] ?? ''));
    $active = in_array($status, ['online','countmanual'], true);

    $associatedCustomerId = resolve_associated_customer_id($p, $exceptionsMap, $autoMap);

    $specs = build_core_specs($p);

    if (isset($countersIdx[$printerId])) {
        foreach (build_counter_specs($countersIdx[$printerId]) as $s) $specs[] = $s;
    }
    if (isset($suppliesIdx[$printerId])) {
        foreach (build_toner_specs($suppliesIdx[$printerId]) as $s) $specs[] = $s;
    }

    $auvoId = $auvoBySerial[$serial] ?? 0;
    if ($auvoId > 0) {
        $patchItems[] = [
            'id' => $auvoId,
            'patch' => [
                ['path'=>'associatedCustomerId','value'=>$associatedCustomerId],
                ['path'=>'categoryId','value'=>$categoryId],
                ['path'=>'active','value'=>$active],
                ['path'=>'equipmentSpecifications','value'=>$specs],
            ]
        ];
    } else {
        $postItems[] = [
            'externalId'           => '',
            'parentEquipmentId'    => 0,
            'associatedCustomerId' => $associatedCustomerId,
            'associatedUserId'     => 0,
            'categoryId'           => $categoryId,
            'name'                 => $name,
            'description'          => '',
            'identifier'           => $serial,
            'base64Image'          => null,
            'expirationDate'       => null,
            'active'               => $active,
            'equipmentSpecifications'=> [],
            'attachments'          => [],
            'warrantyStartDate'    => null,
            'warrantyEndDate'      => null,
        ];
    }
}

// --------- Gravar ---------
$outDir = __DIR__ . '/../out';
writeJsonWithMeta($outDir.'/auvo_post_payload.json',  $postItems);
writeJsonWithMeta($outDir.'/auvo_patch_payload.json', $patchItems);

logMessage($JOB, "Gerados POST=".count($postItems)." PATCH=".count($patchItems)." totalPW={$total}", 'OK');

echo "[{$JOB}] done\n";
