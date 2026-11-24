---
tags: [lims, arxitektura]
yangilangan: 2026-11-23
---
# Arxitektura

## Repo va muhitlar
- Repo: `github.com/ZuhriddinWeb/lims-sevru` (push faqat foydalanuvchi qiladi).
- Lokal: `E:\ai projects\lims-sevru` — o'zgarishlar shu yerda, `npm run build`, keyin `git add -f public/build`, commit, push.
- Server: kod `git fetch` + `git reset --hard origin/main` bilan olinadi (batafsil [[server-va-deploy]]).
- Yangilanishlar «paket» (zip + `OQISH.txt`) ko'rinishida keladi: [[paketlar-tarixi]].

## Backend (Laravel 11)
- `app/Http/Controllers/Api/` — asosiy; bo'limlar: `Intake/` (buyurtmalar, portal, grafik, sozlamalar), `Svodka/`, `Insights/`, `Interlab/`, `Help/`.
- `app/Services/` — biznes mantiq: `Samples/` (SampleRequests, RequestSchedules, RequestSettings, SampleIntake, SampleSource, CustomerPortal), `Svodka/` (SvodkaService, SvodkaGate), `Worksheets/` (Formula), `Insights/` (SimpleXlsx), `Interlab/`, `Help/`.
- Marshrutlar: `routes/api.php`, `routes/sample_requests.php`, `routes/insights.php` …
- Ruxsatlar: middleware `permission:a|b`; `admin` hamma narsadan o'tadi. Ruxsatlar va menyu bazada (`permissions`, `menu_items`).
- `CustomerPortalFence`: portal foydalanuvchisi faqat `api/portal/*` ga kira oladi; menyuda faqat `portal.*` bandlari; «Grafigim» — bo'linmada grafik yoqilgan bo'lsa.
- Seederlar: `php artisan lims:upgrade` xavfsiz seederlarni chaqiradi (yo'q ruxsat/menyu/ma'lumotni qo'shadi, mavjudiga tegmaydi).
- Migratsiyalar — faqat oldinga (rollback ishlatilmaydi); bekor qilingan narsa yangi migratsiya bilan tozalanadi (masalan P38).

## Rejalashtirgich (scheduler)
- Windows Task Scheduler → har daqiqada `php artisan schedule:run`.
- Vazifalar: `interlab:sync` (har daqiqa), `schedules:tick` (har 5 daqiqa — grafik buyurtmalari, muddat, kechikish xabari), `sample-requests:expire` (har soat — muddat, avtomatik yopish), `sample-requests:expire --remind` (09:10 — yopilmaganlar eslatmasi).
- Rejalashtirgich ishlamasa grafik buyurtmalari **yaratilmaydi**.

## Buyurtma oqimi
`draft` → `submitted` (Yuborilgan) → `partial` (Qisman) / `received` → yopiladi;
`expired` (Muddati o'tgan) → kechiktirib qabul **yoki** sabab bilan yopish (`cancelled`/`rejected`) **yoki** N kundan keyin avtomatik yopish (`auto_closed`).
- Qabulda: kelgan namunalar belgilanadi, «Kim olib keldi», «Kim qabul qilib oldi», «Haqiqiy qabul vaqti» (kechiktirib kiritish).
- Probalar haqiqiy qabul vaqti bilan yoziladi (jamlanma hisobot davri shundan).

## Grafik oqimi (Paket 39)
1. Bo'linmaga grafik yoqiladi («Buyurtma sozlamalari» → Bo'linmalar).
2. Buyurtmachi «Grafigim» da qator tuzadi: vaqt, kunlar, namunalar (shifr = prefiks).
3. Har kuni partiya (`schedule_runs`) tayyorlanadi; grafik vaqtidan N daqiqa oldin oddiy buyurtma (`origin=schedule`) yaratilib yuboriladi.
4. Laboratoriya uni oddiy «Buyurtmalar» da («Grafik 08:00» belgisi) qabul qiladi.
5. Shifr: `prefiks-HH:MM` (takror bo'lsa `/1`, `/2`); proba raqami: `B-YYYY-NNNN-poz`.
6. «Muddati o'tdi»: kun oxiri | smena oxiri | keyingi grafik vaqti | N soat (sozlama).

## Frontend (Vue 3)
- `resources/js/modules/`: `intake` (buyurtmalar, portal, grafik), `worksheets`, `insights`, `interlab`, `help`.
- Tarjimalar har modulda alohida `i18n*.js` fayl, `mergeLocaleMessage` bilan (xabarlarda `|` va `@` ishlatilmaydi).
- Menyu bazadan keladi; ikonlar — lucide nomlari.
- Build: `npm run build` → `public/build` (repoga majburan qo'shiladi).

## Muhim jadvallar
`samples`, `sample_elements`, `sample_requests`, `sample_request_items`, `customer_departments`, `request_schedules`, `schedule_runs`, `close_reasons`, `app_settings` (`sample_requests.settings`), `svodka_*`, `worksheets`, `permissions`, `menu_items`, `activity_logs`.

## Bog'liq
[[00-xulosa]] · [[qarorlar]] · [[server-va-deploy]]
