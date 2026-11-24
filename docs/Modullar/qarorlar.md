---
tags: [lims, qarorlar]
yangilangan: 2026-11-23
---
# Qarorlar jurnali

## Buyurtmalar va grafik
- **Shifr takrorlanishi mumkin** (P33). Proba raqami alohida va yagona.
- **«Kim olib keladi» ro'yxati** bo'linma bo'yicha, buyurtmalardan o'zi to'planadi; laboratoriya ham foydalanadi (P34).
- **Grafik — ixtiyoriy**, har bo'linmaga alohida yoqiladi; grafikni faqat buyurtmachi tuzadi (P36).
- **Grafik = oddiy buyurtma** (P39). Alohida qoidalar yo'q. Avval (P36) «Grafik bo'yicha qabul» alohida sahifa edi — bitta «Buyurtmalar» sahifasiga birlashtirildi, chunki forma bir xil.
- **Avtomatik buyurtma** grafik vaqtidan sozlamadagi daqiqa oldin yaratiladi (sukut 60).
- **«Muddati o'tdi»** — sozlamada: kun oxiri / smena oxiri / keyingi grafik vaqti / N soat.
- **Shifr:** grafikdagi shifr = prefiks; buyurtmada `prefiks-HH:MM` (reja vaqti). Qo'lda beriladigan buyurtmada — avvalgidek.
- **Proba raqami** grafik buyurtmasida shifrdan emas, `B-…-poz` (har kuni takrorlanmasin).
- **Kechiktirib kiritish:** «Haqiqiy qabul vaqti»; chegara sozlamada, sukut **72 soat**; undan eskisini faqat laboratoriya boshlig'i. Belgi + audit.
- **Sabab bilan yopish:** muddati o'tgan / grafik buyurtmasi faqat ro'yxatdan sabab bilan yopiladi; «Boshqa» — izoh majburiy; sabablar ro'yxatini lab_head tuzadi.
- **Avtomatik yopish:** muddati o'tib **7 kun** (sozlama) sababsiz — «Avtomatik yopilgan»; har kuni eslatma.
- **Sozlamalar bitta sahifada** («Buyurtma sozlamalari»): umumiy qiymatlar + bo'linmalar jadvali + sabablar.
- **«Manba» filtri** standarti — «Hammasi» (P38).

## Bekor qilingan
- **P37 «Grafik jadvallari»** (Excel ko'rinishidagi jadval, oq/sariq ustunlar) — bekor qilindi (P38). Sabablari: natija grafikka bog'langan edi; «kiritiladi» ustuni ishchi varaqsiz qiymat kiritishga yo'l ochardi.
- **Formulalar** — ishchi varaqda bo'lishi kerak. Texnolog/rahbariyat uchun alohida «Hisobot shakllari» keyin, faqat tasdiqlangan natijalardan.
- **Laboratoriya ishchi varaqsiz tahlil o'tkazmasligi** kerak — tashkiliy qoida.

## Keyinga qoldirilgan
- **Nazorat kartasi** — ixtiyoriy modul (admin sozlamada yoqadi), faqat ma'lumot/nazorat uchun, tizim ishini to'xtatmaydi; grafik qatorida «Nazorat kartasi asosida — karta №, band»; alohida «amal qilish» hisoboti (qamrov + ijro). Kutilmoqda: haqiqiy karta namunasi va javoblar (kim kiritadi, davriylik turlari, me'yorlar).
- **Hisobot shakllari** (texnolog/rahbariyat) — formulalar bilan, tasdiqlangan natijalardan.
- **8 serverni birlashtirish — markaziy dashboard:** markaziy portal (192.168.14.23:5000) har LIMS'dan jamlangan ko'rsatkichlarni API (kalit bilan) orqali tortib, o'z bazasiga saqlaydi; xom ma'lumot emas, faqat jamlangan sonlar; avval KPI ro'yxati rahbariyat bilan kelishiladi.

## Ish qoidalari (xavfsizlik)
- Chatga parol, `.env` yuborilmaydi.
- Serverda ishlatilmaydi: `db:seed` (`--class` siz), `migrate:fresh/refresh/reset/rollback`, `db:wipe`, `key:generate`, `git clean`, `help:import --force`.
- Lokal o'zgartirilgan fayllar (paketlar ularni almashtirmaydi): `HelpController.php`, `HelpEntry.php`, `HelpAssistant.php/.vue`, `help/i18n.js`, `routes/insights.php`, `SampleRequestModuleSeeder.php`, `PortalRequests.vue`, `AppLayout.vue`, `DefaultTemplates.php`.
- Repoga push — faqat foydalanuvchi o'zi.

## Bog'liq
[[00-xulosa]] · [[holat]] · [[paketlar-tarixi]]
