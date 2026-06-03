@echo off
REM ================================================================
REM ZKTeco Attendance Pull - Night Schedule (07:15 PM)
REM Captures late shift/OT punches and cleans old device data
REM ================================================================

SET PHP_PATH=C:\php\php.exe
SET SCRIPT_PATH=C:\xampp\htdocs\deno2\attendance_device\zkteco_puller.php
SET LOG_PATH=C:\xampp\htdocs\deno2\attendance_device\logs\zkteco\scheduler_night.log

echo ================================================= >> %LOG_PATH%
echo Night Pull Started: %date% %time% >> %LOG_PATH%
echo ================================================= >> %LOG_PATH%

%PHP_PATH% %SCRIPT_PATH% --schedule=night >> %LOG_PATH% 2>&1

echo. >> %LOG_PATH%
echo Night Pull Completed: %date% %time% >> %LOG_PATH%
echo ================================================= >> %LOG_PATH%
echo. >> %LOG_PATH%

exit /b %ERRORLEVEL%
