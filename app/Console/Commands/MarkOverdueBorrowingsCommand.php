<?php

namespace App\Console\Commands;

use App\Models\Borrowing;
use Illuminate\Console\Command;

class MarkOverdueBorrowingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'borrowings:mark-overdue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Marca como Vencido los préstamos en curso cuya fecha límite de devolución ya expiró.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $updated = Borrowing::markOverdueBorrowings();

        $this->info("Préstamos marcados como vencidos: {$updated}");

        return self::SUCCESS;
    }
}
