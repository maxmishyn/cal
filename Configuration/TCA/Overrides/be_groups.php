<?php

use TYPO3\CMS\Cal\TreeProvider\TreeView;

defined('TYPO3_MODE') or die();

// Define the TCA for a checkbox and calendar-/category selector to enable access control.
$tempColumns = [
    'tx_cal_enable_accesscontroll' => [
        'exclude' => 1,
        'label' => 'LLL:EXT:cal/Resources/Private/Language/locallang_db.xml:tx_cal_enable_accesscontroll',
        'onChange' => 'reload',
        'config' => [
            'type' => 'check',
            'default' => 0
        ]
    ],
    'tx_cal_calendar' => [
        'exclude' => 1,
        'label' => 'LLL:EXT:cal/Resources/Private/Language/locallang_db.xml:tx_cal_calendar_accesscontroll',
        'displayCond' => 'FIELD:tx_cal_enable_accesscontroll:REQ:true',
        'config' => [
            'renderType' => 'selectMultipleSideBySide',
            'type' => 'select',
            'size' => 10,
            'minitems' => 0,
            'maxitems' => 100,
            'autoSizeMax' => 20,
            'itemListStyle' => 'height:130px;',
            'foreign_table' => 'tx_cal_calendar'
        ]
    ]
];

// Add the checkbox and the calendar-/category selector for backend groups.
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('be_groups', $tempColumns);
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes('be_groups', 'tx_cal_enable_accesscontroll');
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes('be_groups', 'tx_cal_calendar');
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes('be_groups', 'tx_cal_category');
