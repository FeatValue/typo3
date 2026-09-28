<?php

defined('TYPO3') or die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'LLL:EXT:featvalue/Resources/Private/Language/locallang.xlf:content.dashboard',
        'value' => 'featvalue_dashboard',
        'icon' => 'featvalue-module',
        'group' => 'default',
    ]
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['featvalue_dashboard'] = 'featvalue-module';

$GLOBALS['TCA']['tt_content']['types']['featvalue_dashboard'] = [
    'showitem' => '
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
            --palette--;;general,
            pi_flexform,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
            --palette--;;hidden,
            --palette--;;access,
    ',
];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addPiFlexFormValue(
    '*',
    'FILE:EXT:featvalue/Configuration/FlexForms/Dashboard.xml',
    'featvalue_dashboard'
);
