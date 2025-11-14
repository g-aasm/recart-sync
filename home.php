<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/log.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/session.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearErrors'])) {
    clearErrorLog();
    updateStatus('lastErrorCount', 0);

    // redireciona e sai
    header("Location: home.php");
    exit;
}

$status = getStatus();
$errCount = count(getErrorLog());
$themeClass = $_SESSION['theme'] ?? getConfig('DEFAULT_THEME','theme-blue');
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= htmlspecialchars($themeClass) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Recart • Início</title>
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/themes.css">
</head>
<body class="app-body <?= htmlspecialchars($themeClass) ?>">
<div class="layout-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main-area">
        <header class="main-head">
            <h1 class="page-title">Bem-vindo ao Sistema Recart</h1>
            <div class="page-sub">Monitoramento e sincronização de ativos.</div>
        </header>
        <section class="main-content">
<?php
$lines = array_reverse(getSystemLog());
$errors = getErrorLog();
?>
<div class="card">
    <div class="card-head">
        <div class="card-title">Log do Sistema</div>
        <div class="card-desc">Eventos recentes (OK em azul, ERRO em vermelho)</div>
    </div>
    <div class="log-list">
        <?php foreach($lines as $ln): 
            $isErr = str_contains($ln,'[ERROR]');
        ?>
        <div class="log-line <?= $isErr?'err':'ok' ?>">
            <?= htmlspecialchars($ln) ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div class="card-title">Resumo de Erros</div>
        <div class="card-desc">Falhas detectadas pelo sistema</div>
    </div>
    <div class="error-summary">
        <div class="err-count"><?= count($errors) ?> erros registrados</div>
        <form method="post" style="display:inline;">
            <button type="submit" name="clearErrors" class="btn-clear-errors">Zerar erros</button>
        </form>
        <div class="err-list">
            <?php foreach(array_reverse($errors) as $er): ?>
            <div class="err-item"><?= htmlspecialchars($er) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['clearErrors'])){
    clearErrorLog();
    // update status.json lastErrorCount = 0
    updateStatus('lastErrorCount',0);
    header("Location: home.php");
    exit;
}
?>
        </section>
        <footer class="main-foot">
            <small>Recart v0.1-dev • <?= date('Y') ?></small>
        </footer>
    </main>
</div>
</body>
</html>
