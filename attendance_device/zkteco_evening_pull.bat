@echo off
REM ZKTeco Attendance Pull - Evening (05:25 PM)

SET PHP_PATH=C:\php\php.exe
SET SCRIPT_PATH=C:\xampp\htdocs\deno2\attendance_device\zkteco_puller.php
SET DOC_ROOT=C:\xampp\htdocs
SET LOG_PATH=C:\xampp\htdocs\deno2\attendance_device\logs\zkteco\scheduler_evening.log

echo ================================================= >> %LOG_PATH%
echo Evening (05:25 PM) Pull Started: %date% %time%              >> %LOG_PATH%
echo ================================================= >> %LOG_PATH%

"%PHP_PATH%" "%SCRIPT_PATH%" --schedule=evening --doc_root="%DOC_ROOT%" >> %LOG_PATH% 2>&1

SET EXIT_CODE=%ERRORLEVEL%

echo.                                                   >> %LOG_PATH%
echo Evening (05:25 PM) Pull Completed: %date% %time%             >> %LOG_PATH%
echo Exit Code: %EXIT_CODE%                             >> %LOG_PATH%
echo ================================================= >> %LOG_PATH%
echo.                                                   >> %LOG_PATH%

exit /b %EXIT_CODE%
