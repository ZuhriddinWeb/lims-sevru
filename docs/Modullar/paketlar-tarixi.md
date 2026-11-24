---
tags: [lims, paketlar]
yangilangan: 2026-11-23
---
# Paketlar tarixi (oxirgilari)

| Paket | Sana | Mazmuni |
|---|---|---|
| 33 | 2026-11 | Shifr takrorlanishi mumkin; qabulda «Kim olib keldi» + telefon, «Kim qabul qilib oldi»; buyurtmachi shablonlari; «Svodka» → «Jamlanma hisobot»; `lims:migratsiya-tuzat` |
| 34 | 2026-11 | «Kim olib keladi» kontaktlari ro'yxati (combobox), demo ma'lumotlar buyrug'i |
| 35 | 2026-11-18 | «Foydalanish yo'riqnomasi» da video (subtitr, 15 bo'lim); jamlanma hisobotda probasiz kun xatosi tuzatildi |
| 36 | 2026-11-18 | Grafik bo'yicha namunalar: «Grafigim», «Grafik bo'yicha qabul», «Manba» filtri, umumiy Excel |
| 37 | 2026-11-18 | Grafik jadvallari (Excel ko'rinishi, formulalar) — **bekor qilindi** |
| 38 | 2026-11-19 | P37 izlarini tozalash (migratsiya); «Manba» standarti — «Hammasi» |
| 39 | 2026-11-19 | Grafik = oddiy buyurtma (avtomatik), shifr `prefiks-HH:MM`, muddat rejimlari, kechiktirib kiritish (72 soat), sabab bilan yopish, avtomatik yopish (7 kun), «Buyurtma sozlamalari», «Buyurtmalar nazorati» |
| 39b | 2026-11-20 | `server_yangilash.bat` rejalashtirgichni o'zi yaratadi (hali qo'yilmagan) |

## Paketni qo'yish tartibi (lokal)
1. `git pull`
2. Zip'ni loyiha papkasiga yoyish (`Expand-Archive … -Force`), zip'ni loyihadan olib chiqish.
3. O'chirilgan fayllar — `git rm --ignore-unmatch …` (OQISH.txt da).
4. `php artisan migrate`, `php artisan lims:upgrade`, `php artisan optimize:clear`
5. `npm run build` + belgini tekshirish (`Select-String … 'data-late-filter'` — P39)
6. `git add -A`, `git add -f public/build`, commit, push (`storage/logs/laravel.log` commit qilinmaydi).

## Bog'liq
[[holat]] · [[server-va-deploy]] · [[qarorlar]]
