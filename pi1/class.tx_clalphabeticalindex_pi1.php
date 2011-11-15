<?php
  /***************************************************************
   *  Copyright notice
   *
   *  (c) 2005 Christian Lerrahn (typo3@penpal4u.net)
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
  /**
   * Plugin 'Alphabetical site index' for the 'cl_alphabeticalindex' extension.
   *
   * @author	Christian Lerrahn <typo3@penpal4u.net>
   * @package	TYPO3
   * @subpackage	cl_alphabeticalindex
   */


require_once(PATH_tslib.'class.tslib_pibase.php');

class tx_clalphabeticalindex_pi1 extends tslib_pibase {
  var $prefixId = 'tx_clalphabeticalindex_pi1';		// Same as class name
  var $scriptRelPath = 'pi1/class.tx_clalphabeticalindex_pi1.php';	// Path to this script relative to the extension dir.
  var $extKey = 'cl_alphabeticalindex';	// The extension key.
  var $pi_checkCHash = TRUE;
  var $unicodeHack     = TRUE;
  var $collation = 'UTF-8';
	

  /**
   * Function produces the whole index and does all the formatting
   * @param	string	$content : function output is added to this string
   * @param	array	$conf : configuration array
   * @return	string	$content: output generated by this plugin
   */
  function main($content,$conf)	{

    $this->conf = $conf;
    $this->pi_setPiVarDefaults();
    $this->pi_loadLL();
    $cache = 1; // FIXME: we don't cache
    $this->pi_USER_INT_obj = 1; // set object to USER_INT

    // read data from flexforms and merge with TS
    $this->conf['pid_list'] = $this->cObj->data['pages']?$this->cObj->data['pages']:$conf['pidList'];
    $this->conf['recursive'] = $this->cObj->data['recursive']?$this->cObj->data['recursive']:$conf['recursive'];

    // save ATagParams
    $tmpATagParams = $GLOBALS['TSFE']->ATagParams;
    // set params for A tag
    $GLOBALS['TSFE']->ATagParams = $this->conf['index.']['ATagParams'];
	  
    $indexLine = '';
		
    // create array of all upper case letters in the (English) alphabet
    for ($i=65;$i<91;$i++) {
      $letters[$i-65] = chr($i);
    }
    $letters[] = '0-9';

    // exclude certain page types etc
    $genWhere .= $this->cObj->enableFields('pages');
    $excludeDok = isset($this->conf['excludeDoktypes'])?$this->conf['excludeDoktypes']:'5,6';
    $excludeDok = $GLOBALS['TYPO3_DB']->cleanIntList($excludeDok);
    if (!$this->conf['includeNotInMenu']) {
      $genWhere .= ' AND pages.nav_hide=0';
    }
    else {
      $excludeDokArr = t3lib_div::trimExplode(',',$excludeDok,1);
      $excludeDokArr = t3lib_div::removeArrayEntryByValue($excludeDokArr,'5');
      $excludeDok = implode(',',$excludeDokArr);
    }
    $genWhere .= $excludeDok?' AND pages.doktype NOT IN ('.$excludeDok.') AND pages.doktype < 200':' AND pages.doktype < 200';
    
    // exclude certain listed pages
    $excludePages = isset($this->conf['excludePages'])?$this->conf['excludePages']:'';
    $genWhere .= $excludePages?' AND pages.uid NOT IN ('.$excludePages.') ':'';
    
    // exclude certain pageTrees including subpages (coming next)      
    // get existing first letters in pages table
    // recursion level set in TS?
    $rec = isset($this->conf['recursive'])?$this->conf['recursive']:255;

    // root page set in TS or flex form? if not, use all pages
    $plist = $this->conf['pid_list']?$this->conf['pid_list']:0;
    $pl = $GLOBALS['TYPO3_DB']->cleanIntList($plist);
    $sp = t3lib_div::trimExplode(',',$pl,1);
    $pages = $this->pi_getPidList($pl,$rec);
    if ($this->conf['excludeStartingPoint']) {
      $npl = t3lib_div::trimExplode(',',$pages,1);
      foreach ($sp as $pageid) {
	$npl = t3lib_div::removeArrayEntryByValue($npl,$pageid);
      }
      $pages = implode(',',$npl);
    }
    $uidWhere = $pl?'pages.uid IN ('.$pages.')':'1=1';
    $uidWhere .= $genWhere;

    // numeric page titles?
    $numflag = 0;

    $exChar = array();

    switch ($this->conf['useTitleField']) {
    case 1 :
      $titleField = 'subtitle';
      break;
    case 2 :
      $titleField = 'nav_title';
      break;
    default :
      $titleField = 'title';      
    }
    
    // page-title SELECT to get existings chars, depends on used language, results multibyte chars
    if ($GLOBALS['TSFE']->sys_language_uid) {          
      $list = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('DISTINCT(LEFT(UPPER(TRIM(pages_language_overlay.'.$titleField.')),2)) AS my_index','pages pages LEFT JOIN pages_language_overlay pages_language_overlay ON pages.uid = pages_language_overlay.pid ',$uidWhere . ' AND sys_language_uid='.$GLOBALS['TSFE']->sys_language_uid,'my_index','my_index');
    } else {
      $list = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('DISTINCT(LEFT(UPPER(TRIM(pages.'.$titleField.')),2)) AS my_index','pages',$uidWhere,'my_index','my_index');
    }
       
    // run this through the unicode hack
    $list = $this->unicode_adjust($list);
   
    $exChar = array_keys($list);

    // t3lib_div::debug($exChar);
    // merge existing characters with minimum set of characters (if configured)
    if ($this->conf['index.']['showEmpty']) {
      $allChar = array_merge($letters,$exChar);
      $allChar = array_unique($allChar);
    }
    else {
      $allChar = $exChar;
      if ($numflag) {
	$allChar[] = '0-9';
      }
    }
    //t3lib_div::debug(array($exChar,$letters,$allChar));

    // create index
    foreach ($allChar as $indexChar) {
      if (in_array($indexChar,$exChar)) {
        if(intval($this->conf['list.']['useAnchors'])) {
          $indexLine .= $this->cObj->stdWrap('<a href="' . $this->pi_linkTP_keepPIvars_url() . '#' . $indexChar . '">' . $indexChar . '</a>',$this->conf['letter_stdWrap.']);
        }
        else {
	  $indexLine .= $this->cObj->stdWrap($this->pi_linkTP_keepPiVars($indexChar,array('achar' => $indexChar),0),$this->conf['letter_stdWrap.']);
        }
      }
      else {
	$indexLine .= $this->cObj->stdWrap($indexChar,$this->conf['letter_stdWrap.']);
      }
    }
    $content .= $this->cObj->stdWrap($indexLine,$this->conf['index_stdWrap.']);

    // if we have enabled anchor navigation, it makes no sense to split list of pages, because they will become unreachable
    $this->conf['list.']['defaultChar'] = $this->conf['list.']['useAnchors']?'full':$this->conf['list.']['defaultChar'];
    // use table 'pages' or 'pages_language_overlay' to look for title LIKE ..
    if ($GLOBALS['TSFE']->sys_language_uid) {
      $titleTable = 'pages_language_overlay';
    } else {
      $titleTable = 'pages';
    }

    // build list of pages
    // if no character from URL, use 'A'
    $defaultChar = $this->conf['list.']['defaultChar']?$this->conf['list.']['defaultChar']:$exChar[0];
    //    $defaultChar = ($defaultChar == 'full')?'':$defaultChar;
    $curChar = empty($this->piVars['achar'])?$defaultChar:(addslashes($this->piVars['achar']));
    $curChar = ($curChar == 'full')?'':$curChar;
    
    // if we use anchor navigation, than we shoukd know, when insert new anchor. This variable is responsible for that
    $curListChar = '-1';
    
    if ($curChar != '0-9') {
      $titleWhere = '(' . $titleTable . '.'.$titleField.' LIKE \''.$curChar.'%\'';
    }
    else {
      $titleWhere = '(' . $titleTable . '.'.$titleField.' LIKE \'0%\'';
      for ($i=1;$i<10;$i++) {
	$titleWhere .= ' OR ' . $titleTable . '.'.$titleField.' like \''.$i.'%\'';
      }
    }
    $titleWhere .= ')';

    // adjust UPPER and LOWER case search-query mistakes for multibyte chars
    if (ord($curChar) >127) {
      $titleWhere = '(' . $titleTable . '.'.$titleField.' LIKE \''.$curChar.'%\' OR ' . $titleTable . '.'.$titleField.' LIKE \''.mb_strtolower($curChar,$this->collation).'%\')';    
    }

    // page-SELECT depends on used language
    if ($GLOBALS['TSFE']->sys_language_uid) {
      $whereClause = $uidWhere . ' AND ' . $titleWhere . ' AND sys_language_uid='.$GLOBALS['TSFE']->sys_language_uid;         
      $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('pages.*, pages_language_overlay.'.$titleField,'pages pages LEFT JOIN pages_language_overlay pages_language_overlay ON pages.uid = pages_language_overlay.pid ',$whereClause,'','pages_language_overlay.'.$titleField);
    } else {
      $whereClause = $uidWhere. ' AND '.$titleWhere;
      $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*','pages',$whereClause,'','pages.'.$titleField);
    }
    // set params for A tag
    $GLOBALS['TSFE']->ATagParams = $this->conf['list.']['ATagParams'];
    $tmpRow = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
    while ($tmpRow) {
      $row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
      if ($GLOBALS['TSFE']->checkPageGroupAccess($row)) {
	if (($tmpRow[$titleField] == $row[$titleField]) || $duplFlag) {
	  $tmpRes = $GLOBALS['TYPO3_DB']->exec_SELECTquery('uid,'.$titleField,'pages','uid='.$tmpRow['pid'],'',$titleField);
	  $pRow = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($tmpRes);
	  $listLine = $this->pi_linkToPage($tmpRow[$titleField],$tmpRow['uid']);
	  if ($this->conf['list.']['showParent']) {
	    $listLine .= $this->cObj->stdWrap($this->pi_linkToPage($pRow[$titleField],$pRow['uid']),$this->conf['parentTitle_stdWrap.']);
	  }
	  if ($tmpRow[$titleField] == $row[$titleField]) {
	    $duplFlag = 1;
	  }
	  else {
	    $duplFlag = 0;
	  }
	}
	else {
	  $listLine = $this->pi_linkToPage($tmpRow[$titleField],$tmpRow['uid']);
	}

	$anchorName = mb_substr($tmpRow[$titleField],0,1,$this->collation);
	$anchorName = mb_strtoupper($anchorName,$this->collation);
	if((intval($this->conf['list.']['useAnchors'])) && ($curListChar != $anchorName)) {
	  $pageList .= '<a name="' . strtoupper($tmpRow[$titleField][0]) . '"></a>';
	  if (intval($this->conf['list.']['useAnchors.']['showAnchor'])) {
	    $pageList .= $this->cObj->stdWrap($anchorName,$this->conf['anchorName_stdWrap.']);
	  } 
	  $curListChar = mb_substr($tmpRow[$titleField],0,1,$this->collation);
	  $curListChar = mb_strtoupper($curListChar,$this->collation);
	}

	$tmpRow = $row;
	
	$pageList .= $this->cObj->stdWrap($listLine,$this->conf['pageTitle_stdWrap.']);
      }
    }  
    $content .= $this->cObj->stdWrap($pageList,$this->conf['pageList_stdWrap.']);

    // restore original A tag params
    $GLOBALS['TSFE']->ATagParams = $tmpATagParams;
		
    return $this->pi_wrapInBaseClass($content);
  }

  function unicode_adjust($list) {
    /**
     * copied from * tx_cfabwwwglossary
     * (c) 2007 Joscha Feth <joscha@feth.com>
     **/
  
    // ### BEGIN UNICODE HACK ###
  
    // This is needed because TYPO3 stores Unicode data in fields marked as containing latin1-characters.
    // If we only fetch the first character we get only the first byte of a 2-byte char.
    // Therefore we fetch the first two, translate it back to UTF-8 on the client and strip the second (unicode) character after that.
    if($this->unicodeHack) {
      $newlist = array();
      foreach($list as $item) {
	$item = mb_substr($item['my_index'],0,1,$this->collation);
	$item = mb_strtoupper($item,$this->collation);
	if (!(intval($item))) {
	  $newlist[$item] = array('my_index' => $item);
	} else {
	  $newlist['0-9'] = array('my_index' => '0-9');
	  
	  $numflag = 1;
	}
      }
      $list = $newlist;
      unset($newlist);
      
      $sort_flag = SORT_STRING;
      
      if(defined('SORT_LOCALE_STRING')) {
	//~ fix for PHP < 4.4.0
	$sort_flag = SORT_LOCALE_STRING;
      }
      ksort($list,$sort_flag);
    }       
    // ### END UNICODE HACK ###
    return $list;
  }

}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/cl_alphabeticalindex/pi1/class.tx_clalphabeticalindex_pi1.php'])	{
  include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/cl_alphabeticalindex/pi1/class.tx_clalphabeticalindex_pi1.php']);
 }

?>
