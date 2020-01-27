<?php

namespace TYPO3\CMS\Cal\Controller;

/**
 * This file is part of the TYPO3 extension Calendar Base (cal).
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 extension Calendar Base (cal) project - inspiring people to share!
 */
use PDO;
use TYPO3\CMS\Cal\Model\CalDate;
use TYPO3\CMS\Cal\Model\CalendarModel;
use TYPO3\CMS\Cal\Model\EventModel;
use TYPO3\CMS\Cal\Model\Model;
use TYPO3\CMS\Cal\Model\Pear\Date\Calc;
use TYPO3\CMS\Cal\Utility\Cache;
use TYPO3\CMS\Cal\Utility\Functions;
use TYPO3\CMS\Cal\Utility\Registry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Service\MarkerBasedTemplateService;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Plugin\AbstractPlugin;

/**
 * Main controller for the calendar base.
 * All requests come through this class
 * and are routed to the model and view layers for processing.
 */
class Controller extends AbstractPlugin
{
    /**
     * @var string
     */
    public $prefixId = 'tx_cal_controller'; // Same as class name

    /**
     * @var string
     */
    public $scriptRelPath = 'Classes/Controller/Controller.php'; // Path to this script relative to the extension dir.

    /**
     * @var string
     */
    public $locallangPath = 'Resources/Private/Language/locallang.xml';

    /**
     * @var string
     */
    public $extKey = 'cal'; // The extension key.

    /**
     * todo: check
     */
    public $dayStart;

    /**
     * @var string
     */
    public $ext_path;

    /**
     * @var ContentObjectRenderer
     */
    public $cObj; // The backReference to the mother cObj object set at call time

    /**
     * @var ContentObjectRenderer
     */
    public $local_cObj;

    /**
     * todo: check
     */
    public $link_vars;

    /**
     * @var string
     */
    public $pointerName = 'offset';

    /**
     * @var bool
     */
    public $error = false;

    /**
     * @var CalDate
     */
    public $getDateTimeObject;

    /**
     * @var int
     */
    public $SIM_ACCESS_TIME = 0;

    /**
     * @var Cache
     */
    public $cache;

    /**
     * @var array
     */
    public $confArr;

    /**
     * @var ConnectionPool
     */
    public $connectionPool;

    /**
     * @var MarkerBasedTemplateService
     */
    protected $markerBasedTemplateService;

    public function __construct()
    {
        parent::__construct();
        $this->markerBasedTemplateService = GeneralUtility::makeInstance(MarkerBasedTemplateService::class);
    }

    /**
     * Main controller function that serves as the entry point from TYPO3.
     *
     * @param $content
     * @param $conf
     * @return string of calendar data.
     * @throws \TYPO3\CMS\Core\Http\ImmediateResponseException
     */
    public function main($content, $conf): string
    {
        $this->conf = &$conf;

        $this->confArr = unserialize($GLOBALS ['TYPO3_CONF_VARS'] ['EXT'] ['extConf'] ['cal']);

        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        $this->conf ['useInternalCaching'] = 1;
        $this->conf ['cachingEngine'] = 'cachingFramework';

        $this->cacheHandling();

        // Set the week start day, and then include CalDate so that the week start day is already defined.
        $this->setWeekStartDay();

        $this->cleanPiVarParam($this->piVars);
        $this->clearPiVarParams();
        $this->validateDateRanges();
        $this->getParamsFromSession();
        $this->initCaching();

        $hookObjectsArr = $this->getHookObjectsArray('controllerClass');
        // Hook: initCal
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'initCal')) {
                $hookObj->initCal($this);
            }
        }

        $return = $this->initConfigs();
        if (!$this->error) {
            $return .= $this->getContent();
        }

        return $return;
    }

    /**
     * @throws \TYPO3\CMS\Core\Http\ImmediateResponseException
     */
    protected function cacheHandling()
    {
        if (!$this->conf ['isUserInt']) {
            $this->pi_checkCHash = true;
            $requestedNoCache = GeneralUtility::_GP('no_cache');
            if ($requestedNoCache) {
                $this->pi_checkCHash = false;
                $GLOBALS ['TSFE']->set_no_cache();
            }
            if (!$requestedNoCache && count($this->piVars)) {
                $GLOBALS ['TSFE']->reqCHash();
            }
            $this->pi_USER_INT_obj = 0;
        }
    }

    /**
     * Cleans all piVars for XSS vulnerabilities using external library and
     * updates values within $this->piVars as it cleans.
     *
     * @param mixed    Array of nested piVars or individual piVar value.
     */
    public function cleanPiVarParam(&$param)
    {
        if (is_array($param)) {
            $arrayKeys = array_keys($param);
            foreach ($arrayKeys as $key) {
                $this->cleanPiVarParam($param [$key]);
            }
        } else {
            // Don't use default replaceString of <x> because strip-tags will later remove it.
            $param = Functions::removeXSS($param, '--xxx--');
        }
    }

    /**
     * Validates that various date piVars are within valid ranges.  Any dates outside a valid range have
     * their piVars unset.
     */
    public function validateDateRanges()
    {
        if (isset($this->piVars['day'])) {
            $this->piVars['day'] = intval($this->piVars['day']);
            if ($this->piVars['day'] < 1 || $this->piVars['day'] > 31) {
                unset($this->piVars['day']);
            }
        }

        if (isset($this->piVars['month'])) {
            $this->piVars['month'] = intval($this->piVars['month']);
            if ($this->piVars['month'] < 1 || $this->piVars['month'] > 12) {
                unset($this->piVars['month']);
            }
        }

        if (isset($this->piVars['year'])) {
            $this->piVars['year'] = intval($this->piVars['year']);
            if ($this->piVars['year'] < 1900 || $this->piVars['year'] > 5000) {
                unset($this->piVars['year']);
            }
        }

        if (isset($this->piVars['weekday'])) {
            $this->piVars['weekday'] = intval($this->piVars['weekday']);
            if ($this->piVars['weekday'] < 0 || $this->piVars['weekday'] > 6) {
                unset($this->piVars['weekday']);
            }
        }

        if (isset($this->piVars['getdate']) && strlen($this->piVars['getdate']) !== 8) {
            unset($this->piVars['getdate']);
        }
    }

    /**
     * @param bool
     * @return string
     */
    public function getContent($notEmpty = true): string
    {
        $return = '';
        $count = 0;
        do {
            // category check:
            $catArray = GeneralUtility::trimExplode(',', $this->conf ['category'], 1);
            $allowedCatArray = GeneralUtility::trimExplode(',', $this->conf ['view.'] ['allowedCategory'], 1);
            $compareResult = array_diff($allowedCatArray, $catArray);
            if (empty($compareResult) && $this->conf ['view'] !== 'create_event' && $this->conf ['view'] !== 'edit_event') {
                unset($this->piVars ['category']);
            }
            $count++; // Just to make sure we are not getting an endless loop
            /* Convert view names (search_event) to function names (searchevent) */
            $viewFunction = str_replace('_', '', $this->conf ['view']);

            /* @todo Hack! List is a reserved name so we have to change the function name. */
            if ($viewFunction === 'list') {
                $viewFunction = 'listView';
            }

            if (method_exists($this, $viewFunction)) {
                /* Call appropriate view function */
                $return .= $this->$viewFunction();
            } else {
                $customModel = GeneralUtility::makeInstanceService('cal_view', $this->conf ['view']);
                if (!is_object($customModel)) {
                    $return .= $this->conf ['view.'] ['noViewFoundHelpText'] . ' ' . $viewFunction;
                } else {
                    $return .= $customModel->start();
                }
            }
        } while ($return === '' && $count < 4 && $notEmpty);
        $return = $this->finish($return);

        if ($this->conf ['view'] === 'rss' || $this->conf ['view'] === 'ics' || $this->conf ['view'] === 'single_ics' || $this->conf ['view'] === 'load_events' || $this->conf ['view'] === 'load_todos' || $this->conf ['view'] === 'load_rights') {
            return $return;
        }
        if ($this->conf ['view.'] [$this->conf ['view'] . '.'] ['sendOutWithXMLHeader']) {
            header('Content-Type: text/xml');
        }

        $additionalWrapperClasses = GeneralUtility::trimExplode(',', $this->conf ['additionalWrapperClasses'], 1);

        if ($this->conf ['noWrapInBaseClass'] || $this->conf ['view.'] ['enableAjax']) {
            return $return;
        }
        return $this->pi_wrapInBaseClass($return, $additionalWrapperClasses);
    }

    /**
     * @return string
     */
    public function initConfigs(): string
    {
        // If an event record has been added through Insert Records, set some defaults.
        if ($this->conf ['displayCurrentRecord']) {
            $data = &$this->cObj->data;
            $this->conf ['pidList'] = $data ['pid'];
            $this->conf ['view.'] ['allowedViews'] = 'event';
            $this->conf ['getdate'] = $this->conf ['_DEFAULT_PI_VARS.'] ['getdate'] = $data ['start_date'];
            $this->conf ['uid'] = $this->conf ['_DEFAULT_PI_VARS.'] ['uid'] = $data ['uid'];
            $this->conf ['type'] = $this->conf ['_DEFAULT_PI_VARS.'] ['type'] = 'tx_cal_phpicalendar';
            $this->conf ['view'] = $this->conf ['_DEFAULT_PI_VARS.'] ['view'] = 'event';
        }

        if (!$this->conf ['dontListenToPiVars']) {
            $this->pi_setPiVarDefaults(); // Set default piVars from TS
        }

        // Jan 18032006 start
        if ($this->cObj->data ['pi_flexform']) {
            $this->pi_initPIflexForm(); // Init and get the flexform data of the plugin
            $piFlexForm = $this->cObj->data ['pi_flexform'];
            $this->updateConfWithFlexform($piFlexForm);
        }

        // apply stdWrap to pages and pidList
        $this->conf ['pages'] = $this->cObj->stdWrap($this->conf ['pages'], $this->conf ['pages.']);
        $this->conf ['pidList'] = $this->cObj->stdWrap($this->conf ['pidList'], $this->conf ['pidList.']);

        self::updateIfNotEmpty($this->conf ['pages'], $this->cObj->data ['pages']);
        // don't use "updateIfNotEmpty" here, as the default value of "recursive" is 0 and thus not empty and will always override TS settings.
        if ($this->cObj->data ['recursive']) {
            $this->conf ['recursive'] = $this->cObj->data ['recursive'];
        }

        $this->conf ['pidList'] = $this->pi_getPidList(
            $this->conf ['pages'] . ',' . $this->conf ['pidList'],
            $this->conf ['recursive']
        );

        if (!$this->conf ['pidList'] || $this->conf ['pidList'] === '') {
            $this->error = true;
            return '<b>Calendar error: please configure the pidList (calendar plugin -> startingpoints or plugin.tx_cal_controller.pidList or for ics in constants)</b>';
        }

        if ($this->conf ['language']) {
            $this->LLkey = $this->conf ['language'];
        }
        $tempScriptRelPath = $this->scriptRelPath;
        $this->scriptRelPath = $this->locallangPath;
        $this->pi_loadLL();
        $this->scriptRelPath = $tempScriptRelPath;

        $this->conf ['cache'] = 1;
        $GLOBALS ['TSFE']->addCacheTags([
            'cal'
        ]);

        $location = self::convertLinkVarArrayToList($this->piVars ['location_ids']);

        if ($this->piVars ['view'] === $this->piVars ['lastview']) {
            unset($this->piVars ['lastview']);
        }

        if ($this->piVars ['getdate'] === '') {
            $this->conf ['getdate'] = date('Ymd');
        } else {
            $this->conf ['getdate'] = intval($this->piVars ['getdate']);
        }
        if ($this->piVars ['jumpto']) {
            /** @var DateParser $dp */
            $dp = GeneralUtility::makeInstance(DateParser::class);
            $dp->parse($this->piVars ['jumpto'], $this->conf ['dateParserConf.']);
            $newGetdate = $dp->getDateObjectFromStack();
            $this->conf ['getdate'] = $newGetdate->format('%Y%m%d');
            unset($this->piVars ['getdate'], $this->piVars ['jumpto']);
        }

        // date and strtotime should be ok here
        if ($this->conf ['getdate'] <= date(
            'Ymd',
            strtotime($this->conf ['view.'] ['startLinkRange'])
            ) || $this->conf ['getdate'] >= date(
                'Ymd',
                strtotime($this->conf ['view.'] ['endLinkRange'])
            )) {
            $GLOBALS ['TSFE']->additionalHeaderData ['cal'] = '<meta name="robots" content="index,nofollow" />';
            $GLOBALS ['TSFE']->page ['no_search'] = 0;
        }

        if (!$this->conf ['dontListenToPiVars']) {
            $this->conf ['view'] = htmlspecialchars(strip_tags($this->piVars ['view']));
            $this->conf ['lastview'] = htmlspecialchars(strip_tags($this->piVars ['lastview']));
            $this->conf ['uid'] = intval($this->piVars ['uid']);
            $this->conf ['type'] = htmlspecialchars(strip_tags($this->piVars ['type']));
            $this->conf ['monitor'] = htmlspecialchars(strip_tags($this->piVars ['monitor']));
            $this->conf ['gettime'] = intval($this->piVars ['gettime']);
            $this->conf ['postview'] = intval($this->piVars ['postview']);
            $this->conf ['page_id'] = intval($this->piVars ['page_id']);
            $this->conf ['option'] = htmlspecialchars(strip_tags($this->piVars ['option']));
            $this->conf ['switch_calendar'] = intval($this->piVars ['switch_calendar']);
            $this->conf ['location'] = $location;
            $this->conf ['preview'] = intval($this->piVars ['preview']);
        }

        if (!is_array($this->conf ['view.'] ['allowedViews'])) {
            $this->conf ['view.'] ['allowedViews'] = array_unique(GeneralUtility::trimExplode(
                ',',
                str_replace('~', ',', $this->conf ['view.'] ['allowedViews'])
            ));
        }

        // only merge customViews if not empty. Otherwhise the array with allowedViews will have empty entries which will end up in wrong behavior in the rightsServies, which is checking for the number of allowed views.
        if (!empty($this->conf ['view.'] ['customViews'])) {
            $this->conf ['view.'] ['allowedViews'] = array_unique(array_merge(
                $this->conf ['view.'] ['allowedViews'],
                GeneralUtility::trimExplode(',', $this->conf ['view.'] ['customViews'], 1)
            ));
        }

        $allowedViewsByViewPid = $this->getAllowedViewsByViewPid();
        $this->conf ['view.'] ['allowedViewsToLinkTo'] = array_unique(array_merge(
            $this->conf ['view.'] ['allowedViews'],
            $allowedViewsByViewPid
        ));

        // change by Franz: if there is no view parameter given (empty), fall back to the first allowed view
        // This is necessary when you're not passing the viewParameter within the URL and like to handle the correct views based on seperate pages for each view.
        if (!$this->conf ['view'] && $this->conf ['view.'] ['allowedViews'] [0]) {
            $this->conf ['view'] = $this->conf ['view.'] ['allowedViews'] [0];
        }

        $this->getDateTimeObject = new CalDate($this->conf ['getdate'] . '000000');

        if ($this->getDateTimeObject->month > 12) {
            $this->getDateTimeObject->month = 12;
        } elseif ($this->getDateTimeObject->month < 1) {
            $this->getDateTimeObject->month = 1;
        }
        while (!Calc::isValidDate(
            $this->getDateTimeObject->day,
            $this->getDateTimeObject->month,
            $this->getDateTimeObject->year
        )) {
            if ($this->getDateTimeObject->day > 28) {
                $this->getDateTimeObject->day--;
            } elseif ($this->getDateTimeObject->day < 1) {
                $this->getDateTimeObject->day = 1;
            }
        }

        $this->getDateTimeObject->setTZbyID('UTC');
        $this->conf ['day'] = $this->getDateTimeObject->getDay();
        $this->conf ['month'] = $this->getDateTimeObject->getMonth();
        $this->conf ['year'] = $this->getDateTimeObject->getYear();

        self::initRegistry($this);
        $rightsObj = &Registry::Registry('basic', 'rightscontroller');
        $rightsObj = GeneralUtility::makeInstanceService('cal_rights_model', 'rights');
        $rightsObj->setDefaultSaveToPage();

        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $modelObj = new ModelController();

        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $viewObj = GeneralUtility::makeInstance(ViewController::class);

        $this->checkCalendarAndCategory();

        $this->conf ['view'] = $rightsObj->checkView($this->conf ['view']);

        $this->pointerName = $this->conf ['view.'] ['list.'] ['pageBrowser.'] ['pointer'] ?: $this->pointerName;

        // links to files will be rendered with an absolute path
        if (in_array($this->conf ['view'], [
            'ics',
            'rss',
            'singl_ics'
        ])) {
            $GLOBALS ['TSFE']->absRefPrefix = GeneralUtility::getIndpEnv('TYPO3_SITE_URL');
        }

        $hookObjectsArr = $this->getHookObjectsArray('controllerClass');

        // Hook: configuration
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'configuration')) {
                $hookObj->configuration($this);
            }
        }

        return '';
    }

    /**
     *
     */
    public function initCaching()
    {
        $this->SIM_ACCESS_TIME = $GLOBALS ['SIM_ACCESS_TIME'];
        // fallback for TYPO3 < 4.2
        if (!$this->SIM_ACCESS_TIME) {
            $simTime = $GLOBALS ['SIM_EXEC_TIME'];
            $this->SIM_ACCESS_TIME = $simTime - ($simTime % 60);
        }

        if ($this->conf ['useInternalCaching']) {
            $cachingEngine = $this->conf ['cachingEngine'];

            if ($cachingEngine === 'cachingFramework') {
                if (!is_object($GLOBALS ['typo3CacheFactory']) || !isset($GLOBALS ['TYPO3_CONF_VARS'] ['SYS'] ['caching'] ['cacheConfigurations'] ['tx_cal_cache'] ['backend'])) {
                    // if there's no cacheFactory object fall back to internal caching (TYPO3 < 4.3)
                    $cachingEngine = 'internal';
                }
            }

            if (!$cachingEngine) {
                $cachingEngine = 'internal';
            }

            $i = $this->conf ['cacheClearMode'];

            if ($i === 'lifetime') {
                $lifetime = $this->conf ['cacheLifetime'];
            } else { // normal
                $lifetime = $GLOBALS ['TSFE']->get_cache_timeout(); // seconds until a cached page is too old
            }
            $this->cache = new Cache($cachingEngine);
            $this->cache->lifetime = $lifetime;
            $this->cache->ACCESS_TIME = $this->SIM_ACCESS_TIME;
        }
    }

    /**
     *
     */
    public function checkCalendarAndCategory()
    {
        $calendar = '';

        $allCategoryByParentId = [];
        $catIDs = [];

        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $rightsObj = &Registry::Registry('basic', 'rightscontroller');

        // et all categories
        $categoryArray = $modelObj->findAllCategories($this->confArr ['categoryService'], '', $this->conf ['pidList']);

        foreach ((array)$categoryArray [$this->confArr ['categoryService']] [0] [0] as $category) {
            $row = $category->row;
            $allCategoryByParentId [$row ['parent_category']] [] = $row;
            $catIDs [] = $row ['uid'];
        }

        if ((int)$this->piVars ['categorySelection'] === 1 && empty($this->piVars ['category'])) {
            $catIDs = [];
        } else {
            unset($this->piVars ['categorySelection']);
        }
        $this->conf ['view.'] ['category'] = implode(',', array_map(function ($v, $k) {
            return $v;
        }, $catIDs, array_keys($catIDs)));
        // 		$this->conf ['view.'] ['category'] = implode (',', $catIDs);
        if (!$this->conf ['view.'] ['category']) {
            $this->conf ['view.'] ['category'] = '0';
        }
        $category = $this->conf ['view.'] ['category'];
        $this->conf ['view.'] ['allowedCategory'] = $this->conf ['view.'] ['category'];

        $piVarCategory = self::convertLinkVarArrayToList($this->piVars ['category']);

        if ($piVarCategory) {
            if ($this->conf ['view.'] ['category']) {
                $categoryArray = explode(',', $category);
                $piVarCategoryArray = explode(',', $piVarCategory);
                $sameValues = array_intersect($categoryArray, $piVarCategoryArray);
                if (empty($sameValues)) {
                    $category = $this->conf ['view.'] ['category'];
                } else {
                    $category = self::convertLinkVarArrayToList($sameValues);
                }
            } else {
                $category = $piVarCategory;
            }
            $category = is_array($category) ? implode(',', $category) : $category;
        }

        // elect calendars
        // et all first
        $allCalendars = [];
        $calendarArray = $modelObj->findAllCalendar('tx_cal_calendar', $this->conf ['pidList']);
        foreach ((array)$calendarArray ['tx_cal_calendar'] as $calendarObject) {
            $allCalendars [] = $calendarObject->getUid();
        }

        // ompile calendar array
        switch ($this->conf ['view.'] ['calendarMode']) {
            case 0: // show all
                $calendar = $this->conf ['view.'] ['calendar'] = $this->conf ['view.'] ['allowedCalendar'] = implode(
                    ',',
                    $allCalendars
                );
                break;
            case 1: // how selected
                if ($this->conf ['view.'] ['calendar']) {
                    $calendar = $this->conf ['view.'] ['calendar'];
                    $this->conf ['view.'] ['allowedCalendar'] = $this->conf ['view.'] ['calendar'];
                }
                break;
            case 2: // xclude selected
                if ($this->conf ['view.'] ['calendar']) {
                    $calendar = $this->conf ['view.'] ['calendar'] = implode(
                        ',',
                        array_diff($allCalendars, explode(',', $this->conf ['view.'] ['calendar']))
                    );
                    $this->conf ['view.'] ['allowedCalendar'] = $this->conf ['view.'] ['calendar'];
                } else {
                    $calendar = $this->conf ['view.'] ['calendar'] = implode(',', $allCalendars);
                    $this->conf ['view.'] ['allowedCalendar'] = $this->conf ['view.'] ['calendar'];
                }
                break;
        }

        if ($rightsObj->isLoggedIn()) {
            $connection = $this->connectionPool->getConnectionForTable('fe_users');
            $result = $connection->select(
                ['tx_cal_calendar_subscription'],
                'fe_users',
                ['uid' => $rightsObj->getUserId()]
            );
            if ($result->rowCount() > 0) {
                $row = $result->fetch(PDO::FETCH_ASSOC);
                $this->conf ['view.'] ['calendar.'] ['subscription'] = $row ['tx_cal_calendar_subscription'];
            }
        }

        if ($this->conf ['view.'] ['calendar.'] ['subscription'] !== '') {
            $calendar = $this->conf ['view.'] ['allowedCalendar'] = $this->conf ['view.'] ['calendar'] = implode(
                ',',
                array_diff(
                    explode(',', $calendar),
                    explode(',', $this->conf ['view.'] ['calendar.'] ['subscription'])
                )
            );
        }

        $piVarCalendar = self::convertLinkVarArrayToList($this->piVars ['calendar']);
        if ($piVarCalendar) {
            if ($this->conf ['view.'] ['calendar']) {
                $calendarArray = explode(',', $calendar);
                $piVarCalendarArray = explode(',', $piVarCalendar);
                $sameValues = array_intersect($calendarArray, $piVarCalendarArray);
                $calendar = self::convertLinkVarArrayToList($sameValues);
            } else {
                $calendar = $piVarCalendar;
            }
            $calendar = is_array($calendar) ? implode(',', $calendar) : $calendar;
        }

        if ($this->conf ['view.'] ['freeAndBusy.'] ['enable']) {
            $this->conf ['option'] = 'freeandbusy';
            $this->conf ['view.'] ['calendarMode'] = 1;
            $calendar = intval($this->piVars ['calendar']) ?: $this->conf ['view.'] ['freeAndBusy.'] ['defaultCalendarUid'];
            $this->conf ['view.'] ['calendar'] = $calendar;
        }

        $this->conf ['category'] = $category;
        $this->conf ['calendar'] = $calendar;
        $this->conf ['view.'] ['allowedCategories'] = $category;
        $this->conf ['view.'] ['allowedCalendar'] = $calendar;
    }

    /**
     * Sets up a hook in the controller's PHP file with the specified name.
     * @param    string    The name of the hook.
     * @return    array    The array of objects implementing this hoook.
     */
    public function getHookObjectsArray($hookName): array
    {
        return Functions::getHookObjectsArray($this->prefixId, $hookName);
    }

    /**
     * Executes the specified function for each item in the array of hook objects.
     * @param    array    The array of hook objects.
     * @param    string    The name of the function to execute.
     */
    public function executeHookObjectsFunction($hookObjectsArray, $function)
    {
        foreach ($hookObjectsArray as $hookObj) {
            if (method_exists($hookObj, $function)) {
                $hookObj->$function($this);
            }
        }
    }

    /**
     * Clears $this-conf vars related to view and lastview. Useful when calling save and remove functions.
     */
    public function clearConfVars()
    {
        $this->initConfigs();
        $viewParams = $this->shortenLastViewAndGetTargetViewParameters(true);
        $this->conf ['view'] = $viewParams ['view'];
        $this->conf ['lastview'] = '';
        $rightsObj = &Registry::Registry('basic', 'rightscontroller');
        $this->conf ['view'] = $rightsObj->checkView($this->conf ['view']);
        $this->conf ['uid'] = $viewParams ['uid'];
        $this->conf ['type'] = $viewParams ['type'];
    }

    /**
     * @return array
     */
    public function getAllowedViewsByViewPid(): array
    {
        // for now, ownly check basic views.
        $allowedViews = [];
        $regularViews = [
            'day',
            'week',
            'month',
            'year',
            'list',
            'event',
            'location',
            'organizer'
        ];
        $feEditingViews = [
            'event',
            'location',
            'organizer',
            'calendar',
            'category'
        ];
        $editingTypes = [
            'create',
            'edit',
            'delete'
        ];

        foreach ($regularViews as $view) {
            if ($this->conf ['view.'] [$view . '.'] [$view . 'ViewPid']) {
                $allowedViews [] = $view;
            }
        }

        foreach ($feEditingViews as $view) {
            foreach ($editingTypes as $type) {
                if ($this->conf ['view.'] [$view . '.'] [$type . ucfirst($view) . 'ViewPid']) {
                    $allowedViews [] = $type . '_' . $view;
                }
            }
        }

        return $allowedViews;
    }

    /**
     *
     */
    public function saveEvent()
    {
        $hookObjectsArr = $this->getHookObjectsArray('saveEventClass');
        // Hook: preSaveEvent
        $this->executeHookObjectsFunction($hookObjectsArr, 'preSaveEvent');

        $pid = $this->conf ['rights.'] ['create.'] ['event.'] ['saveEventToPid'];
        if (!is_numeric($pid)) {
            $pid = $GLOBALS ['TSFE']->id;
        }

        $eventType = intval($this->piVars ['event_type']);
        $uid = intval($this->piVars ['uid']);
        $modelObj = &Registry::Registry('basic', 'modelcontroller');

        if ((int)$GLOBALS ['TSFE']->fe_user->getKey('ses', 'tx_cal_controller_creatingEvent') === '1') {

            /** @var EventModel $event */
            $event = null;
            if ($eventType === Model::EVENT_TYPE_TODO) {
                $event = $modelObj->saveTodo($this->conf ['uid'], $this->conf ['type'], $pid);
            } else {
                $event = $modelObj->saveEvent($this->conf ['uid'], $this->conf ['type'], $pid);
            }

            // Hook: postSaveEvent
            $this->executeHookObjectsFunction($hookObjectsArr, 'postSaveEvent');

            if ($this->conf ['view.'] ['enableAjax']) {
                if (is_object($event)) {
                    if (in_array($event->getFreq(), [
                            'year',
                            'month',
                            'week',
                            'day'
                        ]) || ($event->getRdate() && in_array($event->getRdateType(), [
                                'date',
                                'datetime',
                                'period'
                            ]))) {
                        $this->conf ['view.'] [$this->conf ['view'] . '.'] ['minDate'] = $event->getStart()->format('%Y%m%d');
                        $this->conf ['view.'] [$this->conf ['view'] . '.'] ['maxDate'] = $this->piVars ['maxDate'];

                        $eventArray = $modelObj->findEvent(
                            $event->getUid(),
                            $this->conf ['type'],
                            $this->conf ['pidList'],
                            false,
                            false,
                            true,
                            false,
                            true,
                            '0,1,2,3,4'
                        );
                        $ajaxStringArray = [];
                        $dateKeys = array_keys($eventArray);
                        foreach ($dateKeys as $dateKey) {
                            $timeKeys = array_keys($eventArray [$dateKey]);
                            foreach ($timeKeys as $timeKey) {
                                $eventKeys = array_keys($eventArray [$dateKey] [$timeKey]);
                                foreach ($eventKeys as $eventKey) {
                                    $eventX = &$eventArray [$dateKey] [$timeKey] [$eventKey];
                                    $ajaxStringArray [] = '{' . $this->getEventAjaxString($eventX) . '}';
                                }
                            }
                        }
                        $ajaxString = implode(',', $ajaxStringArray);
                        echo '[' . $ajaxString . ']';
                    } else {
                        $ajaxString = $this->getEventAjaxString($event);
                        $ajaxString = str_replace([
                            chr(13),
                            "\n"
                        ], [
                            '',
                            ''
                        ], $ajaxString);
                        echo '[{' . $ajaxString . '}]';
                    }
                } else {
                    echo '{"success": false,"errors": {text:"event was not saved"}}';
                }
            }
        }

        unset($this->piVars ['type'], $this->conf ['type']);
        $this->conf ['type'] = '';
        $this->clearConfVars();

        $GLOBALS ['TSFE']->fe_user->setKey('ses', 'tx_cal_controller_creatingEvent', '0');
        $GLOBALS ['TSFE']->storeSessionData();

        $this->checkRedirect($uid ? 'edit' : 'create', 'event');
    }

    /**
     * @return string
     */
    public function removeEvent(): string
    {
        $eventType = intval($this->piVars ['event_type']);
        $hookObjectsArr = $this->getHookObjectsArray('removeEventClass');
        // Hook: preRemoveEvent
        $this->executeHookObjectsFunction($hookObjectsArr, 'preRemoveEvent');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        if ($eventType === Model::EVENT_TYPE_TODO) {
            $modelObj->removeTodo($this->conf ['uid'], $this->conf ['type']);
        } else {
            $modelObj->removeEvent($this->conf ['uid'], $this->conf ['type']);
        }

        // Hook: postRemoveEvent
        $this->executeHookObjectsFunction($hookObjectsArr, 'postRemoveEvent');

        if ($this->conf ['view.'] ['enableAjax']) {
            return 'true';
        }

        $this->clearConfVars();
        $this->checkRedirect('delete', 'event');

        return '';
    }

    /**
     * @return string
     */
    public function createExceptionEvent(): string
    {
        $getdate = $this->conf ['getdate'];
        $pidList = $this->conf ['pidList'];
        $hookObjectsArr = $this->getHookObjectsArray('createExceptionEventClass');
        // Hook: preCreateExceptionEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preCreateExceptionEventRendering')) {
                $hookObj->preCreateExceptionEventRendering($this, $getdate, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnCreateExceptionEvent = $viewObj->drawCreateExceptionEvent($getdate, $pidList);

        // Hook: postCreateExceptionEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postCreateExceptionEventRendering')) {
                $hookObj->postCreateExceptionEventRendering($drawnCreateExceptionEvent, $this);
            }
        }

        return $drawnCreateExceptionEvent;
    }

    /**
     *
     */
    public function saveExceptionEvent()
    {
        $hookObjectsArr = $this->getHookObjectsArray('saveExceptionEventClass');

        // Hook: preSaveExceptionEvent
        $this->executeHookObjectsFunction($hookObjectsArr, 'preSaveExceptionEvent');

        $pid = $this->conf ['rights.'] ['create.'] ['exceptionEvent.'] ['saveExceptionEventToPid'];
        if (!is_numeric($pid)) {
            $pid = $GLOBALS ['TSFE']->id;
        }
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $modelObj->saveExceptionEvent($this->conf ['uid'], $this->conf ['type'], $pid);

        // Hook: postSaveExceptionEvent
        $this->executeHookObjectsFunction($hookObjectsArr, 'postSaveExceptionEvent');

        $this->clearConfVars();
        $this->checkRedirect($this->piVars ['uid'] ? 'edit' : 'create', 'exceptionEvent');
    }

    /**
     *
     */
    public function removeCalendar()
    {
        $hookObjectsArr = $this->getHookObjectsArray('removeCalendarClass');
        // Hook: preRemoveCalendar
        $this->executeHookObjectsFunction($hookObjectsArr, 'preRemoveCalendar');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $modelObj->removeCalendar($this->conf ['uid'], $this->conf ['type']);

        // Hook: postRemoveCalendar
        $this->executeHookObjectsFunction($hookObjectsArr, 'postRemoveCalendar');

        $this->clearConfVars();
        $this->checkRedirect('delete', 'calendar');
    }

    /**
     *
     */
    public function removeCategory()
    {
        $hookObjectsArr = $this->getHookObjectsArray('removeCategoryClass');
        // Hook: preRemoveCategory
        $this->executeHookObjectsFunction($hookObjectsArr, 'preRemoveCategory');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $modelObj->removeCategory($this->conf ['uid'], $this->conf ['type']);

        // Hook: postRemoveCategory
        $this->executeHookObjectsFunction($hookObjectsArr, 'postRemoveCategory');

        $this->clearConfVars();
        $this->checkRedirect('delete', 'category');
    }

    /**
     *
     */
    public function removeLocation()
    {
        $hookObjectsArr = $this->getHookObjectsArray('removeLocationClass');
        // Hook: preRemoveLocation
        $this->executeHookObjectsFunction($hookObjectsArr, 'preRemoveLocation');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $modelObj->removeLocation($this->conf ['uid'], $this->conf ['type']);

        // Hook: postRemoveLocation
        $this->executeHookObjectsFunction($hookObjectsArr, 'postRemoveLocation');

        $this->clearConfVars();
        $this->checkRedirect('delete', 'location');
    }

    /**
     *
     */
    public function removeOrganizer()
    {
        $hookObjectsArr = $this->getHookObjectsArray('removeOrganizerClass');
        // Hook: preRemoveOrganizer
        $this->executeHookObjectsFunction($hookObjectsArr, 'preRemoveOrganizer');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $modelObj->removeOrganizer($this->conf ['uid'], $this->conf ['type']);

        // Hook: postRemoveOrganizer
        $this->executeHookObjectsFunction($hookObjectsArr, 'postRemoveOrganizer');

        $this->clearConfVars();
        $this->checkRedirect('delete', 'organizer');
    }

    /**
     * @return string
     */
    public function saveLocation(): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('saveLocationClass');

        // Hook: preSaveLocation
        $this->executeHookObjectsFunction($hookObjectsArr, 'preSaveLocation');

        $pid = $this->conf ['rights.'] ['create.'] ['location.'] ['saveLocationToPid'];
        if (!is_numeric($pid)) {
            $pid = $GLOBALS ['TSFE']->id;
        }
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $location = $modelObj->saveLocation($this->conf ['uid'], $this->conf ['type'], $pid);

        if ($this->conf ['view.'] ['enableAjax']) {
            return '{' . $this->getEventAjaxString($location) . '}';
        }

        // Hook: postSaveLocation
        $this->executeHookObjectsFunction($hookObjectsArr, 'postSaveLocation');

        $this->clearConfVars();
        $this->checkRedirect($this->piVars ['uid'] ? 'edit' : 'create', 'location');

        return '';
    }

    /**
     * @return string
     */
    public function saveOrganizer(): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('saveOrganizerClass');
        // Hook: preSaveOrganizer
        $this->executeHookObjectsFunction($hookObjectsArr, 'preSaveOrganizer');

        $pid = $this->conf ['rights.'] ['create.'] ['organizer.'] ['saveOrganizerToPid'];
        if (!is_numeric($pid)) {
            $pid = $GLOBALS ['TSFE']->id;
        }
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $organizer = $modelObj->saveOrganizer($this->conf ['uid'], $this->conf ['type'], $pid);

        if ($this->conf ['view.'] ['enableAjax']) {
            return '{' . $this->getEventAjaxString($organizer) . '}';
        }

        // Hook: postSaveOrganizer
        $this->executeHookObjectsFunction($hookObjectsArr, 'postSaveOrganizer');

        $this->clearConfVars();
        $this->checkRedirect($this->piVars ['uid'] ? 'edit' : 'create', 'organizer');

        return '';
    }

    /**
     * @return string
     */
    public function saveCalendar(): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('saveCalendarClass');
        // Hook: preSaveCalendar
        $this->executeHookObjectsFunction($hookObjectsArr, 'preSaveCalendar');

        $pid = $this->conf ['rights.'] ['create.'] ['calendar.'] ['saveCalendarToPid'];
        if (!is_numeric($pid)) {
            $pid = $GLOBALS ['TSFE']->id;
        }
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        /** @var CalendarModel $calendar */
        $calendar = $modelObj->saveCalendar($this->conf ['uid'], $this->conf ['type'], $pid);

        if ($this->conf ['view.'] ['enableAjax']) {
            if (is_object($calendar)) {
                $calendar = $modelObj->findCalendar($calendar->getUid(), $this->conf ['type'], $pid);
                $ajaxString = $this->getEventAjaxString($calendar);
                $ajaxString = str_replace([
                    chr(13),
                    "\n"
                ], [
                    '',
                    ''
                ], $ajaxString);
                return '{' . $ajaxString . '}';
            }
            return '{"success": false,"errors": {text:"calendar was not saved"}}';
        }

        // Hook: postSaveCalendar
        $this->executeHookObjectsFunction($hookObjectsArr, 'postSaveCalendar');

        $this->clearConfVars();
        $this->checkRedirect($this->piVars ['uid'] ? 'edit' : 'create', 'calendar');

        return '';
    }

    /**
     * @return string
     */
    public function saveCategory(): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('saveCategoryClass');

        // Hook: preSaveCategory
        $this->executeHookObjectsFunction($hookObjectsArr, 'preSaveCategory');

        $pid = $this->conf ['rights.'] ['create.'] ['category.'] ['saveCategoryToPid'];
        if (!is_numeric($pid)) {
            $pid = $GLOBALS ['TSFE']->id;
        }
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $category = $modelObj->saveCategory($this->conf ['uid'], $this->conf ['type'], $pid);

        if ($this->conf ['view.'] ['enableAjax']) {
            return '{' . $this->getEventAjaxString($category) . '}';
        }

        // Hook: postSaveCategory
        $this->executeHookObjectsFunction($hookObjectsArr, 'postSaveCategory');

        $this->clearConfVars();
        $this->checkRedirect($this->piVars ['uid'] ? 'edit' : 'create', 'category');

        return '';
    }

    /**
     * @return string
     */
    public function event(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];
        $getdate = $this->conf ['getdate'];

        $hookObjectsArr = $this->getHookObjectsArray('drawEventClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
        if (!in_array($type, $availableTypes, true)) {
            $type = null;
        }
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        /** @var EventModel $event */
        $event = $modelObj->findEvent($uid, $type, $pidList);

        if (!is_object($event)) {
            if (is_string($event)) {
                return $event;
            }
            return Functions::createErrorMessage(
                'Missing or wrong parameter. The event you are looking for could not be found.',
                'Please verify your URL parameter: tx_cal_controller[uid]'
            );
        }

        $categoryArray = implode(',', $event->getCategoryUidsAsArray());
        $relatedEvents = [];

        if ($categoryArray !== '') {
            $tempCategoryMode = $this->conf ['view.'] ['categoryMode'];
            $tempCategory = $this->conf ['view.'] ['category'];

            if ((int)$tempCategoryMode !== 1 && (int)$tempCategoryMode !== 3) {
                $this->conf ['view.'] ['categoryMode'] = 1;
            }
            $this->conf ['view.'] ['category'] = $categoryArray;
            $this->conf ['category'] = $this->conf ['view.'] ['category'];
            $relatedEvents = &$this->findRelatedEvents('event', ' AND tx_cal_event.uid != ' . $event->getUid());

            $this->conf ['view.'] ['categoryMode'] = $tempCategoryMode;
            $this->conf ['view.'] ['category'] = $tempCategory;
            $this->conf ['category'] = $this->conf ['view.'] ['category'];
        }

        // Hook: preEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preEventRendering')) {
                $hookObj->preEventRendering($event, $relatedEvents, $this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnEvent = $viewObj->drawEvent($event, $getdate, $relatedEvents);

        // Hook: postEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postEventRendering')) {
                $hookObj->postEventRendering($drawnEvent, $event, $relatedEvents, $this);
            }
        }

        return $drawnEvent;
    }

    /**
     * @return string
     */
    public function day(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];
        $getdate = $this->conf ['getdate'];

        $hookObjectsArr = $this->getHookObjectsArray('drawDayClass');

        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
        if (!in_array($type, $availableTypes, true)) {
            $type = '';
        }
        $timeObj = new CalDate($this->conf ['getdate'] . '000000');
        $timeObj->setTZbyID('UTC');
        $master_array = $modelObj->findEventsForDay($timeObj, $type, $pidList);
        // Hook: preDayRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preDayRendering')) {
                $hookObj->preDayRendering($master_array, $this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnDay = $viewObj->drawDay($master_array, $getdate);
        // Hook: postDayRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postDayRendering')) {
                $hookObj->postDayRendering($drawnDay, $master_array, $this);
            }
        }

        return $drawnDay;
    }

    /**
     * @return string
     */
    public function week(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];
        $getdate = $this->conf ['getdate'];

        $hookObjectsArr = $this->getHookObjectsArray('drawWeekClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
        if (!in_array($type, $availableTypes, true)) {
            $type = '';
        }
        $timeObj = new CalDate($this->conf ['getdate'] . '000000');
        $timeObj->setTZbyID('UTC');
        $master_array = $modelObj->findEventsForWeek($timeObj, $type, $pidList);
        // Hook: preWeekRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preWeekRendering')) {
                $hookObj->preWeekRendering($master_array, $this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnWeek = $viewObj->drawWeek($master_array, $getdate);
        // Hook: postWeekRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postWeekRendering')) {
                $hookObj->postWeekRendering($drawnWeek, $master_array, $this);
            }
        }

        return $drawnWeek;
    }

    /**
     * @return string
     */
    public function month(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];
        $getdate = $this->conf ['getdate'];

        $hookObjectsArr = $this->getHookObjectsArray('drawMonthClass');

        if ($this->conf ['view.'] ['enableAjax']) {
            $master_array = [];
        } else {
            $modelObj = &Registry::Registry('basic', 'modelcontroller');
            $availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
            if (!in_array($type, $availableTypes, true)) {
                $type = '';
            }

            $timeObj = new CalDate($this->conf ['getdate'] . '000000');
            $timeObj->setTZbyID('UTC');
            $master_array = $modelObj->findEventsForMonth($timeObj, $type, $pidList);
        }
        // Hook: preMonthRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preMonthRendering')) {
                $hookObj->preMonthRendering($master_array, $this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnMonth = $viewObj->drawMonth($master_array, $getdate);
        // Hook: postMonthRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postMonthRendering')) {
                $hookObj->postMonthRendering($drawnMonth, $master_array, $this);
            }
        }
        return $drawnMonth;
    }

    /**
     * @return string
     */
    public function year(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];
        $getdate = $this->conf ['getdate'];

        $hookObjectsArr = $this->getHookObjectsArray('drawYearClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
        if (!in_array($type, $availableTypes, true)) {
            $type = '';
        }
        $timeObj = new CalDate($this->conf ['getdate'] . '000000');
        $timeObj->setTZbyID('UTC');
        $master_array = $modelObj->findEventsForYear($timeObj, $type, $pidList);
        // Hook: preYearRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preYearRendering')) {
                $hookObj->preYearRendering($master_array, $this);
            }
        }

        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnYear = $viewObj->drawYear($master_array, $getdate);
        // Hook: postYearRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postYearRendering')) {
                $hookObj->postYearRendering($drawnYear, $master_array, $this);
            }
        }

        return $drawnYear;
    }

    /**
     * @return string
     */
    public function ics(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('drawIcsClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
        if (!in_array($type, $availableTypes, true)) {
            $type = '';
        }

        $master_array = $modelObj->findEventsForIcs($type, $pidList);

        // Hook: preIcsRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preIcsRendering')) {
                $hookObj->preIcsRendering($master_array, $this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnIcs = $viewObj->drawIcs($master_array, $this->conf ['getdate']);

        // Hook: postIcsRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postIcsRendering')) {
                $hookObj->postIcsRendering($drawnIcs, $master_array, $this);
            }
        }

        return $drawnIcs;
    }

    /**
     * @return string
     */
    public function singleIcs(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $getdate = $this->conf ['getdate'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('drawSingleIcsClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $master_array = [
            $modelObj->findEvent($uid, $type, $pidList)
        ]; // $this->conf['pid_list']));

        // Hook: preSingleIcsRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preSingleIcsRendering')) {
                $hookObj->preSingleIcsRendering($master_array, $this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnIcs = $viewObj->drawIcs($master_array, $getdate);

        // Hook: postSingleIcsRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postSingleIcsRendering')) {
                $hookObj->postSingleIcsRendering($drawnIcs, $master_array, $this);
            }
        }

        return $drawnIcs;
    }

    /**
     * @return string
     */
    public function rss(): string
    {
        $type = $this->conf ['type'];
        $getdate = $this->conf ['getdate'];
        $pidList = $this->conf ['pidList'];
        if ((int)$pidList === 0) {
            return 'Please define plugin.tx_cal_controller.pidList in constants';
        }

        $hookObjectsArr = $this->getHookObjectsArray('drawRssClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
        if (!in_array($type, $availableTypes, true)) {
            $type = '';
        }

        $starttime = Calendar::calculateStartDayTime($this->getDateTimeObject);
        $endtime = new CalDate();
        $endtime->copy($starttime);
        $endtime->addSeconds($this->conf ['view.'] ['rss.'] ['range'] * 86400);
        $master_array = $modelObj->findEventsForRss($starttime, $endtime, $type, $pidList); // $this->conf['pid_list']);

        // Hook: preRssRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preRssRendering')) {
                $hookObj->preRssRendering($master_array, $starttime, $endtime, $this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnIcs = $viewObj->drawRss($master_array, $getdate);

        // Hook: postRssRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postRssRendering')) {
                $hookObj->postRssRendering($drawnIcs, $master_array, $starttime, $endtime, $this);
            }
        }

        return $drawnIcs;
    }

    /**
     * @return string
     */
    public function location(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];

        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('drawLocationClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $availableTypes = $modelObj->getServiceTypes('cal_location_model', 'location');

        if (!in_array($type, $availableTypes, true)) {
            $type = '';
        }

        $location = $modelObj->findLocation($uid, $type, $pidList);
        if (!is_object($location)) {
            if (is_string($location)) {
                return $location;
            }
            return Functions::createErrorMessage(
                'Missing or wrong parameter. The location you are looking for could not be found.',
                'Please verify your URL parameter: tx_cal_controller[uid]'
            );
        }

        if ($this->conf ['view.'] ['enableAjax']) {
            return '{' . $this->getEventAjaxString($location) . '}';
        }
        $relatedEvents = &$this->findRelatedEvents('location', ' AND location_id = ' . $uid);

        // Hook: preLocationRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preLocationRendering')) {
                $hookObj->preLocationRendering($location, $relatedEvents, $this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnLocation = $viewObj->drawLocation($location, $relatedEvents);

        // Hook: postLocationRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postLocationRendering')) {
                $hookObj->postLocationRendering($drawnLocation, $location, $this);
            }
        }

        return $drawnLocation;
    }

    /**
     * @return string
     */
    public function organizer(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('drawOrganizerClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $availableTypes = $modelObj->getServiceTypes('cal_organizer_model', 'organizer');
        if (!in_array($type, $availableTypes, true)) {
            $type = '';
        }

        $organizer = $modelObj->findOrganizer($uid, $type, $pidList);
        if (!is_object($organizer)) {
            if (is_string($organizer)) {
                return $organizer;
            }
            return Functions::createErrorMessage(
                'Missing or wrong parameter. The organizer you are looking for could not be found.',
                'Please verify your URL parameter: tx_cal_controller[uid]'
            );
        }
        $relatedEvents = &$this->findRelatedEvents('organizer', ' AND organizer_id = ' . $uid);

        // Hook: preOrganizerRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preOrganizerRendering')) {
                $hookObj->preOrganizerRendering($organizer, $relatedEvents, $this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnOrganizer = $viewObj->drawOrganizer($organizer, $relatedEvents);

        // Hook: postOrganizerRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postOrganizerRendering')) {
                $hookObj->postOrganizerRendering($drawnOrganizer, $organizer, $this);
            }
        }
        return $drawnOrganizer;
    }

    /**
     * Calculates the time for list view start and end times.
     *
     * @param  string        The string representing the relative time.
     * @param  CalDate       The starting point that timeString is relative to.
     * @return CalDate for list view start or end time.
     */
    public function getListViewTime($timeString, $timeObj = null): CalDate
    {
        /** @var DateParser $dp */
        $dp = new DateParser();
        $dp->parse($timeString, $this->conf ['dateParserConf.'], $timeObj);
        return $dp->getDateObjectFromStack();
    }

    /**
     * @return string
     */
    public function listview(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('drawListClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
        if (!in_array($type, $availableTypes, true)) {
            $type = '';
        }

        $starttimePreset = $this->cObj->stdWrap(
            $this->conf ['view.'] ['list.'] ['starttime'],
            $this->conf ['view.'] ['list.'] ['starttime.']
        );
        $endtimePreset = $this->cObj->stdWrap(
            $this->conf ['view.'] ['list.'] ['endtime'],
            $this->conf ['view.'] ['list.'] ['endtime.']
        );

        $starttime = $this->getListViewTime($starttimePreset);
        $endtime = $this->getListViewTime($endtimePreset);

        if (!$this->conf ['view.'] ['list.'] ['useGetdate']) {
            // do nothing - removed "continue" at this point, due to #543
        } elseif ($this->conf ['view'] === 'list' && !$this->conf ['view.'] ['list.'] ['doNotUseGetdateTheFirstTime'] && $this->conf ['getdate']) {
            if ($this->conf ['view.'] ['list.'] ['useCustomStarttime']) {
                if ($this->conf ['view.'] ['list.'] ['customStarttimeRelativeToGetdate']) {
                    $starttime = $this->getListViewTime($starttimePreset, $this->getDateTimeObject);
                } // on't parse the starttime twice as it done just a few lines above
                /*
                 * else { $starttime = $this->getListViewTime($starttimePreset); }
                 */
            } else {
                $starttime = Calendar::calculateStartDayTime($this->getDateTimeObject);
            }

            if ($this->conf ['view.'] ['list.'] ['useCustomEndtime']) {
                if ($this->conf ['view.'] ['list.'] ['customEndtimeRelativeToGetdate']) {
                    $endtime = $this->getListViewTime($endtimePreset, $this->getDateTimeObject);
                } // on't parse the endtime twice as it done just a few lines above
                /*
                 * else { $endtime = $this->getListViewTime($endtimePreset); }
                 */
            } else {
                if ($this->conf ['view.'] ['list.'] ['useCustomStarttime']) {
                    // if we have a custom starttime but use getdate, calculate the endtime based on the getdate and not on the changed startdate
                    $endtime = Calendar::calculateStartDayTime($this->getDateTimeObject);
                } else {
                    $endtime = new CalDate();
                    $endtime->copy($starttime);
                }
                $endtime->addSeconds(86340);
            }
        }

        $list = $modelObj->findEventsForList($starttime, $endtime, $type, $pidList);

        // Hook: preListRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preListRendering')) {
                $hookObj->preListRendering($list, $starttime, $endtime, $this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnList = $viewObj->drawList($list, $starttime, $endtime);

        // Hook: postListRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postListRendering')) {
                $hookObj->postListRendering($drawnList, $list, $starttime, $endtime, $this);
            }
        }

        return $drawnList;
    }

    /**
     * @return string
     */
    public function icslist(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];
        $getdate = $this->conf ['getdate'];

        $hookObjectsArr = $this->getHookObjectsArray('drawIcsListClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $list = $modelObj->findCategoriesForList($type, $pidList);

        // Hook: preIcsListRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preIcsListRendering')) {
                $hookObj->preIcsListRendering($list, $this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnList = $viewObj->drawIcsList($list, $getdate);

        // Hook: postIcsListRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postIcsListRendering')) {
                $hookObj->postIcsListRendering($drawnList, $list, $this);
            }
        }

        return $drawnList;
    }

    /**
     * @return string
     */
    public function admin(): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('drawAdminClass');

        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnPage = $viewObj->drawAdminPage();

        // Hook: postAdminRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postAdminRendering')) {
                $hookObj->postAdminRendering($drawnPage, $this);
            }
        }

        return $drawnPage;
    }

    /**
     * @return string
     */
    public function searchEvent(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('drawSearchClass');

        $start_day = $this->piVars ['start_day'];
        $end_day = $this->piVars ['end_day'];
        $searchword = preg_replace('/["\']/', '', strip_tags($this->piVars ['query']));
        $this->piVars ['query'] = $searchword;

        if (!$start_day) {
            $start_day = $this->getListViewTime($this->conf ['view.'] ['search.'] ['defaultValues.'] ['start_day']);
            $start_day = Calendar::calculateStartDayTime($start_day);
        } else {
            $start_day = new CalDate(Functions::getYmdFromDateString(
                $this->conf,
                $start_day
                ) . '000000');
            $start_day->setHour(0);
            $start_day->setMinute(0);
            $start_day->setSecond(0);
            $start_day->setTZbyID('UTC');
        }
        if (!$end_day) {
            $end_day = $this->getListViewTime($this->conf ['view.'] ['search.'] ['defaultValues.'] ['end_day']);
            $end_day = Calendar::calculateEndDayTime($end_day);
        } else {
            $end_day = new CalDate(Functions::getYmdFromDateString(
                $this->conf,
                $end_day
                ) . '000000');
            $end_day->setHour(23);
            $end_day->setMinute(59);
            $end_day->setSecond(59);
            $end_day->setTZbyID('UTC');
        }
        if ($this->piVars ['single_date']) {
            $start_day = new CalDate(Functions::getYmdFromDateString(
                $this->conf,
                $this->piVars ['single_date']
            ));
            $start_day->setHour(0);
            $start_day->setMinute(0);
            $start_day->setSecond(0);
            $start_day->setTZbyID('UTC');
            $end_day = new CalDate();
            $end_day->copy($start_day);
            $end_day->addSeconds(86399);
        }

        $minStarttime = new CalDate($this->conf ['view.'] ['search.'] ['startRange'] . '000000');
        $maxEndtime = new CalDate($this->conf ['view.'] ['search.'] ['endRange'] . '000000');

        if ($start_day->before($minStarttime)) {
            $start_day->copy($minStarttime);
        }
        if ($start_day->after($maxEndtime)) {
            $start_day->copy($maxEndtime);
        }

        if ($end_day->before($minStarttime)) {
            $end_day->copy($minStarttime);
        }
        if ($end_day->after($maxEndtime)) {
            $end_day->copy($maxEndtime);
        }
        if ($end_day->before($start_day)) {
            $end_day->copy($start_day);
        }

        $locationIds = strip_tags(self::convertLinkVarArrayToList($this->piVars ['location_ids']));
        $organizerIds = strip_tags(self::convertLinkVarArrayToList($this->piVars ['organizer_ids']));

        $this->getDateTimeObject->copy($start_day);

        $modelObj = &Registry::Registry('basic', 'modelcontroller');

        $list = [];
        if ($this->piVars ['submit'] || !$this->conf ['view.'] ['search.'] ['startSearchAfterSubmit']) {
            $list = $modelObj->searchEvents(
                $type,
                $pidList,
                $start_day,
                $end_day,
                $searchword,
                $locationIds,
                $organizerIds
            );
        }

        // Hook: preSearchEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preSearchEventRendering')) {
                $hookObj->preSearchEventRendering($list, $this);
            }
        }

        if ($this->conf ['view.'] ['enableAjax']) {
            $ajaxStringArray = [];
            foreach ($list as $event) {
                $ajaxStringArray [] = '{' . $this->getEventAjaxString($event) . '}';
            }
            return '[' . implode(',', $ajaxStringArray) . ']';
        }

        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnList = $viewObj->drawSearchEventResult(
            $list,
            $start_day,
            $end_day,
            $searchword,
            $locationIds,
            $organizerIds
        );

        // Hook: postSearchEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postSearchEventRendering')) {
                $hookObj->postSearchEventRendering($drawnList, $list, $this);
            }
        }

        return $drawnList;
    }

    /**
     * @return string
     */
    public function createEvent(): string
    {
        $getDate = $this->conf ['getdate'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('createEventClass');

        // Hook: preCreateEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preCreateEventRendering')) {
                $hookObj->preCreateEventRendering($this, $getDate, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnCreateEvent = $viewObj->drawCreateEvent($getDate, $pidList);

        // Hook: postCreateEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postCreateEventRendering')) {
                $hookObj->postCreateEventRendering($drawnCreateEvent, $this);
            }
        }

        return $drawnCreateEvent;
    }

    /**
     * @return string
     */
    public function confirmEvent(): string
    {
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('confirmEventClass');

        // Hook: preConfirmEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preConfirmEventRendering')) {
                $hookObj->preConfirmEventRendering($this, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnConfirmEvent = $viewObj->drawConfirmEvent($pidList);

        // Hook: postConfirmEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postConfirmEventRendering')) {
                $hookObj->postConfirmEventRendering($drawnConfirmEvent, $this);
            }
        }

        return $drawnConfirmEvent;
    }

    /**
     * @return string
     */
    public function editEvent(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('editEventClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $event = $modelObj->findEvent($uid, $type, $pidList);

        // Hook: preEditEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preEditEventRendering')) {
                $hookObj->preEditEventRendering($this, $event, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnEditEvent = $viewObj->drawEditEvent($event, $pidList);

        // Hook: postEditEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postEditEventRendering')) {
                $hookObj->postEditEventRendering($drawnEditEvent, $this);
            }
        }

        return $drawnEditEvent;
    }

    /**
     * @return string
     */
    public function deleteEvent(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('deleteEventClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $event = $modelObj->findEvent($uid, $type, $pidList);

        // Hook: preDeleteEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preDeleteEventRendering')) {
                $hookObj->preDeleteEventRendering($this, $event, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnDeleteEvent = $viewObj->drawDeleteEvent($event, $pidList);

        // Hook: postDeleteEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postDeleteEventRendering')) {
                $hookObj->postDeleteEventRendering($drawnDeleteEvent, $this);
            }
        }

        return $drawnDeleteEvent;
    }

    /**
     * @return string
     */
    public function createLocation(): string
    {
        $getdate = $this->conf ['getdate'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('createLocationClass');

        // Hook: preCreateLocationRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preCreateLocationRendering')) {
                $hookObj->preCreateLocationRendering($this, $getdate, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnCreateLocation = $viewObj->drawCreateLocation($pidList);

        // Hook: postCreateLocationRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postCreateLocationRendering')) {
                $hookObj->postCreateLocationRendering($drawnCreateLocation, $this);
            }
        }

        return $drawnCreateLocation;
    }

    /**
     * @return string
     */
    public function confirmLocation(): string
    {
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('confirmLocationClass');

        // Hook: preConfirmLocationRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preConfirmLocationRendering')) {
                $hookObj->preConfirmLocationRendering($this, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnConfirmLocation = $viewObj->drawConfirmLocation($pidList);

        // Hook: postConfirmLocationRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postConfirmLocationRendering')) {
                $hookObj->postConfirmLocationRendering($drawnConfirmLocation, $this);
            }
        }

        return $drawnConfirmLocation;
    }

    /**
     * @return string
     */
    public function editLocation(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('editLocationClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $location = $modelObj->findLocation($uid, $type, $pidList);

        // Hook: preEditLocationRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preEditLocationRendering')) {
                $hookObj->preEditLocationRendering($this, $location, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnEditLocation = $viewObj->drawEditLocation($location, $pidList);

        // Hook: postEditLocationRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postEditLocationRendering')) {
                $hookObj->postEditLocationRendering($drawnEditLocation, $this);
            }
        }

        return $drawnEditLocation;
    }

    /**
     * @return string
     */
    public function deleteLocation(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('deleteLocationClass');

        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $location = $modelObj->findLocation($uid, $type, $pidList);

        // Hook: preDeleteLocationRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preDeleteLocationRendering')) {
                $hookObj->preDeleteLocationRendering($this, $location, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnDeleteLocation = $viewObj->drawDeleteLocation($location, $pidList);

        // Hook: postDeleteLocationRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postDeleteLocationRendering')) {
                $hookObj->postDeleteLocationRendering($drawnDeleteLocation, $this);
            }
        }

        return $drawnDeleteLocation;
    }

    /**
     * @return string
     */
    public function createOrganizer(): string
    {
        $getdate = $this->conf ['getdate'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('createOrganizerClass');

        // Hook: preCreateOrganizerRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preCreateOrganizerRendering')) {
                $hookObj->preCreateOrganizerRendering($this, $getdate, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnCreateOrganizer = $viewObj->drawCreateOrganizer($pidList);

        // Hook: postCreateOrganizerRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postCreateOrganizerRendering')) {
                $hookObj->postCreateOrganizerRendering($drawnCreateOrganizer, $this);
            }
        }

        return $drawnCreateOrganizer;
    }

    /**
     * @return string
     */
    public function confirmOrganizer(): string
    {
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('confirmOrganizerClass');

        // Hook: preConfirmOrganizerRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preConfirmOrganizerRendering')) {
                $hookObj->preConfirmOrganizerRendering($this, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnConfirmOrganizer = $viewObj->drawConfirmOrganizer($pidList);

        // Hook: postConfirmOrganizerRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postConfirmOrganizerRendering')) {
                $hookObj->postConfirmOrganizerRendering($drawnConfirmOrganizer, $this);
            }
        }

        return $drawnConfirmOrganizer;
    }

    /**
     * @return string
     */
    public function editOrganizer(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('editOrganizerClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $organizer = $modelObj->findOrganizer($uid, $type, $pidList);

        // Hook: preEditOrganizerRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preEditOrganizerRendering')) {
                $hookObj->preEditOrganizerRendering($this, $organizer, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnEditOrganizer = $viewObj->drawEditOrganizer($organizer, $pidList);

        // Hook: postEditOrganizerRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postEditOrganizerRendering')) {
                $hookObj->postEditOrganizerRendering($drawnEditOrganizer, $this);
            }
        }

        return $drawnEditOrganizer;
    }

    /**
     * @return string
     */
    public function deleteOrganizer(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('deleteOrganizerClass');

        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $organizer = $modelObj->findOrganizer($uid, $type, $pidList);

        // Hook: preDeleteOrganizerRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preDeleteOrganizerRendering')) {
                $hookObj->preDeleteOrganizerRendering($this, $organizer, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnDeleteOrganizer = $viewObj->drawDeleteOrganizer($organizer, $pidList);

        // Hook: postDeleteOrganizerRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postDeleteOrganizerRendering')) {
                $hookObj->postDeleteOrganizerRendering($drawnDeleteOrganizer, $this);
            }
        }

        return $drawnDeleteOrganizer;
    }

    /**
     * @return string
     */
    public function createCalendar(): string
    {
        $getdate = $this->conf ['getdate'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('createCalendarClass');

        // Hook: preCreateCalendarRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preCreateCalendarRendering')) {
                $hookObj->preCreateCalendarRendering($this, $getdate, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnCreateCalendar = $viewObj->drawCreateCalendar($pidList);

        // Hook: postCreateCalendarRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postCreateCalendarRendering')) {
                $hookObj->postCreateCalendarRendering($drawnCreateCalendar, $this);
            }
        }

        return $drawnCreateCalendar;
    }

    /**
     * @return string
     */
    public function confirmCalendar(): string
    {
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('confirmCalendarClass');

        // Hook: preConfirmCalendarRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preConfirmCalendarRendering')) {
                $hookObj->preConfirmCalendarRendering($this, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnConfirmCalendar = $viewObj->drawConfirmCalendar($pidList);

        // Hook: postConfirmCalendarRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postConfirmCalendarRendering')) {
                $hookObj->postConfirmCalendarRendering($drawnConfirmCalendar, $this);
            }
        }

        return $drawnConfirmCalendar;
    }

    /**
     * @return string
     */
    public function editCalendar(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('editCalendarClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $calendar = $modelObj->findCalendar($uid, $type, $pidList);

        // Hook: preEditCalendarRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preEditCalendarRendering')) {
                $hookObj->preEditCalendarRendering($this, $calendar, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnEditCalendar = $viewObj->drawEditCalendar($calendar, $pidList);

        // Hook: postEditCalendarRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postEditCalendarRendering')) {
                $hookObj->postEditCalendarRendering($drawnEditCalendar, $this);
            }
        }

        return $drawnEditCalendar;
    }

    /**
     * @return string
     */
    public function deleteCalendar(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('deleteCalendarClass');

        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $calendar = $modelObj->findCalendar($uid, $type, $pidList);

        // Hook: preDeleteCalendarRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preDeleteCalendarRendering')) {
                $hookObj->preDeleteCalendarRendering($this, $calendar, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnDeleteCalendar = $viewObj->drawDeleteCalendar($calendar, $pidList);

        // Hook: postDeleteCalendarRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postDeleteCalendarRendering')) {
                $hookObj->postDeleteCalendarRendering($drawnDeleteCalendar, $this);
            }
        }

        return $drawnDeleteCalendar;
    }

    /**
     * @return string
     */
    public function createCategory(): string
    {
        $getdate = $this->conf ['getdate'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('createCategoryClass');

        // Hook: preCreateCategoryRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preCreateCategoryRendering')) {
                $hookObj->preCreateCategoryRendering($this, $getdate, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnCreateCategory = $viewObj->drawCreateCategory($pidList);

        // Hook: postCreateCategoryRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postCreateCategoryRendering')) {
                $hookObj->postCreateCategoryRendering($drawnCreateCategory, $this);
            }
        }

        return $drawnCreateCategory;
    }

    /**
     * @return string
     */
    public function confirmCategory(): string
    {
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('confirmCategoryClass');

        // Hook: preConfirmCategoryRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preConfirmCategoryRendering')) {
                $hookObj->preConfirmCategoryRendering($this, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnConfirmCategory = $viewObj->drawConfirmCategory($pidList);

        // Hook: postConfirmCategoryRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postConfirmCategoryRendering')) {
                $hookObj->postConfirmCategoryRendering($drawnConfirmCategory, $this);
            }
        }

        return $drawnConfirmCategory;
    }

    /**
     * @return string
     */
    public function editCategory(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('editCategoryClass');

        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $category = $modelObj->findCategory($uid, $type, $pidList);

        // Hook: preEditCategoryRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preEditCategoryRendering')) {
                $hookObj->preEditCategoryRendering($this, $category, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnEditCategory = $viewObj->drawEditCategory($category, $pidList);

        // Hook: postEditCategoryRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postEditCategoryRendering')) {
                $hookObj->postEditCategoryRendering($drawnEditCategory, $this);
            }
        }

        return $drawnEditCategory;
    }

    /**
     * @return string
     */
    public function deleteCategory(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('deleteCategoryClass');

        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $category = $modelObj->findCategory($uid, $type, $pidList);

        // Hook: preDeleteCategoryRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preDeleteCategoryRendering')) {
                $hookObj->preDeleteCategoryRendering($this, $category, $pidList);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnDeleteCategory = $viewObj->drawDeleteCategory($category, $pidList);

        // Hook: postDeleteCategoryRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postDeleteCategoryRendering')) {
                $hookObj->postDeleteCategoryRendering($drawnDeleteCategory, $this);
            }
        }

        return $drawnDeleteCategory;
    }

    /**
     * @return string
     */
    public function searchAll(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('drawSearchAllClass');

        if (intval($this->piVars ['start_day']) === 0) {
            $starttime = $this->getListViewTime($this->conf ['view.'] ['search.'] ['defaultValues.'] ['start_day']);
        } else {
            $starttime = new CalDate(intval($this->piVars ['start_day']) . '000000');
        }
        if (intval($this->piVars ['end_day']) === 0) {
            $endtime = $this->getListViewTime($this->conf ['view.'] ['search.'] ['defaultValues.'] ['end_day']);
        } else {
            $endtime = new CalDate(intval($this->piVars ['end_day']) . '000000');
        }
        $searchword = strip_tags($this->piVars ['query']);
        if ($searchword === '') {
            $searchword = $this->cObj->stdWrap(
                $this->conf ['view.'] ['search.'] ['defaultValues.'] ['query'],
                $this->conf ['view.'] ['search.'] ['event.'] ['defaultValues.'] ['query.']
            );
        }
        $endtime->addSeconds(86399);

        /* Get the boundaries for allowed search dates */
        $minStarttime = new CalDate(intval($this->conf ['view.'] ['search.'] ['startRange']) . '000000');
        $maxEndtime = new CalDate(intval($this->conf ['view.'] ['search.'] ['endRange']) . '000000');

        /* Check starttime against boundaries */
        if ($starttime->before($minStarttime)) {
            $starttime->copy($minStarttime);
        }
        if ($starttime->after($maxEndtime)) {
            $starttime->copy($maxEndtime);
        }

        /* Check endtime against boundaries */
        if ($endtime->before($minStarttime)) {
            $endtime->copy($minStarttime);
        }
        if ($endtime->after($maxEndtime)) {
            $endtime->copy($maxEndtime);
        }

        /* Check endtime against starttime */
        if ($endtime->before($starttime)) {
            $endtime->copy($starttime);
        }

        $locationIds = strip_tags(self::convertLinkVarArrayToList($this->piVars ['location_ids']));
        $organizerIds = strip_tags(self::convertLinkVarArrayToList($this->piVars ['organizer_ids']));
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $list = [];
        if ($this->piVars ['query'] && ($this->piVars ['submit'] || !$this->conf ['view.'] ['search.'] ['startSearchAfterSubmit'])) {
            $list ['phpicalendar_event'] = $modelObj->searchEvents(
                $type,
                $pidList,
                $starttime,
                $endtime,
                $searchword,
                $locationIds,
                $organizerIds
            );
            $list ['location'] = $modelObj->searchLocation($type, $pidList, $searchword);
            $list ['organizer'] = $modelObj->searchOrganizer($type, $pidList, $searchword);
        }

        // Hook: preSearchAllRendering
        if (is_array($hookObjectsArr)) {
            foreach ($hookObjectsArr as $hookObj) {
                if (method_exists($hookObj, 'preSearchAllRendering')) {
                    $hookObj->preSearchAllRendering($list, $this);
                }
            }
        }

        if ($this->conf ['view.'] ['enableAjax']) {
            $ajaxStringArray = [];
            foreach ($list as $location) {
                $ajaxStringArray [] = '{' . $this->getEventAjaxString($location) . '}';
            }
            return '[' . implode(',', $ajaxStringArray) . ']';
        }

        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnList = $viewObj->drawSearchAllResult(
            $list,
            $starttime,
            $endtime,
            $searchword,
            $locationIds,
            $organizerIds
        );

        // Hook: postSearchAllRendering
        if (is_array($hookObjectsArr)) {
            foreach ($hookObjectsArr as $hookObj) {
                if (method_exists($hookObj, 'postSearchAllRendering')) {
                    $hookObj->postSearchAllRendering($drawnList, $list, $this);
                }
            }
        }
        return $drawnList;
    }

    /**
     * @return string
     */
    public function searchLocation(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('drawSearchLocationClass');

        $searchword = strip_tags($this->piVars ['query']);
        if ($searchword === '') {
            $searchword = $this->cObj->stdWrap(
                $this->conf ['view.'] ['search.'] ['location.'] ['defaultValues.'] ['query'],
                $this->conf ['view.'] ['search.'] ['location.'] ['defaultValues.'] ['query.']
            );
        }
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $list = $modelObj->searchLocation($type, $pidList, $searchword);

        // Hook: preSearchLocationRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preSearchLocationRendering')) {
                $hookObj->preSearchLocationRendering($list, $this);
            }
        }

        if ($this->conf ['view.'] ['enableAjax']) {
            $ajaxStringArray = [];
            foreach ($list as $location) {
                $ajaxStringArray [] = '{' . $this->getEventAjaxString($location) . '}';
            }
            return '[' . implode(',', $ajaxStringArray) . ']';
        }

        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnList = $viewObj->drawSearchLocationResult($list, $searchword);

        // Hook: postSearchLocationRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postSearchLocationRendering')) {
                $hookObj->postSearchLocationRendering($drawnList, $list, $this);
            }
        }

        return $drawnList;
    }

    /**
     * @return string
     */
    public function searchOrganizer(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('drawSearchOrganizerClass');

        $searchword = strip_tags($this->piVars ['query']);
        if ($searchword === '') {
            $searchword = $this->cObj->stdWrap(
                $this->conf ['view.'] ['search.'] ['organizer.'] ['defaultValues.'] ['query'],
                $this->conf ['view.'] ['search.'] ['organizer.'] ['defaultValues.'] ['query.']
            );
        }
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $list = $modelObj->searchOrganizer($type, $pidList, $searchword);

        // Hook: preSearchOrganizerRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preSearchOrganizerRendering')) {
                $hookObj->preSearchOrganizerRendering($list, $this);
            }
        }

        if ($this->conf ['view.'] ['enableAjax']) {
            $ajaxStringArray = [];
            foreach ($list as $organizer) {
                $ajaxStringArray [] = '{' . $this->getEventAjaxString($organizer) . '}';
            }
            return '[' . implode(',', $ajaxStringArray) . ']';
        }

        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnList = $viewObj->drawSearchOrganizerResult($list, $searchword);

        // Hook: postSearchOrganizerRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postSearchOrganizerRendering')) {
                $hookObj->postSearchOrganizerRendering($drawnList, $list, $this);
            }
        }

        return $drawnList;
    }

    /**
     * @return string
     */
    public function searchUserAndGroup(): string
    {
        $builder = $this->connectionPool->getQueryBuilderForTable('fe_users');

        $hookObjectsArr = $this->getHookObjectsArray('drawSearchUserAndGroupClass');

        $searchword = strip_tags($this->piVars ['query']);
        $allowedUsers = GeneralUtility::trimExplode(',', $this->conf ['rights.'] ['allowedUsers'], 1);

        if (count($allowedUsers) > 0) {
            $builder->andWhere($builder->expr()->in('uid', $allowedUsers));
        }

        if ($searchword !== '') {
            $builder->andWhere($this->cObj->searchWhere(
                $searchword,
                $this->conf ['view.'] ['search.'] ['searchUserFieldList'],
                'fe_users'
            ));
        }

        $additionalWhere = '';

        // Hook: preSearchUser
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preSearchUser')) {
                $hookObj->preSearchUser($additionalWhere, $this);
            }
        }

        $userList = [];

        $builder->andWhere($builder->expr()->in('pid', $this->conf ['pidList']));
        $result = $builder->select('*')->from('fe_users')->andWhere($additionalWhere)->execute();
        if ($result->rowCount() > 0) {
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                unset($row ['username'], $row ['password']);
                $userList [] = $row;
            }
        }

        // Hook: postSearchUser
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postSearchUser')) {
                $hookObj->postSearchUser($userList, $this);
            }
        }

        $builder->resetQueryParts();

        $allowedGroups = GeneralUtility::trimExplode(',', $this->conf ['rights.'] ['allowedGroups'], 1);
        if (count($allowedUsers) > 0) {
            $builder->andWhere($builder->expr()->in('pid', implode(',', $allowedGroups)));
        }

        if ($searchword !== '') {
            $builder->andWhere($this->cObj->searchWhere(
                $searchword,
                $this->conf ['view.'] ['search.'] ['searchGroupFieldList'],
                'fe_groups'
            ));
        }

        $additionalWhere = '';

        // Hook: preSearchGroup
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preSearchGroup')) {
                $hookObj->preSearchGroup($additionalWhere, $this);
            }
        }

        $groupList = [];

        $builder->andWhere($builder->expr()->in('pid', $this->conf ['pidList']));
        $result = $builder->select('*')->from('fe_groups')->andWhere($additionalWhere)->execute();
        if ($result->rowCount() > 0) {
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $groupList [] = $row;
            }
        }

        // Hook: postSearchGroup
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postSearchGroup')) {
                $hookObj->postSearchGroup($groupList, $this);
            }
        }

        $ajaxUserStringArray = [];
        foreach ($userList as $user) {
            $ajaxUserStringArray [] = '{' . $this->getEventAjaxString($user) . '}';
        }
        $ajaxGroupStringArray = [];
        foreach ($groupList as $group) {
            $ajaxGroupStringArray [] = '{' . $this->getEventAjaxString($group) . '}';
        }
        return '{"fe_users":[' . implode(',', $ajaxUserStringArray) . '],"fe_groups":[' . implode(
            ',',
            $ajaxGroupStringArray
            ) . ']}';
    }

    /**
     * @return string
     */
    public function subscription(): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('drawSubscriptionClass');

        // Hook: preSubscriptionRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preSubscriptionRendering')) {
                $hookObj->preSubscriptionRendering($this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnSubscriptionManager = $viewObj->drawSubscriptionManager();

        // Hook: postSubscriptionRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postSubscriptionRendering')) {
                $hookObj->postSubscriptionRendering($drawnSubscriptionManager, $this);
            }
        }

        return $drawnSubscriptionManager;
    }

    /**
     * @return string
     */
    public function meeting(): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('drawMeetingClass');

        // Hook: preMeetingRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preMeetingRendering')) {
                $hookObj->preMeetingRendering($this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnMeetingManager = $viewObj->drawMeetingManager();

        // Hook: postMeetingRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postMeetingRendering')) {
                $hookObj->postMeetingRendering($drawnMeetingManager, $this);
            }
        }

        return $drawnMeetingManager;
    }

    /**
     * @return string
     * @deprecated since ext:cal v2, will be removed in ext:cal v3
     */
    public function translation(): string
    {
        trigger_error('Deprecated since ext:cal v2, will be removed in ext:cal v3.', E_USER_DEPRECATED);

        $type = $this->conf ['type'];
        $overlay = intval($this->piVars ['overlay']);
        $uid = $this->conf ['uid'];
        $servicename = $this->piVars ['servicename'];
        $subtype = $this->piVars ['subtype'];
        if ($overlay > 0 && $uid > 0) {
            $hookObjectsArr = $this->getHookObjectsArray('createTranslationClass');

            // Hook: preCreateTranslation
            foreach ($hookObjectsArr as $hookObj) {
                if (method_exists($hookObj, 'preCreateTranslation')) {
                    trigger_error('The hook \'preCreateTranslation\' is deprecated since ext:cal v2, will be removed in ext:cal v3.', E_USER_DEPRECATED);
                    $hookObj->preCreateTranslation($this);
                }
            }
            $modelObj = &Registry::Registry('basic', 'modelcontroller');
            $modelObj->createTranslation($uid, $overlay, $servicename, $type, $subtype);

            // Hook: postCreateTranslation
            foreach ($hookObjectsArr as $hookObj) {
                if (method_exists($hookObj, 'postCreateTranslation')) {
                    $hookObj->postCreateTranslation($this);
                }
            }
        }
        unset($this->piVars ['overlay'], $this->piVars ['servicename'], $this->piVars ['subtype']);
        $viewParams = $this->shortenLastViewAndGetTargetViewParameters(false);
        $this->conf ['view'] = $viewParams ['view'];
        $this->conf ['lastview'] = $viewParams ['lastview'];
        $rightsObj = &Registry::Registry('basic', 'rightscontroller');
        $this->conf ['view'] = $rightsObj->checkView($this->conf ['view']);
        $this->conf ['uid'] = $viewParams ['uid'];
        $this->conf ['type'] = $viewParams ['type'];
        return '';
    }

    /**
     * @return string
     */
    public function todo(): string
    {
        $uid = $this->conf ['uid'];
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];
        $getdate = $this->conf ['getdate'];

        $hookObjectsArr = $this->getHookObjectsArray('drawTodoClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $todoSubtype = $this->confArr ['todoSubtype'];
        $availableTypes = $modelObj->getServiceTypes('cal_event_model', $todoSubtype);

        if (!in_array($type, $availableTypes, true)) {
            $type = '';
        }
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $todo = $modelObj->findTodo($uid, $type, $pidList);

        // Hook: preTodoRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preTodoRendering')) {
                $hookObj->preTodoRendering($todo, $this);
            }
        }
        $viewObj = &Registry::Registry('basic', 'viewcontroller');
        $drawnTodo = $viewObj->drawEvent($todo, $getdate);

        // Hook: postTodoRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postTodoRendering')) {
                $hookObj->postTodoRendering($drawnTodo, $todo, $this);
            }
        }

        return $drawnTodo;
    }

    /**
     * @return string
     */
    public function loadEvents(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('drawLoadEventsClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
        if (!in_array($type, $availableTypes, true)) {
            $type = '';
        }

        if (!$this->piVars ['start']) {
            $this->piVars ['start'] = $this->confArr ['recurrenceStart'];
        }
        $startObj = new CalDate($this->piVars ['start'] . '000000');
        $startObj->setTZbyID('UTC');

        if (!$this->piVars ['end']) {
            $this->piVars ['end'] = $this->confArr ['recurrenceEnd'];
        }

        $endObj = new CalDate($this->piVars ['end'] . '000000');
        $endObj->setTZbyID('UTC');
        $eventTypes = '0,1,2,3';

        if ($this->confArr ['todoSubtype'] === 'event') {
            $eventTypes = '0,1,2,3,4';
        }

        $master_array = $modelObj->findEventsForList($startObj, $endObj, $type, $pidList, $eventTypes);

        // Hook: preLoadEventsRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preLoadEventsRendering')) {
                $hookObj->preLoadEventsRendering($master_array, $this);
            }
        }

        $this->conf ['view'] = $this->piVars ['targetView'];
        $ajaxString = '';
        if (!empty($master_array)) {
            // use array keys for the loop in order to be able to use referenced events instead of copies and save some memory
            $masterArrayKeys = array_keys($master_array);
            $ajaxStringArray = [];

            foreach ($masterArrayKeys as $dateKey) {
                $dateArray = &$master_array [$dateKey];
                $dateArrayKeys = array_keys($dateArray);
                foreach ($dateArrayKeys as $timeKey) {
                    $arrayOfEvents = &$dateArray [$timeKey];
                    $eventKeys = array_keys($arrayOfEvents);
                    foreach ($eventKeys as $eventKey) {
                        $event = &$arrayOfEvents [$eventKey];
                        $ajaxStringArray [] = '{' . $this->getEventAjaxString($event) . '}';
                    }
                }
            }
            $ajaxString = implode(',', $ajaxStringArray);
        }
        $this->conf ['view'] = 'load_events';

        $sims = [];
        $rems = [];
        $wrapped = [];
        $sims ['###IMG_PATH###'] = Functions::expandPath($this->conf ['view.'] ['imagePath']);
        $page = Functions::substituteMarkerArrayNotCached(
            '[' . $ajaxString . ']',
            $sims,
            $rems,
            $wrapped
        );

        // Hook: postLoadEventsRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postLoadEventsRendering')) {
                $hookObj->postLoadEventsRendering($page, $this);
            }
        }

        return $page;
    }

    /**
     * @param int $uid
     * @param string $eventType
     * @return string
     */
    public function loadEvent($uid, $eventType = ''): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $eventType = intval($this->piVars ['event_type']);

        $hookObjectsArr = $this->getHookObjectsArray('drawLoadEventClass');
        /** @var ModelController $modelObj */
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        if ($eventType === Model::EVENT_TYPE_TODO) {
            $todoSubtype = $this->confArr ['todoSubtype'];
            $availableTypes = $modelObj->getServiceTypes('cal_event_model', $todoSubtype);
            if (!in_array($type, $availableTypes, true)) {
                $type = '';
            }
            $event = $modelObj->findTodo($uid, $type, $pidList);
        } else {
            $availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
            if (!in_array($type, $availableTypes, true)) {
                $type = '';
            }
            $event = $modelObj->findEvent($uid, $type, $pidList);
        }

        // Hook: preLoadEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preLoadEventRendering')) {
                $hookObj->preLoadEventRendering($event, $this);
            }
        }

        if (is_object($event)) {
            $ajaxString = $this->getEventAjaxString($event);
            $ajaxString .= 'events.push(tmp' . $event->getUid() . ');' . "\n";
            $ajaxString .= 'addEvents();';
        } else {
            $ajaxString = 'error, can not find the event';
        }

        // Hook: posteLoadEventRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postLoadEventRendering')) {
                $hookObj->postLoadEventRendering($ajaxString, $this);
            }
        }

        return $ajaxString;
    }

    /**
     * @param EventModel $event
     * @return string
     */
    public function getEventAjaxString(&$event): string
    {
        $eventValues = $event->getValuesAsArray();

        if ((int)$eventValues ['isFreeAndBusyEvent'] === 1) {
            $eventValues ['titel'] = $this->conf ['view.'] ['freeAndBusy.'] ['eventTitle'];
            $eventValues ['description'] = $event->getCalendarObject()->getTitle();
        }
        $ajaxStringArray = [];

        foreach ($eventValues as $key => $value) {
            if (is_array($value)) {
                if (count($value) > 0) {
                    $ajaxStringArray [] = '"' . $key . '":' . '{' . $this->getEventAjaxString($eventValues [$key]) . '}';
                } else {
                    $ajaxStringArray [] = '"' . $key . '":' . '[]';
                }
            } elseif (is_object($value)) {
                $ajaxStringArray [] = '"' . $key . '":' . '{' . $this->getEventAjaxString($eventValues [$key]) . '}';
            } elseif ($key !== 'l18n_diffsource') {
                $ajaxStringArray [] = '"' . $key . '":' . json_encode($value);
            }
        }

        return implode(',', $ajaxStringArray);
    }

    /**
     * @return string
     */
    public function loadTodos(): string
    {
        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];

        $hookObjectsArr = $this->getHookObjectsArray('drawLoadTodosClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $availableTypes = $modelObj->getServiceTypes('cal_event_model', 'event');
        if (!in_array($type, $availableTypes, true)) {
            $type = '';
        }

        $result_array = $modelObj->findCurrentTodos($type, $pidList);

        // Hook: preLoadTodosRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preLoadTodosRendering')) {
                $hookObj->preLoadTodosRendering($result_array, $this);
            }
        }

        $ajaxStringArray = [];

        $this->conf ['view'] = $this->piVars ['targetView'];
        if (!empty($result_array)) {
            // use array keys for the loop in order to be able to use referenced events instead of copies and save some memory
            $resultArrayKeys = array_keys($result_array);
            foreach ($resultArrayKeys as $resultArrayKey) {
                $masterArrayKeys = array_keys($result_array [$resultArrayKey]);
                foreach ($masterArrayKeys as $dateKey) {
                    $dateArray = &$result_array [$resultArrayKey] [$dateKey];
                    $dateArrayKeys = array_keys($dateArray);
                    foreach ($dateArrayKeys as $timeKey) {
                        $arrayOfEvents = &$dateArray [$timeKey];
                        $eventKeys = array_keys($arrayOfEvents);
                        foreach ($eventKeys as $eventKey) {
                            $event = &$arrayOfEvents [$eventKey];
                            $ajaxStringArray [] = '{' . $this->getEventAjaxString($event) . '}';
                        }
                    }
                }
            }
        }
        $this->conf ['view'] = 'load_todos';

        $sims = [];
        $rems = [];
        $wrapped = [];
        $sims ['###IMG_PATH###'] = Functions::expandPath($this->conf ['view.'] ['imagePath']);
        $page = Functions::substituteMarkerArrayNotCached('[' . implode(
            ',',
            $ajaxStringArray
            ) . ']', $sims, $rems, $wrapped);

        // Hook: postLoadTodosRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postLoadTodosRendering')) {
                $hookObj->postLoadTodosRendering($page, $this);
            }
        }

        return $page;
    }

    /**
     * @return string
     */
    public function loadCalendars(): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('drawLoadCalendarsClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $ajaxStringArray = [];
        $deselectedCalendarIds = GeneralUtility::trimExplode(
            ',',
            $this->conf ['view.'] ['calendar.'] ['subscription'],
            1
        );
        $calendarIds = [];
        foreach ($deselectedCalendarIds as $calendarUid) {
            $calendarIds [] = $calendarUid;
            $calendar = $modelObj->findCalendar($calendarUid, 'tx_cal_calendar', $this->conf ['pidList']);
            $ajaxStringArray [] = '{' . $this->getEventAjaxString($calendar) . '}';
        }
        $calendarArray = $modelObj->findAllCalendar('tx_cal_calendar', $this->conf ['pidList']);

        // Hook: preLoadCalendarsRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preLoadCalendarsRendering')) {
                $hookObj->preLoadCalendarsRendering($calendarArray, $this);
            }
        }

        foreach ($calendarArray ['tx_cal_calendar'] as $calendar) {
            /** @var CalendarModel $calendar */
            if (!in_array($calendar->getUid(), $calendarIds, true)) {
                $ajaxStringArray [] = '{' . $this->getEventAjaxString($calendar) . '}';
            }
        }

        // Hook: postLoadCalendarsRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postLoadCalendarsRendering')) {
                $hookObj->postLoadCalendarsRendering($ajaxStringArray, $this);
            }
        }
        return '[' . implode(',', $ajaxStringArray) . ']';
    }

    /**
     * @return string
     */
    public function loadCategories(): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('drawLoadCategoriesClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $ajaxStringArray = [];
        $categoryArray = $modelObj->findAllCategories(
            'cal_category_model',
            $this->confArr ['categoryService'],
            $this->conf ['pidList']
        );

        // Hook: preLoadCategoriesRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preLoadCategoriesRendering')) {
                $hookObj->preLoadCategoriesRendering($categoryArray, $this);
            }
        }

        foreach ($categoryArray ['tx_cal_category'] as $category) {
            $ajaxStringArray [] = '{' . $this->getEventAjaxString($category) . '}';
        }

        // Hook: postLoadCategoriesRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postLoadCategoriesRendering')) {
                $hookObj->postLoadCategoriesRendering($ajaxStringArray, $this);
            }
        }
        return '[' . implode(',', $ajaxStringArray) . ']';
    }

    /**
     * @return string
     */
    public function loadLocations(): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('drawLoadLocationsClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $ajaxStringArray = [];

        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];
        $locationArray = $modelObj->findAllLocations($type, $pidList);

        // Hook: preLoadLocationsRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preLoadLocationsRendering')) {
                $hookObj->preLoadLocationsRendering($locationArray, $this);
            }
        }

        foreach ($locationArray as $location) {
            $ajaxStringArray [] = '{' . $this->getEventAjaxString($location) . '}';
        }

        // Hook: postLoadLocationsRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postLoadLocationsRendering')) {
                $hookObj->postLoadLocationsRendering($ajaxStringArray, $this);
            }
        }
        return '[' . implode(',', $ajaxStringArray) . ']';
    }

    /**
     * @return string
     */
    public function loadOrganizers(): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('drawLoadOrganizersClass');
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        $ajaxStringArray = [];

        $type = $this->conf ['type'];
        $pidList = $this->conf ['pidList'];
        $organizerArray = $modelObj->findAllOrganizer($type, $pidList);

        // Hook: preLoadOrganizersRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preLoadOrganizersRendering')) {
                $hookObj->preLoadOrganizersRendering($organizerArray, $this);
            }
        }

        foreach ($organizerArray as $organizer) {
            $ajaxStringArray [] = '{' . $this->getEventAjaxString($organizer) . '}';
        }

        // Hook: postLoadOrganizersRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postLoadOrganizersRendering')) {
                $hookObj->postLoadOrganizersRendering($ajaxStringArray, $this);
            }
        }
        return '[' . implode(',', $ajaxStringArray) . ']';
    }

    /**
     * @return string
     */
    public function loadRights(): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('drawLoadRightsClass');
        $rightsObj = &Registry::Registry('basic', 'rightscontroller');
        $options = [
            'create',
            'edit',
            'delete'
        ];
        $rights = [];
        foreach ($options as $option) {
            $isAllowedToOptionCalendar = $rightsObj->isAllowedTo($option, 'calendar') ? 'true' : 'false';
            $isAllowedToOptionCategory = $rightsObj->isAllowedTo($option, 'category') ? 'true' : 'false';
            $isAllowedToOptionEvent = $rightsObj->isAllowedTo($option, 'event') ? 'true' : 'false';
            $isAllowedToOptionLocation = $rightsObj->isAllowedTo($option, 'location') ? 'true' : 'false';
            $isAllowedToOptionOrganizer = $rightsObj->isAllowedTo($option, 'organizer') ? 'true' : 'false';
            $rights [] = '"' . ($option === 'delete' ? 'del' : $option) . '":{"calendar":' . $isAllowedToOptionCalendar . ',"category":' . $isAllowedToOptionCategory . ',"event":' . $isAllowedToOptionEvent . ',"location":' . $isAllowedToOptionLocation . ',"organizer":' . $isAllowedToOptionOrganizer . '}';
        }
        $rights [] = '"admin":' . ($rightsObj->isCalAdmin() ? 'true' : 'false');
        $rights [] = '"userId":' . $rightsObj->getUserId();
        $rights [] = '"userGroups":[' . implode(',', $rightsObj->getUserGroups()) . ']';

        // Hook: postLoadRightsRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postLoadRightsRendering')) {
                $hookObj->postLoadRightsRendering($rights, $this);
            }
        }
        return '{' . implode(',', $rights) . '}';
    }

    /**
     * @param array $piFlexForm
     */
    public function updateConfWithFlexform(&$piFlexForm)
    {
        if ((int)$this->conf ['dontListenToFlexForm'] === 1) {
            return;
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['general.'] ['calendarName'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['calendarName'],
                $this->pi_getFFvalue($piFlexForm, 'calendarName')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['general.'] ['allowSubscribe'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['allowSubscribe'],
                (int)$this->pi_getFFvalue($piFlexForm, 'subscription') === 1 ? 1 : -1
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['general.'] ['subscribeFeUser'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['subscribeFeUser'],
                (int)$this->pi_getFFvalue($piFlexForm, 'subscription') === 2 ? 1 : -1
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['general.'] ['subscribeWithCaptcha'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['subscribeWithCaptcha'],
                $this->pi_getFFvalue($piFlexForm, 'subscribeWithCaptcha')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['general.'] ['allowedViews'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['allowedViews'],
                $this->pi_getFFvalue($piFlexForm, 'allowedViews')
            );
        }

        if ((int)$this->conf ['dontListenToFlexForm.'] ['day.'] ['dayViewPid'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['day.'] ['dayViewPid'],
                $this->pi_getFFvalue($piFlexForm, 'dayViewPid', 's_Day_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['day.'] ['dayStart'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['day.'] ['dayStart'],
                $this->pi_getFFvalue($piFlexForm, 'dayStart', 's_Day_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['day.'] ['dayEnd'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['day.'] ['dayEnd'],
                $this->pi_getFFvalue($piFlexForm, 'dayEnd', 's_Day_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['day.'] ['gridLength'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['day.'] ['gridLength'],
                $this->pi_getFFvalue($piFlexForm, 'gridLength', 's_Day_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['day.'] ['weekViewPid'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['week.'] ['weekViewPid'],
                $this->pi_getFFvalue($piFlexForm, 'weekViewPid', 's_Week_View')
            );
        }

        if ((int)$this->conf ['dontListenToFlexForm.'] ['month.'] ['monthViewPid'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['month.'] ['monthViewPid'],
                $this->pi_getFFvalue($piFlexForm, 'monthViewPid', 's_Month_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['month.'] ['monthMakeMiniCal'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['month.'] ['monthMakeMiniCal'],
                $this->pi_getFFvalue($piFlexForm, 'monthMakeMiniCal', 's_Month_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['month.'] ['monthShowListView'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['month.'] ['showListInMonthView'],
                $this->pi_getFFvalue($piFlexForm, 'monthShowListView', 's_Month_View')
            );
        }

        if ((int)$this->conf ['dontListenToFlexForm.'] ['year.'] ['yearViewPid'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['year.'] ['yearViewPid'],
                $this->pi_getFFvalue($piFlexForm, 'yearViewPid', 's_Year_View')
            );
        }

        if ((int)$this->conf ['dontListenToFlexForm.'] ['event.'] ['eventViewPid'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['event.'] ['eventViewPid'],
                $this->pi_getFFvalue($piFlexForm, 'eventViewPid', 's_Event_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['event.'] ['isPreview'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['event.'] ['isPreview'],
                $this->pi_getFFvalue($piFlexForm, 'isPreview', 's_Event_View')
            );
        }

        if ((int)$this->conf ['dontListenToFlexForm.'] ['list.'] ['listViewPid'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['list.'] ['listViewPid'],
                $this->pi_getFFvalue($piFlexForm, 'listViewPid', 's_List_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['list.'] ['starttime'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['list.'] ['starttime'],
                $this->pi_getFFvalue($piFlexForm, 'starttime', 's_List_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['list.'] ['endtime'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['list.'] ['endtime'],
                $this->pi_getFFvalue($piFlexForm, 'endtime', 's_List_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['list.'] ['maxEvents'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['list.'] ['maxEvents'],
                $this->pi_getFFvalue($piFlexForm, 'maxEvents', 's_List_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['list.'] ['maxRecurringEvents'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['list.'] ['maxRecurringEvents'],
                $this->pi_getFFvalue($piFlexForm, 'maxRecurringEvents', 's_List_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['list.'] ['usePageBrowser'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['list.'] ['pageBrowser.'] ['usePageBrowser'],
                $this->pi_getFFvalue($piFlexForm, 'usePageBrowser', 's_List_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['list.'] ['recordsPerPage'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['list.'] ['pageBrowser.'] ['recordsPerPage'],
                $this->pi_getFFvalue($piFlexForm, 'recordsPerPage', 's_List_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['list.'] ['pagesCount'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['list.'] ['pageBrowser.'] ['pagesCount'],
                $this->pi_getFFvalue($piFlexForm, 'pagesCount', 's_List_View')
            );
        }

        if ((int)$this->conf ['dontListenToFlexForm.'] ['ics.'] ['showIcsLinks'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['ics.'] ['showIcsLinks'],
                $this->pi_getFFvalue($piFlexForm, 'showIcsLinks', 's_Ics_View')
            );
        }

        if ((int)$this->conf ['dontListenToFlexForm.'] ['other.'] ['showLogin'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['other.'] ['showLogin'],
                $this->pi_getFFvalue($piFlexForm, 'showLogin', 's_Other_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['other.'] ['showSearch'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['other.'] ['showSearch'],
                $this->pi_getFFvalue($piFlexForm, 'showSearch', 's_Other_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['other.'] ['showJumps'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['other.'] ['showJumps'],
                $this->pi_getFFvalue($piFlexForm, 'showJumps', 's_Other_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['other.'] ['showGoto'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['other.'] ['showGoto'],
                $this->pi_getFFvalue($piFlexForm, 'showGoto', 's_Other_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['other.'] ['showCalendarSelection'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['other.'] ['showCalendarSelection'],
                $this->pi_getFFvalue($piFlexForm, 'showCalendarSelection', 's_Other_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['other.'] ['showCategorySelection'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['other.'] ['showCategorySelection'],
                $this->pi_getFFvalue($piFlexForm, 'showCategorySelection', 's_Other_View')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['other.'] ['showTomorrowEvents'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['other.'] ['showTomorrowEvents'],
                $this->pi_getFFvalue($piFlexForm, 'showTomorrowEvents', 's_Other_View')
            );
        }

        if ((int)$this->conf ['dontListenToFlexForm.'] ['filters.'] ['categorySelection'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['category'],
                $this->pi_getFFvalue($piFlexForm, 'categorySelection', 's_Cat')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['filters.'] ['categoryMode'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['categoryMode'],
                $this->pi_getFFvalue($piFlexForm, 'categoryMode', 's_Cat')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['filters.'] ['calendarSelection'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['calendar'],
                $this->pi_getFFvalue($piFlexForm, 'calendarSelection', 's_Cat')
            );
        }
        if ((int)$this->conf ['dontListenToFlexForm.'] ['filters.'] ['calendarMode'] !== 1) {
            self::updateIfNotEmpty(
                $this->conf ['view.'] ['calendarMode'],
                $this->pi_getFFvalue($piFlexForm, 'calendarMode', 's_Cat')
            );
        }

        $flexformTyposcript = $this->pi_getFFvalue($piFlexForm, 'myTS', 's_TS_View');
        if ($flexformTyposcript) {
            $tsparser = new \TYPO3\CMS\Core\TypoScript\Parser\TypoScriptParser();
            // Copy conf into existing setup
            $tsparser->setup = $this->conf;
            // Parse the new Typoscript
            $tsparser->parse($flexformTyposcript);
            // Copy the resulting setup back into conf
            $this->conf = $tsparser->setup;
        }
    }

    /**
     * @param string $confVar
     * @param string $newConfVar
     */
    public static function updateIfNotEmpty(&$confVar, $newConfVar)
    {
        if ($newConfVar !== '') {
            $confVar = $newConfVar;
        }
    }

    /**
     * @param string|array $linkVar
     * @return string
     */
    public static function convertLinkVarArrayToList($linkVar): string
    {
        if (is_array($linkVar)) {
            $first = true;
            $new = '';
            foreach ($linkVar as $key => $value) {
                if ($first) {
                    if ($value === 'on') {
                        $value = intval($key);
                    }
                    $new .= intval($value);
                    $first = false;
                } else {
                    if ($value === 'on') {
                        $value = intval($key);
                    }
                    $new .= ',' . intval($value);
                }
            }
            return $new;
        }
        return implode(',', GeneralUtility::intExplode(',', $linkVar));
    }

    /**
     * @param array $tags
     * @param string $page
     * @return string
     */
    public static function replace_tags($tags, $page): string
    {
        if (count($tags) > 0) {
            $sims = [];
            foreach ($tags as $tag => $data) {
                // This replaces any tags
                $upperTag = strtoupper($tag);
                $sims ['###' . $upperTag . '###'] = Functions::substituteMarkerArrayNotCached(
                    $data,
                    '###' . $upperTag . '###',
                    [],
                    []
                );
            }

            $page = Functions::substituteMarkerArrayNotCached($page, $sims, [], []);
        }

        return $page;
    }

    /**
     * @param bool $takeFirstInsteadOfLast
     * @return mixed
     */
    public function shortenLastViewAndGetTargetViewParameters($takeFirstInsteadOfLast = false)
    {
        $returnParams = [];
        if (count($this->conf ['view.'] ['allowedViews']) === 1 && count($this->conf ['view.'] ['allowedViewsToLinkTo']) === 1) {
            $returnParams ['lastview'] = null;
            $returnParams ['view'] = $this->conf ['view.'] ['allowedViews'] [0];
        } else {
            $views = explode('||', $this->conf ['lastview']);
            if ($takeFirstInsteadOfLast) {
                $target = array_shift($views);
                $views = [];
            } else {
                $target = array_pop($views);
            }
            $lastview = implode('||', $views);

            $viewParams = self::convertLastViewParamsToArray($target);
            $returnParams = $viewParams [0];

            switch (trim($returnParams ['view'])) {
                case 'event':
                case 'organizer':
                case 'location':
                case 'edit_calendar':
                case 'edit_category':
                case 'edit_location':
                case 'edit_organizer':
                case 'edit_event':
                    break;
                case 'rss':
                    $returnParams ['uid'] = null;
                    $returnParams ['type'] = null;
                    $returnParams ['gettime'] = null;
                    $returnParams ['getdate'] = $this->conf ['getdate'];
                    $returnParams ['page_id'] .= ',151';
                    break;
                default:
                    $returnParams ['uid'] = null;
                    $returnParams ['type'] = null;
                    $returnParams ['gettime'] = null;
                    $returnParams ['getdate'] = empty($returnParams ['getdate']) ? $this->conf ['getdate'] : $returnParams ['getdate'];
                    break;
            }

            switch ($this->conf ['view']) {
                case 'search_event':
                    $returnParams ['start_day'] = null;
                    $returnParams ['end_day'] = null;
                    $returnParams ['category'] = null;
                    $returnParams ['query'] = null;
                    break;
                case 'event':
                    $returnParams ['ts_table'] = null;
                    break;
            }
            $returnParams ['lastview'] = $lastview;
        }
        return $returnParams;
    }

    /**
     * @param bool $overrideParams
     * @return string|null
     */
    public function extendLastView($overrideParams = false)
    {
        if (count($this->conf ['view.'] ['allowedViews']) === 1 && count($this->conf ['view.'] ['allowedViewsToLinkTo']) === 1) {
            return null;
        }

        $params = [
            'view' => $this->conf ['view'],
            'page_id' => $GLOBALS ['TSFE']->id
        ];
        if ($overrideParams && is_array($overrideParams)) {
            $params = array_merge($params, $overrideParams);
        }
        switch ($this->conf ['view']) {
            case 'event':
            case 'organizer':
            case 'location':
            case 'edit_calendar':
            case 'edit_category':
            case 'edit_location':
            case 'edit_organizer':
            case 'edit_event':
                $params ['uid'] = $this->conf ['uid'];
                $params ['type'] = $this->conf ['type'];
                break;
            default:
                break;
        }

        $paramsForUrl = [];
        foreach ($params as $key => $val) {
            $paramsForUrl [] = $key . '-' . $val;
        }

        return ($this->conf ['lastview'] !== null ? $this->conf ['lastview'] . '||' : '') . implode('|', $paramsForUrl);
    }

    /**
     * @param  string $config
     * @return array:
     */
    public static function convertLastViewParamsToArray($config): array
    {
        $views = explode('||', $config);
        $result = [];
        foreach ($views as $viewNr => $viewConf) {
            $paramArray = explode('|', $viewConf);
            foreach ($paramArray as $paramString) {
                $param = explode('-', $paramString);
                $result [$viewNr] [$param [0]] = $param [1];
            }
        }
        return $result;
    }

    /**
     * @param Controller $controller
     */
    public static function initRegistry(&$controller)
    {
        $myCobj = &Registry::Registry('basic', 'cobj');
        $myCobj = $controller->cObj;
        $controller->cObj = &$myCobj;
        $myConf = &Registry::Registry('basic', 'conf');
        $myConf = $controller->conf;
        $controller->conf = &$myConf;
        $myController = &Registry::Registry('basic', 'controller');
        $myController = $controller;
        $controller = &$myController;
        // besides of the regular cObj we provide a localCobj, whos data can be overridden with custom data for a more flexible rendering of TSObjects
        $local_cObj = &Registry::Registry('basic', 'local_cobj');
        $local_cObj = new ContentObjectRenderer();
        $local_cObj->start([]);
        $cache = &Registry::Registry('basic', 'cache');
        $cache = [];
        $controller->local_cObj = &$local_cObj;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return get_class($this);
    }

    /**
     * @param string $str
     * @param array $additionalClasses
     * @return string
     */
    public function pi_wrapInBaseClass($str, $additionalClasses = []): string
    {
        $content = '<div class="' . str_replace('_', '-', $this->prefixId) . ' ' . implode(' ', $additionalClasses) . '">
		' . $str . '</div>';

        if (!$GLOBALS ['TSFE']->config ['config'] ['disablePrefixComment']) {
            $content = '
			<!--

			BEGIN: Content of extension "' . $this->extKey . '", plugin "' . $this->prefixId . '"

			-->
			' . $content . '

			<!-- END: Content of extension "' . $this->extKey . '", plugin "' . $this->prefixId . '" -->

			';
        }

        return $content;
    }

    /**
     * @param array $params
     */
    public function moveParamsIntoSession(&$params)
    {
        if (empty($params)) {
            $params = $this->piVars;
        }
        $sessionPiVars = GeneralUtility::trimExplode(',', $this->conf ['sessionPiVars'], 1);

        foreach ((array)$params [$this->prefixId] as $key => $value) {
            if (in_array($key, $sessionPiVars, true)) {
                $_SESSION [$this->prefixId] [$key] = $value;
                unset($params [$this->prefixId] [$key]);
            }
        }
    }

    /**
     *
     */
    public function getParamsFromSession()
    {
        if (!$this->piVars ['view']) {
            if ($this->piVars ['week']) {
                $this->piVars ['view'] = 'week';
            } elseif ($this->piVars ['day']) {
                $this->piVars ['view'] = 'day';
            } elseif ($this->piVars ['month']) {
                $this->piVars ['view'] = 'month';
            } elseif ($this->piVars ['year']) {
                $this->piVars ['view'] = 'year';
            }
        }

        if ($this->conf ['dontListenToPiVars']) {
            $this->piVars = [];
        } else {
            foreach ((array)$_SESSION [$this->prefixId] as $key => $value) {
                if (!array_key_exists($key, $this->piVars)) {
                    $this->piVars [$key] = $value;
                }
            }
        }
        if (!$this->piVars ['getdate'] && !$this->piVars ['week'] && !$this->piVars ['year'] && $this->conf ['_DEFAULT_PI_VARS.'] ['getdate']) {
            $this->piVars ['getdate'] = $this->conf ['_DEFAULT_PI_VARS.'] ['getdate'];
        }
        if (!$this->piVars ['getdate']) {
            if ($this->piVars ['week']) {
                $this->piVars ['getdate'] = Functions::getDayByWeek(
                    $this->piVars ['year'],
                    $this->piVars ['week'],
                    $this->piVars ['weekday']
                );

                unset($this->piVars ['year'], $this->piVars ['week'], $this->piVars ['weekday']);
            } else {
                $date = new CalDate();
                $date->setTZbyID('UTC');
                if (!$this->piVars ['year']) {
                    $this->piVars ['year'] = $date->format('%Y');
                }
                if (!$this->piVars ['month']) {
                    $this->piVars ['month'] = $date->format('%m');
                }
                if (!$this->piVars ['day']) {
                    $this->piVars ['day'] = $date->format('%d');
                }
                if ((int)$this->piVars ['month'] === 2) {
                    if (
                        $this->piVars ['day'] >= 29
                        && (
                            ((int)$this->piVars ['year'] % 400) === 0
                            || (
                                ((int)$this->piVars ['year'] % 4) === 0
                                && ((int)$this->piVars ['year'] % 100) !== 0
                            )
                        )
                    ) {
                        $this->piVars ['day'] = 29;
                    } elseif ($this->piVars ['day'] > 28) {
                        $this->piVars ['day'] = 28;
                    }
                } elseif (
                    $this->piVars ['day'] > 30
                    && in_array($this->piVars ['month'], [
                        4,
                        6,
                        9,
                        11
                    ], true)
                ) {
                    $this->piVars ['day'] = 30;
                }
                $this->piVars ['getdate'] = str_pad(
                    (int)$this->piVars ['year'],
                    4,
                    '0',
                    STR_PAD_LEFT
                    ) . str_pad(
                        (int)$this->piVars ['month'],
                        2,
                        '0',
                        STR_PAD_LEFT
                    ) . str_pad((int)$this->piVars ['day'], 2, '0', STR_PAD_LEFT);
                unset($this->piVars ['year'], $this->piVars ['month'], $this->piVars ['day']);
            }
        }
        unset($_SESSION [$this->prefixId]);
    }

    /**
     *
     */
    public function clearPiVarParams()
    {
        if ($this->conf ['dontListenToPiVars'] || $this->conf ['clearPiVars'] === 'all') {
            $this->piVars = [];
        } else {
            $clearPiVars = GeneralUtility::trimExplode(',', $this->conf ['clearPiVars'], 1);
            foreach ((array)$this->piVars as $key => $value) {
                if (in_array($key, $clearPiVars, true)) {
                    unset($this->piVars [$key]);
                }
            }
        }
    }

    /**
     * Returns a array with fields/parameters that can be used for link rendering in typoscript.
     * It's based on the link functions from \TYPO3\CMS\Frontend\Plugin\AbstractPlugin.
     *
     * @param  array            Referenced array in which the parameters get merged into
     * @param  array            Array with parameter=>value pairs of piVars that should override present piVars
     * @param  bool        Flag that indicates if the linktarget is allowed to be cached (takes care of cacheHash and no_cache parameter)
     * @param  bool        Flag that's clearing all present piVars, thus only piVars defined in $overrulePIvars are kept
     * @param int        Alternative ID of a page that should be used as link target. If empty or 0, current page is used
     */
    public function getParametersForTyposcriptLink(
        &$parameterArray,
        $overrulePIvars = [],
        $cache = false,
        $clearAnyway = false,
        $altPageId = 0
    ) {

        // copied from function 'pi_linkTP_keepPIvars'
        if (is_array($this->piVars) && is_array($overrulePIvars) && !$clearAnyway) {
            $piVars = $this->piVars;
            unset($piVars ['DATA']);
            ArrayUtility::mergeRecursiveWithOverrule($piVars, $overrulePIvars);
            $overrulePIvars = $piVars;
            if ($this->pi_autoCacheEn) {
                $cache = $this->pi_autoCache($overrulePIvars);
            }
        }

        $piVars = [
            $this->prefixId => $overrulePIvars
        ];

        /* TEST */
        if ($piVars [$this->prefixId] ['getdate']) {
            $date = new CalDate($piVars [$this->prefixId] ['getdate']);

            $sessionVars = [];
            switch ($piVars [$this->prefixId] ['view']) {
                case 'week':
                    $piVars [$this->prefixId] ['year'] = $date->getYear();
                    $piVars [$this->prefixId] ['week'] = $date->getWeekOfYear();
                    $piVars [$this->prefixId] ['weekday'] = $date->getDayOfWeek();
                    $sessionVars ['month'] = substr($piVars [$this->prefixId] ['getdate'], 4, 2);
                    $sessionVars ['day'] = substr($piVars [$this->prefixId] ['getdate'], 6, 2);
                    if ($date->getMonth() === 12 && (int)$piVars [$this->prefixId] ['week'] === 1) {
                        $piVars [$this->prefixId] ['year']++;
                    }

                    unset($piVars [$this->prefixId] ['view'], $piVars [$this->prefixId] ['getdate']);
                    break;
                case 'event':
                case 'todo':
                    $piVars [$this->prefixId] ['year'] = substr($piVars [$this->prefixId] ['getdate'], 0, 4);
                    $piVars [$this->prefixId] ['month'] = substr($piVars [$this->prefixId] ['getdate'], 4, 2);
                    $piVars [$this->prefixId] ['day'] = substr($piVars [$this->prefixId] ['getdate'], 6, 2);
                    unset($piVars [$this->prefixId] ['getdate']);
                    break;
                case 'day':
                    $piVars [$this->prefixId] ['day'] = substr($piVars [$this->prefixId] ['getdate'], 6, 2);
                // no break
                case 'month':
                    $piVars [$this->prefixId] ['month'] = substr($piVars [$this->prefixId] ['getdate'], 4, 2);
                // no break
                case 'year':
                    $piVars [$this->prefixId] ['year'] = substr($piVars [$this->prefixId] ['getdate'], 0, 4);
                    $sessionVars ['month'] = substr($piVars [$this->prefixId] ['getdate'], 4, 2);
                    $sessionVars ['day'] = substr($piVars [$this->prefixId] ['getdate'], 6, 2);
                    unset($piVars [$this->prefixId] ['view'], $piVars [$this->prefixId] ['getdate']);
            }
        }
        /* TEST */

        // use internal method for cleaning up piVars
        $this->cleanupUrlParameter($piVars);

        // copied and modified logic of function 'pi_linkTP'
        // once useCacheHash property in typolinks has stdWrap, we can use this flag - until then it's unfortunately useless :(
        //$parameterArray['link_useCacheHash'] = $this->pi_USER_INT_obj ? 0 : $cache;
        $parameterArray ['link_no_cache'] = $this->pi_USER_INT_obj ? 0 : !$cache;
        if ($this->pi_tmpPageId) {
            $parameterArray ['link_parameter'] = $altPageId ?: $this->pi_tmpPageId;
        } else {
            $parameterArray ['link_parameter'] = $altPageId ?: $GLOBALS ['TSFE']->id;
        }
        $parameterArray ['link_additionalParams'] = $this->conf ['parent.'] ['addParams'] . GeneralUtility::implodeArrayForUrl(
            '',
            $piVars,
            '',
            true
            ) . $this->pi_moreParams;
        $parameterArray ['link_ATagParams'] = 'class="url"';

        // add time/date related parameters to all link objects, so that they can use them e.g. to display the monthname etc.
        $parameterArray ['getdate'] = $this->conf ['getdate'];
        if (is_object($date) && $overrulePIvars ['getdate']) {
            $parameterArray ['link_timestamp'] = $date->getTime();
            $parameterArray ['link_getdate'] = $overrulePIvars ['getdate'];
        }
    }

    /**
     * Modified function pi_linkTP.
     * It calls a function for cleaning up the piVars right before calling the original function.
     * Returns the $str wrapped in <a>-tags with a link to the CURRENT page, but with $urlParameters set as extra parameters for the page.
     *
     * @param  string        The content string to wrap in <a> tags
     * @param  array         Array with URL parameters as key/value pairs. They will be "imploded" and added to the list of parameters defined in the plugins TypoScript property "parent.addParams" plus $this->pi_moreParams.
     * @param  bool       If $cache is set (0/1), the page is asked to be cached by a &cHash value (unless the current plugin using this class is a USER_INT). Otherwise the no_cache-parameter will be a part of the link.
     * @param  int       Alternative page ID for the link. (By default this function links to the SAME page!)
     * @return string input string wrapped in <a> tags
     * @see pi_linkTP_keepPIvars(), \TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer::typoLink()
     */
    public function pi_linkTP($str, $urlParameters = [], $cache = false, $altPageId = 0): string
    {
        $this->cleanupUrlParameter($urlParameters);
        $link = parent::pi_linkTP($str, $urlParameters, $cache, $altPageId);
        $this->pi_USER_INT_obj = 0;
        return $link;
    }

    /**
     * @param array $urlParameters
     */
    public function cleanupUrlParameter(&$urlParameters)
    {
        /*
         * [Franz] this little construct should be a first step into a centralized url-parameter handler for intelligent and nice looking urls. /* But it's merely experimental. A better, more flexible solution/concept need's to be found. /* To save some parsing time, I've removed the calls to $controller->extendLastView() on calls to the pi-link functions, /* because all these use internally this function and the lastView parameter is added by this function now.
         */
        $params = &$urlParameters [$this->prefixId];
        $removeParams = [];
        $lastViewParams = [];
        $useLastView = true;
        // temporary fix for BACK_LINK urls
        $dontExtendLastView = $params ['dontExtendLastView'];
        unset($params ['dontExtendLastView']);

        switch (trim($params ['view'])) {
            case 'search_all':
            case 'search_event':
            case 'search_location':
            case 'search_organizer':
                $this->pi_USER_INT_obj = 1;
                $useLastView = false;
                break;
            default:
                if ($params ['type'] || GeneralUtility::inList('week,day,year', trim($params ['view']))) {
                    $removeParams = [
                        $this->getPointerName(),
                        'submit',
                        'query'
                    ];
                }
                if ($params [$this->getPointerName] || ($params ['category'] && $params ['view'] !== 'event')) {
                    $useLastView = false;
                }
                break;
        }
        if (count($removeParams)) {
            foreach ($removeParams as $name) {
                if (isset($params [$name])) {
                    $lastViewParams [$name] = $params [$name];
                    unset($params [$name]);
                }
            }
            if ($useLastView && !$dontExtendLastView) {
                $params ['lastview'] = $this->extendLastView($lastViewParams);
            } else {
                if (!$useLastView) {
                    $params ['lastview'] = null;
                }
            }
        }

        $this->moveParamsIntoSession($urlParameters);
    }

    /**
     * @param string $action
     * @param string $object
     */
    private function checkRedirect($action, $object)
    {
        if ($this->conf ['view.'] ['enableAjax']) {
            die();
        }
        if ($this->conf ['view.'] [$action . '_' . $object . '.'] ['redirectAfter' . ucwords($action) . 'ToPid'] || $this->conf ['view.'] [$action . '_' . $object . '.'] ['redirectAfter' . ucwords($action) . 'ToView']) {
            $linkParams = [];
            if ($object === 'event') {
                $linkParams [$this->prefixId . '[getdate]'] = $this->conf ['getdate'];
            }
            if ($this->conf ['view.'] [$action . '_' . $object . '.'] ['redirectAfter' . ucwords($action) . 'ToView']) {
                $linkParams [$this->prefixId . '[view]'] = $this->conf ['view.'] [$action . '_' . $object . '.'] ['redirectAfter' . ucwords($action) . 'ToView'];
            }
            $hookObjectsArr = $this->getHookObjectsArray('beforeRedirect');
            // Hook: beforeRedirect
            foreach ($hookObjectsArr as $hookObj) {
                if (method_exists($hookObj, 'beforeRedirect')) {
                    $hookObj->beforeRedirect($this, $action, $object, $linkParams);
                }
            }
            $this->pi_linkTP(
                '|',
                $linkParams,
                $this->conf ['cache'],
                $this->conf ['view.'] [$action . '_' . $object . '.'] ['redirectAfter' . ucwords($action) . 'ToPid']
            );
            $rURL = $this->cObj->lastTypoLinkUrl;
            header('Location: ' . GeneralUtility::locationHeaderUrl($rURL));
            exit;
        }
    }

    /**
     * Method for post processing the rendered event
     *
     * @param $content
     * @return string $content
     */
    public function finish(&$content): string
    {
        $hookObjectsArr = $this->getHookObjectsArray('finishViewRendering');
        // Hook: preFinishViewRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'preFinishViewRendering')) {
                $hookObj->preFinishViewRendering($this, $content);
            }
        }

        // translate output
        $this->translateLanguageMarker($content);

        // Hook: postFinishViewRendering
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postFinishViewRendering')) {
                $hookObj->postFinishViewRendering($this, $content);
            }
        }
        return $content;
    }

    /**
     * @param $content
     * @return string
     */
    public function translateLanguageMarker(&$content): string
    {
        // translate leftover markers

        $match = [];
        preg_match_all('!(###|%%%)([A-Z0-9_-|]*)\1!is', $content, $match);
        $allLanguageMarkers = array_unique($match [2]);

        if (count($allLanguageMarkers)) {
            $sims = [];
            foreach ($allLanguageMarkers as $key => $marker) {
                $wrapper = $match [1] [$key];
                if (preg_match('/.*_LABEL$/', $marker)) {
                    $value = $this->pi_getLL('l_' . strtolower(substr($marker, 0, -6)));
                } elseif (preg_match('/^L_.*/', $marker)) {
                    $value = $this->pi_getLL(strtolower($marker));
                } elseif ($wrapper === '%%%') {
                    $value = $this->pi_getLL('l_' . strtolower($marker));
                } else {
                    $value = '';
                }
                $sims [$wrapper . $marker . $wrapper] = $value;
            }
            if (count($sims)) {
                $content = $this->markerBasedTemplateService->substituteMarkerArray($content, $sims);
            }
        }
        return $content;
    }

    /**
     * @return string
     */
    public function getPointerName(): string
    {
        return $this->pointerName;
    }

    /**
     * @param string $objectType
     * @param string $additionalWhere
     * @return array
     */
    public function findRelatedEvents($objectType, $additionalWhere): array
    {
        $relatedEvents = [];
        $modelObj = &Registry::Registry('basic', 'modelcontroller');
        if ((int)$this->conf ['view.'] [$this->conf ['view'] . '.'] [$objectType . '.'] ['includeEventsInResult'] === 1) {
            $starttime = $this->getListViewTime($this->conf ['view.'] [$this->conf ['view'] . '.'] [$this->conf ['view'] . '.'] ['includeEventsInResult.'] ['starttime']);
            $endtime = $this->getListViewTime($this->conf ['view.'] [$this->conf ['view'] . '.'] [$this->conf ['view'] . '.'] ['includeEventsInResult.'] ['endtime']);
            $relatedEvents = $modelObj->findEventsForList(
                $starttime,
                $endtime,
                '',
                $this->conf ['pidList'],
                '0,1,2,3',
                $additionalWhere
            );
        }
        return $relatedEvents;
    }

    /**
     * Sets the PHP constant for the week start day.
     * This must be called as
     * early as possible to avoid PEAR Date defining its default instead.
     */
    public function setWeekStartDay()
    {
        if ($this->cObj->data ['pi_flexform']) {
            $this->pi_initPIflexForm(); // Init and get the flexform data of the plugin
            $piFlexForm = $this->cObj->data ['pi_flexform'];

            if ((int)$this->conf ['dontListenToFlexForm.'] ['day.'] ['weekStartDay'] !== 1) {
                self::updateIfNotEmpty(
                    $this->conf ['view.'] ['weekStartDay'],
                    $this->pi_getFFvalue($piFlexForm, 'weekStartDay')
                );
            }
        }

        define('DATE_CALC_BEGIN_WEEKDAY', $this->conf ['view.'] ['weekStartDay'] === 'Sunday' ? 0 : 1);
    }
}
