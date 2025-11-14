<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/log.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/session.php';

// ----- CARREGAR DADOS BRUTOS -----
// já tínhamos isso:
$printersPath    = __DIR__ . '/data/printwayy_printers.json';
$auvoClientsPath = __DIR__ . '/data/auvo_clients.json';

$printersData = [];
if (file_exists($printersPath)) {
    $tmp = json_decode(file_get_contents($printersPath), true);
    $printersData = $tmp['data']['data'] ?? $tmp['data'] ?? [];
    if (!is_array($printersData)) $printersData = [];
}

$auvoClientsData = [];
if (file_exists($auvoClientsPath)) {
    $tmp = json_decode(file_get_contents($auvoClientsPath), true);
    $auvoClientsData = $tmp['data']['data'] ?? $tmp['data'] ?? [];
    if (!is_array($auvoClientsData)) $auvoClientsData = [];
}

// catálogos auxiliares
$modelsCatalog    = loadModelsCatalog();
$clientExceptions = loadClientExceptions();  // exceções por (doc+department)
$manualDocsNorm   = loadClientManualDocs();  // lista de docs em modo manual

// ====== MODELOS (mesmo que antes) ======
/* ... mantém o bloco que calcula $modelsView, $totalModels, etc ... */


// ====== CLIENTES: construir as duas listas ======
$automaticList = buildAutomaticList(
    $printersData,
    $auvoClientsData,
    $clientExceptions,
    $manualDocsNorm
);

$manualList = buildManualList(
    $printersData,
    $auvoClientsData,
    $clientExceptions,
    $manualDocsNorm
);

// métricas (só pra exibir)
$autoCount   = count($automaticList);
$manualCount = count($manualList);



// ====== BLOCO MODELOS ======

// gerar lista única de modelos (manufacturer + model) detectados
$modelsDetected = []; // ["HP LaserJet Pro MFP M428fdw" => true]
foreach ($printersData as $p) {
    $manu = isset($p['manufacturer']) ? trim($p['manufacturer']) : '';
    $mod  = isset($p['model']) ? trim($p['model']) : '';
    if ($manu === '' && $mod === '') continue;

    $fullModel = trim($manu.' '.$mod);
    if ($fullModel === '') continue;

    $modelsDetected[$fullModel] = true;
}

$modelsDetectedList = array_keys($modelsDetected);
sort($modelsDetectedList, SORT_NATURAL | SORT_FLAG_CASE);

// monta visão de tabela
$modelsView = []; // [ ["modelName"=>..., "hasImage"=>bool, "imageBase64"=>...], ... ]
foreach ($modelsDetectedList as $mName) {
    $entry  = $modelsCatalog[$mName] ?? null;
    $imgB64 = $entry['imageBase64'] ?? null;
    $hasImg = !empty($imgB64);

    $modelsView[] = [
        "modelName"   => $mName,
        "hasImage"    => $hasImg,
        "imageBase64" => $imgB64
    ];
}

$totalModels        = count($modelsView);
$modelsWithImage    = count(array_filter($modelsView, fn($m)=>$m['hasImage']));
$modelsWithoutImage = $totalModels - $modelsWithImage;


// ====== BLOCO CLIENTES ======
//
// Regras de agrupamento revisadas:
// - Documento "problemático" (aparece em client_exceptions.json) => agrupa por (docNorm + department)
// - Documento "normal" => agrupa só por docNorm
//
// Isso evita duplicar o mesmo CNPJ saudável em vários departments.

function isDocProblematico($docRaw, $exceptionsList) {
    $norm = normalizeDoc($docRaw);
    foreach ($exceptionsList as $rule) {
        $ruleDocNorm = normalizeDoc($rule['docProblematico'] ?? '');
        if ($ruleDocNorm === $norm) {
            return true;
        }
    }
    return false;
}

$clientCombos = []; // chave => dados agregados

foreach ($printersData as $p) {
    $customerName = $p['customer']['name']        ?? '';
    $department   = $p['location']['department']  ?? '';
    $cpf          = $p['location']['cpf']         ?? null;
    $cnpj         = $p['location']['cnpj']        ?? null;

    // documento bruto do PrintWayy
    $docRaw     = $cpf ?: $cnpj ?: '';
    $docDisplay = $docRaw; // pra exibir na tabela
    $docNorm    = normalizeDoc($docRaw);

    // documento é problemático?
    $isProblem = isDocProblematico($docRaw, $clientExceptions);

    // chave única dessa combinação:
    // - se problemático -> docNorm + department (precisa tratar filial separada)
    // - se normal       -> só docNorm (um doc = um cliente)
    if ($isProblem) {
        $groupKey = $docNorm . ' | ' . mb_strtoupper(trim($department));
    } else {
        $groupKey = $docNorm;
    }

    if (!isset($clientCombos[$groupKey])) {

        // Resolve cliente Auvo seguindo as regras:
        // 1. exceção (se problema)
        // 2. doc direto
        // 3. nada
        $resolution = resolveClienteAuvo(
            $docRaw,
            $department,
            $auvoClientsData,
            $clientExceptions
        );

        $clientCombos[$groupKey] = [
            "printwayyCustomerName" => $customerName,
            "printwayyDepartment"   => $department,
            "docDisplay"            => $docDisplay,

            "auvoClientId"          => $resolution['auvoClientId'],
            "auvoClientDescription" => $resolution['auvoClientDescription'],
            "source"                => $resolution['source'], // direct / exception / none

            "isProblem"             => $isProblem
        ];
    } else {
        // Já existe este grupo. Podemos eventualmente aprimorar exibindo
        // um department "representativo".
        // Se ele estava vazio ou genérico, podemos atualizar department só se for problemático:
        if ($isProblem) {
            // em caso problemático faz sentido manter o department específico
            // já gravado, então não substitui
        } else {
            // em caso normal, tanto faz; podemos manter o primeiro department que entrou
            // ou até atualizar pra algum department "mais legível"
            // vamos deixar como está (nenhuma ação)
        }
    }
}

// transformar em lista e ordenar pra exibir
$clientCombosList = array_values($clientCombos);

usort($clientCombosList, function($a,$b){
    // ordenar por nome + department pra ficar estável
    $A = mb_strtoupper(($a['printwayyCustomerName'] ?? '').' '.$a['printwayyDepartment']);
    $B = mb_strtoupper(($b['printwayyCustomerName'] ?? '').' '.$b['printwayyDepartment']);
    return strcmp($A,$B);
});

// métricas pro cabeçalho do card
$totalCombos    = count($clientCombosList);
$directCount    = count(array_filter($clientCombosList, fn($c)=>$c['source']==='direct'));
$exceptionCount = count(array_filter($clientCombosList, fn($c)=>$c['source']==='exception'));
$pendingCount   = count(array_filter($clientCombosList, fn($c)=>$c['source']==='none'));

$status = getStatus();
$errCount = count(getErrorLog());
$themeClass = $_SESSION['theme'] ?? getConfig('DEFAULT_THEME','theme-blue');
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= htmlspecialchars($themeClass) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Recart • Sincronização</title>
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/themes.css">
<script>
(function () {
  const KEY = 'recart_scroll';
  if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }

  window.addEventListener('load', function () {
    // Se tiver #âncora na URL, deixa o browser rolar pra ela
    if (location.hash) return;
    const y = sessionStorage.getItem(KEY);
    if (y) window.scrollTo(0, parseInt(y, 10));
  });

  window.addEventListener('beforeunload', function () {
    sessionStorage.setItem(KEY, String(window.scrollY));
  });
})();
</script>
</head>
<body class="app-body <?= htmlspecialchars($themeClass) ?>">
<div class="layout-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main-area">
        <header class="main-head">
            <h1 class="page-title">Sincronização PrintWayy ↔ Auvo</h1>
            <div class="page-sub">Informações da última sincronização efetuada e ajustes de imagens e clientes.</div>
        </header>
        <section class="main-content">
<?php
// status visual
$st = getStatus();
$auto = $st['autoSyncEnabled'] ? 'ON' : 'OFF';

if ($_SERVER['REQUEST_METHOD']==='POST'){
    if (isset($_POST['toggleAuto'])){
        $new = ($st['autoSyncEnabled']?false:true);
        updateStatus('autoSyncEnabled',$new);
        logMessage('SYSTEM','Sync automático '.($new?'ativado':'desativado'),'OK');
        header("Location: sync_printwayy_auvo.php");
        exit;
    }
    if (isset($_POST['runFullNow'])){
        // chamaria run_full_sync.php via include
        require __DIR__.'/requests/run_full_sync.php';
        exit;
    }
}

// Info dos arquivos coletados (se existirem)
function getDataMeta($file){
    $p = __DIR__.'/data/'.$file;
    if (!file_exists($p)) return null;
    $raw = json_decode(file_get_contents($p),true);
    if (!$raw) return null;
    $meta = $raw['meta'] ?? [];
    return $meta;
}

$datasets = [
    "printwayy_printers.json"=>"Impressoras (PrintWayy)",
    "printwayy_counters.json"=>"Contadores (PrintWayy)",
    "printwayy_supplies.json"=>"Suprimentos (PrintWayy)",
    "auvo_clients.json"=>"Clientes (Auvo)",
    "auvo_categories.json"=>"Categorias (Auvo)",
    "auvo_equipments.json"=>"Equipamentos (Auvo)",
];

$generatedFiles = [
    'out/auvo_patch_payload.json' => 'Payload PATCH (Auvo)',
    'out/auvo_post_payload.json'  => 'Payload POST (Auvo)',
    'out/auvo_orphans.json'       => 'Órfãos (Auvo → PrintWayy)',
];
?>
<!--
<div class="card">
    <div class="card-head">
        <div class="card-title">Painel de Ações Manuais</div>
    </div>
    <div id="actionFeedback" class="action-feedback" style="display:none;"></div>
</div>

<div class="card">
    <div class="card-head">
        <div class="card-title">Ações Manuais</div>
        <div class="card-desc">
            Operações rápidas.
        </div>
    </div>

    <div class="grid-buttons">

        <button id="btnFullCycle" class="act-btn act-full">
            Rodar Ciclo Completo
        </button>
        <button class="act-btn js-run-request" data-endpoint="requests/printwayy_printers.php">
            Buscar Impressoras PrintWayy
        </button>

        <button class="act-btn js-run-request" data-endpoint="requests/auvo_clients.php">
            Buscar Clientes Auvo
        </button>

        <button class="act-btn js-run-request" data-endpoint="requests/auvo_categories.php">
            Buscar Categorias Auvo
        </button>

        <button class="act-btn js-run-request" data-endpoint="requests/auvo_equipments.php">
            Buscar Equipamentos Auvo
        </button>

        <button class="act-btn js-run-request" data-endpoint="requests/generate_auvo_payloads.php">
            Gerar Arquivos Auvo
        </button>

        <button id="btnSyncNow" class="act-btn act-sync"
            data-endpoint="requests/sync_recart.php">
            Sincronizar com Auvo
        </button>

    </div>
</div>

<div class="card">
    <div class="card-head">
        <div class="card-title">Ações de Conferência</div>
        <div class="card-desc">
            Operações rápidas.
        </div>
    </div>

    <div class="grid-buttons">
        <button class="act-btn js-run-request" data-endpoint="requests/check_auvo_vs_printwayy.php">
            Checar órfãos (Auvo → PrintWayy)
        </button>
    </div>
</div>

-->

<div class="card">
    <div class="card-head">
        <div class="card-title">Últimas Coletas</div>
        <div class="card-desc">Status dos arquivos JSON salvos</div>
    </div>
    <div class="datasets">
        <?php foreach($datasets as $file=>$label):
            $meta = getDataMeta($file);
        ?>
        <div class="ds-line">
            <div class="ds-left">
                <div class="ds-name"><?= htmlspecialchars($label) ?></div>
                <div class="ds-file"><?= htmlspecialchars($file) ?></div>
            </div>
            <div class="ds-right">
                <?php if($meta): ?>
                    <div class="ds-meta">
                        <div><strong><?= (int)($meta['count'] ?? 0) ?></strong> itens</div>
                        <div>Atualizado: <strong><?= htmlspecialchars($meta['fetched_at'] ?? '-') ?></strong></div>
                    </div>
                <?php else: ?>
                    <div class="ds-meta ds-empty">sem dados ainda</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div class="card-title">Últimos Arquivos Gerados</div>
        <div class="card-desc">Resultados prontos para auditoria/envio</div>
    </div>
    <div class="datasets">
        <?php foreach ($generatedFiles as $file=>$label):
            $meta = getOutFileMeta($file);
        ?>
        <div class="ds-line">
            <div class="ds-left">
                <div class="ds-name"><?= htmlspecialchars($label) ?></div>
                <div class="ds-file"><?= htmlspecialchars($file) ?></div>
            </div>
            <div class="ds-right">
                <?php if ($meta): ?>
                    <div class="ds-meta">
                        <div><strong><?= (int)($meta['count'] ?? 0) ?></strong> itens</div>
                        <div>Atualizado: <strong><?= htmlspecialchars($meta['fetched_at'] ?? '-') ?></strong></div>
                    </div>
                <?php else: ?>
                    <div class="ds-meta ds-empty">sem dados ainda</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>


<div class="card">
    <div class="card-head">
        <div class="card-title">Catálogo de Modelos</div>
        <div class="card-desc">
            Lista de modelos detectados no PrintWayy. Você pode definir a imagem padrão de cada modelo (base64).<br>
            Modelos totais: <?= $totalModels ?> |
            Com imagem: <?= $modelsWithImage ?> |
            Sem imagem: <?= $modelsWithoutImage ?>
        </div>
    </div>

    <div class="table-wrap">
        <table class="nice-table">
            <thead>
                <tr>
                    <th>Modelo</th>
                    <th>Preview</th>
                    <th>Imagem</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($modelsView)): ?>
                <tr>
                    <td colspan="3" class="muted">Nenhum modelo detectado ainda.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($modelsView as $m): ?>
                    <tr>
                        <td style="vertical-align:top;">
                            <?= htmlspecialchars($m['modelName']) ?>
                        </td>

                        <td style="vertical-align:top; width:160px;">
                            <?php if ($m['hasImage']): ?>
                                <img src="<?= htmlspecialchars($m['imageBase64']) ?>"
                                     alt="preview"
                                     style="max-width:140px; max-height:90px; border-radius:6px; border:1px solid rgba(255,255,255,.15); object-fit:contain; background:#111;"
                                />
                            <?php else: ?>
                                <div class="muted" style="font-size:.8rem;opacity:.7;">
                                    (sem imagem)
                                </div>
                            <?php endif; ?>
                        </td>

                        <td style="vertical-align:top;">
                            <form action="requests/save_model_image.php" method="post" enctype="multipart/form-data" style="font-size:.75rem; line-height:1.4;">
                                <input type="hidden" name="modelName" value="<?= htmlspecialchars($m['modelName']) ?>" />

                                <div style="margin-bottom:.5rem;">
                                    <label style="display:block; font-weight:600; margin-bottom:.25rem;">Enviar nova imagem:</label>
                                    <input type="file" name="modelImage" accept="image/*" style="font-size:.75rem; color:#fff; background:#111; border:1px solid rgba(255,255,255,.2); border-radius:.4rem; padding:.4rem .5rem; width:100%;" />
                                </div>

                                <button type="submit"
                                        class="act-btn"
                                        style="padding:.4rem .75rem; font-size:.7rem;">
                                    <?= $m['hasImage'] ? 'Trocar imagem' : 'Definir imagem' ?>
                                </button>
                            </form>
                        </td>

                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- AÇÃO: Atualizar todas as impressoras (urlImage) via PATCH -->
    <div class="card-foot" style="margin-top:1rem; display:flex; justify-content:flex-end;">
        <form action="requests/update_all_equipment_images.php" method="post" onsubmit="return confirm('Atualizar urlImage de TODAS as impressoras que tiverem imagem no catálogo?\n\nIsso fará PATCH no Auvo para cada equipamento com modelo mapeado.')">
            <button type="submit" class="act-btn"
                    style="padding:.55rem .9rem; font-size:.8rem; background:#0a7; border:1px solid rgba(255,255,255,.15);">
                Atualizar todas as impressoras
            </button>
        </form>
    </div>

</div>

<div class="card">
    <div class="card-head">
        <div class="card-title">Clientes Gerenciados Manualmente / Filiais ⚠</div>
        <div class="card-desc">
            Cada bloco abaixo representa um documento (CPF/CNPJ) que está sendo tratado manualmente
            ou não teve vínculo direto com o Auvo.<br>
            Total manuais: <?= $manualCount ?><br>
            Defina, filial a filial, qual cliente Auvo recebe cada departamento.
        </div>
    </div>

    <div class="manual-wrap">
        <?php if (empty($manualList)): ?>
            <div class="manual-block">
                <div class="client-value muted">Nenhum cliente em modo manual agora.</div>
            </div>
        <?php else: ?>
            <?php foreach ($manualList as $block): ?>
                <div class="manual-block">

                    <div class="manual-headline">
                        <div>
                            <div class="manual-title">
                                <?= htmlspecialchars($block['customerName'] ?: '—') ?>
                            </div>
                            <div class="manual-sub">
                                Documento (CPF/CNPJ PrintWayy): 
                                <span style="font-family:monospace;"><?= htmlspecialchars($block['docDisplay'] ?: '—') ?></span>
                            </div>
                        </div>

                        <div style="text-align:right;max-width:200px;flex:0 0 200px;">
                            <div class="manual-sub" style="margin-bottom:.5rem;">
                                Este cliente inteiro está em modo manual.
                            </div>

                            <form action="requests/set_client_manual_mode.php" method="post">
                                <input type="hidden" name="doc" value="<?= htmlspecialchars($block['docDisplay']) ?>" />
                                <input type="hidden" name="mode" value="auto" />
                                <button type="submit" class="remove-auto-btn" style="border-color:rgba(96,165,250,.5);color:#bfdbfe;">
                                    ↩ Voltar para automático
                                </button>
                            </form>
                        </div>
                    </div>

                    <?php foreach ($block['departments'] as $deptInfo): ?>
                        <div class="dept-card">
                            <div class="dept-line-label">Departamento / Filial</div>
                            <div class="dept-line-value" style="margin-bottom:.5rem;">
                                <?= htmlspecialchars($deptInfo['department'] ?: '(sem departamento)') ?>
                            </div>

                            <div class="dept-line-label">Cliente Auvo Atual</div>
                            <div class="dept-line-value" style="margin-bottom:.75rem;">
                                <?php if ($deptInfo['status'] === 'mapped'): ?>
                                    <span class="tag tag-info" style="display:inline-block;margin-bottom:.4rem;">
                                        ID <?= htmlspecialchars($deptInfo['auvoClientId'] ?? '') ?>
                                    </span><br>
                                    <span style="font-size:.8rem;opacity:.8;">
                                        <?= htmlspecialchars($deptInfo['auvoClientDescription'] ?? '') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="tag tag-warn" style="display:inline-block;margin-bottom:.4rem;">
                                        Pendente
                                    </span><br>
                                    <span style="font-size:.8rem;opacity:.6;">
                                        Ainda não há exceção salva para este department.
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="dept-form">
                                <div class="dept-form-inner">
                                    <form action="requests/save_client_exception.php" method="post">
                                        <input type="hidden" name="docProblematico" value="<?= htmlspecialchars($block['docDisplay']) ?>" />
                                        <input type="hidden" name="departmentMatch" value="<?= htmlspecialchars($deptInfo['department']) ?>" />

                                        <label>Cliente Auvo (ID):</label>
                                        <select name="auvoClientId">
                                            <option value="">-- selecione --</option>
                                            <?php foreach ($auvoClientsData as $cli): ?>
                                                <?php
                                                $selected = '';
                                                if ($deptInfo['status']==='mapped' && (string)$deptInfo['auvoClientId'] === (string)($cli['id'] ?? '')) {
                                                    $selected = 'selected';
                                                }
                                                ?>
                                                <option value="<?= htmlspecialchars($cli['id']) ?>" <?= $selected ?>>
                                                    <?= htmlspecialchars(($cli['id'] ?? '').' - '.($cli['description'] ?? '')) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit"
                                                class="act-btn"
                                                style="margin-top:.5rem;padding:.4rem .75rem; font-size:.7rem; width:100%; text-align:center;">
                                            Salvar exceção
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>



        </section>
        <footer class="main-foot">
            <small>Recart v0.1-dev • <?= date('Y') ?></small>
        </footer>
    </main>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const feedbackBox = document.getElementById("actionFeedback");

    function showFeedback(msg, type = "info") {
        if (!feedbackBox) return;
        feedbackBox.textContent = msg;
        feedbackBox.style.display = "block";
        feedbackBox.className = "action-feedback " + type;
    }

    async function runSingleEndpoint(url) {
        const res = await fetch(url, {
            method: "GET",
            headers: { "X-Requested-With": "recart-panel" }
        });
        const data = await res.json();
        return data;
    }

    // Botões individuais (Buscar Impressoras, Clientes, Categorias, Equipamentos, Sincronizar com Auvo)
    document.querySelectorAll(".js-run-request").forEach(btn => {
        btn.addEventListener("click", async () => {
            const endpoint = btn.getAttribute("data-endpoint");
            if (!endpoint) return;

            btn.disabled = true;
            btn.classList.add("loading");
            showFeedback("Executando: " + btn.textContent + "...", "info");

            try {
                const result = await runSingleEndpoint(endpoint);
                if (result.ok) {
                    showFeedback("OK: " + btn.textContent + " ("
                        + (result.count !== undefined ? result.count + " itens" : "feito")
                        + ")", "ok");
                } else {
                    showFeedback("Erro: " + (result.error || "Falha na execução"), "err");
                }
            } catch (e) {
                console.error(e);
                showFeedback("Falha ao comunicar com o servidor.", "err");
            }

            btn.disabled = false;
            btn.classList.remove("loading");
        });
    });

    // Botão "Sincronizar com Auvo" (usa sync_recart.php)
    const btnSyncNow = document.getElementById("btnSyncNow");
    if (btnSyncNow) {
        btnSyncNow.addEventListener("click", async () => {
            const endpoint = btnSyncNow.getAttribute("data-endpoint");
            btnSyncNow.disabled = true;
            btnSyncNow.classList.add("loading");
            showFeedback("Executando sincronização com Auvo...", "info");

            try {
                const result = await runSingleEndpoint(endpoint);
                if (result.ok) {
                    showFeedback("Sincronização finalizada: " + result.message, "ok");
                } else {
                    showFeedback("Erro na sincronização: " + (result.error || "Falha"), "err");
                }
            } catch (e) {
                console.error(e);
                showFeedback("Falha de comunicação na sincronização.", "err");
            }

            btnSyncNow.disabled = false;
            btnSyncNow.classList.remove("loading");
        });
    }

    // Botão "Rodar Ciclo Completo"
    const btnFull = document.getElementById("btnFullCycle");
    if (btnFull) {
        btnFull.addEventListener("click", async () => {
            btnFull.disabled = true;
            btnFull.classList.add("loading");

            const steps = [
                { label: "Buscando Impressoras PrintWayy", endpoint: "requests/printwayy_printers.php" },
                { label: "Buscando Clientes Auvo",        endpoint: "requests/auvo_clients.php" },
                { label: "Buscando Categorias Auvo",      endpoint: "requests/auvo_categories.php" },
                { label: "Buscando Equipamentos Auvo",    endpoint: "requests/auvo_equipments.php" },
                { label: "Sincronizando com Auvo",        endpoint: "requests/sync_recart.php" }
            ];

            let allGood = true;

            for (const step of steps) {
                showFeedback(step.label + "...", "info");

                try {
                    const result = await runSingleEndpoint(step.endpoint);

                    if (!result || result.ok !== true) {
                        allGood = false;
                        showFeedback("Erro em: " + step.label
                            + " -> " + (result && (result.error || result.message) || "Falha"), "err");
                        break;
                    } else {
                        // sucesso parcial
                        if (result.count !== undefined) {
                            showFeedback(step.label + " OK ("+result.count+" itens)", "ok");
                        } else {
                            showFeedback(step.label + " OK", "ok");
                        }
                    }
                } catch (e) {
                    console.error(e);
                    allGood = false;
                    showFeedback("Falha de comunicação em: " + step.label, "err");
                    break;
                }
            }

            if (allGood) {
                showFeedback("Ciclo completo finalizado com sucesso.", "ok");
            } else {
                // já mostramos erro acima, então só deixa assim
            }

            btnFull.disabled = false;
            btnFull.classList.remove("loading");
        });
    }

});
</script>

<style>
/* Feedback visual */
.action-feedback {
    font-size: .8rem;
    margin-top: 1rem;
    padding: .7rem .9rem;
    border-radius: .5rem;
    font-weight: 500;
    background: rgba(0,0,0,.4);
    border:1px solid rgba(255,255,255,.1);
    color:#fff;
    box-shadow: 0 20px 40px rgba(0,0,0,.6);
    white-space: pre-line;
}
.action-feedback.ok {
    background: rgba(16,185,129,.15);
    border-color: rgba(16,185,129,.4);
    color:#a7f3d0;
}
.action-feedback.err {
    background: rgba(239,68,68,.15);
    border-color: rgba(239,68,68,.4);
    color:#fecaca;
}
.action-feedback.info {
    background: rgba(59,130,246,.15);
    border-color: rgba(59,130,246,.4);
    color:#bfdbfe;
}
.act-btn.loading {
    opacity:.5;
    pointer-events:none;
}
.grid-buttons {
    display:grid;
    grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
    gap: .75rem;
}
.act-btn {
    background: rgba(255,255,255,.05);
    border:1px solid rgba(255,255,255,.12);
    border-radius:.6rem;
    padding:.9rem 1rem;
    font-size:.9rem;
    font-weight:500;
    color:#fff;
    text-align:left;
    cursor:pointer;
    transition:all .15s;
    line-height:1.3;
}
.act-btn:hover {
    background: rgba(255,255,255,.08);
    box-shadow:0 20px 40px rgba(0,0,0,.6);
}
.act-full {
    background: rgba(147,51,234,.15);
    border-color: rgba(147,51,234,.4);
    color:#e9d5ff;
    font-weight:600;
}
.act-sync {
    background: rgba(16,185,129,.15);
    border-color: rgba(16,185,129,.4);
    color:#a7f3d0;
    font-weight:600;
}.client-list-wrap {
    border:1px solid rgba(255,255,255,.07);
    border-radius:.75rem;
    background:rgba(0,0,0,.3);
    box-shadow:0 30px 60px rgba(0,0,0,.7);
    margin-top:1rem;
    overflow:hidden;
    font-size:.8rem;
    color:#fff;
}.client-row {
    display:flex;
    flex-wrap:wrap;
    gap:1rem;
    padding:1rem 1.25rem;
    border-bottom:1px solid rgba(255,255,255,.07);
    background:rgba(0,0,0,.15);
}
.client-row:nth-child(even){
    background:rgba(255,255,255,.03);
}
.client-col {
    min-width:200px;
    flex:1;
}
.client-label {
    font-size:.7rem;
    text-transform:uppercase;
    letter-spacing:.03em;
    opacity:.6;
    margin-bottom:.25rem;
    font-weight:600;
}
.client-value {
    font-size:.8rem;
    line-height:1.4;
    color:#fff;
    word-break:break-word;
    white-space:pre-line;
}
.client-doc {
    font-family: monospace;
    font-size:.8rem;
    color:#fff;
    opacity:.8;
}
.status-badge-block {
    display:flex;
    flex-direction:column;
    gap:.4rem;
}
.tag-info {
    background: rgba(96,165,250,.15);
    border:1px solid rgba(96,165,250,.4);
    color:#bfdbfe;
}auto-wrap, .manual-wrap {
    border:1px solid rgba(255,255,255,.07);
    border-radius:.75rem;
    background:rgba(0,0,0,.3);
    box-shadow:0 30px 60px rgba(0,0,0,.7);
    margin-top:1rem;
    overflow:hidden;
    font-size:.8rem;
    color:#fff;
}

.auto-row {
    display:flex;
    flex-wrap:wrap;
    gap:1rem;
    padding:1rem 1.25rem;
    border-bottom:1px solid rgba(255,255,255,.07);
    background:rgba(0,0,0,.15);
}
.auto-row:nth-child(even){
    background:rgba(255,255,255,.03);
}

.manual-block {
    border-bottom:1px solid rgba(255,255,255,.07);
    background:rgba(0,0,0,.15);
    padding:1rem 1.25rem;
}
.manual-block:nth-child(even){
    background:rgba(255,255,255,.03);
}

.manual-headline {
    display:flex;
    flex-wrap:wrap;
    justify-content:space-between;
    gap:1rem;
    margin-bottom:1rem;
}

.manual-title {
    font-size:.8rem;
    line-height:1.4;
    font-weight:600;
    color:#fff;
}
.manual-sub {
    font-size:.7rem;
    opacity:.7;
    line-height:1.4;
    color:#fff;
}

.dept-card {
    border:1px solid rgba(255,255,255,.15);
    border-radius:.5rem;
    background:rgba(0,0,0,.4);
    padding:.75rem .9rem;
    margin-bottom:.75rem;
}

.dept-line-label {
    font-size:.7rem;
    text-transform:uppercase;
    letter-spacing:.03em;
    opacity:.6;
    margin-bottom:.25rem;
    font-weight:600;
}
.dept-line-value {
    font-size:.8rem;
    line-height:1.4;
    color:#fff;
    word-break:break-word;
    white-space:pre-line;
}

.client-label {
    font-size:.7rem;
    text-transform:uppercase;
    letter-spacing:.03em;
    opacity:.6;
    margin-bottom:.25rem;
    font-weight:600;
}
.client-value {
    font-size:.8rem;
    line-height:1.4;
    color:#fff;
    word-break:break-word;
    white-space:pre-line;
}

/* status tags */
.tag {
    display:inline-block;
    font-size:.7rem;
    line-height:1.2;
    font-weight:600;
    border-radius:.4rem;
    padding:.35rem .5rem;
    background:rgba(255,255,255,.07);
    border:1px solid rgba(255,255,255,.18);
    color:#fff;
    white-space:nowrap;
}
.tag-ok {
    background:rgba(16,185,129,.15);
    border-color:rgba(16,185,129,.4);
    color:#a7f3d0;
}
.tag-warn {
    background:rgba(251,191,36,.15);
    border-color:rgba(251,191,36,.4);
    color:#fde68a;
}
.tag-info {
    background: rgba(96,165,250,.15);
    border:1px solid rgba(96,165,250,.4);
    color:#bfdbfe;
}

/* botão de remover do automático */
.remove-auto-btn {
    background:none;
    border:1px solid rgba(255,0,0,.5);
    color:#ff6b6b;
    border-radius:.4rem;
    font-size:.7rem;
    font-weight:600;
    padding:.4rem .6rem;
    cursor:pointer;
}
.remove-auto-btn:hover {
    background:rgba(255,0,0,.1);
}

/* formulário exceção dentro de cada filial */
.dept-form {
    margin-top:.75rem;
    font-size:.75rem;
    line-height:1.4;
}
.dept-form-inner {
    background:rgba(0,0,0,.6);
    border:1px solid rgba(255,255,255,.15);
    border-radius:.5rem;
    padding:.5rem .6rem;
}
.dept-form label {
    display:block;
    font-size:.7rem;
    opacity:.7;
    margin-bottom:.25rem;
}
.dept-form select {
    width:100%;
    background:#000;
    color:#fff;
    border:1px solid rgba(255,255,255,.2);
    border-radius:.4rem;
    padding:.4rem .5rem;
    font-size:.7rem;
}
.dept-form button {
    width:100%;
    text-align:center;
    margin-top:.5rem;
    padding:.4rem .75rem;
    font-size:.7rem;
}

</style>

</body>
</html>
