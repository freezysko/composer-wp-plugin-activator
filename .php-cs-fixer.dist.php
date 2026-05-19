<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->exclude('fixtures');   // tests/fixtures/wp* are intentional fakes; do not normalize.

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3.0'           => true,
        '@PHP81Migration'      => true,   // ONLY 8.1 migration — see A-d1; 8.2-8.4 presets would emit syntax PHP 8.1 cannot parse
        // Risky additions (each justified):
        'declare_strict_types'         => true,   // existing convention — make it enforced.
        'native_constant_invocation'   => true,   // perf + clarity for global constants in non-root namespaces.
        'native_function_invocation'   => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'no_unused_imports'            => true,
        'void_return'                  => true,
        'phpdoc_align'                 => ['align' => 'left'],
        'global_namespace_import'      => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
    ])
    ->setFinder($finder);
