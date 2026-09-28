<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Type\Map;

$hosts = class_exists(\Featvalue\Typo3\Config::class)
    ? \Featvalue\Typo3\Config::cspHosts()
    : [
        'https://dashboard.featvalue.io',
        'https://integrations.featvalue.io',
        'https://app.featvalue.io',
        'https://external-auth.featvalue.io',
        'https://login.featvalue.io',
        'https://tasks.featvalue.io',
        'https://register.featvalue.io',
        'https://www.featvalue.io',
    ];
$sources = array_map(static fn (string $host): UriValue => new UriValue($host), $hosts);

$mutations = new MutationCollection(
    new Mutation(MutationMode::Extend, Directive::FrameSrc, ...$sources),
    new Mutation(MutationMode::Extend, Directive::ScriptSrc, ...$sources),
    new Mutation(MutationMode::Extend, Directive::ConnectSrc, ...$sources),
);

// Jedes Scope-Paar ist ein eigenes Argument von Map::fromEntries().
return Map::fromEntries(
    [Scope::frontend(), $mutations],
    [Scope::backend(), $mutations],
);
