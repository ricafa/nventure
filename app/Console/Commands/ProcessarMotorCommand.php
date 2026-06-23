<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ServicoMotor;
use Illuminate\Console\Command;

class ProcessarMotorCommand extends Command
{
    protected $signature = 'motor:processar {--data= : Data YYYY-MM-DD (default: hoje)}';

    protected $description = 'Processa a marcação a mercado (MtM) das posições abertas para a data.';

    public function handle(ServicoMotor $motor): int
    {
        try {
            $dataParam = $this->option('data');
            // se o usuário passou --data vazio (não string), assume false e vira null
            $dataStr = is_string($dataParam) ? $dataParam : 'today';
            $data = new \DateTimeImmutable($dataStr);
        } catch (\Exception $e) {
            $this->error('Data malformada. Use o formato YYYY-MM-DD.');

            return self::FAILURE;
        }

        $resumo = $motor->processar($data, 'agendador');

        $this->info(sprintf(
            'Motor #%d · %s · %d/%d sucessos · %d falhas',
            $resumo->execucaoId,
            $resumo->dataCalculo,
            $resumo->sucessos,
            $resumo->posicoesProcessadas,
            count($resumo->falhas)
        ));

        return self::SUCCESS;
    }
}
