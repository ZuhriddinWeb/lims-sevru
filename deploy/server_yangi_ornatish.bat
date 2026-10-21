@echo off
rem ======================================================================
rem  LIMS - YANGI SERVERGA NOLDAN O'RNATISH (Paket 24 holati, 2026-10-20)
rem
rem  Oldindan (SERVERGA_NOLDAN_ORNATISH.txt, 1-4 bo'limlar):
rem    - PHP 8.2+, ODBC + pdo_sqlsrv, Composer, Git, IIS; SQL Server'da BO'SH baza va login
rem    - git clone qilingan; shu skript loyiha ichidagi deploy\ papkasidan ishga tushiriladi
rem  "Administrator sifatida" ochilgan cmd da:
rem      cd C:\inetpub\lims
rem      deploy\server_yangi_ornatish.bat
rem
rem  XAVFSIZ: baza holatini o'zi aniqlaydi. Bazada foydalanuvchilar bo'lsa (ishlab turgan
rem  server) - hech narsa qilmay to'xtaydi; bunday server uchun deploy\server_yangilash.bat.
rem  Chala qolgan o'rnatishni qayta ishga tushirib davom ettirish mumkin.
rem ======================================================================
setlocal EnableExtensions
set "SELF=%~dp0"
if "%SELF:~0,2%"=="\\" (
  echo [X] Skript tarmoq yo'lidan ishga tushirildi: %SELF%
  echo     Uni SERVERNING O'ZIDA, mahalliy yo'ldan ishga tushiring - masalan RDP orqali kirib:
  echo        cd /d C:\xampp\htdocs\LIMS
  echo        deploy\%~nx0
  echo     Hech narsa o'zgarmadi.
  exit /b 1
)
cd /d "%~dp0.."
if not exist artisan (
  echo [X] artisan topilmadi. Skript loyiha ichidagi deploy papkasida bo'lishi kerak.
  exit /b 1
)
echo.
echo Loyiha papkasi: %CD%
for /f "delims=" %%C in ('git log -1 --oneline 2^>nul') do echo Commit:         %%C
echo.

echo === 1/11  PHP va dastur fayllari ===
php -r "exit(version_compare(PHP_VERSION,'8.2.0','>=')?0:1);"
if errorlevel 1 (
  echo   [X] PHP 8.2 yoki yangisi kerak. Hozir:
  php -v
  exit /b 1
)
for /f "delims=" %%V in ('php -r "echo PHP_VERSION;"') do echo   [ok] PHP %%V
set "PHPEXE="
for /f "delims=" %%P in ('where php') do if not defined PHPEXE set "PHPEXE=%%P"
echo   php.exe: %PHPEXE%
set MISSING=0
for %%E in (pdo_sqlsrv openssl curl zip mbstring fileinfo gd dom xml tokenizer ctype) do (
  php -m | findstr /i /x "%%E" >nul
  if errorlevel 1 (
    echo   [X] PHP kengaytmasi yoqilmagan: %%E
    set MISSING=1
  )
)
if "%MISSING%"=="1" (
  echo.
  echo   php.ini ni oching - php --ini - va extension=... qatorlarini yoqing, so'ng qayta ishga tushiring.
  echo   pdo_sqlsrv uchun Microsoft ODBC Driver 17/18 ham o'rnatilgan bo'lishi kerak.
  exit /b 1
)
echo   [ok] PHP kengaytmalari
set PRE=0
call :need bootstrap\providers.php "bootstrap\providers.php"
findstr /c:"InterlabServiceProvider" bootstrap\providers.php >nul || (echo   [X] providers.php da InterlabServiceProvider yo'q& set PRE=1)
findstr /c:"SampleIntakeServiceProvider" bootstrap\providers.php >nul || (echo   [X] providers.php da SampleIntakeServiceProvider yo'q& set PRE=1)
call :need app\Providers\DatabaseTypesServiceProvider.php "SQL Server id turi tuzatishi"
call :need app\Services\Insights\RecycleBin.php "Paket 19: audit, savatcha"
call :need app\Services\Help\HelpAssistant.php "Paket 21: yordamchi"
call :need database\help\help_qa.json "Paket 21: yordamchi javoblari"
call :need app\Console\Commands\SetPassword.php "lims:set-password"
call :need app\Console\Commands\WorksheetAddTotals.php "Paket 23: varaq bo'yicha jami"
call :need app\Services\Samples\SampleRequests.php "Paket 24: qoralama buyurtma"
call :need app\Console\Commands\LimsUpgrade.php "lims:upgrade"
call :need app\Console\Commands\InstallState.php "lims:install-state"
set MANIFEST=public\build\manifest.json
if not exist %MANIFEST% set MANIFEST=public\build\.vite\manifest.json
findstr /c:"requests/views/LabRequests.vue" %MANIFEST% >nul 2>nul
if errorlevel 1 (
  echo   [X] public\build yo'q yoki eski - lokalda: npm run build, git add -f public/build, commit, push; serverda git pull
  set PRE=1
) else (
  echo   [ok] public\build - serverda Node kerak emas
)
if "%PRE%"=="1" (
  echo.
  echo   Kod to'liq kelmagan. Hech narsa o'zgarmadi.
  exit /b 1
)
echo.

echo === 2/11  Composer paketlari ===
where composer >nul 2>nul
if errorlevel 1 (
  echo   [X] composer topilmadi. getcomposer.org dan o'rnating va yangi cmd oching.
  exit /b 1
)
call composer install --no-dev --optimize-autoloader --no-interaction
if errorlevel 1 goto :fail
echo.

echo === 3/11  .env fayli ===
if exist .env goto :env_have
if exist deploy\env.production.example (
  copy /y deploy\env.production.example .env >nul
) else (
  copy /y .env.example .env >nul
)
echo   .env yaratildi. Hozir ochiladi: DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD, APP_URL,
echo   CORS_ALLOWED_ORIGINS ni to'ldiring, saqlang va yoping.
notepad .env
:env_have
findstr /r /c:"^APP_KEY=base64:" .env >nul
if errorlevel 1 (
  php artisan key:generate --force
  if errorlevel 1 goto :fail
) else (
  echo   [ok] APP_KEY bor - O'ZGARTIRILMAYDI
)
findstr /i /r /c:"^DB_INTEGER_IDS=false" .env >nul && (echo   [X] .env da DB_INTEGER_IDS=false - o'chiring, natija kiritish 404 beradi& exit /b 1)
findstr /i /r /c:"^APP_DEBUG=true" .env >nul && echo   [!] .env: APP_DEBUG=true - ishlab turgan serverda false bo'lsin
php artisan config:clear >nul
echo.

echo === 4/11  Bazaga ulanish va baza holati ===
php artisan db:show
if errorlevel 1 (
  echo   [X] Bazaga ulanib bo'lmadi. .env dagi DB_* ni, SQL Server Mixed Mode va TCP/IP yoqilganini tekshiring.
  echo       Sertifikat xatosi bo'lsa .env:  DB_TRUST_SERVER_CERTIFICATE=true
  exit /b 1
)
php artisan lims:install-state
if errorlevel 40 goto :state_error
if errorlevel 30 goto :state_foreign
if errorlevel 20 goto :state_existing
if errorlevel 10 goto :state_partial
echo.
set /p EMPTY=Baza bo'sh. Shu bazaga LIMS o'rnatilsinmi? [ha/yoq]: 
if /i not "%EMPTY%"=="ha" (
  echo   To'xtatildi. Hech narsa o'zgarmadi.
  exit /b 1
)
goto :migrate

:state_partial
echo   Avvalgi o'rnatish chala qolgan - davom ettiriladi.
goto :migrate

:state_existing
echo.
echo   [X] Bu bazada foydalanuvchilar bor - bu ISHLAB TURGAN server.
echo       Noldan o'rnatish unga TEGMAYDI. Yangilash uchun:  deploy\server_yangilash.bat
echo       Hech narsa o'zgarmadi.
exit /b 1

:state_foreign
echo.
echo   [X] Bazada boshqa dasturning jadvallari bor. LIMS uchun alohida BO'SH baza yarating
echo       - deploy\baza_yaratish.sql - va .env dagi DB_DATABASE ni o'zgartiring. Hech narsa o'zgarmadi.
exit /b 1

:state_error
echo   [X] Baza holatini aniqlab bo'lmadi - yuqoridagi xabarga qarang.
exit /b 1

:migrate
echo.
echo === 5/11  Migratsiyalar - barcha jadvallar ===
php artisan migrate --force
if errorlevel 1 goto :fail
echo.

echo === 6/11  Boshlang'ich ma'lumotlar ===
echo   1 - TO'LIQ: ruxsat, rol, laboratoriya, elementlar, menyu + ZML xodimlari - 10 hisob,
echo       sinov profillari. NKMK GMZ-3 markaziy laboratoriyasi nusxasi.
echo   2 - FAQAT TIZIM: ruxsat, rol, laboratoriya, elementlar, menyu. Foydalanuvchi va
echo       profillar bo'sh; administratorni o'zingiz yaratasiz. Boshqa korxona uchun.
set /p MODE=Tanlang [1/2]: 
if "%MODE%"=="1" goto :seed_full
if "%MODE%"=="2" goto :seed_core
echo   1 yoki 2 kiriting.
exit /b 1

:seed_full
php artisan db:seed --force
if errorlevel 1 goto :fail
goto :seeded

:seed_core
for %%S in (PermissionSeeder RoleSeeder LaboratorySeeder LaboratoryGroupExtraSeeder ElementSeeder MenuSeeder) do (
  echo   - %%S
  php artisan db:seed --class=%%S --force
  if errorlevel 1 goto :fail
)

:seeded
echo.
echo === 7/11  Administrator ===
if "%MODE%"=="2" goto :adm_new
echo   Standart admin / admin123 - almashtiring:
php artisan lims:set-password admin
if errorlevel 1 goto :fail
echo.
echo   Boshqa 9 ta hisobning paroli standart - lims12345. Hozir almashtirasizmi?
set /p OTH=[ha/yoq - yoq bo'lsa keyin: php artisan lims:set-password LOGIN]: 
if /i not "%OTH%"=="ha" goto :adm_done
for %%U in (mirzoyeva shamsiyev xolmurodova ishnazarova urinov xomatova qobilov haydarov saydullayev) do (
  echo   --- %%U
  php artisan lims:set-password %%U
)
goto :adm_done

:adm_new
set /p ADM=Administrator logini, masalan admin: 
php artisan lims:set-password %ADM% --create
if errorlevel 1 goto :fail

:adm_done
echo.

echo === 8/11  Modullar, yordamchi, varaq shablonlari - lims:upgrade ===
php artisan lims:upgrade
if errorlevel 1 goto :fail
echo.

echo === 9/11  Fayllar uchun havola va kesh ===
if exist public\storage (
  echo   [ok] public\storage bor
) else (
  php artisan storage:link
  if errorlevel 1 (
    echo   [!] storage:link bajarilmadi - cmd Administrator emas.
    echo       Qo'lda:  mklink /J public\storage storage\app\public
  )
)
php artisan optimize:clear
if errorlevel 1 goto :fail
echo.

echo === 10/11  Rejalashtirgich - Task Scheduler, har daqiqada schedule:run ===
echo   Laboratoriyalararo sinxronlash, buyurtma muddati va kalibrovka eslatmalari shunga bog'liq.
schtasks /Query /TN "LIMS Scheduler" >nul 2>nul
if not errorlevel 1 (
  echo   [ok] LIMS Scheduler allaqachon bor
  goto :verify
)
set /p TASK=Vazifa yaratilsinmi? [ha/yoq]: 
if /i not "%TASK%"=="ha" goto :verify
schtasks /Create /TN "LIMS Scheduler" /SC MINUTE /MO 1 /RU SYSTEM /RL HIGHEST /F /TR "\"%PHPEXE%\" \"%CD%\artisan\" schedule:run"
if errorlevel 1 echo   [!] Vazifa yaratilmadi - Administrator cmd kerak. Qo'lda: SERVERGA_NOLDAN_ORNATISH.txt, 8-bo'lim.
echo.

:verify
echo === 11/11  Tekshirish ===
php artisan migrate:status | findstr /i "Pending" >nul
if errorlevel 1 (echo   [ok] Barcha migratsiyalar bajarilgan) else (echo   [!] Bajarilmagan migratsiya bor)
for %%R in (help/status sample-requests portal/requests insights/desk lab-shifts customer-departments interlab/v1/identity) do (
  php artisan route:list --path=%%R 2>nul | findstr /c:"api/" >nul && (echo   [ok] /api/%%R) || (echo   [X] /api/%%R topilmadi)
)
echo.
echo   Yordamchi sinovi:
php artisan help:ask "namunani qanday royxatga olaman"
echo.
echo   Rejalashtirgich - 3 ta vazifa:
php artisan schedule:list
echo.
echo TAYYOR. Endi IIS sayt va ruxsatlar - SERVERGA_NOLDAN_ORNATISH.txt, 7-bo'lim;
echo so'ng brauzerda kirib tekshiring - 9-bo'lim.
endlocal
exit /b 0

:need
if exist %1 (
  echo   [ok] %~2
) else (
  echo   [X] %1 yo'q - %~2
  set PRE=1
)
exit /b 0

:fail
echo.
echo [X] XATO - yuqoridagi xabarni nusxalab yuboring.
echo     Tuzatgach skriptni qayta ishga tushiring: chala qolgan o'rnatish davom ettiriladi.
endlocal
exit /b 1
