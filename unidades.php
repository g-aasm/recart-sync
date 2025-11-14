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
<title>Recart • Unidades</title>
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/themes.css">
</head>
<body class="app-body <?= htmlspecialchars($themeClass) ?>">
<div class="layout-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <main class="main-area">
        <header class="main-head">
            <h1 class="page-title">Controle de Unidades</h1>
            <div class="page-sub">Cadastro e vínculo de impressoras (em breve)</div>
        </header>
        <section class="main-content">
<div class="card">
    <div class="card-head">
        <div class="card-title">Em desenvolvimento</div>
        <div class="card-desc">Aqui futuramente vamos gerenciar as unidades e atrelar a ativos.</div>
    </div>
    <p style="font-size:.9rem;color:var(--text-dim);line-height:1.4;">
        • Cadastrar unidade<br/>
        • Informações de contato<br/>
        • Impressoras vinculadas<br/>
        • Métricas locais
    </p>
</div>
        </section>
        <footer class="main-foot">
            <small>Recart v0.1-dev • <?= date('Y') ?></small>
        </footer>
    </main>
</div>
</body>
</html>
