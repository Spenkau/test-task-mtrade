<?php

return [
    'driver' => env('IMPORT_DRIVER', 'file'),
    'token' => env('IMPORT_TOKEN'),
    'path' => env('IMPORT_PATH'),

    // должен лежать в storage или путь относительно его
    'nomenclature' => 'nomenclature_1c.csv',
];
