@echo off
rem ======================================================================
rem  LIMS - ISHLAB TURGAN SERVERNI YANGILASH (Paket 13 ... Paket 33), 2026-11-11
rem  Server qaysi holatda bo'lishidan qat'i nazar: faqat yetishmaganini qo'shadi.
rem  Oraliq paketlar o'tkazib yuborilgan server ham shu bitta skript bilan to'liq bo'ladi.
rem
rem  Oldin qo'lda (SERVERGA_YANGILASH.txt, B-bo'lim):
rem    1) git log -1 --oneline   - hozirgi commit raqamini yozib qo'ying
rem    2) git pull
rem  So'ng "Administrator sifatida" ochilgan cmd da, loyiha papkasida:
rem    deploy\server_yangilash.bat
rem
rem  Bazadagi mavjud ma'lumot O'CHIRILMAYDI va O'ZGARTIRILMAYDI:
rem    migratsiyalar - faqat yangi jadval/ustun/indeks;
rem    php artisan lims:upgrade - faqat yo'q ruxsat/menyu/rol; foydalanuvchi va parollarga tegmaydi.
rem  Qayta ishga tushirish xavfsiz.
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
where php >nul 2>nul
if errorlevel 1 if exist C:\xampp\php\php.exe set "PATH=C:\xampp\php;%PATH%"
where php >nul 2>nul
if errorlevel 1 (
  echo [X] php topilmadi. Masalan:  set PATH=C:\xampp\php;%%PATH%%
  exit /b 1
)
echo.
echo Loyiha papkasi: %CD%
for /f "delims=" %%C in ('git log -1 --oneline 2^>nul') do echo Joriy commit:   %%C
echo.

echo === 1/12  Yangi kod to'liq keldimi ===
set PRE=0
findstr /c:"InterlabServiceProvider" bootstrap\providers.php >nul || (echo   [X] bootstrap\providers.php da InterlabServiceProvider qatori yo'q& set PRE=1)
findstr /c:"SampleIntakeServiceProvider" bootstrap\providers.php >nul || (echo   [X] bootstrap\providers.php da SampleIntakeServiceProvider qatori yo'q& set PRE=1)
call :need app\Providers\DatabaseTypesServiceProvider.php "Tuzatish: natija kiritish, SQL Server"
findstr /c:"DatabaseTypesServiceProvider" app\Providers\SampleIntakeServiceProvider.php bootstrap\providers.php >nul 2>nul || (echo   [X] DatabaseTypesServiceProvider ulanmagan - natija kiritish 404 beradi& set PRE=1)
call :need app\Services\Insights\RecycleBin.php "Paket 19: audit jurnali, savatcha"
call :need app\Services\Help\HelpAssistant.php "Paket 21: yordamchi"
call :need database\help\help_qa.json "Paket 21: yordamchi javoblari"
call :need app\Console\Commands\SetPassword.php "Paket 22: lims:set-password"
call :need app\Console\Commands\WorksheetAddTotals.php "Paket 23: varaq bo'yicha jami"
call :need app\Services\Samples\SampleRequests.php "Paket 24: qoralama buyurtma"
call :need database\migrations\2026_10_22_100000_create_app_settings_table.php "Paket 27: portal sozlamalari"
call :need database\migrations\2026_10_23_100000_add_delivery_shift_to_sample_requests.php "Paket 28: smena, natijalar"
call :need app\Providers\SvodkaServiceProvider.php "Paket 29: buyurtmachi svodkasi"
call :need routes\svodka.php "Paket 29: svodka yo'llari"
call :need database\migrations\2026_10_29_100000_add_group_to_sample_requests.php "Paket 30: buyurtma guruhga"
call :need database\migrations\2026_11_02_100000_create_svodka_viewers_table.php "Paket 31: svodkani ko'ruvchilar"
call :need app\Console\Commands\SvodkaTekshir.php "Paket 31: svodka:tekshir"
call :need app\Services\Svodka\ResultAnalysis.php "Paket 32: natijalar tahlili"
call :need database\migrations\2026_11_11_100000_portal_templates_and_receipt_fields.php "Paket 33: shablonlar, kim qabul qildi"
call :need app\Http\Controllers\Api\Intake\PortalTemplateController.php "Paket 33: buyurtmachi shablonlari"
call :need app\Console\Commands\WorksheetPushResults.php "Paket 31: worksheets:natija-otkaz"
call :need database\seeders\WorksheetModuleSeeder.php "seeder: WorksheetModuleSeeder"
call :need database\seeders\InterlabModuleSeeder.php "seeder: InterlabModuleSeeder"
call :need database\seeders\SampleIntakeModuleSeeder.php "seeder: SampleIntakeModuleSeeder"
call :need database\seeders\InsightsModuleSeeder.php "seeder: InsightsModuleSeeder"
call :need database\seeders\SampleRequestModuleSeeder.php "seeder: SampleRequestModuleSeeder"
call :need database\seeders\SvodkaModuleSeeder.php "seeder: SvodkaModuleSeeder"
call :need app\Console\Commands\LimsUpgrade.php "lims:upgrade"
call :need app\Console\Commands\InstallState.php "lims:install-state"
if "%PRE%"=="1" (
  echo.
  echo   Kod to'liq kelmagan. git pull bajarilganini va lokalda hammasi commit qilinganini tekshiring.
  echo   Hech narsa o'zgarmadi.
  exit /b 1
)
echo.
echo   Barcha fayllar eng oxirgi versiyadami - deploy\tekshir_versiyalar.ps1:
powershell -NoProfile -ExecutionPolicy Bypass -File deploy\tekshir_versiyalar.ps1
if not errorlevel 1 goto :files_ok
echo.
echo   [!] Yuqorida ESKI/FARQ yoki YO'Q fayllar bor. Odatda sabab: lokalda biror paket yoyilmagan
echo       yoki commit qilinmagan. To'g'ri yo'l - lokalda tuzatib, push, serverda git pull.
set /p GOON=Shunga qaramay davom etilsinmi? [ha/yoq]:
if /i not "%GOON%"=="ha" (
  echo   To'xtatildi. Hech narsa o'zgarmadi.
  exit /b 1
)
:files_ok
echo.

echo === 2/12  PHP va kengaytmalar ===
php -r "exit(version_compare(PHP_VERSION,'8.2.0','>=')?0:1);"
if errorlevel 1 (
  echo   [X] PHP 8.2 yoki yangisi kerak
  exit /b 1
)
set MISSING=0
for %%E in (pdo_sqlsrv openssl curl zip mbstring fileinfo) do (
  php -m | findstr /i /x "%%E" >nul
  if errorlevel 1 (
    echo   [!] %%E yoqilmagan
    set MISSING=1
  )
)
if "%MISSING%"=="1" (
  echo   pdo_sqlsrv shart; zip - Excel'ga yuklash, svodka; openssl/curl - laboratoriyalararo.
  echo   php.ini da extension=... ni yoqing va Apache/IIS ni qayta ishga tushiring.
) else (
  echo   [ok] PHP kengaytmalari
)
php artisan --version >nul 2>nul
if errorlevel 1 (
  echo   [X] php artisan ishlamayapti - vendor buzilgan bo'lishi mumkin:  composer install --no-dev --optimize-autoloader
  exit /b 1
)
echo.

echo === 3/12  .env ===
findstr /i /r /c:"^DB_INTEGER_IDS=false" .env >nul && (echo   [X] .env: DB_INTEGER_IDS=false - o'chiring, aks holda natija kiritish 404& exit /b 1)
findstr /i /r /c:"^APP_DEBUG=true" .env >nul && echo   [!] .env: APP_DEBUG=true - ishlab turgan serverda false bo'lsin
findstr /r /c:"^APP_KEY=base64:" .env >nul || (echo   [X] .env: APP_KEY bo'sh - bu yangi server emasmi? SERVERGA_NOLDAN_ORNATISH.txt& exit /b 1)
echo   [ok] .env  (APP_KEY o'zgartirilmaydi)
php artisan config:clear >nul
php artisan lims:install-state
if errorlevel 40 (echo   [X] Bazaga ulanib bo'lmadi - .env dagi DB_* ni tekshiring& exit /b 1)
if errorlevel 30 (echo   [X] Bazada LIMS jadvallari yo'q - .env dagi DB_DATABASE ni tekshiring& exit /b 1)
if errorlevel 20 goto :state_ok
echo   [X] Baza bo'sh yoki o'rnatish chala qolgan - bu YANGI server.
echo       Buning uchun:  deploy\server_yangi_ornatish.bat   Hech narsa o'zgarmadi.
exit /b 1
:state_ok
echo.

echo === 4/12  Zaxira nusxa - MAJBURIY ===
set "DB_DATABASE="
set "DB_HOST="
for /f "tokens=1,* delims==" %%A in ('findstr /b /c:"DB_DATABASE=" /c:"DB_HOST=" .env') do set "%%A=%%~B"
for /f "usebackq delims=" %%T in (`php -r "echo date('Y-m-d_Hi');"`) do set TS=%%T
echo   Baza: %DB_DATABASE%   Server: %DB_HOST%
set /p AUTO=Zaxira nusxani skript hozir olsinmi (sqlcmd, Windows hisobi)? [ha/yoq]:
if /i not "%AUTO%"=="ha" goto :backup_manual
where sqlcmd >nul 2>nul || (echo   [!] sqlcmd topilmadi& goto :backup_manual)
sqlcmd -b -S "%DB_HOST%" -E -Q "BACKUP DATABASE [%DB_DATABASE%] TO DISK = N'%DB_DATABASE%_%TS%.bak' WITH COPY_ONLY, INIT, STATS = 20"
if not errorlevel 1 goto :backup_ok
echo   Qayta urinish (-C, sertifikatga ishonch)...
sqlcmd -b -C -S "%DB_HOST%" -E -Q "BACKUP DATABASE [%DB_DATABASE%] TO DISK = N'%DB_DATABASE%_%TS%.bak' WITH COPY_ONLY, INIT, STATS = 20"
if not errorlevel 1 goto :backup_ok
echo   [!] Avtomatik zaxira olinmadi.
:backup_manual
echo.
echo   Qo'lda: SSMS - bazada o'ng tugma - Tasks - Back Up... - Copy-only backup - OK
echo   yoki:   sqlcmd -S %DB_HOST% -E -Q "BACKUP DATABASE [%DB_DATABASE%] TO DISK = N'%DB_DATABASE%_%TS%.bak' WITH COPY_ONLY, INIT"
set /p BACKUP=Zaxira nusxa olindimi? [ha/yoq]:
if /i not "%BACKUP%"=="ha" (
  echo   Avval zaxira nusxa oling. Hech narsa o'zgarmadi.
  exit /b 1
)
:backup_ok
echo   [ok] zaxira nusxa: %DB_DATABASE%_%TS%.bak  (SQL Server'ning Backup papkasida)
echo   .env faylining nusxasi ham xavfsiz joyda bo'lsin.
echo.

echo === 5/12  Texnik ishlar rejimi ===
php artisan down --retry=60
echo.

echo === 6/12  Migratsiyalar - faqat yangi jadval/ustun/indeks ===
php artisan migrate:status | findstr /i "Pending"
if errorlevel 1 (
  echo   Bajarilmagan migratsiya yo'q.
  goto :after_migrate
)
echo.
echo   Server holatiga qarab 1 tadan 17 tagacha bo'lishi mumkin - ro'yxat SERVERGA_YANGILASH.txt, 4-jadvalda.
echo   Nomi 2026_10_ yoki 2026_11_ bilan boshlanmagan migratsiya bo'lsa - to'xtating va ichini ko'ring.
set /p GO=Shu migratsiyalar bajarilsinmi? [ha/yoq]:
if /i not "%GO%"=="ha" (
  echo   To'xtatildi. Sayt texnik rejimda: ochish uchun  php artisan up
  exit /b 1
)
php artisan migrate --force
if errorlevel 1 goto :fail
:after_migrate
echo.

echo === 7/12  Modullar, ruxsatlar, menyu, yordamchi, varaq shablonlari ===
php artisan lims:upgrade
if errorlevel 1 goto :fail
echo.

echo === 8/12  Ochiq ishchi varaqlarga VARAQ BO'YICHA JAMI - ixtiyoriy ===
php artisan worksheets:add-totals --open --dry-run | findstr /c:"Varaqlar:"
echo   Faqat TASDIQLANMAGAN varaqlarga ko'rsatish uchun jami qo'shiladi; natijalar o'zgarmaydi.
set /p OPEN=Qo'shilsinmi? [ha/yoq]:
if /i "%OPEN%"=="ha" php artisan worksheets:add-totals --open
echo.

echo === 9/12  Tasdiqlangan, lekin probaga yozilmay qolgan natijalar - ixtiyoriy ===
php artisan worksheets:natija-otkaz --dry-run | findstr /c:"O'tkaziladi:"
echo   Faqat probada natija BO'LMAGAN joyga yoziladi; qo'lda kiritilgan natijaga tegilmaydi.
echo   Batafsil ro'yxat:  php artisan worksheets:natija-otkaz --dry-run
set /p PUSH=O'tkazilsinmi? [ha/yoq]:
if /i "%PUSH%"=="ha" php artisan worksheets:natija-otkaz
echo.

echo === 10/12  Kesh va saytni ochish ===
php artisan optimize:clear
if errorlevel 1 goto :fail
php artisan up
echo.

echo === 11/12  Tekshirish ===
php artisan migrate:status | findstr /i "Pending" >nul
if errorlevel 1 (echo   [ok] Barcha migratsiyalar bajarilgan) else (echo   [!] Bajarilmagan migratsiya qoldi)
for %%R in (help/status sample-requests portal/requests portal/svodka portal/request-templates sample-intake/receivers svodka-releases result-analysis insights/desk lab-shifts customer-departments interlab/v1/identity) do (
  php artisan route:list --path=%%R 2>nul | findstr /c:"api/" >nul && (echo   [ok] /api/%%R) || (echo   [X] /api/%%R topilmadi)
)
echo.
echo   Rejalashtirgich - 3 ta vazifa bo'lishi kerak:
php artisan schedule:list
schtasks /Query /TN "LIMS Scheduler" >nul 2>nul
if errorlevel 1 (
  echo   [!] Windows Task Scheduler da "LIMS Scheduler" yo'q - laboratoriyalararo sinxronlash,
  echo       buyurtma muddati va eslatmalar ishlamaydi. SERVERGA_YANGILASH.txt, C-bo'lim.
) else (
  echo   [ok] Task Scheduler: LIMS Scheduler
)
echo.

echo === 12/12  Oxirgi qadam - qo'lda ===
echo   XAMPP: Control Panel - Apache - Stop, keyin Start  - PHP eski kodni xotirada ushlab qolmasin.
echo   IIS:   iisreset
echo   Brauzerda Ctrl+F5.
echo.
echo TAYYOR. Keyingi bir martalik sozlashlar - SERVERGA_YANGILASH.txt, C-bo'lim.
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
echo     Sayt texnik ishlar rejimida qoldi. Vaqtincha ochish:  php artisan up
echo     Tuzatilgach skriptni qayta ishga tushirish xavfsiz (bajarilgan qadam takrorlanmaydi).
echo     Ortga qaytish kerak bo'lsa - SERVERGA_YANGILASH.txt, D-bo'lim (zaxira nusxadan tiklash).
endlocal
exit /b 1
