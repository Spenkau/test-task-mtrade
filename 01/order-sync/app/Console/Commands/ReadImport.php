<?php

namespace App\Console\Commands;

use App\Contracts\DataSourceInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:read-import')]
#[Description('Command description')]
class ReadImport extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DataSourceInterface $dataSource)
    {
        $data = $dataSource->read();

        echo $data;
    }
}
