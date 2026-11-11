@echo off
rem Smart Bin - start the WhatsApp gateway. Keep this window open while the system runs.
title Smart Bin WhatsApp gateway
cd /d "%~dp0wa-gateway"
if not exist node_modules (
  echo Installing packages the first time...
  set PUPPETEER_SKIP_DOWNLOAD=true
  call npm install --no-audit --no-fund
)
node gateway.js
pause
