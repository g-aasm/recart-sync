<?php
// requests/save_client_exception.php
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../log.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Método não permitido";
    exit;
}

// Campos vindos do formulário
$docProblematico = $_POST['docProblematico'] ?? '';
$departmentMatch = $_POST['departmentMatch'] ?? '';
$auvoClientId    = $_POST['auvoClientId'] ?? '';

$docProblematico = trim($docProblematico);
$departmentMatch = trim($departmentMatch);
$auvoClientId    = trim($auvoClientId);

if ($docProblematico === '' || $departmentMatch === '' || $auvoClientId === '') {
    http_response_code(400);
    echo "Parâmetros incompletos";
    exit;
}

// Carregar todos os clientes Auvo pra descobrir a descrição
$auvoClientsPath = __DIR__ . '/../data/auvo_clients.json';
$auvoClientsData = [];
if (file_exists($auvoClientsPath)) {
    $tmp = json_decode(file_get_contents($auvoClientsPath), true);
    $auvoClientsData = $tmp['data']['data'] ?? $tmp['data'] ?? [];
    if (!is_array($auvoClientsData)) $auvoClientsData = [];
}

// Descobrir descrição desse cliente no Auvo
$auvoClientDescription = '';
foreach ($auvoClientsData as $cli) {
    if ((string)($cli['id'] ?? '') === $auvoClientId) {
        $auvoClientDescription = $cli['description'] ?? '';
        break;
    }
}

// Carregar exceções atuais
$excFile = __DIR__ . '/../data/config/client_exceptions.json';
$exceptions = [];
if (file_exists($excFile)) {
    $raw = file_get_contents($excFile);
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $exceptions = $json;
    }
}

// Atualizar (se já existe uma combinação com o mesmo docProblematico + departmentMatch, sobrescreve)
$updated = false;
for ($i=0; $i<count($exceptions); $i++) {
    $rule = $exceptions[$i];
    $sameDoc  = normalizeDoc($rule['docProblematico'] ?? '') === normalizeDoc($docProblematico);
    $sameDept = mb_strtoupper(trim((string)($rule['departmentMatch'] ?? ''))) === mb_strtoupper(trim($departmentMatch));
    if ($sameDoc && $sameDept) {
        $exceptions[$i]['auvoClientId']          = $auvoClientId;
        $exceptions[$i]['auvoClientDescription'] = $auvoClientDescription;
        $updated = true;
        break;
    }
}

if (!$updated) {
    $exceptions[] = [
        "docProblematico"      => $docProblematico,
        "departmentMatch"      => $departmentMatch,
        "auvoClientId"         => $auvoClientId,
        "auvoClientDescription"=> $auvoClientDescription
    ];
}

// Salvar de volta
file_put_contents(
    $excFile,
    json_encode($exceptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// Log
$now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$ts  = $now->format('d/m/Y H:i:s');
logMessage(
    'ADMIN',
    "Exceção de cliente cadastrada: doc=$docProblematico dept=$departmentMatch => AuvoID=$auvoClientId ($auvoClientDescription) em $ts",
    'OK'
);

// Redireciona para tela principal
header("Location: ../sync_printwayy_auvo.php");
exit;
