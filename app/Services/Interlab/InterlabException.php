<?php

namespace App\Services\Interlab;

use RuntimeException;

/**
 * Modul xatosi. $status — HTTP kodi (kiruvchi xabarlarda jo'natuvchiga qaytariladi).
 *   400/401/403/404/409/422 — doimiy (qayta yuborish foydasiz)
 *   423/429/5xx             — vaqtinchalik (jo'natuvchi keyinroq qayta yuboradi)
 */
class InterlabException extends RuntimeException
{
    public function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function retryable(): bool
    {
        return in_array($this->status, [423, 429], true) || $this->status >= 500;
    }
}
