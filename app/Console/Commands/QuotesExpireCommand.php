<?php

namespace App\Console\Commands;

use App\Services\Sales\QuoteExpiry;
use Illuminate\Console\Command;

/** Expire Internet-provider quotations past their validity date (QuoteExpiry). Scheduled daily. */
class QuotesExpireCommand extends Command
{
    protected $signature = 'quotes:expire';

    protected $description = 'Mark quotations past their validity date as expired (Internet-provider businesses)';

    public function handle(QuoteExpiry $expiry): int
    {
        $this->info('Expired ' . $expiry->run() . ' quotation(s).');

        return self::SUCCESS;
    }
}
