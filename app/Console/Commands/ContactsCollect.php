<?php

namespace App\Console\Commands;

use App\Models\CustomerContact;
use App\Models\CustomerDepartment;
use App\Services\Samples\CustomerContacts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 34] Bo'linma xodimlari ma'lumotnomasini mavjud buyurtmalardan bir marta to'ldirish.
 *
 *   php artisan lims:kontaktlar          — faqat hisobot: kim topildi, qaysi yozuvlar bitta odam deb birlashadi,
 *                                          qaysilari o'xshash (tekshirish kerak). HECH NARSA O'ZGARMAYDI.
 *   php artisan lims:kontaktlar --yoz    — ma'lumotnomada yo'q xodimlarni qo'shadi (borlariga tegmaydi);
 *                                          o'xshash juftliklar uchun «Bitta odammi?» deb so'raydi (sukut — yo'q)
 *
 * Manba: buyurtmalardagi «Kim olib keladi» + telefon va qabuldagi «Kim olib keldi» + telefon (qoralamalar hisobga
 * olinmaydi). Bitta odam: yozilishi faqat probel/harf kattaligi/kirill-lotin bilan farq qilsa. Nomi — eng ko'p
 * yozilgani, telefon — eng oxirgisi. Buyurtmalardagi ma'lumot O'ZGARTIRILMAYDI.
 */
class ContactsCollect extends Command
{
    protected $signature = 'lims:kontaktlar {--yoz : ma\'lumotnomaga yozish}';

    protected $description = "Bo'linma xodimlari ma'lumotnomasini mavjud buyurtmalardan to'ldirish (avval hisobot)";

    public function handle(): int
    {
        if (! Schema::hasTable('customer_contacts') || ! Schema::hasTable('sample_requests')) {
            $this->error("customer_contacts jadvali yo'q — avval: php artisan migrate");

            return self::FAILURE;
        }
        $hasPhone = Schema::hasColumn('sample_requests', 'handed_over_phone');

        $rows = [];
        $q = DB::table('sample_requests')->where('status', '!=', 'draft')
            ->select(['customer_department_id', 'contact_name', 'contact_phone', 'handed_over_by', 'submitted_at', 'received_at', 'updated_at'])
            ->when($hasPhone, fn ($q) => $q->addSelect('handed_over_phone'))
            ->orderBy('id');
        foreach ($q->cursor() as $r) {
            $rows[] = [$r->customer_department_id, $r->contact_name, $r->contact_phone, $r->submitted_at ?: $r->updated_at];
            if ($r->handed_over_by) {
                $rows[] = [$r->customer_department_id, $r->handed_over_by, $hasPhone ? $r->handed_over_phone : null, $r->received_at ?: $r->updated_at];
            }
        }

        // bo'lim → kalit → {yozilishlar, telefon, soni, oxirgi sana}
        $groups = [];
        foreach ($rows as [$dept, $name, $phone, $at]) {
            $clean = CustomerContacts::cleanName($name);
            $key = CustomerContacts::nameKey($clean);
            if (! $dept || $key === '') {
                continue;
            }
            $g = &$groups[$dept][$key];
            $g['names'][$clean] = ($g['names'][$clean] ?? 0) + 1;
            $g['count'] = ($g['count'] ?? 0) + 1;
            $p = CustomerContacts::phoneValue($phone);
            if ($p && ($at >= ($g['phone_at'] ?? ''))) {
                $g['phone'] = $p;
                $g['phone_at'] = (string) $at;
            }
            $g['last'] = max((string) ($g['last'] ?? ''), (string) $at);
            unset($g);
        }

        if (! $groups) {
            $this->info("Buyurtmalarda «Kim olib keladi» yozuvlari topilmadi.");

            return self::SUCCESS;
        }

        $depts = CustomerDepartment::whereIn('id', array_keys($groups))->pluck('name', 'id');
        $write = (bool) $this->option('yoz');
        $pad = fn (string $s, int $n) => $s . str_repeat(' ', max(1, $n - mb_strlen($s)));
        $added = 0;
        $existing = 0;
        $total = 0;
        foreach ($groups as $dept => $list) {
            $this->line('');
            $this->line('<info>' . ($depts[$dept] ?? "#$dept") . '</info>');
            $have = CustomerContact::where('customer_department_id', $dept)->pluck('name_key')->flip();
            foreach ($list as $key => &$g) {
                arsort($g['names']);
                $g['name'] = array_key_first($g['names']);
            }
            unset($g);
            uasort($list, fn ($a, $b) => $b['count'] <=> $a['count']);

            // o'xshash, lekin har xil kalitli yozuvlar: --yoz bilan — har juftlik so'raladi (sukut: alohida qoladi)
            $keys = array_keys($list);
            foreach ($keys as $i => $a) {
                foreach (array_slice($keys, $i + 1) as $b) {
                    if (! isset($list[$a], $list[$b]) || ! CustomerContacts::similarity(explode(' ', $a), explode(' ', $b))) {
                        continue;
                    }
                    $q = "«{$list[$a]['name']}» ({$list[$a]['count']}) ↔ «{$list[$b]['name']}» ({$list[$b]['count']})";
                    if ($write && ! isset($have[$b]) && $this->input->isInteractive() && $this->confirm("Bitta odammi? {$q} — ha bo'lsa «{$list[$a]['name']}» qoladi", false)) {
                        $list[$a]['count'] += $list[$b]['count'];
                        $list[$a]['names'] += [$list[$b]['name'] => $list[$b]['count']];
                        if (($list[$b]['phone_at'] ?? '') > ($list[$a]['phone_at'] ?? '') && ! empty($list[$b]['phone'])) {
                            $list[$a]['phone'] = $list[$b]['phone'];
                            $list[$a]['phone_at'] = $list[$b]['phone_at'];
                        }
                        $list[$a]['last'] = max($list[$a]['last'], $list[$b]['last']);
                        unset($list[$b]);
                    } else {
                        $this->line("  <comment>o'xshash — tekshiring:</comment> {$q}");
                    }
                }
            }

            foreach ($list as $key => $g) {
                $total++;
                $this->line('  ' . (isset($have[$key]) ? 'bor  ' : 'yangi') . '  ' . $pad($g['name'], 40) . $pad($g['phone'] ?? '—', 20) . sprintf('%3d marta', $g['count']));
                if (count($g['names']) > 1) {
                    $others = array_slice(array_keys($g['names']), 1);
                    $this->line('         bitta odam deb olindi: ' . implode(', ', array_map(fn ($n) => "«{$n}»", $others)));
                }
                if (isset($have[$key])) {
                    $existing++;
                } elseif ($write) {
                    CustomerContact::create([
                        'customer_department_id' => $dept,
                        'full_name'              => $g['name'],
                        'name_key'               => $key,
                        'phone'                  => $g['phone'] ?? null,
                        'use_count'              => $g['count'],
                        'last_used_at'           => $g['last'] ?: null,
                    ]);
                    $added++;
                }
            }
        }

        $this->line('');
        $this->line("Jami xodim: {$total}   ma'lumotnomada bor: {$existing}   " . ($this->option('yoz') ? "qo'shildi: {$added}" : "qo'shiladi: " . ($total - $existing)));
        if (! $this->option('yoz')) {
            $this->line("Bu faqat hisobot — hech narsa o'zgarmadi. Yozish:  php artisan lims:kontaktlar --yoz");
            $this->line("--yoz da har «o'xshash» juftlik uchun «Bitta odammi?» deb so'raladi. Keyin ham «Shablonlarim» → «Bo'linma xodimlari» da tuzatsa bo'ladi.");
        }

        return self::SUCCESS;
    }
}
