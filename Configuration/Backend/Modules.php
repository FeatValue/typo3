<?php

declare(strict_types=1);

use Featvalue\Typo3\Controller\BackendModuleController;

return [
    'featvalue' => [
        'position' => ['before' => 'tools'],
        'standalone' => true,
        'access' => 'user',
        'path' => '/module/featvalue',
        'iconIdentifier' => 'featvalue-module',
        'labels' => [
            'title' => 'LLL:EXT:featvalue/Resources/Private/Language/locallang.xlf:module.title',
            'description' => 'LLL:EXT:featvalue/Resources/Private/Language/locallang.xlf:module.description',
        ],
        'aliases' => ['web_featvalue'],
        'routes' => [
            '_default' => [
                'target' => BackendModuleController::class . '::mainAction',
            ],
        ],
    ],
    'web_featvalue_tasks' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user',
        'path' => '/module/web/tasks',
        'iconIdentifier' => 'featvalue-module',
        'labels' => [
            'title' => 'LLL:EXT:featvalue/Resources/Private/Language/locallang.xlf:module.tasks',
        ],
        'routes' => [
            '_default' => [
                'target' => BackendModuleController::class . '::tasksAction',
            ],
        ],
    ],
];
