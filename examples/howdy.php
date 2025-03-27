<?php

use function Laravel\Prompts\text;

require __DIR__.'/vendor/autoload.php';

$name = text('What is your name?', required: true, placeholder: 'John Doe');

echo "\n\033[48;5;25m\033[1;97mHowdy {$name},\033[0m\n";
echo "\nNice to meet you, you must be a good egg!\n\n";

sleep(2);
