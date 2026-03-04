<?php

defined('TYPO3') or die();

(static function (string $extKey) {
    if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet') === false) {
        /** @noinspection PhpIncludeInspection */
        include(\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath(
            $extKey,
            'Resources/Private/Composer/vendor/autoload.php'
        ));
    }

    $featureConf = &$GLOBALS['TYPO3_CONF_VARS']['SYS']['features'];
    if (!isset($featureConf['spreadsheets.tabsContentElement'])) {
        $extConf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class
        );
        $featureConf['spreadsheets.tabsContentElement'] = $extConf->get('spreadsheets', 'ce_tabs') === '1';
    }

    // add content element to insert tables in content element wizard
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addPageTSConfig(
        "@import 'EXT:spreadsheets/Configuration/PageTSconfig/NewContentElementWizard.typoscript'"
    );

    // register template for backend preview rendering
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addPageTSConfig(
        "@import 'EXT:spreadsheets/Configuration/PageTSconfig/BackendPreview.typoscript'"
    );

    // add field type to form engine
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1513268927167] = [
        'nodeName' => 'spreadsheetInput',
        'priority' => 30,
        'class' => \Hoogi91\Spreadsheets\Form\Element\DataInputElement::class,
    ];

})(
    'spreadsheets'
);
