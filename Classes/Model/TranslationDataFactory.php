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

use TYPO3\CMS\Core\Html\RteHtmlParser;
use TYPO3\CMS\Core\Utility\DebugUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Localizationteam\L10nmgr\Model\TranslationData;
use Localizationteam\L10nmgr\Model\Tools\XmlTools;

/**
 * Returns initialised TranslationData Objects
 * This is used to get TranslationData out of the import files for example
 *
 * @author  Kasper Skaarhoj <kasperYYYY@typo3.com>
 * @author  Daniel Pötzinger <ext@aoemedia.de>
 *
 * @package TYPO3
 * @subpackage tx_l10nmgr
 */
class TranslationDataFactory
{

    /**
     * @var string List of error messages
     */
    protected $_errorMsg;

    /**
     * public Factory method to get initialised tranlationData Object from the passed XMLNodes Array
     * see tx_l10nmgr_CATXMLImportManager
     *
     * @param array $xmlNodes Array with XMLNodes from the CATXML
     * @return TranslationData Object with data
     **/
    function getTranslationDataFromCATXMLNodes(&$xmlNodes)
    {
        $data = $this->_getParsedCATXMLFromXMLNodes($xmlNodes);

        /** @var $translationData TranslationData */
        $translationData = GeneralUtility::makeInstance(TranslationData::class);
        $translationData->setTranslationData($data);

        return $translationData;
    }

    /**
     * Parses XML String and returns translationData
     *
     * @param array $xmlNodes Array with XMLNodes
     * @return array with translated information
     **/
    function _getParsedCATXMLFromXMLNodes(&$xmlNodes)
    {
        /** @var $xmlTool XmlTools */
        $xmlTool = GeneralUtility::makeInstance(XmlTools::class);

        //print_r($xmlNodes); exit;
        $translation = array();

        // OK, this method of parsing the XML really sucks, but it was 4:04 in the night and ... I have no clue to make it better on PHP4. Anyway, this will work for now. But is probably unstable in case a user puts formatting in the content of the translation! (since only the first CData chunk will be found!)
        if (is_array($xmlNodes['TYPO3L10N'][0]['ch']['pageGrp'])) {
            foreach ($xmlNodes['TYPO3L10N'][0]['ch']['pageGrp'] as $pageGrp) {
                if (is_array($pageGrp['ch']['data'])) {
                    foreach ($pageGrp['ch']['data'] as $row) {
                        $attrs = $row['attrs'];
                        list(, $uidString, $fieldName) = explode(':', $attrs['key']);
                        if ($attrs['transformations'] == '1') {
                            $translationValue = $xmlTool->XML2RTE($row['XMLvalue']);
                            $translation[$attrs['table']][$attrs['elementUid']][$attrs['key']] = $translationValue;
                        } else {
                            //Substitute &amp; with & and <br/> with <br>
                            //$row['XMLvalue'] = htmlspecialchars($row['XMLvalue'],ENT_COMPAT|ENT_IGNORE|ENT_XHTML,'UTF-8',false);
                            //$row['XMLvalue'] = str_replace('&amp;', '&', $row['XMLvalue']);
                            //$row['XMLvalue'] = str_replace('<br/>', '<br>', $row['XMLvalue']);
                            //$row['XMLvalue'] = str_replace('<br />', '<br>', $row['XMLvalue']);
                            $row['values'][0] = preg_replace('/&(?!(amp|nbsp|quot|apos|lt|gt);)/', '&amp;',
                                $row['values'][0]);
                            $row['values'][0] = preg_replace('/\xc2\xa0/', '&nbsp;', $row['values'][0]);
                            $row['values'][0] = htmlspecialchars($row['values'][0], ENT_COMPAT | ENT_IGNORE | ENT_XHTML,
                                'UTF-8', false);
                            //$row['values'][0] = str_replace('<br/>', '<br>', $row['values'][0]);
                            //$row['values'][0] = str_replace('<br />', '<br>', $row['values'][0]);

                            //check if $row['values'][0] is beginning of $row['XMLvalue']
                            if (TYPO3_DLOG) {
                                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': V0: ' . $row['values'][0],
                                    'l10nmgr');
                                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': XML: ' . $row['XMLvalue'],
                                    'l10nmgr');
                            }
                            $pattern = $row['values'][0];
                            if (TYPO3_DLOG) {
                                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': Pattern: ' . $pattern,
                                    'l10nmgr');
                            }
                            $pattern2 = '/' . preg_replace('/\//i', '\/', preg_quote($pattern)) . '/';
                            $pattern = '/^' . preg_replace('/\//i', '\/', preg_quote($pattern)) . '/';
	                        $originalValue = htmlspecialchars($row['XMLvalue'], ENT_COMPAT | ENT_IGNORE | ENT_XHTML,
		                        'UTF-8', false);
                            if (TYPO3_DLOG) {
                                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': Pattern: ' . $pattern,
                                    'l10nmgr');
                                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': Pattern 2: ' . $pattern2,
                                    'l10nmgr');
                            }
                            if (preg_match($pattern, $originalValue, $treffer)) {
                                if (TYPO3_DLOG) {
                                    GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': Start row[values][0] eq start row[XMLvalue]!!!' . LF . 'XMLvalue: ' . $row['XMLvalue'],
                                        'l10nmgr');
                                }
                                $translation[$attrs['table']][$attrs['elementUid']][$attrs['key']] = $row['XMLvalue'];
                            } elseif ((preg_match('/<[^>]+>/i', $originalValue)) && (!preg_match($pattern2,
		                            $originalValue, $treffer))
                            ) {
                                if (TYPO3_DLOG) {
                                    GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': TAG found in: ' . $row['XMLvalue'],
                                        'l10nmgr');
                                    GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': TAG found: ' . $row['values'][0],
                                        'l10nmgr');
                                }
                                $translation[$attrs['table']][$attrs['elementUid']][$attrs['key']] = $row['values'][0] . $row['XMLvalue'];
                            } else {
                                if (TYPO3_DLOG) {
                                    GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': No TAG found in: ' . $row['XMLvalue'],
                                        'l10nmgr');
                                }
                                $translation[$attrs['table']][$attrs['elementUid']][$attrs['key']] = $row['XMLvalue'];
                            }
                            if (TYPO3_DLOG) {
                                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': IMPORT: ' . $translation[$attrs['table']][$attrs['elementUid']][$attrs['key']],
                                    'l10nmgr');
                            }
                        }
                    }
                }
            }
        }

        return $translation;
    }

    /**
     * public Factory method to get initialized translationData Object from the passed XML
     *
     * @param string $xmlFile Path to the XML file
     * @return TranslationData Object with data
     **/
    function getTranslationDataFromExcelXMLFile($xmlFile)
    {
        $fileContent = GeneralUtility::getUrl($xmlFile);

        $data = $this->_getParsedExcelXML($fileContent);
        if ($data === false) {
            die($this->_errorMsg);
        }

        /** @var $translationData TranslationData */
        $translationData = GeneralUtility::makeInstance(TranslationData::class);
        $translationData->setTranslationData($data);

        return $translationData;
    }

    /**
     * Private internal function to parse the excel import XML format.
     * TODO: possibly make separate class for this.
     *
     * @param string $fileContent String with XML
     * @return array with translated information
     **/
    function _getParsedExcelXML($fileContent)
    {
        // Parse XML in a rude fashion:
        // Check if &nbsp; has to be substituted -> DOCTYPE -> entity?
        $xmlNodes = GeneralUtility::xml2tree(str_replace('&nbsp;', '&#160;',
            $fileContent)); // For some reason PHP chokes on incoming &nbsp; in XML!
        $translation = array();

        if (!is_array($xmlNodes)) {
            $this->_errorMsg .= $xmlNodes;

            return false;
        }

        // At least OpenOfficeOrg Calc changes the worksheet identifier. For now we better check for this, otherwise we cannot import translations edited with OpenOfficeOrg Calc.
        if (isset($xmlNodes['Workbook'][0]['ch']['Worksheet'])) {
            $worksheetIdentifier = 'Worksheet';
        }
        if (isset($xmlNodes['Workbook'][0]['ch']['ss:Worksheet'])) {
            $worksheetIdentifier = 'ss:Worksheet';
        }

        // OK, this method of parsing the XML really sucks, but it was 4:04 in the night and ... I have no clue to make it better on PHP4. Anyway, this will work for now. But is probably unstable in case a user puts formatting in the content of the translation! (since only the first CData chunk will be found!)
        if (is_array($xmlNodes['Workbook'][0]['ch'][$worksheetIdentifier][0]['ch']['Table'][0]['ch']['Row'])) {
            foreach ($xmlNodes['Workbook'][0]['ch'][$worksheetIdentifier][0]['ch']['Table'][0]['ch']['Row'] as $row) {
                if (!isset($row['ch']['Cell'][0]['attrs']['ss:Index'])) {
                    list($Ttable, $Tuid, $Tkey) = explode('][',
                        substr(trim($row['ch']['Cell'][0]['ch']['Data'][0]['values'][0]), 12, -1));
                    $translation[$Ttable][$Tuid][$Tkey] = $row['ch']['Cell'][4]['ch']['Data'][0]['values'][0];
                }
            }
        }

        return $translation;
    }

    /**
     * For supporting older Format (without pagegrp element)
     *    public Factory method to get initialised tranlationData Object from the passed XML
     *
     * @param string $xmlFile Path to the XML file
     * @return TranslationData Object with data
     **/
    function getTranslationDataFromOldFormatCATXMLFile($xmlFile)
    {
        $fileContent = GeneralUtility::getUrl($xmlFile);
        $data = $this->_getParsedCATXMLFromOldFormat($fileContent);
        if ($data === false) {
            die($this->_errorMsg);
        }

        /** @var $translationData TranslationData */
        $translationData = GeneralUtility::makeInstance(TranslationData::class);
        $translationData->setTranslationData($data);

        return $translationData;
    }

    /**
     * For supporting older Format (without pagegrp element)
     *
     * @param string $fileContent String with XML
     * @return array with translated information
     **/
    function _getParsedCATXMLFromOldFormat($fileContent)
    {
        /** @var $parseHTML RteHtmlParser */
        $parseHTML = GeneralUtility::makeInstance(RteHtmlParser::class);
        $xmlNodes = GeneralUtility::xml2tree(str_replace('&nbsp;', '&#160;', $fileContent),
            2); // For some reason PHP chokes on incoming &nbsp; in XML!

        if (!is_array($xmlNodes)) {
            $this->_errorMsg .= $xmlNodes;

            return false;
        }
        $translation = array();

        // OK, this method of parsing the XML really sucks, but it was 4:04 in the night and ... I have no clue to make it better on PHP4. Anyway, this will work for now. But is probably unstable in case a user puts formatting in the content of the translation! (since only the first CData chunk will be found!)
        if (is_array($xmlNodes['TYPO3L10N'][0]['ch']['Data'])) {
            foreach ($xmlNodes['TYPO3L10N'][0]['ch']['Data'] as $row) {
                $attrs = $row['attrs'];

                list(, $uidString, $fieldName) = explode(':', $attrs['key']);
                if ($attrs['transformations'] == '1') { //substitute check with rte enabled fields from TCA

                    //$translationValue =$this->_getXMLFromTreeArray($row);
                    $translationValue = $row['XMLvalue'];

                    //fixed setting of Parser (TO-DO set it via typoscript)
                    $parseHTML->procOptions['typolist'] = false;
                    $parseHTML->procOptions['typohead'] = false;
                    $parseHTML->procOptions['keepPDIVattribs'] = true;
                    $parseHTML->procOptions['dontConvBRtoParagraph'] = true;
                    //$parseHTML->procOptions['preserveTags'].=',br';
                    if (!is_array($parseHTML->procOptions['HTMLparser_db.'])) {
                        $parseHTML->procOptions['HTMLparser_db.'] = array();
                    }
                    $parseHTML->procOptions['HTMLparser_db.']['xhtml_cleaning'] = true;
                    //trick to preserve strongtags
                    $parseHTML->procOptions['denyTags'] = 'strong';
                    //$parseHTML->procOptions['disableUnifyLineBreaks']=TRUE;
                    $parseHTML->procOptions['dontRemoveUnknownTags_db'] = true;

                    $translationValue = $parseHTML->TS_transform_db($translationValue,
                        $css = 1); // removes links from content if not called first!
                    //print_r($translationValue);
                    $translationValue = $parseHTML->TS_images_db($translationValue);
                    //print_r($translationValue);
                    $translationValue = $parseHTML->TS_links_db($translationValue);
                    //print_r($translationValue);
                    //	print_r($translationValue);
                    //substitute & with &amp;
                    $translationValue = str_replace('&amp;', '&', $translationValue);
                    $translation[$attrs['table']][$attrs['elementUid']][$attrs['key']] = $translationValue;
                } else {
                    $translation[$attrs['table']][$attrs['elementUid']][$attrs['key']] = $row['values'][0];
                }
            }
        }

        return $translation;
    }
}