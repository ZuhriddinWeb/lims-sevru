<?php

namespace App\Services\Worksheets;

/**
 * Xavfsiz formula hisoblagichi (eval ishlatilmaydi).
 *
 * Sintaksis:
 *   sonlar:        12, 0.5, .25          (o'nlik ajratgich — nuqta)
 *   o'zgaruvchi:   kortochka, mass, ag_added   (ustun yoki parametr kaliti)
 *   amallar:       +  -  *  /  ^  ( )
 *   funksiyalar:   round(x, n)  trunc(x, n)  floor(x)  ceil(x)  abs(x)  sqrt(x)  pow(a, b)
 *                  min(...)  max(...)  avg(...)  sum(...)  count(...)
 *
 * Qator formulasida o'zgaruvchi — shu qatorning qiymati (son).
 * Yakuniy (summary) formulada qator ustuni — barcha parallellar ro'yxati,
 * shuning uchun u avg(), sum(), min(), max(), count() ichida ishlatiladi.
 *
 * Bo'sh qiymat (null) arifmetikada null beradi; nolga bo'lish — null.
 * JS'dagi nusxa: resources/js/modules/worksheets/engine.js (bir xil natija).
 */
final class Formula
{
    public const MAX_LENGTH = 500;
    private const MAX_DEPTH = 64;

    public const FUNCTIONS = [
        'round', 'trunc', 'floor', 'ceil', 'abs', 'sqrt', 'pow',
        'min', 'max', 'avg', 'sum', 'count',
    ];

    private const AGGREGATES = ['min', 'max', 'avg', 'sum', 'count'];

    /** @var array<int, array{0:string,1:mixed,2:int}> */
    private array $tokens = [];
    private int $pos = 0;
    private int $depth = 0;

    // ------------------------------------------------------------------ parse

    /**
     * @return array AST
     * @throws FormulaException
     */
    public static function parse(string $source): array
    {
        $source = trim($source);
        if ($source === '') {
            throw new FormulaException("Formula bo'sh");
        }
        if (mb_strlen($source) > self::MAX_LENGTH) {
            throw new FormulaException('Formula juda uzun (' . self::MAX_LENGTH . ' belgidan oshmasin)');
        }

        $p = new self();
        $p->tokens = self::tokenize($source);
        $ast = $p->parseExpression();

        if ($p->peek()[0] !== 'end') {
            $tok = $p->peek();
            throw new FormulaException(self::unexpected($tok));
        }

        return $ast;
    }

    /** Formuladagi barcha o'zgaruvchi nomlari (funksiyalar emas). */
    public static function identifiers(array $ast): array
    {
        $out = [];
        $walk = function (array $node) use (&$walk, &$out) {
            switch ($node[0]) {
                case 'v':
                    $out[$node[1]] = true;
                    break;
                case 'u':
                    $walk($node[2]);
                    break;
                case 'b':
                    $walk($node[2]);
                    $walk($node[3]);
                    break;
                case 'f':
                    foreach ($node[2] as $arg) {
                        $walk($arg);
                    }
                    break;
            }
        };
        $walk($ast);

        return array_keys($out);
    }

    private static function tokenize(string $s): array
    {
        $tokens = [];
        $len = strlen($s);
        $i = 0;

        while ($i < $len) {
            $ch = $s[$i];

            if (ctype_space($ch)) {
                $i++;
                continue;
            }

            if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $len && ctype_digit($s[$i + 1]))) {
                $start = $i;
                while ($i < $len && ctype_digit($s[$i])) {
                    $i++;
                }
                if ($i < $len && $s[$i] === '.') {
                    $i++;
                    while ($i < $len && ctype_digit($s[$i])) {
                        $i++;
                    }
                }
                $tokens[] = ['num', (float) substr($s, $start, $i - $start), $start];
                continue;
            }

            if (ctype_alpha($ch) || $ch === '_') {
                $start = $i;
                while ($i < $len && (ctype_alnum($s[$i]) || $s[$i] === '_')) {
                    $i++;
                }
                $tokens[] = ['id', strtolower(substr($s, $start, $i - $start)), $start];
                continue;
            }

            if (strpos('+-*/^(),', $ch) !== false) {
                $tokens[] = ['op', $ch, $i];
                $i++;
                continue;
            }

            throw new FormulaException('Ruxsat etilmagan belgi: «' . $ch . '» (' . ($i + 1) . '-pozitsiya)');
        }

        $tokens[] = ['end', null, $len];

        return $tokens;
    }

    private function peek(): array
    {
        return $this->tokens[$this->pos];
    }

    private function next(): array
    {
        return $this->tokens[$this->pos++];
    }

    private function isOp(string $op): bool
    {
        $t = $this->peek();

        return $t[0] === 'op' && $t[1] === $op;
    }

    private function expectOp(string $op): void
    {
        $t = $this->next();
        if ($t[0] !== 'op' || $t[1] !== $op) {
            throw new FormulaException('«' . $op . '» kutilgan edi — ' . self::unexpected($t));
        }
    }

    private static function unexpected(array $t): string
    {
        if ($t[0] === 'end') {
            return 'formula kutilmaganda tugadi';
        }
        $text = $t[0] === 'num' ? (string) $t[1] : (string) $t[1];
        $hint = $text === ',' ? " (o'nlik son uchun nuqta ishlating: 0.5)" : '';

        return 'kutilmagan «' . $text . '» (' . ($t[2] + 1) . '-pozitsiya)' . $hint;
    }

    private function enter(): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new FormulaException('Formula juda chuqur ichma-ich');
        }
    }

    private function leave(): void
    {
        $this->depth--;
    }

    private function parseExpression(): array
    {
        $this->enter();
        $left = $this->parseTerm();
        while ($this->isOp('+') || $this->isOp('-')) {
            $op = $this->next()[1];
            $left = ['b', $op, $left, $this->parseTerm()];
        }
        $this->leave();

        return $left;
    }

    private function parseTerm(): array
    {
        $left = $this->parseUnary();
        while ($this->isOp('*') || $this->isOp('/')) {
            $op = $this->next()[1];
            $left = ['b', $op, $left, $this->parseUnary()];
        }

        return $left;
    }

    private function parseUnary(): array
    {
        if ($this->isOp('-') || $this->isOp('+')) {
            $op = $this->next()[1];
            $this->enter();
            $operand = $this->parseUnary();
            $this->leave();

            return $op === '-' ? ['u', '-', $operand] : $operand;
        }

        return $this->parsePower();
    }

    private function parsePower(): array
    {
        $base = $this->parsePrimary();
        if ($this->isOp('^')) {
            $this->next();
            $this->enter();
            $exp = $this->parseUnary();
            $this->leave();

            return ['b', '^', $base, $exp];
        }

        return $base;
    }

    private function parsePrimary(): array
    {
        $t = $this->next();

        if ($t[0] === 'num') {
            return ['n', $t[1]];
        }

        if ($t[0] === 'id') {
            if ($this->isOp('(')) {
                if (! in_array($t[1], self::FUNCTIONS, true)) {
                    throw new FormulaException("Noma'lum funksiya: «{$t[1]}»");
                }
                $this->next();
                $args = [];
                if (! $this->isOp(')')) {
                    $args[] = $this->parseExpression();
                    while ($this->isOp(',')) {
                        $this->next();
                        $args[] = $this->parseExpression();
                    }
                }
                $this->expectOp(')');

                return ['f', $t[1], $args];
            }
            if (in_array($t[1], self::FUNCTIONS, true)) {
                throw new FormulaException("«{$t[1]}» — funksiya, undan keyin qavs kerak: {$t[1]}(…)");
            }

            return ['v', $t[1]];
        }

        if ($t[0] === 'op' && $t[1] === '(') {
            $inner = $this->parseExpression();
            $this->expectOp(')');

            return $inner;
        }

        throw new FormulaException(self::unexpected($t));
    }

    // --------------------------------------------------------------- evaluate

    /**
     * @param  array  $ast
     * @param  array<string, float|null|array<int, float|null>>  $vars
     * @return float|null
     * @throws FormulaException
     */
    public static function evaluate(array $ast, array $vars): ?float
    {
        $v = self::evalNode($ast, $vars);
        if (is_array($v)) {
            throw new FormulaException(
                "Natija bitta son bo'lishi kerak — ustun ro'yxatini avg(), sum(), min(), max() yoki count() ichida ishlating"
            );
        }

        return $v;
    }

    /** @return float|null|array */
    private static function evalNode(array $node, array $vars)
    {
        switch ($node[0]) {
            case 'n':
                return (float) $node[1];

            case 'v':
                if (! array_key_exists($node[1], $vars)) {
                    throw new FormulaException("Noma'lum o'zgaruvchi: «{$node[1]}»");
                }
                $val = $vars[$node[1]];

                return is_array($val) ? array_values($val) : ($val === null ? null : (float) $val);

            case 'u':
                $x = self::evalNode($node[2], $vars);
                self::assertScalar($x, $node[2]);

                return $x === null ? null : -$x;

            case 'b':
                $a = self::evalNode($node[2], $vars);
                $b = self::evalNode($node[3], $vars);
                self::assertScalar($a, $node[2]);
                self::assertScalar($b, $node[3]);
                if ($a === null || $b === null) {
                    return null;
                }

                switch ($node[1]) {
                    case '+':
                        $r = $a + $b;
                        break;
                    case '-':
                        $r = $a - $b;
                        break;
                    case '*':
                        $r = $a * $b;
                        break;
                    case '/':
                        if ($b == 0.0) {
                            return null;
                        }
                        $r = $a / $b;
                        break;
                    default: // ^
                        $r = $a ** $b;
                }

                return is_finite($r) ? (float) $r : null;

            case 'f':
                return self::call($node[1], $node[2], $vars);
        }

        throw new FormulaException("Noto'g'ri formula tuzilmasi");
    }

    private static function assertScalar($value, array $node): void
    {
        if (is_array($value)) {
            $name = $node[0] === 'v' ? "«{$node[1]}»" : 'ifoda';
            throw new FormulaException(
                "{$name} — bir nechta qator qiymati; uni avg(), sum(), min(), max() yoki count() ichida ishlating"
            );
        }
    }

    private static function call(string $fn, array $argNodes, array $vars)
    {
        $args = array_map(fn ($n) => self::evalNode($n, $vars), $argNodes);

        if (in_array($fn, self::AGGREGATES, true)) {
            if (count($args) === 0) {
                throw new FormulaException("{$fn}() kamida bitta argument talab qiladi");
            }
            $flat = [];
            foreach ($args as $a) {
                foreach (is_array($a) ? $a : [$a] as $x) {
                    if ($x !== null) {
                        $flat[] = (float) $x;
                    }
                }
            }
            switch ($fn) {
                case 'count':
                    return (float) count($flat);
                case 'sum':
                    return $flat ? array_sum($flat) : null;
                case 'avg':
                    return $flat ? array_sum($flat) / count($flat) : null;
                case 'min':
                    return $flat ? min($flat) : null;
                default:
                    return $flat ? max($flat) : null;
            }
        }

        foreach ($args as $i => $a) {
            self::assertScalar($a, $argNodes[$i]);
        }

        $arity = [
            'round' => [1, 2], 'trunc' => [1, 2], 'floor' => [1, 1], 'ceil' => [1, 1],
            'abs' => [1, 1], 'sqrt' => [1, 1], 'pow' => [2, 2],
        ][$fn];
        if (count($args) < $arity[0] || count($args) > $arity[1]) {
            $need = $arity[0] === $arity[1] ? $arity[0] : "{$arity[0]}–{$arity[1]}";
            throw new FormulaException("{$fn}() {$need} ta argument qabul qiladi");
        }

        $x = $args[0];
        if ($x === null) {
            return null;
        }

        switch ($fn) {
            case 'round':
            case 'trunc':
                $n = $args[1] ?? 0.0;
                if ($n === null) {
                    return null;
                }
                if ($n < 0 || $n > 10 || floor($n) != $n) {
                    throw new FormulaException("{$fn}() ning ikkinchi argumenti 0 dan 10 gacha butun son bo'lishi kerak");
                }

                return Rounding::apply($x, (int) $n, $fn);
            case 'floor':
                return floor($x);
            case 'ceil':
                return ceil($x);
            case 'abs':
                return abs($x);
            case 'sqrt':
                return $x < 0 ? null : sqrt($x);
            default: // pow
                if ($args[1] === null) {
                    return null;
                }
                $r = $x ** $args[1];

                return is_finite($r) ? (float) $r : null;
        }
    }
}
