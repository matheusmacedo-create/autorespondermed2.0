<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Dias úteis (segunda a sexta) entre duas datas, como a política de entrega das
 * lojas conta o prazo. Feriados não entram: o número sai igual ou menor que o
 * real, nunca a favor da defesa.
 */
class BusinessDays
{
    public static function between(CarbonInterface $from, CarbonInterface $to): int
    {
        $day = $from->copy()->startOfDay()->addDay();
        $end = $to->copy()->startOfDay();
        $count = 0;

        while ($day->lte($end)) {
            $count += $day->isWeekday() ? 1 : 0;
            $day->addDay();
        }

        return $count;
    }
}
