<?php

namespace App\Console\Commands;

use App\Services\Help\HelpAssistant;
use Illuminate\Console\Command;

/**
 * php artisan help:ask "parolni unutdim" --locale=uz
 * Yordamchini konsoldan sinash (ruxsat tekshirilmaydi; javob topilmasa savol «javobsiz» jurnaliga ham yoziladi).
 */
class HelpAsk extends Command
{
    protected $signature = 'help:ask {question} {--locale=uz} {--route=}';

    protected $description = 'Yordamchiga konsoldan savol berish (sinash uchun)';

    public function handle(HelpAssistant $help): int
    {
        $res = $help->ask(null, (string) $this->argument('question'), (string) $this->option('locale'), $this->option('route') ?: null);
        $this->line('Holat: '.$res['status']);
        if ($res['answer']) {
            $this->info($res['answer']['question']);
            $this->line($res['answer']['text']);
        }
        foreach ($res['related'] as $r) {
            $this->line('  • '.$r['question']);
        }

        return self::SUCCESS;
    }
}
