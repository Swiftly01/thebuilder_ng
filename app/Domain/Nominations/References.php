<?php

declare(strict_types=1);

namespace App\Domain\Nominations;

use Illuminate\Support\Facades\DB;

final class References
{
    public function next(string $kind): string
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('References require a transaction.');
        }$year = now()->year;
        $key = $kind.'-'.$year;
        DB::table('reference_counters')->insertOrIgnore(['key' => $key, 'value' => 0]);
        $row = DB::table('reference_counters')->where('key', $key)->lockForUpdate()->first();
        $value = $row->value + 1;
        DB::table('reference_counters')->where('key', $key)->update(['value' => $value]);

        return sprintf('TB-%s-%d-%06d', $kind, $year, $value);
    }
}
