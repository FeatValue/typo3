<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'FeatValue',
    'description' => 'Embeds the FeatValue client portal in TYPO3: backend module, content element, SSO and mail relay.',
    'category' => 'plugin',
    'author' => 'FeatValue',
    'author_company' => 'FeatValue',
    'state' => 'stable',
    'version' => '0.1.3',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'fluid_styled_content' => '13.4.0-14.99.99',
            'scheduler' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
