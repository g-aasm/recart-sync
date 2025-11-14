<?php
session_start();
require_once __DIR__.'/config.php';
require_once __DIR__.'/log.php';

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';
    if ($user === getConfig('ADMIN_USER') && $pass === getConfig('ADMIN_PASS')){
        $_SESSION['auth'] = true;
        $_SESSION['theme'] = getConfig('DEFAULT_THEME','theme-blue');
        logMessage('USER','Login efetuado por '.$user,'OK');
        header("Location: home.php");
        exit;
    } else {
        $errorMsg = "Credenciais inválidas";
        logError('USER','Tentativa de login falhou para '.$user);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Recart • Login</title>
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/themes.css">
<style>
body{
    min-height:100vh;
    display:flex;
    justify-content:center;
    align-items:center;
    background: radial-gradient(circle at 20% 20%, #1f2937 0%, #000 60%);
    color:#fff;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Inter', Roboto, 'Segoe UI', sans-serif;
}
.login-card{
    background: rgba(31,41,55,0.6);
    box-shadow:0 25px 60px rgba(0,0,0,.8);
    backdrop-filter: blur(16px);
    border:1px solid rgba(255,255,255,.08);
    border-radius:20px;
    padding:2rem 2rem 1.5rem;
    width:100%;
    max-width:360px;
}
.brand{
    text-align:center;
    margin-bottom:1.5rem;
}
.brand .logo{
    font-size:1.25rem;
    font-weight:600;
    color:#fff;
    letter-spacing:-0.04em;
}
.brand small{
    display:block;
    color:#9ca3af;
    font-size:.75rem;
    margin-top:.25rem;
}
label{
    display:block;
    font-size:.8rem;
    color:#9ca3af;
    margin-bottom:.4rem;
}
input{
    width:100%;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.12);
    background:rgba(0,0,0,.4);
    color:#fff;
    font-size:.9rem;
    padding:.75rem .9rem;
    outline:none;
    box-shadow:0 0 0 2px transparent;
}
input:focus{
    box-shadow:0 0 0 2px rgba(96,165,250,.4);
    border-color:rgba(96,165,250,.6);
}
button{
    width:100%;
    border:none;
    border-radius:12px;
    padding:.8rem 1rem;
    margin-top:1rem;
    background:linear-gradient(90deg,#2563eb 0%,#7c3aed 100%);
    color:#fff;
    font-weight:600;
    font-size:.9rem;
    cursor:pointer;
    box-shadow:0 20px 40px rgba(37,99,235,.4);
}
.error-msg{
    background:rgba(220,38,38,.15);
    border:1px solid rgba(220,38,38,.4);
    color:#fecaca;
    font-size:.8rem;
    border-radius:10px;
    padding:.6rem .75rem;
    margin-bottom:1rem;
}
.footer-note{
    text-align:center;
    font-size:.7rem;
    color:#6b7280;
    margin-top:1rem;
}
</style>
</head>
<body>

<div class="login-card">
    <div class="brand">
        <div class="logo">RECART</div>
        <small>Sistema de Integração e Monitoramento</small>
    </div>
    <?php if ($errorMsg): ?>
    <div class="error-msg"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>
    <form method="POST">
        <label for="user">Usuário</label>
        <input id="user" name="user" type="text" required />

        <label for="pass" style="margin-top:1rem;">Senha</label>
        <input id="pass" name="pass" type="password" required />

        <button type="submit">Entrar</button>
    </form>
    <div class="footer-note">
        © <?= date('Y') ?> Recart • v1.0-dev
    </div>
</div>

</body>
</html>
