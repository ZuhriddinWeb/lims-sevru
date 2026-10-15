<?php

namespace App\Services\Help;

/**
 * Yordamchi: matnni solishtirish uchun tayyorlash (Laravelga bog'liq emas — CLI da sinab ko'rish mumkin).
 *
 * Bir xil qoida savolga ham, bazadagi matnlarga ham qo'llanadi, shuning uchun:
 *   - katta/kichik harf, apostrof turlari (o', o‘, oʻ, o`) farq qilmaydi;
 *   - kirill yozuv lotinga o'giriladi («намуна» = «namuna», «пароль» = «parol»);
 *   - bo'sh so'zlar («qanday», «как», «how») tashlanadi;
 *   - bir ma'noli so'zlar bitta shaklga keltiriladi (proba/образец/sample → namuna).
 */
final class HelpText
{
    private const CYR = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'j',
        'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh', 'ъ' => '', 'ы' => 'i', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya', 'ў' => 'o', 'қ' => 'q', 'ғ' => 'g', 'ҳ' => 'h',
    ];

    /** Apostrof o'rnida ishlatiladigan belgilar — olib tashlanadi */
    private const APOS = ["'", '’', '‘', 'ʻ', 'ʼ', '`', '´', '′'];

    private const STOP = [
        // o'zbekcha
        'va', 'bilan', 'uchun', 'qanday', 'qanaqa', 'qanaqasiga', 'nima', 'nimaga', 'nimani', 'qayerda', 'qaerda',
        'qayer', 'bu', 'shu', 'bor', 'mi', 'ham', 'hamda', 'men', 'man', 'menga', 'meni', 'bizda', 'kerak', 'kerakmi',
        'bolad', 'boladi', 'bolsa', 'qilaman', 'qilish', 'yoki', 'lekin', 'ya', 'u', 'ular', 'qaysi', 'qancha',
        'nega', 'nimadan', 'qanday', 'degan', 'deb', 'yana', 'endi', 'hozir',
        // ruscha
        'kak', 'chto', 'eto', 'gde', 'dlya', 'i', 'v', 'na', 's', 'po', 'a', 'ili', 'li', 'mne', 'mogu', 'menya',
        'zachem', 'kakoy', 'kakaya', 'kakie', 'chem', 'pri', 'iz', 'ot', 'do', 'ne', 'k', 'u', 'o', 'est', 'byt',
        'nujno', 'mojno', 'skolko', 'kogda', 'ego', 'ee', 'eto', 'etot', 'eta',
        // inglizcha
        'how', 'what', 'is', 'the', 'an', 'do', 'to', 'of', 'in', 'on', 'my', 'can', 'does', 'where', 'for', 'and',
        'or', 'it', 'are', 'be', 'this', 'that', 'with', 'i', 'me', 'we', 'should', 'would', 'which', 'when', 'why',
        'there', 'from', 'by', 'at', 'as', 'if',
    ];

    /** Boshlanishi bo'yicha bir ma'noli so'zlarni bitta shaklga keltirish: [regex => kanonik] */
    private const CANON = [
        '/^(prob(?!l|ir)|obraz|sampl|namun)/' => 'namuna',
        '/^(parol|passw)/' => 'parol',
        '/^(ruxsat|razresh|permis|prav(?:a|o)$|rights?$|dostup)/' => 'ruxsat',
        '/^(ochir|udal|delet|remov|ochirib)/' => 'ochir',
        '/^(qosh|dobav|add$|adding)/' => 'qosh',
        '/^(yarat|sozd|creat)/' => 'yarat',
        '/^(ozgar|izmen|chang|edit|tahrir|redakt)/' => 'ozgar',
        '/^(natij|rezult|result)/' => 'natija',
        '/^(varaq|worksheet|rabochi)/' => 'varaq',
        '/^(list$|lista$|listy$|listov$|listu$|sheet)/' => 'varaq',
        '/^(uskun|oborud|equip|asbob|pribor|instrum)/' => 'uskuna',
        '/^(foydalanuvch|polzovat|users?$)/' => 'foydalanuvchi',
        '/^(xodim|sotrudnik|employee|staff)/' => 'xodim',
        '/^(buyurtmach|zakazchik|customer|klient|client)/' => 'buyurtmachi',
        '/^(bolim|podrazdel|department)/' => 'bolim',
        '/^(savat|korzin|trash|recycle)/' => 'savatcha',
        '/^(hisobot|otchet|report)/' => 'hisobot',
        '/^(tasdiq|utverd|approv)/' => 'tasdiq',
        '/^(smena|smen$|smeny|smenu|shift)/' => 'smena',
        '/^(excel|eksport|export|vygruz)/' => 'excel',
        '/^(kalibr|calibr)/' => 'kalibr',
        '/^(qoldiq|ostat|balans|balance|remaining)/' => 'qoldiq',
        '/^(menyu|menu)/' => 'menyu',
        '/^(jurnal|audit|history$|istoriy|tarix)/' => 'jurnal',
        '/^(tikla|vosstan|restor|qaytar|vernut|vozvrat)/' => 'tikla',
        '/^(imzo|podpis|signoff|sign$|signature)/' => 'imzo',
        '/^(takror|povtor|parallel|retest|perezamer)/' => 'takror',
        '/^(chop|pechat|print)/' => 'chop',
        '/^(tekshir|proverk|prover|check|verif)/' => 'tekshir',
        '/^(shablon|template)/' => 'shablon',
        '/^(laborator)/' => 'laboratoriya',
        '/^(guruh|gruppa|gruppy|group)/' => 'guruh',
        '/^(element|pokazatel|parametr|parameter)/' => 'element',
        '/^(suv$|voda|vodn|water)/' => 'suv',
        '/^(kir(?!it)|kira|vxod|vhod|login|signin|voyti|vojti)/' => 'kirish',
        '/^(muammo|problem|oshibk|error|xato|nosoz|sboy)/' => 'xato',
        '/^(yangi|noviy|novog|novuyu|novye|novaya|new$)/' => 'yangi',
        '/^(chiqish|vyxod|vyhod|logout|signout)/' => 'chiqish',
    ];

    public static function stopWords(): array
    {
        static $set = null;

        return $set ??= array_flip(self::STOP);
    }

    /** «Qo'shish» → «qoshish»; «Пароль» → «parol»; ko'p bo'shliq → bitta */
    public static function fold(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        $t = str_replace(self::APOS, '', $t);
        $t = strtr($t, self::CYR);

        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^a-z0-9]+/u', ' ', $t)));
    }

    /** Bitta so'zni kanonik shaklga keltirish (maydalash emas — faqat bir ma'noli so'zlarni birlashtirish) */
    public static function canon(string $token): string
    {
        foreach (self::CANON as $re => $to) {
            if (preg_match($re, $token)) {
                return $to;
            }
        }

        return $token;
    }

    /**
     * Matn → noyob bo'lmagan so'zlar ro'yxati (tartib saqlanadi, bo'sh so'zlar tashlangan).
     *
     * @return string[]
     */
    public static function tokens(string $text): array
    {
        $folded = self::fold($text);
        if ($folded === '') {
            return [];
        }
        // ikki so'zli iboralar bitta so'z sifatida («sign in» → «login»; «sign» imzo ma'nosiga ham ega)
        $folded = preg_replace(['/\b(sign|log) ?in\b/', '/\b(sign|log) ?out\b/'], ['login', 'logout'], $folded);
        $stop = self::stopWords();
        $out = [];
        foreach (explode(' ', $folded) as $w) {
            if ($w === '' || isset($stop[$w])) {
                continue;
            }
            // bir harfli so'zlar (va raqamlarsiz) ma'no ko'tarmaydi
            if (strlen($w) < 2 && ! ctype_digit($w)) {
                continue;
            }
            $out[] = self::canon($w);
        }

        return $out;
    }

    /** Ikki so'zning o'xshashligi: 1 (bir xil), 0.85 (o'zak bir xil), 0.8 (bitta xato), 0 (boshqa) */
    public static function likeness(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        $la = strlen($a);
        $lb = strlen($b);
        $min = min($la, $lb);
        if ($min >= 4) {
            $common = 0;
            $n = $min;
            while ($common < $n && $a[$common] === $b[$common]) {
                $common++;
            }
            // o'zagi bir xil: namuna~namunalar, parol~parolni, ozgartirish~ozgartiraman
            if ($common >= 4 && $common >= (int) ceil($min * 0.6)) {
                return 0.85;
            }
        }
        if ($min >= 5 && abs($la - $lb) <= 2) {
            $d = levenshtein($a, $b);
            if ($d <= 1 || ($min >= 9 && $d <= 2)) {
                return 0.8;
            }
        }

        return 0.0;
    }
}
