<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())->in([__DIR__ . '/src', __DIR__ . '/tests'])->notPath('bootstrap.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'yoda_style' => false,
    ])
    ->setFinder($finder);
