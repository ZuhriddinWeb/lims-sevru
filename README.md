# LIMS — ЦНИЛ (СевРУ)

Laboratoriya axborot boshqaruv tizimi. Laravel 11 (API) + Vue 3 (SPA).

Texnik topshiriqdagi 4 ta laboratoriya uchun: oltin bo'yicha texnologik, geologik,
analitik va suv muammolari laboratoriyalari.

---

## 1. O'rnatish

Talablar: **PHP 8.2+**, **Composer**, **Node 18+**, **MySQL 8** (yoki PostgreSQL/SQLite).

```bash
# 1. Bog'liqliklarni o'rnatish
composer install
npm install

# 2. Sozlamalar
cp .env.example .env
php artisan key:generate
```

`.env` faylida bazani ko'rsating:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lims_sevru
DB_USERNAME=root
DB_PASSWORD=
```

```bash
# 3. Jadvallar va boshlang'ich ma'lumotlar
php artisan migrate --seed

# 4. Fayllar uchun havola
php artisan storage:link

# 5. Ishga tushirish (ikki terminal)
php artisan serve     # http://localhost:8000
npm run dev           # Vite
```

Ishlab chiqarish uchun: `npm run build`, keyin veb-server `public/` papkasiga yo'naltiriladi.

---

## 2. Kirish ma'lumotlari

Seeder quyidagi hisoblarni yaratadi:

| Login      | Parol       | Rol                      | Nima qila oladi                              |
|------------|-------------|--------------------------|----------------------------------------------|
| `admin`    | `admin123`  | Administrator            | Hammasi: rollar, menyu, foydalanuvchilar     |
| `alijon`   | `lims12345` | Laboratoriya boshlig'i   | Barcha lab modullari + kataloglar + audit    |
| `operator` | `lims12345` | Operator (ro'yxat)       | Proba ro'yxatga olish va uzatish             |
| `spectr`   | `lims12345` | Analitik (spektral)      | Faqat natija kiritish                        |
| `chem`     | `lims12345` | Analitik (kimyoviy)      | Faqat natija kiritish                        |
| `water`    | `lims12345` | Suv laboratoriyasi       | Suv/havo natijalari + AI tahlil              |

Birinchi kirishdan keyin parollarni almashtiring.

---

## 3. Ruxsat tizimi

Ruxsat **modul × amal** ko'rinishida: `samples.view`, `samples.create`, `samples.update`,
`samples.delete`, `samples.result` va h.k.

Qayerda tekshiriladi:

1. **Marshrut** — `routes/api.php` dagi `permission:` middleware (`app/Http/Middleware/CheckPermission.php`)
2. **Menyu** — `/api/menu` faqat ruxsat berilgan bandlarni qaytaradi
3. **Interfeys** — `auth.can('samples.create')` tugmalarni yashiradi
4. **URL** — Vue router guard to'g'ridan-to'g'ri kirishni ham bloklaydi

Rollarni boshqarish: **Administratsiya → Rollar va ruxsatlar**. U yerdagi matritsada
har bir modul qatori va amal ustuni kesishmasida katakcha bor — belgilansa ruxsat beriladi.
Modul nomiga bosish butun qatorni belgilaydi.

Yangi rol qo'shish uchun kod o'zgartirish shart emas — interfeysdan yaratiladi.

---

## 4. Menyuni boshqarish

Menyu bazadan o'qiladi (`menu_items` jadvali). **Administratsiya → Menyu** bo'limida
band qo'shish, nomini uch tilda yozish, ikonka (lucide nomi, masalan `flask-conical`),
marshrut nomi, kerakli ruxsat va tartibni belgilash mumkin. `is_active` o'chirilsa band yashiriladi.

---

## 5. Universal CRUD tizimi

Modullar bitta dvigatel ustida ishlaydi. Yangi bo'lim qo'shish uchun uch qadam:

**1-qadam.** Resurs ta'rifini yozing (`resources/js/resources/lab.js` yoki `admin.js`):

```js
export const reagentResource = {
  key: 'reagents',
  endpoint: '/reagents',
  permission: 'reagents',          // reagents.view / .create / .update / .delete
  titleKey: 'reagent.title',
  columns: [
    { key: 'name', labelKey: 'reagent.name', primary: true, sortable: true },
    { key: 'status', labelKey: 'status.label', type: 'status' },
    { key: 'expires_at', labelKey: 'reagent.expires', type: 'date' },
  ],
  filters: [
    { key: 'status', type: 'select', labelKey: 'status.label', options: [...] },
  ],
  form: {
    sections: [{ fields: [
      { key: 'name', type: 'text', labelKey: 'reagent.name', required: true, col: 2 },
      { key: 'laboratory_id', type: 'relation', source: 'laboratories', labelKey: 'catalog.laboratory' },
    ]}],
  },
}
```

**2-qadam.** `resources/js/resources/index.js` registriga qo'shing.

**3-qadam.** Routerga yozing:

```js
{
  path: 'reagents', name: 'reagents',
  component: () => import('@/views/ResourcePage.vue'),
  meta: { permission: 'reagents.view', resource: 'reagents' },
}
```

Jadval, qidiruv, filtrlar, saralash, sahifalash, forma, tahrirlash, o'chirish tasdig'i,
ruxsat tekshiruvi va mobil ko'rinish avtomatik ishlaydi.

### Maydon turlari

`text` · `textarea` · `number` · `date` · `datetime-local` · `password` · `email` · `tel`
· `select` (statik variantlar) · `relation` (katalogdan) · `multiselect` (bir nechta tanlov)
· `switch` (ha/yo'q)

Foydali xossalar: `required`, `col: 2` (ikki ustunni egallaydi), `hintKey`, `default`,
`filterOptions` (boshqa maydonga bog'liq ro'yxat — masalan laboratoriya tanlanganda
faqat uning guruhlari ko'rsatiladi), `optionLabel`.

### Ustun turlari

`date` · `datetime` · `number` · `status` (rangli nishon) · `localized` (ko'p tilli nom)
· `enum` · `bool`, hamda `format(row, { t, locale })` — ixtiyoriy formatlash funksiyasi.

---

## 6. Til va mavzu

Uch til: **ru / uz / en**. Yuqori panel yoki profil sahifasidan almashtiriladi,
tanlov foydalanuvchi profilida saqlanadi (`users.locale`).

Tarjimalar: `resources/js/i18n/locales/{ru,uz,en}.js`. Uchala fayl bir xil tuzilishga ega.

Kun/tun rejimi `users.theme` da saqlanadi va sahifa yuklanishidan oldin qo'llanadi
(`app.blade.php` dagi kichik skript orqali miltillash yo'q qilingan).

Kataloglardagi nomlar bazada uch tilda saqlanadi (`name_ru`, `name_uz`, `name_en`) —
interfeys joriy tilga mosini tanlaydi.

---

## 7. Proba harakati (TZ bo'yicha)

```
Texnologik laboratoriya (Gravitatsiya / Sianlash guruhi)
  │  probani qabul qiladi, shifr beradi (PR-YYYYMM-NNNN)
  │  hujjat raqami, bo'linma, agregat holati, elementlar belgilanadi
  ↓
Analitik laboratoriya (Spektral / Kimyoviy guruh)
  │  "Faqat mening probalarim" filtri bilan o'z ishini ko'radi
  │  har bir element bo'yicha natija kiritadi (sana/vaqt avtomatik)
  ↓
Natijalar to'liq bo'lganda status avtomatik "Yakunlangan" ga o'tadi → arxiv
```

Probalar `period_month` maydoni orqali oylarga ajratilgan — filtrdan oy tanlanadi.
Qidiruv shifr, proba raqami yoki sana oralig'i bo'yicha ishlaydi.

---

## 8. Uskunalar

- Kalibrovka/tekshiruv tarixi, sertifikat raqami va amal qilish muddati
- Texnik xizmat, ta'mirlash, komponent almashtirish tarixi va qiymati
- Holatlar: ishchi / xizmatda / ishdan chiqqan / kalibrovka kutmoqda
- **Bloklash**: kalibrovkadan o'tmagan asbob bloklanadi (`failed` natija kiritilsa avtomatik)
- Bosh sahifada 30 kun ichidagi kalibrovkalar eslatmasi, kechikkanlari qizil rangda

---

## 9. AI tahlil

**AI tahlil** bo'limi suv/havo monitoring natijalarini vaqt bo'yicha tahlil qiladi:
o'rtacha, minimum, maksimum, foizdagi o'zgarish, tendensiya (barqaror/o'smoqda/kamaymoqda)
va umumiy xulosa — *me'yorda* / *e'tibor talab etadi* / *yomonlashish*.

Standart holatda hisob-kitob serverda lokal bajariladi (tashqi xizmat shart emas).
Tashqi LLM ulamoqchi bo'lsangiz `.env` da:

```env
AI_PROVIDER=anthropic
AI_API_KEY=...
AI_BASE_URL=...
AI_MODEL=claude-sonnet-4-6
```

va `app/Http/Controllers/Api/AiAssistantController.php` dagi `callExternalAi()`
metodini to'ldiring.

---

## 10. Loyiha tuzilishi

```
app/
  Http/Controllers/Api/     18 ta kontroller (CRUD + biznes-mantiq)
  Http/Middleware/          CheckPermission — ruxsat tekshiruvi
  Models/                   18 ta Eloquent model
database/
  migrations/               11 ta migratsiya
  seeders/                  ruxsatlar, rollar, laboratoriyalar, elementlar, menyu, foydalanuvchilar
resources/
  js/
    components/crud/        DataTable · FormField · CrudPage (universal dvigatel)
    components/ui/          BaseModal · ConfirmDialog · StatusBadge · Pagination · ToastHost · ...
    composables/useCrud.js  ro'yxat + CRUD holati
    resources/              modul ta'riflari (shu yerga yangi bo'lim qo'shiladi)
    stores/                 auth (ruxsatlar) · ui (mavzu, til, bildirishnoma) · catalogs (kesh)
    i18n/locales/           ru · uz · en
    views/                  sahifalar
    router/                 marshrutlar va ruxsat guard'lari
routes/api.php              barcha endpointlar ruxsatlar bilan
```

---

## 11. Audit

Har bir yaratish/o'zgartirish/o'chirish va kirish/chiqish `activity_logs` jadvaliga yoziladi:
kim, qachon, qaysi obyekt, qanday maydonlar, IP manzil.
Ko'rish: **Administratsiya → Audit jurnali**.

---

## 12. Keyingi qadamlar

Tizim ishga tushgach foydali bo'ladigan qo'shimchalar:

- Hisobotlarni Excel/PDF ga chiqarish (probalar reyestri, natijalar bayonnomasi)
- Uskuna hujjatlarini yuklash (jadval va API tayyor — `equipment_documents`)
- Kalibrovka muddati yaqinlashganda elektron pochta/Telegram xabarnomasi
- Natijalarni tasdiqlash bosqichi (elektron imzo)
