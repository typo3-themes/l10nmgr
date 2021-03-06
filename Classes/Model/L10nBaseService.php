<?php
namespace Localizationteam\L10nmgr\Model;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2006 Kasper Skårhøj <kasperYYYY@typo3.com>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\DebugUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * baseService class for offering common services like saving translation etc...
 *
 * @author     Kasper Skaarhoj <kasperYYYY@typo3.com>
 * @author     Daniel Pötzinger <development@aoemedia.de>
 * @package    TYPO3
 * @subpackage tx_l10nmgr
 */
class L10nBaseService
{

    protected static $targetLanguageID = null;
    /**
     * @var bool Translate even if empty.
     */
    protected $createTranslationAlsoIfEmpty = false;
    /**
     * @var bool Import as default language.
     */
    protected $importAsDefaultLanguage = false;
    /**
     * @var array Extension's configuration as from the EM
     */
    protected $extensionConfiguration = array();

    public function __construct()
    {
        // Load the extension's configuration
        $this->extensionConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['l10nmgr']);
    }

    /**
     * @return integer|NULL
     */
    public static function getTargetLanguageID()
    {
        return self::$targetLanguageID;
    }

    /**
     * Save the translation
     *
     * @param L10nConfiguration $l10ncfgObj
     * @param TranslationData $translationObj
     */
    function saveTranslation(L10nConfiguration $l10ncfgObj, TranslationData $translationObj)
    {
        // Provide a hook for specific manipulations before saving
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePreProcess'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePreProcess'] as $classReference) {
                $processingObject = GeneralUtility::getUserObj($classReference);
                $processingObject->processBeforeSaving($l10ncfgObj, $translationObj, $this);
            }
        }

        $sysLang = $translationObj->getLanguage();
        $accumObj = $l10ncfgObj->getL10nAccumulatedInformationsObjectForLanguage($sysLang);
        $flexFormDiffArray = $this->_submitContentAndGetFlexFormDiff($accumObj->getInfoArray($sysLang),
            $translationObj->getTranslationData());

        if ($flexFormDiffArray !== false) {
            $l10ncfgObj->updateFlexFormDiff($sysLang, $flexFormDiffArray);
        }

        // Provide a hook for specific manipulations after saving
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePostProcess'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePostProcess'] as $classReference) {
                $processingObject = GeneralUtility::getUserObj($classReference);
                $processingObject->processAfterSaving($l10ncfgObj, $translationObj, $flexFormDiffArray, $this);
            }
        }
    }

    /**
     * Submit incoming content to database. Must match what is available in $accum.
     *
     * @param array $accum Translation configuration
     * @param array $inputArray Array with incoming translation. Must match what is found in $accum
     * @return mixed False if error - else flexFormDiffArray (if $inputArray was an array and processing was performed.)
     */
    function _submitContentAndGetFlexFormDiff($accum, $inputArray)
    {
        if ($this->getImportAsDefaultLanguage()) {
            return $this->_submitContentAsDefaultLanguageAndGetFlexFormDiff($accum, $inputArray);
        } else {
            return $this->_submitContentAsTranslatedLanguageAndGetFlexFormDiff($accum, $inputArray);
        }
    }

    /**
     * Getter for $importAsDefaultLanguage
     *
     * @return boolean
     */
    public function getImportAsDefaultLanguage()
    {
        return $this->importAsDefaultLanguage;
    }

    /**
     * Setter for $importAsDefaultLanguage
     *
     * @param boolean $importAsDefaultLanguage
     * @return void
     */
    public function setImportAsDefaultLanguage($importAsDefaultLanguage)
    {
        $this->importAsDefaultLanguage = $importAsDefaultLanguage;
    }

    /**
     * Submit incoming content as default language to database. Must match what is available in $accum.
     *
     * @param array $accum Translation configuration
     * @param array $inputArray Array with incoming translation. Must match what is found in $accum
     * @return mixed False if error - else flexFormDiffArray (if $inputArray was an array and processing was performed.)
     */
    function _submitContentAsDefaultLanguageAndGetFlexFormDiff($accum, $inputArray)
    {
        if (is_array($inputArray)) {
            // Initialize:
            /** @var $flexToolObj FlexFormTools */
            $flexToolObj = GeneralUtility::makeInstance(FlexFormTools::class);
            $TCEmain_data = array();

            $_flexFormDiffArray = array();
            // Traverse:
            foreach ($accum as $pId => $page) {
                foreach ($accum[$pId]['items'] as $table => $elements) {
                    foreach ($elements as $elementUid => $data) {
                        if (is_array($data['fields'])) {

                            foreach ($data['fields'] as $key => $tData) {

                                if (is_array($tData) && isset($inputArray[$table][$elementUid][$key])) {

                                    list($Ttable, $TuidString, $Tfield, $Tpath) = explode(':', $key);
                                    list($Tuid, $Tlang, $TdefRecord) = explode('/', $TuidString);

                                    if (!$this->createTranslationAlsoIfEmpty && $inputArray[$table][$elementUid][$key] == '' && $Tuid == 'NEW') {
                                        //if data is empty do not save it
                                        unset($inputArray[$table][$elementUid][$key]);
                                        continue;
                                    }
                                    #\TYPO3\CMS\Core\Utility\DebugUtility::debug($elementUid);

                                    // If FlexForm, we set value in special way:
                                    if ($Tpath) {
                                        if (!is_array($TCEmain_data[$Ttable][$elementUid][$Tfield])) {
                                            $TCEmain_data[$Ttable][$elementUid][$Tfield] = array();
                                        }
                                        //TCEMAINDATA is passed as reference here:
                                        $flexToolObj->setArrayValueByPath($Tpath,
                                            $TCEmain_data[$Ttable][$elementUid][$Tfield],
                                            $inputArray[$table][$elementUid][$key]);
                                        $_flexFormDiffArray[$key] = array(
                                            'translated' => $inputArray[$table][$elementUid][$key],
                                            'default' => $tData['defaultValue']
                                        );
                                    } else {
                                        $TCEmain_data[$Ttable][$elementUid][$Tfield] = $inputArray[$table][$elementUid][$key];
                                    }
                                    unset($inputArray[$table][$elementUid][$key]); // Unsetting so in the end we can see if $inputArray was fully processed.
                                } else {
                                    //debug($tData,'fields not set for: '.$elementUid.'-'.$key);
                                    //debug($inputArray[$table],'inputarray');
                                }
                            }
                            if (is_array($inputArray[$table][$elementUid]) && !count($inputArray[$table][$elementUid])) {
                                unset($inputArray[$table][$elementUid]); // Unsetting so in the end we can see if $inputArray was fully processed.
                            }
                        }
                    }
                    if (is_array($inputArray[$table]) && !count($inputArray[$table])) {
                        unset($inputArray[$table]); // Unsetting so in the end we can see if $inputArray was fully processed.
                    }
                }
            }

            if ($TCEmain_data['pages_language_overlay']) {
                $TCEmain_data['pages'] = $TCEmain_data['pages_language_overlay'];
                unset($TCEmain_data['pages_language_overlay']);
            }
            #\TYPO3\CMS\Core\Utility\DebugUtility::debug($TCEmain_data);
            //var_dump($TCEmain_data);

            $this->lastTCEMAINCommandsCount = 0;

            // Now, submitting translation data:
            $tce = GeneralUtility::makeInstance(DataHandler::class);
            $tce->stripslashes_values = false;
            $tce->dontProcessTransformations = true;
            $tce->isImporting = true;
            $tce->start($TCEmain_data,
                array()); // check has been done previously that there is a backend user which is Admin and also in live workspace
            $tce->process_datamap();

            if (count($tce->errorLog)) {
                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': TCEmain update errors: ' . GeneralUtility::arrayToLogString($tce->errorLog),
                    'l10nmgr');
            }

            if (count($tce->autoVersionIdMap) && count($_flexFormDiffArray)) {

                foreach ($_flexFormDiffArray as $key => $value) {
                    list($Ttable, $Tuid, $Trest) = explode(':', $key, 3);
                    if ($tce->autoVersionIdMap[$Ttable][$Tuid]) {
                        $_flexFormDiffArray[$Ttable . ':' . $tce->autoVersionIdMap[$Ttable][$Tuid] . ':' . $Trest] = $_flexFormDiffArray[$key];
                        unset($_flexFormDiffArray[$key]);
                    }
                }
            }

            // Should be empty now - or there were more information in the incoming array than there should be!
            if (count($inputArray)) {
                debug($inputArray, 'These fields were ignored since they were not in the configuration:');
            }

            return $_flexFormDiffArray;
        } else {
            return false;
        }
    }

    /**
     * Submit incoming content as translated language to database. Must match what is available in $accum.
     *
     * @param array $accum Translation configuration
     * @param array $inputArray Array with incoming translation. Must match what is found in $accum
     * @return mixed False if error - else flexFormDiffArray (if $inputArray was an array and processing was performed.)
     */
    function _submitContentAsTranslatedLanguageAndGetFlexFormDiff($accum, $inputArray)
    {
	    global $TCA;
        if (is_array($inputArray)) {
            // Initialize:
            /** @var $flexToolObj FlexFormTools */
            $flexToolObj = GeneralUtility::makeInstance(FlexFormTools::class);
            $gridElementsInstalled = ExtensionManagementUtility::isLoaded('gridelements');
            $TCEmain_data = array();
            $TCEmain_cmd = array();

            $_flexFormDiffArray = array();
            // Traverse:
            foreach ($accum as $pId => $page) {
                foreach ($accum[$pId]['items'] as $table => $elements) {
                    foreach ($elements as $elementUid => $data) {
                        if (is_array($data['fields'])) {

                            foreach ($data['fields'] as $key => $tData) {

                                if (is_array($tData) && isset($inputArray[$table][$elementUid][$key])) {

                                    list($Ttable, $TuidString, $Tfield, $Tpath) = explode(':', $key);
                                    list($Tuid, $Tlang, $TdefRecord) = explode('/', $TuidString);

                                    if (!$this->createTranslationAlsoIfEmpty && $inputArray[$table][$elementUid][$key] == '' && $Tuid == 'NEW') {
                                        //if data is empty do not save it
                                        unset($inputArray[$table][$elementUid][$key]);
                                        continue;
                                    }

                                    // If new element is required, we prepare for localization
                                    if ($Tuid === 'NEW') {
                                        if ($table === 'tt_content' && $gridElementsInstalled === true) {
                                            $element = BackendUtility::getRecordRaw($table,
                                                'uid = ' . (int)$elementUid . ' AND deleted = 0');
                                            if ($element['colPos'] > -1) {
	                                            if (isset($TCEmain_cmd['tt_content'][$elementUid])) {
		                                            unset($TCEmain_cmd['tt_content'][$elementUid]);
	                                            }
                                                $TCEmain_cmd['tt_content'][$elementUid]['localize'] = $Tlang;
                                            } else {
                                                if ($element['tx_gridelements_container'] > 0) {
                                                    //TYPO3\CMS\Core\Utility\DebugUtility::debug($element,'$element');
                                                    $container = BackendUtility::getRecordRaw('tt_content',
	                                                    $TCA['tt_content']['ctrl']['transOrigPointerField'] . ' = ' . (int)$element['tx_gridelements_container'] . '
	                                                    AND deleted = 0 AND sys_language_uid = ' . (int)$Tlang
                                                    );
                                                    if ($container['uid'] > 0) {
	                                                    if (isset($TCEmain_cmd['tt_content'][$elementUid])) {
		                                                    unset($TCEmain_cmd['tt_content'][$elementUid]);
	                                                    }
                                                        $TCEmain_cmd['tt_content'][$container['uid']]['inlineLocalizeSynchronize'] = 'tx_gridelements_children,localize';
                                                    }
                                                }
                                            }
                                        } elseif ($table === 'sys_file_reference') {
	                                        $element = BackendUtility::getRecordRaw($table,
		                                        'uid = ' . (int)$elementUid . ' AND deleted = 0');
	                                        if ($element['uid_foreign'] && $element['tablenames'] && $element['fieldname']) {
		                                        if ($element['tablenames'] === 'pages') {
			                                        if (isset($TCEmain_cmd[$table][$elementUid])) {
				                                        unset($TCEmain_cmd[$table][$elementUid]);
			                                        }
		                                            $TCEmain_cmd[$table][$elementUid]['localize'] = $Tlang;
		                                        } else {
			                                        $parent = BackendUtility::getRecordRaw($element['tablenames'],
				                                        $TCA[$element['tablenames']]['ctrl']['transOrigPointerField'] . ' = ' . (int)$element['uid_foreign'] . '
			                                            AND deleted = 0 AND sys_language_uid = ' . (int)$Tlang
			                                        );
			                                        if ($parent['uid'] > 0) {
				                                        if (isset($TCEmain_cmd[$element['tablenames']][$element['uid_foreign']])) {
					                                        unset($TCEmain_cmd[$element['tablenames']][$element['uid_foreign']]);
				                                        }
				                                        $TCEmain_cmd[$element['tablenames']][$element['uid_foreign']]['inlineLocalizeSynchronize'] = $element['fieldname'] . ',localize';
			                                        }
		                                        }
	                                        }
                                        } else {
		                                    //print "\nNEW\n";
	                                        if (isset($TCEmain_cmd[$table][$elementUid])) {
		                                        unset($TCEmain_cmd[$table][$elementUid]);
	                                        }
		                                    $TCEmain_cmd[$table][$elementUid]['localize'] = $Tlang;
                                        }
                                    }

                                    // If FlexForm, we set value in special way:
                                    if ($Tpath) {
                                        if (!is_array($TCEmain_data[$Ttable][$TuidString][$Tfield])) {
                                            $TCEmain_data[$Ttable][$TuidString][$Tfield] = array();
                                        }
                                        //TCEMAINDATA is passed as reference here:
                                        $flexToolObj->setArrayValueByPath($Tpath,
                                            $TCEmain_data[$Ttable][$TuidString][$Tfield],
                                            $inputArray[$table][$elementUid][$key]);
                                        $_flexFormDiffArray[$key] = array(
                                            'translated' => $inputArray[$table][$elementUid][$key],
                                            'default' => $tData['defaultValue']
                                        );
                                    } else {
                                        $TCEmain_data[$Ttable][$TuidString][$Tfield] = $inputArray[$table][$elementUid][$key];
                                    }
                                    unset($inputArray[$table][$elementUid][$key]); // Unsetting so in the end we can see if $inputArray was fully processed.
                                } else {
                                    //debug($tData,'fields not set for: '.$elementUid.'-'.$key);
                                    //debug($inputArray[$table],'inputarray');
                                }
                            }
                            if (is_array($inputArray[$table][$elementUid]) && !count($inputArray[$table][$elementUid])) {
                                unset($inputArray[$table][$elementUid]); // Unsetting so in the end we can see if $inputArray was fully processed.
                            }
                        }
                    }
                    if (is_array($inputArray[$table]) && !count($inputArray[$table])) {
                        unset($inputArray[$table]); // Unsetting so in the end we can see if $inputArray was fully processed.
                    }
                }
            }

            self::$targetLanguageID = $Tlang;

            // Execute CMD array: Localizing records:
            /** @var $tce DataHandler */
            $tce = GeneralUtility::makeInstance(DataHandler::class);
            if ($this->extensionConfiguration['enable_neverHideAtCopy'] == 1) {
                $tce->neverHideAtCopy = true;
            }
            $tce->stripslashes_values = false;
            $tce->isImporting = true;
            if (count($TCEmain_cmd)) {
                $tce->start(array(), $TCEmain_cmd);
                $tce->process_cmdmap();
                if (count($tce->errorLog)) {
                    debug($tce->errorLog, 'TCEmain localization errors:');
                }
            }

            // Before remapping
            if (TYPO3_DLOG) {
                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': TCEmain_data before remapping: ' . GeneralUtility::arrayToLogString($TCEmain_data),
                    'l10nmgr');
            }
            // Remapping those elements which are new:
            $this->lastTCEMAINCommandsCount = 0;
            foreach ($TCEmain_data as $table => $items) {
                foreach ($TCEmain_data[$table] as $TuidString => $fields) {
	                if ($table === 'sys_file_reference' && $fields['tablenames'] === 'pages') {
		                $parent = BackendUtility::getRecordRaw('pages_language_overlay',
			                'pid = ' . (int)$element['uid_foreign'] . '
			                AND deleted = 0 AND sys_language_uid = ' . (int)$Tlang
		                );
		                if ($parent['uid']) {
			                $fields['tablenames'] = 'pages_language_overlay';
			                $fields['uid_foreign'] = $parent['uid'];
		                }
	                }
                    list($Tuid, $Tlang, $TdefRecord) = explode('/', $TuidString);
                    $this->lastTCEMAINCommandsCount++;
                    if ($Tuid === 'NEW') {
                        if ($tce->copyMappingArray_merged[$table][$TdefRecord]) {
                            $TCEmain_data[$table][BackendUtility::wsMapId($table,
                                $tce->copyMappingArray_merged[$table][$TdefRecord])] = $fields;
                        } else {
                            GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': Record "' . $table . ':' . $TdefRecord . '" was NOT localized as it should have been!',
                                'l10nmgr');
                        }
                        unset($TCEmain_data[$table][$TuidString]);
                    }
                }
            }
            // After remapping
            if (TYPO3_DLOG) {
                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': TCEmain_data after remapping: ' . GeneralUtility::arrayToLogString($TCEmain_data),
                    'l10nmgr');
            }

            // Now, submitting translation data:
            /** @var $tce DataHandler */
            $tce = GeneralUtility::makeInstance(DataHandler::class);
            if ($this->extensionConfiguration['enable_neverHideAtCopy'] == 1) {
                $tce->neverHideAtCopy = true;
            }
            $tce->stripslashes_values = false;
            $tce->dontProcessTransformations = true;
            $tce->isImporting = true;
            //print_r($TCEmain_data);
            $tce->start($TCEmain_data,
                array()); // check has been done previously that there is a backend user which is Admin and also in live workspace
            $tce->process_datamap();

            self::$targetLanguageID = null;

            if (count($tce->errorLog)) {
                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': TCEmain update errors: ' . GeneralUtility::arrayToLogString($tce->errorLog),
                    'l10nmgr');
            }

            if (count($tce->autoVersionIdMap) && count($_flexFormDiffArray)) {
                if (TYPO3_DLOG) {
                    GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': flexFormDiffArry: ' . GeneralUtility::arrayToLogString($this->flexFormDiffArray),
                        'l10nmgr');
                }
                foreach ($_flexFormDiffArray as $key => $value) {
                    list($Ttable, $Tuid, $Trest) = explode(':', $key, 3);
                    if ($tce->autoVersionIdMap[$Ttable][$Tuid]) {
                        $_flexFormDiffArray[$Ttable . ':' . $tce->autoVersionIdMap[$Ttable][$Tuid] . ':' . $Trest] = $_flexFormDiffArray[$key];
                        unset($_flexFormDiffArray[$key]);
                    }
                }
                if (TYPO3_DLOG) {
                    GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': autoVersionIdMap: ' . $tce->autoVersionIdMap,
                        'l10nmgr');
                    GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': _flexFormDiffArray: ' . GeneralUtility::arrayToLogString($_flexFormDiffArray),
                        'l10nmgr');
                }
            }

            // Should be empty now - or there were more information in the incoming array than there should be!
            if (count($inputArray)) {
                debug($inputArray, 'These fields were ignored since they were not in the configuration:');
            }

            return $_flexFormDiffArray;
        } else {
            return false;
        }
    }
}