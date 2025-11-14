<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/log.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/session.php';

$status = getStatus();
$errCount = count(getErrorLog());
$themeClass = $_SESSION['theme'] ?? getConfig('DEFAULT_THEME','theme-blue');
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= htmlspecialchars($themeClass) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Recart • Configurações</title>
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/themes.css">
</head>
<body class="app-body <?= htmlspecialchars($themeClass) ?>">
<div class="layout-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main-area">
        <header class="main-head">
            <h1 class="page-title">Configurações</h1>
            <div class="page-sub">Tema e Intervalo de Sincronização</div>
        </header>
        <section class="main-content">
<?php
$themes = [
    "theme-blue"=>"Azul (padrão Dourada Tec)",
    "theme-dark"=>"Dark / Neon",
    "theme-light"=>"Claro Clean"
];

if ($_SERVER['REQUEST_METHOD']==='POST'){
    if (isset($_POST['selectedTheme'])){
        $_SESSION['theme'] = $_POST['selectedTheme'];
        logMessage('SYSTEM',"Tema alterado para ".$_POST['selectedTheme'],'OK');
    }
    if (isset($_POST['syncInterval'])){
        $val = (int)$_POST['syncInterval'];
        $runtimePath = __DIR__.'/data/runtime_settings.json';
        $rt = [];
        if (file_exists($runtimePath)){
            $rt = json_decode(file_get_contents($runtimePath),true) ?: [];
        }
        $rt['SYNC_INTERVAL_MINUTES']=$val;
        file_put_contents($runtimePath, json_encode($rt, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        updateStatus('syncIntervalMinutes',$val);
        logMessage('SYSTEM',"Intervalo de sync alterado para ".$val." min",'OK');
    }
    header("Location: settings.php");
    exit;
}

$currentTheme = $_SESSION['theme'] ?? getConfig('DEFAULT_THEME','theme-blue');
$currentInterval = getConfig('SYNC_INTERVAL_MINUTES',15);
$statusNow = getStatus();
?>
<div class="card">
    <div class="card-head">
        <div class="card-title">Tema Visual</div>
        <div class="card-desc">Escolha o tema da interface</div>
    </div>
    <form method="post" class="form-theme">
        <div class="theme-options">
            <?php foreach($themes as $val=>$label): ?>
            <label class="theme-row">
                <input type="radio" name="selectedTheme" value="<?= htmlspecialchars($val) ?>" <?= $val===$currentTheme?'checked':'' ?> />
                <span class="theme-name"><?= htmlspecialchars($label) ?></span>
                <span class="theme-badge <?= htmlspecialchars($val) ?>"><?= htmlspecialchars($val) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <button class="btn-apply" type="submit">Aplicar Tema</button>
    </form>
</div>

<div class="card">
    <div class="card-head">
        <div class="card-title">Sincronização Automática</div>
        <div class="card-desc">Defina o intervalo em minutos</div>
    </div>
    <form method="post" class="form-syncint">
        <label>Intervalo atual (minutos): <?= (int)$statusNow['syncIntervalMinutes'] ?></label>
        <input type="number" min="1" step="1" name="syncInterval" value="<?= (int)$statusNow['syncIntervalMinutes'] ?>" class="input-num"/>
        <button class="btn-apply" type="submit">Salvar Intervalo</button>
    </form>
</div>
        </section>
        <footer class="main-foot">
            <small>Recart v0.1-dev • <?= date('Y') ?></small>
        </footer>
    </main>
</div>
</body>
</html>
