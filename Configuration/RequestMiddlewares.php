<?php

declare(strict_types=1);

use Featvalue\Typo3\Middleware\ApiMiddleware;

return [
    'frontend' => [
        'featvalue/api' => [
            'target' => ApiMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
