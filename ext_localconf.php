<?php

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Featvalue\Typo3\Task\MailRelayTask::class] = [
    'extension' => 'featvalue',
    'title' => 'LLL:EXT:featvalue/Resources/Private/Language/locallang.xlf:task.mailRelay.title',
    'description' => 'LLL:EXT:featvalue/Resources/Private/Language/locallang.xlf:task.mailRelay.description',
    'frequency' => 300,
];
