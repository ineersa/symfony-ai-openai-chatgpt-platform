<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())->setRiskyAllowed(true)->setRules(['@Symfony' => true, 'declare_strict_types' => true])->setFinder(PhpCsFixer\Finder::create()->in(__DIR__.'/src')->in(__DIR__.'/tests')->append([__DIR__.'/castor.php']));
