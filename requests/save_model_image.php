<?php
// requests/save_model_image.php
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../log.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Método não permitido";
    exit;
}

$modelName = $_POST['modelName'] ?? '';
$modelName = trim($modelName);

if ($modelName === '') {
    http_response_code(400);
    echo "Modelo inválido";
    exit;
}

if (!isset($_FILES['modelImage']) || $_FILES['modelImage']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo "Imagem não enviada ou com erro";
    exit;
}

// Lê o arquivo binário
$tmpPath  = $_FILES['modelImage']['tmp_name'];
$mimeType = mime_content_type($tmpPath); // ex: image/png, image/jpeg
$bin      = file_get_contents($tmpPath);

// Monta base64 data URI
$base64 = 'data:' . $mimeType . ';base64,' . base64_encode($bin);

// Carrega catálogo atual
$catalogFile = __DIR__ . '/../data/config/models_catalog.json';
$catalog = [];
if (file_exists($catalogFile)) {
    $raw = file_get_contents($catalogFile);
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $catalog = $json;
    }
}

// Atualiza/insere
if (!isset($catalog[$modelName])) {
    $catalog[$modelName] = [];
}
$catalog[$modelName]['imageBase64'] = $base64;

// Salva de volta
file_put_contents(
    $catalogFile,
    json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// Log
$now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$ts  = $now->format('d/m/Y H:i:s');
logMessage(
    'ADMIN',
    "Imagem atualizada para modelo '$modelName' em $ts",
    'OK'
);

// Redireciona de volta pra tela principal
header("Location: ../sync_printwayy_auvo.php");
exit;
