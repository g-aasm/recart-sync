<?php
header('Access-Control-Allow-Origin: *'); // opcional pra debug local
function sendJsonResponse($arr){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// envolve dados crus com metadados
function wrapWithMeta($source, $dataArray){
    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));

    // Tenta descobrir a contagem real de registros
    if (is_array($dataArray)) {

        // Caso 1: formato padrão com "count" e "data"
        if (isset($dataArray['count']) && isset($dataArray['data'])) {
            $realCount = $dataArray['count'];

        // Caso 2: formato Auvo -> dentro de "result.entityList"
        } elseif (isset($dataArray['result']['entityList']) && is_array($dataArray['result']['entityList'])) {
            $realCount = count($dataArray['result']['entityList']);

        // Caso 3: lista direta simples [ {...}, {...}, ... ]
        } else {
            $realCount = count($dataArray);
        }
    } else {
        $realCount = 0;
    }

    return [
        "meta" => [
            "source"      => $source,
            "fetched_at"  => $now->format('d/m/Y H:i:s'),
            "count"       => $realCount
        ],
        "data" => $dataArray
    ];
}

function updateStatus($key, $value) {
    $statusFile = __DIR__ . '/data/status.json';

    // carrega status atual, se existir
    $current = [];
    if (file_exists($statusFile)) {
        $raw = file_get_contents($statusFile);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $current = $decoded;
        }
    }

    // atualiza a chave
    $current[$key] = $value;

    // salva de volta
    file_put_contents(
        $statusFile,
        json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

// Lê catálogo de modelos (modelo -> imagem base64)
function loadModelsCatalog() {
    $file = __DIR__ . '/data/config/models_catalog.json';
    if (!file_exists($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [];
    }
    return $json;
}

// Lê exceções de clientes (CNPJ problemático -> department -> cliente Auvo)
function loadClientExceptions() {
    $file = __DIR__ . '/data/config/client_exceptions.json';
    if (!file_exists($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [];
    }
    return $json;
}

// Normaliza CPF/CNPJ removendo pontuação e barras invertidas
function normalizeDoc($docRaw) {
    if ($docRaw === null) return '';
    // exemplo de entrada problemática: "25.994.179\/0001-70"
    // 1. remove backslash "\/"
    $clean = str_replace('\\/', '/', $docRaw);

    // 2. remove tudo que não é dígito
    $clean = preg_replace('/\D+/', '', $clean);

    return $clean ?: '';
}

/**
 * Resolve qual cliente do Auvo deve ser associado a uma impressora do PrintWayy.
 *
 * Parâmetros:
 * - $docPrintwayy: CPF/CNPJ vindo do PrintWayy (location.cpf ou location.cnpj)
 * - $departmentPrintwayy: department da impressora (location.department)
 * - $auvoClientsData: array decodificado de auvo_clients.json (lista de clientes Auvo)
 * - $exceptionsList: array de client_exceptions.json
 *
 * Retorno:
 * [
 *   "auvoClientId" => int|null,
 *   "auvoClientDescription" => string|null,
 *   "source" => "exception" | "direct" | "none"
 * ]
 */
function resolveClienteAuvo($docPrintwayy, $departmentPrintwayy, $auvoClientsData, $exceptionsList) {
    $docNorm = normalizeDoc($docPrintwayy);
    $deptNorm = mb_strtoupper(trim((string)$departmentPrintwayy));

    // 1. Tenta exceções primeiro (CNPJ problemático mapeado manualmente)
    foreach ($exceptionsList as $rule) {
        $ruleDoc  = normalizeDoc($rule['docProblematico'] ?? '');
        $ruleDept = mb_strtoupper(trim((string)($rule['departmentMatch'] ?? '')));

        if ($ruleDoc === $docNorm && $ruleDept === $deptNorm) {
            return [
                "auvoClientId"          => $rule['auvoClientId'] ?? null,
                "auvoClientDescription" => $rule['auvoClientDescription'] ?? null,
                "source"                => "exception"
            ];
        }
    }

    // 2. Match direto por documento (1 pra 1)
    // auvo_clients.json deve ter cpfCnpj, id, description
    foreach ($auvoClientsData as $cli) {
        $docAuvo = $cli['cpfCnpj'] ?? '';
        $idAuvo  = $cli['id'] ?? null;

        if (!$idAuvo) continue;

        if (normalizeDoc($docAuvo) === $docNorm) {
            return [
                "auvoClientId"          => $cli['id'],
                "auvoClientDescription" => $cli['description'] ?? null,
                "source"                => "direct"
            ];
        }
    }

    // 3. Não achou
    return [
        "auvoClientId"          => null,
        "auvoClientDescription" => null,
        "source"                => "none"
    ];
}

// Lê lista de documentos que o usuário marcou para modo MANUAL (fora do automático)
function loadClientManualDocs() {
    $file = __DIR__ . '/data/config/client_manual_docs.json';
    if (!file_exists($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [];
    }
    // garantir que estão normalizados
    $out = [];
    foreach ($json as $docRaw) {
        $norm = normalizeDoc($docRaw);
        if ($norm !== '') {
            $out[$norm] = true;
        }
    }
    
    return array_keys($out); // lista só de docs normalizados
}

// Salva lista de documentos em modo MANUAL
function saveClientManualDocs($docsNormList) {
    $file = __DIR__ . '/data/config/client_manual_docs.json';
    // vamos salvar como lista de strings (normalizadas)
    file_put_contents(
        $file,
        json_encode(array_values($docsNormList), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

// Adiciona ou remove um documento da lista manual
// $mode = 'manual' => força modo manual
// $mode = 'auto'   => remove do manual
function setClientManualDoc($docRaw, $mode) {
    $norm = normalizeDoc($docRaw);
    if ($norm === '') return false;

    $current = loadClientManualDocs(); // lista normalizada
    $map = [];
    foreach ($current as $d) { $map[$d] = true; }

    if ($mode === 'manual') {
        $map[$norm] = true;
    } else if ($mode === 'auto') {
        unset($map[$norm]);
    }

    saveClientManualDocs(array_keys($map));
    return true;
}

/**
 * buildAutomaticList
 *
 * Gera a lista de clientes "automáticos", ou seja:
 * - Documento bate 1:1 com cliente Auvo via cpfCnpj (source == 'direct')
 * - Documento NÃO está marcado como manual
 *
 * Retorna array de itens tipo:
 * [
 *   [
 *     "docDisplay" => "...",
 *     "docNorm" => "...",
 *     "printwayyCustomerName" => "...",
 *     "auvoClientId" => "...",
 *     "auvoClientDescription" => "..."
 *   ],
 *   ...
 * ]
 */
function buildAutomaticList($printersData, $auvoClientsData, $clientExceptions, $manualDocsNorm) {
    // vamos agrupar por documento normalizado
    $byDoc = [];

    foreach ($printersData as $p) {
        $customerName = $p['customer']['name']        ?? '';
        $department   = $p['location']['department']  ?? '';
        $cpf          = $p['location']['cpf']         ?? null;
        $cnpj         = $p['location']['cnpj']        ?? null;

        $docRaw     = $cpf ?: $cnpj ?: '';
        $docNorm    = normalizeDoc($docRaw);
        $docDisplay = $docRaw;

        if ($docNorm === '') continue;

        // pula se esse doc está marcado manual
        if (in_array($docNorm, $manualDocsNorm, true)) {
            continue;
        }

        // tenta resolver cliente Auvo
        $resolution = resolveClienteAuvo(
            $docRaw,
            $department,          // department aqui não importa muito pra direct
            $auvoClientsData,
            $clientExceptions
        );

        // só entram os que foram "direct"
        if ($resolution['source'] !== 'direct') {
            continue;
        }

        // agrupa pelo documento norm
        if (!isset($byDoc[$docNorm])) {
            $byDoc[$docNorm] = [
                "docDisplay"             => $docDisplay,
                "docNorm"                => $docNorm,
                "printwayyCustomerName"  => $customerName,
                "auvoClientId"           => $resolution['auvoClientId'],
                "auvoClientDescription"  => $resolution['auvoClientDescription'],
            ];
        }
    }

    // ordenar por nome do cliente pra exibir bonitinho
    $list = array_values($byDoc);
    usort($list, function($a,$b){
        $A = mb_strtoupper($a['printwayyCustomerName'] ?? '');
        $B = mb_strtoupper($b['printwayyCustomerName'] ?? '');
        return strcmp($A,$B);
    });

    return $list;
}

/**
 * buildManualList
 *
 * Gera os blocos de clientes "manuais":
 * - Todos os docs que estão marcados como manual
 * - Todos os docs que NÃO conseguiram vínculo automático direto (source 'none')
 *
 * Para cada doc manual, listamos TODOS os departments (filiais) encontrados,
 * e pra cada department descobrimos se já existe exceção cadastrada.
 *
 * Retorna array no formato:
 * [
 *   [
 *     "docDisplay" => "...",
 *     "docNorm" => "...",
 *     "customerName" => "...", // nome "principal" que vimos
 *     "departments" => [
 *        [
 *          "department" => "...",
 *          "auvoClientId" => "...ou null...",
 *          "auvoClientDescription" => "...ou null...",
 *          "status" => "mapped" | "pending"
 *        ],
 *        ...
 *     ]
 *   ],
 *   ...
 * ]
 */
function buildManualList($printersData, $auvoClientsData, $clientExceptions, $manualDocsNorm) {
    // INDEX DE EXCEÇÕES:
    // vamos montar um indice rápido para achar exceção existente por (docNorm + deptNorm)
    $exceptionIndex = [];
    foreach ($clientExceptions as $ex) {
        $dNorm  = normalizeDoc($ex['docProblematico'] ?? '');
        $deptNm = mb_strtoupper(trim((string)($ex['departmentMatch'] ?? '')));
        if ($dNorm !== '' && $deptNm !== '') {
            $exceptionIndex[$dNorm.'|'.$deptNm] = [
                "auvoClientId"          => $ex['auvoClientId'] ?? null,
                "auvoClientDescription" => $ex['auvoClientDescription'] ?? null
            ];
        }
    }

    // Vamos agrupar todas as impressoras por documento normalizado
    // e, dentro de cada doc, todos os departments que apareceram.
    //
    // IMPORTANTE:
    // Um doc deve entrar no modo MANUAL se:
    //  - o doc está explicitamente marcado manual (está em $manualDocsNorm)
    //  - OU qualquer department desse doc NÃO conseguiu match automático ("none")
    //  - OU qualquer department desse doc já está usando exceção ("exception")
    //
    // Mas independente da razão, se o doc é MANUAL,
    // precisamos listar TODOS os departments encontrados para esse doc.
    //
    // Estrutura temporária:
    // $docsTmp[docNorm] = [
    //   "docDisplay" => "...",
    //   "customerName" => "... (primeiro que vimos)",
    //   "departments" => [
    //        deptNorm => [
    //           "departmentRaw" => "...",
    //           "resolution" => [ "source"=>direct|exception|none, auvoClientId, auvoClientDescription ],
    //        ],
    //        ...
    //   ],
    //   "isManualDoc" => bool (se deve ir pra lista final)
    // ]
    $docsTmp = [];

    foreach ($printersData as $p) {
        $customerName = $p['customer']['name']        ?? '';
        $department   = $p['location']['department']  ?? '';
        $cpf          = $p['location']['cpf']         ?? null;
        $cnpj         = $p['location']['cnpj']        ?? null;

        $docRaw     = $cpf ?: $cnpj ?: '';
        $docNorm    = normalizeDoc($docRaw);
        $docDisplay = $docRaw;

        if ($docNorm === '') continue;

        // normalizar department pra chave
        $deptRaw  = $department ?: '(sem departamento)';
        $deptNorm = mb_strtoupper(trim($deptRaw));

        // roda a resolução pra esse par doc+department
        $resolution = resolveClienteAuvo(
            $docRaw,
            $deptRaw,
            $auvoClientsData,
            $clientExceptions
        );
        // $resolution tem:
        //   auvoClientId
        //   auvoClientDescription
        //   source: "direct" | "exception" | "none"

        // cria estrutura base do doc se ainda não existe
        if (!isset($docsTmp[$docNorm])) {
            $docsTmp[$docNorm] = [
                "docDisplay"   => $docDisplay,
                "customerName" => $customerName,
                "departments"  => [],
                "isManualDoc"  => false,
            ];
        }

        // registra department dentro desse doc
        if (!isset($docsTmp[$docNorm]["departments"][$deptNorm])) {
            $docsTmp[$docNorm]["departments"][$deptNorm] = [
                "departmentRaw"          => $deptRaw,
                "auvoClientId"           => $resolution['auvoClientId'],
                "auvoClientDescription"  => $resolution['auvoClientDescription'],
                "source"                 => $resolution['source'], // direct/exception/none
            ];
        }

        // Agora decidir se ESSE DOC precisa ir pra lista manual.
        // Critérios:
        // 1) Este doc foi explicitamente marcado manual em client_manual_docs.json
        if (in_array($docNorm, $manualDocsNorm, true)) {
            $docsTmp[$docNorm]["isManualDoc"] = true;
        }

        // 2) Ou esta combinação doc+department caiu em "none"
        if ($resolution['source'] === 'none') {
            $docsTmp[$docNorm]["isManualDoc"] = true;
        }

        // 3) Ou esta combinação doc+department caiu em "exception"
        if ($resolution['source'] === 'exception') {
            $docsTmp[$docNorm]["isManualDoc"] = true;
        }

        // Observação:
        // Se a resolução for "direct", isso por si só NÃO torna o doc manual.
        // Mas se esse doc já está manual por outro motivo (ex: marcado manual
        // ou outra filial diferente dele precisa exceção),
        // ele já vai ser isManualDoc=true e vai aparecer aqui mesmo assim.
    }

    // Agora montamos a lista final SOMENTE com docs que são manuais.
    // E para cada doc manual: listamos TODOS os departments daquele doc.
    $list = [];

    foreach ($docsTmp as $docNorm => $info) {
        if (!$info["isManualDoc"]) {
            continue; // esse doc fica no automático, não no manual
        }

        // transformar departments em lista legível
        $depsOut = [];
        foreach ($info["departments"] as $deptNorm => $deptInfo) {

            // vamos marcar status visual:
            // - mapped    = tem auvoClientId (significa que já tem exceção OU que caiu direct mas doc é manual)
            // - pending   = ainda não tem auvoClientId => precisa você escolher
            $hasId = !empty($deptInfo['auvoClientId']);

            $depsOut[] = [
                "department"            => $deptInfo['departmentRaw'],
                "auvoClientId"          => $deptInfo['auvoClientId'] ?? null,
                "auvoClientDescription" => $deptInfo['auvoClientDescription'] ?? null,
                "status"                => $hasId ? "mapped" : "pending"
            ];
        }

        // ordenar os departments pra ficar bonito
        usort($depsOut, function($a,$b){
            $A = mb_strtoupper($a['department'] ?? '');
            $B = mb_strtoupper($b['department'] ?? '');
            return strcmp($A,$B);
        });

        $list[] = [
            "docDisplay"   => $info["docDisplay"],
            "docNorm"      => $docNorm,
            "customerName" => $info["customerName"],
            "departments"  => $depsOut
        ];
    }

    // ordenar blocos por nome do cliente
    usort($list, function($a,$b){
        $A = mb_strtoupper($a['customerName'] ?? '');
        $B = mb_strtoupper($b['customerName'] ?? '');
        return strcmp($A,$B);
    });

    return $list;
}

// Lê metadados de arquivos gerados em /out
function getOutFileMeta(string $file)
{
    if (!file_exists($file)) return null;

    $mtime = date('d/m/Y H:i:s', filemtime($file));
    $raw   = @file_get_contents($file);
    $json  = json_decode((string)$raw, true);

    $count = 0;
    $stamp = $mtime;

    if (is_array($json)) {
        // formatos possíveis:
        // 1) {"meta": {...}, "data": [...]}  -> orphans
        if (isset($json['data']) && is_array($json['data'])) {
            $count = count($json['data']);
        }
        // 2) [ {...}, {...} ]  -> payloads de POST/PATCH como lista
        elseif (array_keys($json) === range(0, count($json)-1)) {
            $count = count($json);
        }

        // timestamp preferencial quando existir
        if (!empty($json['meta']['generatedAt'])) {
            $stamp = (string)$json['meta']['generatedAt'];
        }
    }

    return [
        'count'      => (int)$count,
        'fetched_at' => $stamp,
    ];
}

?>
