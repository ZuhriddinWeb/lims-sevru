---
tags: [lims, holat]
yangilangan: 2026-11-23
---
# Joriy holat (2026-11-23)

## Versiya
- Oxirgi paket: **Paket 39** (+ 38). Repoga push qilingan, GMZ-3 serverida o'rnatilgan.
- Testlar: 25 ta (backend), hammasi o'tgan.
- **Paket 39b** (`server_yangilash.bat` — «LIMS Scheduler» yo'q bo'lsa o'zi yaratadi) — tayyor, hali qo'yilmagan.

## GMZ-3 serveri (192.168.26.8:3010, baza `lims3`)
- Kod: `D:\xampp\htdocs\LIMS`, tarmoqda `\\192.168.26.8\xampp` (= `D:\xampp`), veb-server IIS.
- PHP: to'g'risi `D:\php files\php.exe` (8.3.12). Oddiy `php` buyrug'i eski 8.1 ni chaqiradi — artisan'ni doim `"D:\php files\php.exe" artisan …` bilan bering.
- Grafik: TNB va OTK uchun sinovdan o'tdi — «Grafik 15:00», «Grafik 18:00» buyurtmalari yaratildi.
- **Rejalashtirgich — vaqtinchalik:** PowerShell oynasida `& "D:\php files\php.exe" artisan schedule:work` ishlab turibdi (oynani yopmaslik, RDP'dan «Disconnect» bilan chiqish; server qayta yoqilsa qayta ishga tushirish).
- Task Scheduler «LIMS Scheduler»: `D:\xampp\lims_scheduler.bat` (cd + PHP 8.3 + `scheduler.log` ga yozish). `SYSTEM` nomidan ishlamadi; `l.admin` nomidan qo'lda «Run» ishlaydi, lekin har daqiqalik trigger ishga tushmadi.

## Ochiq vazifalar
- [ ] Task Scheduler'ni doimiy sozlash: `schtasks /Create /TN "LIMS Scheduler" /SC MINUTE /MO 1 /RU srv-gmz3-dmr\l.admin /RP * /RL HIGHEST /F /TR "D:\xampp\lims_scheduler.bat"` → `scheduler.log` har daqiqada yangilanishini tekshirish → `schedule:work` oynasini yopish.
- [ ] Deploy yo'riqnomasi va `server_yangilash.bat` ni «IIS ishlatayotgan PHP'ni topish» + bat-wrapper usuliga moslash; Paket 39b ni qo'yish.
- [ ] Qolgan 7 ta serverni Paket 39 gacha yangilash va har birida rejalashtirgichni sozlash.
- [ ] «Buyurtmalarim» (PortalRequests.vue — lokal fayl) da grafik belgisi va «Manba» filtri — foydalanuvchi lokal versiyasiga qo'shish.
- [ ] Shifr usuli: namunalardagi haqiqiy yozuvlarni ko'rib tasdiqlash (taxmin: prefiks + soat).
- [ ] Nazorat kartasi moduli — karta namunasi va javoblar kutilmoqda ([[qarorlar#Keyinga qoldirilgan]]).
- [ ] Markaziy rahbariyat dashboardi — KPI ro'yxatini kelishish.

## Bog'liq
[[00-xulosa]] · [[server-va-deploy]] · [[paketlar-tarixi]]
