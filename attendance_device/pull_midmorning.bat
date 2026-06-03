@echo off
REM ================================================================
REM ZKTeco Attendance Pull - Mid-Morning Schedule (10:45 AM)
REM Updates mid-morning punches
REM ================================================================

SET PHP_PATH=C:\php\php.exe
SET SCRIPT_PATH=C:\xampp\htdocs\deno2\attendance_device\zkteco_puller.php
SET LOG_PATH=C:\xampp\htdocs\deno2\attendance_device\logs\zkteco\scheduler_midmorning.log

echo ================================================= >> %LOG_PATH%
echo Mid-Morning Pull Started: %date% %time% >> %LOG_PATH%
echo ================================================= >> %LOG_PATH%

%PHP_PATH% %SCRIPT_PATH% --schedule=midmorning >> %LOG_PATH% 2>&1

echo. >> %LOG_PATH%
echo Mid-Morning Pull Completed: %date% %time% >> %LOG_PATH%
echo ================================================= >> %LOG_PATH%
echo. >> %LOG_PATH%

exit /b %ERRORLEVEL%
