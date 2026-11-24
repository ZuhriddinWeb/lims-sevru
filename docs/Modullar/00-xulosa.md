---
tags: [lims, xulosa]
yangilangan: 2026-11-23
---
# LIMS — umumiy xulosa

## Loyiha nima qiladi
- NKMK zavod laboratoriyalari (GMZ-1…7, ЦНИЛ) uchun laboratoriya axborot tizimi (LIMS).
- Namuna (proba) hayot sikli: buyurtma → qabul → proba raqami → ishchi varaq → natija → tasdiqlash → protokol / jamlanma hisobot.
- Buyurtmachi bo'linmalar (OTK, sexlar, TNB, Texnologik tadqiqotlar guruhi…) o'z portalidan buyurtma beradi, natijani kuzatadi, Excel'ga yuklaydi.
- Grafik bo'yicha doimiy namunalar: buyurtmachi grafik tuzadi, buyurtma vaqtidan oldin **avtomatik** yaratiladi (Paket 39).
- Jamlanma hisobot (svodka), rahbariyat paneli, natijalar tahlili (AI), laboratoriyalararo tekshiruv, uskunalar, audit jurnali, yordamchi.
- Har bir zavodda alohida server va alohida baza; keyingi bosqich — markaziy rahbariyat dashboardi (qarang [[qarorlar#Keyinga qoldirilgan]]).

## Foydalanuvchilar va rollar
| Rol (kod) | Kim | Asosiy imkoniyatlar |
|---|---|---|
| `admin` | Administrator | Hamma narsa (ruxsat tekshiruvi chetlab o'tiladi), kataloglar, menyu, rollar |
| `lab_head` | Laboratoriya boshlig'i | Qabul, tasdiqlash, «Buyurtma sozlamalari», grafikni bo'linmaga yoqish, kechiktirib kiritish (72 soatdan eski), nazorat hisoboti |
| `operator` | Operator (ro'yxatga olish) | Buyurtmalarni qabul qilish, proba ro'yxatga olish, ishchi varaq |
| `analyst` | Analitik | Tahlil, natija kiritish |
| `viewer` | Kuzatuvchi (rahbariyat) | Ko'rish, hisobotlar, «Buyurtmalar nazorati» |
| `customer` (portal) | Buyurtmachi bo'linma xodimi | «Mening probalarim», «Buyurtmalarim», «Shablonlarim», «Grafigim», «Jamlanma hisobot» — faqat o'z bo'linmasi |

- Laboratoriya guruhlari (Namuna tahlil guruhi, Fazali nazorat guruhi …): guruhga biriktirilgan xodim faqat o'z guruhiga yuborilgan buyurtmalarni ko'radi.
- Laboratoriya buyurtmachining **namuna olish nuqtasi nomini ko'rmaydi** (jamlanma hisobot xolisligi).

## Texnologiyalar
- Laravel: 11.x
- PHP: 8.2+ (GMZ-3 serverida — `D:\php files\php.exe`, 8.3.12; `D:\xampp\php` — eski 8.1, ishlatilmaydi)
- Autentifikatsiya: Laravel Sanctum (SPA)
- Ma'lumotlar bazasi: Microsoft SQL Server (2017+; GMZ-3 da baza `lims3`), testlar — SQLite
- Frontend: Vue 3 + Vite, Pinia, vue-router, vue-i18n (uz / ru / en), Tailwind CSS, chart.js, lucide ikonlari
- Veb-server: IIS (GMZ-3), XAMPP/Apache ham qo'llab-quvvatlanadi
- Qo'shimcha paketlar: yo'q (rollar/ruxsatlar, Excel (SimpleXlsx), formula hisoblagichi — o'z kodimizda)

## Modullar (qisqa)
- Namunalar, Ishchi varaqlar (formulalar shu yerda), Uskunalar, Hisobotlar
- Buyurtmalar (intake): buyurtma, qabul, shablonlar, «Kim olib keladi» kontaktlari, grafik (avtomatik buyurtma), yopish sabablari, nazorat hisoboti
- Jamlanma hisobot (svodka), Natijalar tahlili (AI), Rahbariyat paneli (insights)
- Laboratoriyalararo tekshiruv (interlab), Yordamchi (help), Audit, Savatcha

## Bog'liq yozuvlar
[[arxitektura]] · [[qarorlar]] · [[holat]] · [[paketlar-tarixi]] · [[server-va-deploy]]
