<?php

namespace App\Contracts;

interface DataSourceInterface
{
    public function read(): string;
}
