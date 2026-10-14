<?php

namespace App\Services\Insights;

use Illuminate\Support\Facades\DB;

/**
 * Sana/vaqt ifodalari — baza turiga qarab (SQL Server, MySQL/MariaDB, PostgreSQL, SQLite).
 * Natija doim bir xil ko'rinishdagi satr: guruhlash va PHP tomonda solishtirish oson.
 *
 * Ustun nomi faqat koddan keladi (foydalanuvchi kiritmaydi).
 */
final class TimeSql
{
    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /** «HH:MM» */
    public static function hm(string $col): string
    {
        return match (self::driver()) {
            'sqlsrv'           => "CONVERT(char(5), {$col}, 108)",
            'mysql', 'mariadb' => "DATE_FORMAT({$col}, '%H:%i')",
            'pgsql'            => "to_char({$col}, 'HH24:MI')",
            default            => "strftime('%H:%M', {$col})",
        };
    }

    /** «YYYY-MM-DD» */
    public static function day(string $col): string
    {
        return match (self::driver()) {
            'sqlsrv'           => "CONVERT(char(10), {$col}, 120)",
            'mysql', 'mariadb' => "DATE_FORMAT({$col}, '%Y-%m-%d')",
            'pgsql'            => "to_char({$col}, 'YYYY-MM-DD')",
            default            => "strftime('%Y-%m-%d', {$col})",
        };
    }

    /** «YYYY-MM-DD HH» */
    public static function hour(string $col): string
    {
        return match (self::driver()) {
            'sqlsrv'           => "CONVERT(char(13), {$col}, 120)",
            'mysql', 'mariadb' => "DATE_FORMAT({$col}, '%Y-%m-%d %H')",
            'pgsql'            => "to_char({$col}, 'YYYY-MM-DD HH24')",
            default            => "strftime('%Y-%m-%d %H', {$col})",
        };
    }

    /** «YYYY-MM» */
    public static function month(string $col): string
    {
        return match (self::driver()) {
            'sqlsrv'           => "CONVERT(char(7), {$col}, 120)",
            'mysql', 'mariadb' => "DATE_FORMAT({$col}, '%Y-%m')",
            'pgsql'            => "to_char({$col}, 'YYYY-MM')",
            default            => "strftime('%Y-%m', {$col})",
        };
    }

    /** Ikki vaqt orasidagi daqiqalar (butun son) */
    public static function minutes(string $from, string $to): string
    {
        return match (self::driver()) {
            'sqlsrv'           => "DATEDIFF(minute, {$from}, {$to})",
            'mysql', 'mariadb' => "TIMESTAMPDIFF(MINUTE, {$from}, {$to})",
            'pgsql'            => "CAST(EXTRACT(EPOCH FROM ({$to} - {$from})) / 60 AS INTEGER)",
            default            => "CAST(ROUND((julianday({$to}) - julianday({$from})) * 1440) AS INTEGER)",
        };
    }

    public static function bucket(string $col, string $bucket): string
    {
        return match ($bucket) {
            'hour'  => self::hour($col),
            'month' => self::month($col),
            default => self::day($col),
        };
    }
}
