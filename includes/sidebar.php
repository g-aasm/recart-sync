<?php

$current = basename($_SERVER['PHP_SELF']);

// includes/sidebar.php
if (!isset($errCount)) $errCount = 0; // fallback seguro
?>
<aside class="sidebar">
    <div class="sidebar-top">
        <div class="sb-brand">RECART</div>
        <div class="sb-user">Olá, Admin</div>
    </div>

    <nav class="sb-nav">
        <a class="sb-item <?= $current=='home.php'?'active':'' ?>" href="home.php">Início</a>
        <a class="sb-item <?= $current=='sync_printwayy_auvo.php'?'active':'' ?>" href="sync_printwayy_auvo.php">PrintWayy ↔ Auvo</a>
        <a class="sb-item <?= $current=='unidades.php'?'active':'' ?>" href="unidades.php">Controle de Unidades</a>
        <a class="sb-item <?= $current=='settings.php'?'active':'' ?>" href="settings.php">Configurações</a>
        <a class="sb-item <?= $current=='settings.php'?'active':'' ?>" href="logout.php">Sair</a>
    </nav>

    <div class="sb-status">
        <div class="status-line">
            <span class="lbl">Execução:</span>
            <span class="val ok">CRON</span>
        </div>
        <div class="status-line">
            <span class="lbl">Erros:</span>
            <span class="val err"><?= htmlspecialchars($errCount) ?></span>
        </div>
    </div>
</aside>
