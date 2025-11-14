@echo off
setlocal ENABLEDELAYEDEXPANSION
chcp 65001 >nul

rem === CONFIGURAÇÃO ==========================================================
set "PHP_EXE=php"           rem Altere se precisar do caminho completo (ex.: C:\PHP\php.exe)
set "BASE=C:\Projetos\recart\cron"
rem ==========================================================================

%PHP_EXE% -v >nul 2>&1
if errorlevel 1 (
  echo [ERRO] PHP nao encontrado no PATH. Ajuste a variavel PHP_EXE no .bat.
  exit /b 1
)

call :run "Impressoras PW"         "%PHP_EXE% %BASE%\cron_printwayy_printers.php"        || goto :fail
call :run "Suprimentos PW"         "%PHP_EXE% %BASE%\cron_printwayy_supplies.php"        || goto :fail
call :run "Contadores PW"          "%PHP_EXE% %BASE%\cron_printwayy_counters.php"        || goto :fail
call :run "Equipamentos Auvo"      "%PHP_EXE% %BASE%\cron_auvo_equipments.php"           || goto :fail
call :run "Clientes Auvo"          "%PHP_EXE% %BASE%\cron_auvo_clients.php"              || goto :fail
call :run "Categorias Auvo"        "%PHP_EXE% %BASE%\cron_auvo_categories.php"           || goto :fail
call :run "Diff PW - Auvo"         "%PHP_EXE% %BASE%\cron_build_sync_payloads.php"       || goto :fail
call :run "Novas impressoras"      "%PHP_EXE% %BASE%\cron_auvo_upload_post.php"          || goto :fail
call :run "Atualizar impressoras"  "%PHP_EXE% %BASE%\cron_auvo_upload_patch.php"         || goto :fail

echo.
echo ============================
echo  TODAS AS ETAPAS CONCLUIDAS
echo ============================
exit /b 0

:run
set "STEP=%~1"
set "CMD=%~2"
echo.
echo ------------------------------------------------------------
echo  Iniciando: %STEP%
echo  Comando  : %CMD%
echo ------------------------------------------------------------
%CMD%
if errorlevel 1 (
  echo [ERRO] Falha em: %STEP%  (erro %ERRORLEVEL%)
  exit /b %ERRORLEVEL%
)
echo [OK] %STEP% concluido.
exit /b 0

:fail
echo.
echo ============================
echo  EXECUCAO INTERROMPIDA
echo ============================
pause
exit /b 1
