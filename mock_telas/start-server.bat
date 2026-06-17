@echo off
title NeverVenture - Risco Commodities Server
echo ==========================================================
echo           NEVERVENTURE - RISCO COMMODITIES MVP
echo ==========================================================
echo.
echo Iniciando o servidor de desenvolvimento local na porta 8080...
echo O seu navegador sera aberto automaticamente em instantes.
echo.
echo Pressione CTRL+C para encerrar o servidor a qualquer momento.
echo.

:: Abre a página do MVP no navegador padrão
start "" "http://127.0.0.1:8080/Risco%%20Commodities.html"

:: Inicia o servidor HTTP estático
npx -y http-server -p 8080
