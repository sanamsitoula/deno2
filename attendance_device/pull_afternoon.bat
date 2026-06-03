@echo off
REM ================================================================
REM ZKTeco Attendance Pull - Afternoon Schedule (01:25 PM)
REM Captures after-lunch check-in punches
REM ================================================================

SET PHP_PATH=C:\php\php.exe
SET SCRIPT_PATH=C:\xampp\htdocs\deno2\attendance_device\zkteco_puller.php
SET LOG_PATH=C:\xampp\htdocs\deno2\attendance_device\logs\zkteco\scheduler_afternoon.log

echo ================================================= >> %LOG_PATH%
echo Afternoon Pull Started: %date% %time% >> %LOG_PATH%
echo ================================================= >> %LOG_PATH%

%PHP_PATH% %SCRIPT_PATH% --schedule=afternoon >> %LOG_PATH% 2>&1

echo. >> %LOG_PATH%
echo Afternoon Pull Completed: %date% %time% >> %LOG_PATH%
echo ================================================= >> %LOG_PATH%
echo. >> %LOG_PATH%

exit /b %ERRORLEVEL%
