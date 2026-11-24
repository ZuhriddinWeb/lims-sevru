---
tags: [lims, server, deploy]
yangilangan: 2026-11-23
---
# Serverlar va yangilash

## Laboratoriyalar (markaziy portal: 192.168.14.23:5000)
| Laboratoriya | Manzil |
|---|---|
| GMZ-1 | 172.17.12.95:8081 |
| GMZ-2 | 172.29.35.199:8002 |
| GMZ-3 | 192.168.26.8:3010 |
| GMZ-4 | 172.28.15.20:443 |
| GMZ-5 | 172.29.203.246:8080 |
| GMZ-6 | 172.17.12.95:8080 |
| GMZ-7 | 172.29.35.199:8007 |
| ЦНИЛ | 192.168.14.23:5001 |

## Mavjud serverni yangilash (har serverda bir xil)
1. SSMS — zaxira nusxa.
2. `git fetch origin` → `git reset --hard origin/main` (eski fayllar o'zi o'chadi)
3. `php artisan config:clear`, `php artisan migrate:status | Select-String Pending`
4. `php artisan migrate --force`, `php artisan lims:upgrade`, `php artisan optimize:clear`
5. `powershell -ExecutionPolicy Bypass -File deploy\tekshir_versiyalar.ps1` → «Farq qiladi: 0», «Paket 39 bilan yig'ilgan»
6. Veb-serverni qayta ishga tushirish (IIS: `iisreset`; XAMPP: Apache Stop/Start), brauzerda Ctrl+F5.
- Yo'riqnomalar: `deploy\SERVERGA_YANGILASH.txt` (B2 — VS Code orqali), `deploy\SERVERGA_NOLDAN_ORNATISH.txt` (yangi zavod), skriptlar `server_yangilash.bat`, `server_yangi_ornatish.bat`.
- VS Code tarmoq papkasidan ishlaganda buyruqlar **sizning kompyuteringizda** bajariladi (bazaga ulanadi, lekin Task Scheduler kabi narsalar serverda yaratilmaydi).

## Rejalashtirgich (har serverda bir marta)
1. To'g'ri PHP'ni topish: `%windir%\system32\inetsrv\appcmd list config /section:system.webServer/fastCgi` (IIS) yoki `where php`; `php.exe -v` → 8.2+.
2. Wrapper fayl `D:\xampp\lims_scheduler.bat`:
   ```
   @echo off
   cd /d D:\xampp\htdocs\LIMS
   "D:\php files\php.exe" artisan schedule:run >> storage\logs\scheduler.log 2>&1
   ```
3. Vazifa: `schtasks /Create /TN "LIMS Scheduler" /SC MINUTE /MO 1 /RU <server>\<admin> /RP * /RL HIGHEST /F /TR "D:\xampp\lims_scheduler.bat"`
4. Tekshirish: `scheduler.log` har daqiqada yangilanadi; 5 ga bo'linadigan daqiqalarda `schedules:tick … DONE`.
- Vaqtinchalik: `& "D:\php files\php.exe" artisan schedule:work` (oyna ochiq turishi kerak).
- Xatolar: `0x80070002` — yo'l topilmadi; `0x1` — PHP xato bilan tugadi (masalan, PHP 8.1 «requires >= 8.2.0»).

## Bog'liq
[[holat]] · [[arxitektura]] · [[paketlar-tarixi]]
