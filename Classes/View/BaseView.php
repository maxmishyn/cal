<?php

namespace TYPO3\CMS\Cal\View;

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
use TYPO3\CMS\Cal\Controller\Calendar;
use TYPO3\CMS\Cal\Controller\Controller;
use TYPO3\CMS\Cal\Domain\Repository\SubscriptionRepository;
use TYPO3\CMS\Cal\Model\CalDate;
use TYPO3\CMS\Cal\Model\CalendarModel;
use TYPO3\CMS\Cal\Model\CategoryModel;
use TYPO3\CMS\Cal\Model\EventModel;
use TYPO3\CMS\Cal\Model\Pear\Date\Calc;
use TYPO3\CMS\Cal\Model\TodoModel;
use TYPO3\CMS\Cal\Service\BaseService;
use TYPO3\CMS\Cal\Service\CalendarService;
use TYPO3\CMS\Cal\Utility\Functions;
use TYPO3\CMS\Cal\Utility\Registry;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Service\MarkerBasedTemplateService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * Class BaseView
 */
class BaseView extends BaseService
{
    public $tempATagParam;
    public $master_array;
    public $viewarray;
    public $eventArray;
    public $legend = '';
    protected $pointerName = '';

    protected $cachedValueArray = [];

    /**
     * @var CharsetConverter
     */
    protected $cs_convert;

    /**
     * @var ContentObjectRenderer
     */
    public $local_cObj;

    /**
     * @var MarkerBasedTemplateService
     */
    protected $markerBasedTemplateService;

    /**
     * @var SubscriptionRepository
     */
    protected $subscriptionRepository;

    public function __construct()
    {
        parent::__construct();
        $this->markerBasedTemplateService = GeneralUtility::makeInstance(MarkerBasedTemplateService::class);
        $this->subscriptionRepository = GeneralUtility::makeInstance(SubscriptionRepository::class);
        if ($this->controller) {
            $this->pointerName = $this->controller->getPointerName();
        } else {
            $this->pointerName = null;
        }
    }

    /**
     * @param $master_array
     */
    public function _init(&$master_array)
    {
        $this->cs_convert = new CharsetConverter();
        $this->master_array = &$master_array;
        $this->initLocalCObject();
        if ($this->controller) {
            $this->pointerName = $this->controller->getPointerName();
        } else {
            $this->pointerName = null;
        }
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getAdminLinkMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###ADMIN_LINK###'] = '';
        if ($this->rightsObj->isAllowedToConfigure()) {
            $this->initLocalCObject();
            $this->controller->getParametersForTyposcriptLink(
                $this->local_cObj->data,
                ['view' => 'admin', 'lastview' => $this->controller->extendLastView()],
                $this->conf['cache'],
                $this->conf['clear_anyway']
            );
            $sims['###ADMIN_LINK###'] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.']['admin.']['adminViewLink'],
                $this->conf['view.']['admin.']['adminViewLink.']
            );
        }
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getTomorrowsEventsMarker(&$page, &$sims, &$rems, &$wrapped)
    {
        $rems['###TOMORROWS_EVENTS###'] = '';
        if ((int)$this->conf['view.']['other.']['showTomorrowEvents'] === 1) {
            $rems['###TOMORROWS_EVENTS###'] = $this->tomorrows_events($this->markerBasedTemplateService->getSubpart(
                $page,
                '###TOMORROWS_EVENTS###'
            ));
        }
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getTodoMarker(&$page, &$sims, &$rems, &$wrapped)
    {
        $rems['###TODO###'] = '';
        $confArr = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['cal']);
        if ($confArr['todoSubtype'] === 'todo' && $this->rightsObj->isViewEnabled('todo')) {
            $dateObject = new CalDate($this->conf['getdate']);
            $pidList = $this->conf['pidList'];
            $todos = [];
            switch ($this->conf['view']) {
                case 'day':
                    $todos = $this->modelObj->findTodosForDay($dateObject, '', $pidList);
                    break;
                case 'week':
                    $todos = $this->modelObj->findTodosForWeek($dateObject, '', $pidList);
                    break;
                case 'month':
                    $todos = $this->modelObj->findTodosForMonth($dateObject, '', $pidList);
                    break;
                case 'year':
                    $todos = $this->modelObj->findTodosForYear($dateObject, '', $pidList);
                    break;
                case 'list':
                    $endtime = $this->cObj->stdWrap(
                        $this->conf['view.']['list.']['endtime'],
                        $this->conf['view.']['list.']['endtime.']
                    );
                    $todos = $this->modelObj->findTodosForList(
                        $dateObject,
                        $this->controller->getListViewTime($endtime, $dateObject),
                        '',
                        $pidList
                    );
                    break;
            }
            $todoContent = '<tr><td></td></tr>';
            if (!empty($todos)) {
                foreach ($todos as $todoDate => $todoTimeArray) {
                    if (is_object($todoTimeArray)) {
                        $todoContent .= $todoTimeArray->renderEventFor($this->conf['view']);
                    } else {
                        foreach ($todoTimeArray as $key => $todoArray) {
                            /**
                             * @var int $todoUid
                             * @var TodoModel $todo
                             */
                            foreach ($todoArray as $todoUid => $todo) {
                                if (is_object($todo)) {
                                    $todoContent .= $todo->renderEventFor($this->conf['view']);
                                }
                            }
                        }
                    }
                }
            }

            $todoTemplate = $this->markerBasedTemplateService->getSubpart($page, '###TODO###');
            $rems['###TODO###'] = Functions::substituteMarkerArrayNotCached(
                $todoTemplate,
                [],
                ['###TODO_ENTRIES###' => $todoContent]
            );
        }
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getUserLoginMarker(&$page, &$sims, &$rems, &$wrapped)
    {
        $rems['###USER_LOGIN###'] = '';
        if ((int)$this->conf['view.']['other.']['showLogin'] === 1) {
            $local_sims = [];
            $local_rems = [];
            $parameter = ['view' => $this->conf['view'], $this->pointerName => null];
            $local_sims['###LOGIN_ACTION###'] = $this->controller->pi_linkTP_keepPIvars_url(
                $parameter,
                $this->conf['cache'],
                $this->conf['clear_anyway'],
                $this->conf['view.']['other.']['loginPageId']
            );

            if ($this->rightsObj->isLoggedIn()) {
                $local_sims['###LOGIN_TYPE###'] = 'logout';
                $local_sims['###L_LOGIN###'] = $this->controller->pi_getLL('l_logout');
                $local_sims['###L_LOGIN_BUTTON###'] = $this->controller->pi_getLL('l_logout');
                $local_sims['###USERNAME###'] = $this->rightsObj->getUserName();
                $local_rems['###LOGIN###'] = '';
            } else {
                $local_sims['###LOGIN_TYPE###'] = 'login';
                $local_sims['###L_LOGIN###'] = $this->controller->pi_getLL('l_login');
                $local_sims['###L_LOGIN_BUTTON###'] = $this->controller->pi_getLL('l_login');
                $local_rems['###LOGOUT###'] = '';
            }
            $local_sims['###USER_FOLDER###'] = $this->conf['view.']['other.']['userFolderId'];
            $local_sims['###REDIRECT_URL###'] = $this->controller->pi_linkTP_keepPIvars_url();
            $rems['###USER_LOGIN###'] = Functions::substituteMarkerArrayNotCached($this->markerBasedTemplateService->getSubpart(
                $page,
                '###USER_LOGIN###'
            ), $local_sims, $local_rems, []);
        }
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getIcsLinkMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###ICS_LINK###'] = '';
        if ((int)$this->conf['view.']['ics.']['showIcsLinks'] === 1) {
            $this->initLocalCObject();
            $this->local_cObj->setCurrentVal($this->controller->pi_getLL('l_calendar_icslink'));
            $this->local_cObj->data['link_wrap'] = str_replace(
                '%s',
                '|',
                $this->conf['view.']['ics.']['link_wrap']
            ); // for backwards compatibility only, could be dropped actualy
            $this->controller->getParametersForTyposcriptLink(
                $this->local_cObj->data,
                ['view' => 'icslist', $this->pointerName => null, 'lastview' => $this->controller->extendLastView()],
                $this->conf['cache'],
                1
            );
            $sims['###ICS_LINK###'] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.']['ics.']['icsViewLink'],
                $this->conf['view.']['ics.']['icsViewLink.']
            );
        }
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getSearchMarker(&$page, &$sims, &$rems, &$wrapped)
    {
        $rems['###SEARCH###'] = '';
        if ((int)$this->conf['view.']['other.']['showSearch'] === 1) {
            $local_sims = [];
            $page = $this->replace_files($page, ['search_box' => $this->conf['view.']['other.']['searchBoxTemplate']]);
            $local_sims['###GETDATE###'] = $this->conf['getdate'];

            $rems['###SEARCH###'] = Functions::substituteMarkerArrayNotCached($this->markerBasedTemplateService->getSubpart(
                $page,
                '###SEARCH###'
            ), $local_sims, [], []);
        }
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getSearchActionURLMarker(&$page, &$sims, &$rems, &$wrapped)
    {
        $parameter = ['view' => 'search_all', 'getdate' => $this->conf['getdate']];
        $sims['###SEARCH_ACTION_URL###'] = htmlspecialchars($this->controller->pi_linkTP_keepPIvars_url(
            $parameter,
            $this->conf['cache'],
            true
        ));
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getJumpsMarker(&$page, &$sims, &$rems, &$wrapped)
    {
        $rems['###JUMPS###'] = '';
        if ((int)$this->conf['view.']['other.']['showJumps'] === 1) {
            preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $this->conf['getdate'], $day_array2);
            $this_year = $day_array2[1];
            $temp_sims = [];
            $temp_sims['###LIST_JUMPS###'] = $this->list_jumps();
            $temp_sims['###LIST_ICALS###'] = '';
            $temp_sims['###LIST_YEARS###'] = $this->list_years(
                $this_year,
                $this->conf['view.']['other.']['dateFormatYearJump']
            );
            $temp_sims['###LIST_MONTHS###'] = $this->list_months(
                $this_year,
                $this->conf['view.']['other.']['dateFormatMonthJump']
            );
            $temp_sims['###LIST_WEEKS###'] = $this->list_weeks(
                $this_year,
                $this->conf['view.']['other.']['dateFormatWeekJump']
            );

            $rems['###JUMPS###'] = Functions::substituteMarkerArrayNotCached($this->markerBasedTemplateService->getSubpart(
                $page,
                '###JUMPS###'
            ), $temp_sims, [], []);
        }
    }

    /**
     * Escapes the given string for use in JavaScript variables.
     * @param string $s string to escape
     * @return string escaped string to use in JS variable contents
     */
    public function escapeForJS($s): string
    {
        // escape all single & double quotes and backslashes
        return preg_replace('/(["\'\\\\])/', '\\\\$1', $s);
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getCalendarSelectorMarker(&$page, &$sims, &$rems, &$wrapped)
    {
        $calendarOptions = '';
        $rems['###CALENDAR_SELECTOR###'] = '';
        if ($this->conf['view.']['other.']['showCalendarSelection']) {
            $temp_sims = [];
            $selectedCalendars = explode(
                ',',
                Controller::convertLinkVarArrayToList($this->controller->piVars['calendar'])
            );
            $calendarService = $this->modelObj->getServiceObjByKey('cal_calendar_model', 'calendar', 'tx_cal_calendar');
            $calendarArray = $calendarService->getCalendarFromTable(
                $this->conf['pidList'],
                $calendarService->getCalendarSearchString($this->conf['pidList'], true, false)
            );
            if (is_array($calendarArray)) {
                $calendarOptions .= '<option value="0">' . $this->controller->pi_getLL('l_all_cal_comb_lang') . '</option>';
                foreach ($calendarArray as $calendar) {
                    if (in_array($calendar->row['uid'], $selectedCalendars, true)) {
                        $calendarOptions .= '<option value="' . $calendar->row['uid'] . '" selected="selected">' . $calendar->getTitle() . '</option>';
                    } else {
                        $calendarOptions .= '<option value="' . $calendar->row['uid'] . '">' . $calendar->getTitle() . '</option>';
                    }
                }
            }

            $temp_sims['###L_CALENDAR###'] = $this->controller->pi_getLL('l_calendar');
            $temp_sims['###CALENDAR_IDS###'] = $calendarOptions;
            $change_calendar_action_url = $this->controller->pi_linkTP_keepPIvars_url(
                ['view' => $this->conf['view']],
                $this->conf['cache'],
                true
            );
            $temp_sims['###CHANGE_CALENDAR_ACTION_URL###'] = htmlspecialchars($change_calendar_action_url);
            $temp_sims['###CHANGE_CALENDAR_ACTION_URL_JS###'] = $this->escapeForJS($change_calendar_action_url);
            $rems['###CALENDAR_SELECTOR###'] = Functions::substituteMarkerArrayNotCached($this->markerBasedTemplateService->getSubpart(
                $page,
                '###CALENDAR_SELECTOR###'
            ), $temp_sims, [], []);
        }
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getBackLinkMarker(&$page, &$sims, &$rems, &$wrapped)
    {
        $sims['###BACK_LINK###'] = '';
        $viewParams = $this->controller->shortenLastViewAndGetTargetViewParameters();
        // by Franz, 25.02.2009
        // checking for a allowed view with '$this->rightsObj->isViewEnabled($viewParams['view'])' for a chash-validated backlink piVar seems a bit odd.
        // So I removed this check in order to ease website admins life to not have to care about allowedViews only to get backlinks working :)
        // Hope this doesn't break anything or opens up XSS leaks. Feel free to put it back in if in doubt.
        if ($this->conf['view'] !== $viewParams['view']) {
            $this->initLocalCObject();
            $this->local_cObj->setCurrentVal($this->controller->pi_getLL('l_back'));
            $this->local_cObj->data['view'] = $viewParams['view'];
            $pid = intval($viewParams['page_id']);
            $viewParams['dontExtendLastView'] = true;
            $this->controller->getParametersForTyposcriptLink(
                $this->local_cObj->data,
                $viewParams,
                $this->conf['cache'],
                $this->conf['clear_anyway'],
                $pid
            );
            $sims['###BACK_LINK###'] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.']['backLink'],
                $this->conf['view.']['backLink.']
            );
        }
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getLegendMarker(&$page, &$sims, &$rems, $view)
    {
        $this->list_legend($sims['###LEGEND###']);
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getListMarker(&$page, &$sims, &$rems, &$wrapped)
    {
        $rems['###LIST###'] = '';
        $starttime = new CalDate($this->conf['getdate'] . '000000');
        $starttime->setTZbyID('UTC');
        $tx_cal_listview = GeneralUtility::makeInstanceService('cal_view', 'list', 'list');
        // set alternate rendering view, so that the rendering of the attached listView can be customized
        $tempAlternateRenderingView = $tx_cal_listview->conf['alternateRenderingView'];
        $renderingView = $this->conf['view.'][$this->conf['view'] . '.']['useListEventRenderSettingsView'];
        $tx_cal_listview->conf['alternateRenderingView'] = $renderingView ?: 'list';
        $listSubpart = $this->markerBasedTemplateService->getSubpart($page, '###LIST###');

        if ($this->conf['view'] === 'month' && $this->conf['view.']['month.']['showListInMonthView']) {
            $starttime = Calendar::calculateStartMonthTime($starttime);
            $endtime = Calendar::calculateEndMonthTime($starttime);
            $rems['###LIST###'] = $tx_cal_listview->drawList($this->master_array, $listSubpart, $starttime, $endtime);
        } elseif ($this->conf['view'] === 'day') {
            $starttime = Calendar::calculateStartDayTime($starttime);
            $endtime = Calendar::calculateEndDayTime($starttime);
            $rems['###LIST###'] = $tx_cal_listview->drawList($this->master_array, $listSubpart, $starttime, $endtime);
        } elseif ($this->conf['view'] === 'week') {
            $starttime = Calendar::calculateStartWeekTime($starttime);
            $endtime = Calendar::calculateEndWeekTime($starttime);
            $rems['###LIST###'] = $tx_cal_listview->drawList($this->master_array, $listSubpart, $starttime, $endtime);
        } elseif ($this->conf['view'] === 'year') {
            $starttime = Calendar::calculateStartYearTime($starttime);
            $endtime = Calendar::calculateEndYearTime($starttime);
            $rems['###LIST###'] = $tx_cal_listview->drawList($this->master_array, $listSubpart, $starttime, $endtime);
        }

        $tx_cal_listview->conf['alternateRenderingView'] = $tempAlternateRenderingView;
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getRelatedListMarker(&$page, &$sims, &$rems, &$wrapped)
    {
        $rems['###RELATED_LIST###'] = '';
        $tx_cal_listview = GeneralUtility::makeInstanceService('cal_view', 'list', 'list');
        $listSubpart = $this->markerBasedTemplateService->getSubpart($page, '###RELATED_LIST###');
        if ($this->conf['view.'][$this->conf['view'] . '.'][$this->conf['view'] . '.']['includeEventsInResult']) {
            $starttime = $this->controller->getListViewTime($this->conf['view.'][$this->conf['view'] . '.'][$this->conf['view'] . '.']['includeEventsInResult.']['starttime']);
            $endtime = $this->controller->getListViewTime($this->conf['view.'][$this->conf['view'] . '.'][$this->conf['view'] . '.']['includeEventsInResult.']['endtime']);
            if ($this->master_array && !empty($this->master_array)) {
                $rems['###RELATED_LIST###'] = $tx_cal_listview->drawList(
                    $this->master_array,
                    $listSubpart,
                    $starttime,
                    $endtime
                );
            }
        }
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getCreateEventLinkMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###CREATE_EVENT_LINK###'] = '';
        if ($this->rightsObj->isAllowedToCreateEvent()) {
            $createOffset = intval($this->conf['rights.']['create.']['event.']['timeOffset']) * 60;

            $now = new CalDate();
            $now->setTZbyID('UTC');

            if ($this->conf['getdate'] !== $now->format('%Y%m%d')) {
                $cal_time_obj = new CalDate($this->conf['getdate'] . '000000');
                $cal_time_obj->setTZbyID('UTC');
            } else {
                $cal_time_obj = new CalDate();
                $cal_time_obj->setTZbyID('UTC');
                $cal_time_obj->addSeconds($createOffset + 10);
            }

            $sims['###CREATE_EVENT_LINK###'] = $this->getCreateEventLink(
                $view,
                '',
                $cal_time_obj,
                $createOffset,
                true,
                '',
                '',
                $this->conf['view.']['day.']['dayStart']
            );
        }
    }

    /**
     * @param $view
     * @param $wrap
     * @param CalDate $cal_time_obj
     * @param $createOffset
     * @param $isAllowedToCreateEvent
     * @param $remember
     * @param $class
     * @param $time
     * @return string
     */
    public function getCreateEventLink(
        $view,
        $wrap,
        $cal_time_obj,
        $createOffset,
        $isAllowedToCreateEvent,
        $remember,
        $class,
        $time
    ): string {
        $tmp = '';
        if (!$this->rightsObj->isViewEnabled('create_event')) {
            if ($this->conf['view.']['enableAjax']) {
                return sprintf($wrap, $remember, $class, '');
            }
            return sprintf($wrap, $remember, $class, '');
        }
        $now = new CalDate();
        $now->setTZbyID('UTC');
        $now->addSeconds($createOffset);
        if ($this->rightsObj->isAllowedToCreateEventForTodayAndFuture()) {
            $now->setHour(23);
            $now->setMinute(59);
        }
        if ($isAllowedToCreateEvent && ($cal_time_obj->after($now) || $this->rightsObj->isAllowedToCreateEventInPast())) {
            $this->initLocalCObject();
            if ($this->conf['view.']['enableAjax']) {
                $this->local_cObj->setCurrentVal($this->conf['view.'][$view . '.']['event.']['addIcon']);
                $this->local_cObj->data['link_ATagParams'] = sprintf(
                    ' onclick="' . $this->conf['view.'][$view . '.']['event.']['addLinkOnClick'] . '"',
                    $time,
                    $cal_time_obj->format('%Y%m%d')
                );
                $this->controller->getParametersForTyposcriptLink(
                    $this->local_cObj->data,
                    ['gettime' => $time, 'getdate' => $cal_time_obj->format('%Y%m%d'), 'view' => 'create_event'],
                    0,
                    $this->conf['clear_anyway'],
                    $this->conf['view.']['event.']['createEventViewPid']
                );
                $tmp .= $this->local_cObj->cObjGetSingle(
                    $this->conf['view.'][$view . '.']['event.']['addLink'],
                    $this->conf['view.'][$view . '.']['event.']['addLink.']
                );
                if ($wrap) {
                    $tmp = sprintf(
                        $wrap,
                        'id="cell_' . $cal_time_obj->format('%Y%m%d') . $time . '" ondblclick="javascript:eventUid=0;eventTime=\'' . $time . '\';eventDate=' . $cal_time_obj->format('%Y%m%d') . ';EventDialog.showDialog(this);" ',
                        $remember,
                        $class,
                        $tmp,
                        $cal_time_obj->format('%Y %m %d %H %M %s')
                    );
                }
            } else {
                $this->local_cObj->setCurrentVal($this->conf['view.'][$view . '.']['event.']['addIcon']);
                $this->local_cObj->data['link_no_cache'] = 1;
                $this->local_cObj->data['link_additionalParams'] = '&tx_cal_controller[gettime]=' . $time . '&tx_cal_controller[getdate]=' . $cal_time_obj->format('%Y%m%d') . '&tx_cal_controller[lastview]=' . $this->controller->extendLastView() . '&tx_cal_controller[view]=create_event';
                $this->local_cObj->data['link_section'] = 'default';
                $this->local_cObj->data['link_parameter'] = $this->conf['view.']['event.']['createEventViewPid'] ?: $GLOBALS['TSFE']->id;
                $tmp .= $this->local_cObj->cObjGetSingle(
                    $this->conf['view.'][$view . '.']['event.']['addLink'],
                    $this->conf['view.'][$view . '.']['event.']['addLink.']
                );
                if ($wrap) {
                    $tmp = sprintf($wrap, $remember, $class, $tmp, $cal_time_obj->format('%Y %m %d %H %M %s'));
                }
            }
        } elseif ($this->conf['view.']['enableAjax']) {
            $tmp = sprintf($wrap, $remember, $class, '');
        } else {
            $tmp = sprintf($wrap, $remember, $class, '');
        }
        return $tmp;
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getQueryMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###QUERY###'] = strip_tags($this->controller->piVars['query']);
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getLastviewMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###LASTVIEW###'] = $this->controller->extendLastView();
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getThisViewMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###THIS_VIEW###'] = $this->conf['view'];
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getTypeMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###TYPE###'] = $this->conf['type'];
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getOptionMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###OPTION###'] = $this->conf['option'];
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getCalendarMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###CALENDAR###'] = $this->conf['calendar'];
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getPageIdMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###PAGE_ID###'] = $this->conf['page_id'];
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getAjaxUrlMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###AJAX_URL###'] = $this->controller->pi_linkTP_keepPIvars_url([], 0, 1);
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getAjax2UrlMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###AJAX2_URL###'] = GeneralUtility::getIndpEnv('TYPO3_SITE_URL');
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getAvailableCalendarMarker(&$page, &$sims, &$rems, $view)
    {
        $ajaxString = '';
        $deselectedCalendarIds = GeneralUtility::trimExplode(',', $this->conf['view.']['calendar.']['subscription'], 1);
        $calendarIds = [];
        foreach ($deselectedCalendarIds as $calendarUid) {
            $calendarIds[] = $calendarUid;
            /** @var Calendar $calendar */
            $calendar = $this->modelObj->findCalendar($calendarUid, 'tx_cal_calendar', $this->conf['pidList']);
            $ajaxString .= 'var tmpCal' . $calendar->getUid() . ' = new Array();';
            $calendarValues = $calendar->getValuesAsArray();
            foreach ($calendarValues as $key => $value) {
                if ($key !== 'l18n_diffsource') {
                    $ajaxString .= 'tmpCal' . $calendar->getUid() . '[\'' . $key . '\']=' . '\'' . $value . '\';';
                }
            }
            $ajaxString .= 'tmpCal' . $calendar->getUid() . '[\'enabled\']=' . 'false;';
            $ajaxString .= 'availableCalendar[' . $calendar->getUid() . '] = new calCalendar(tmpCal' . $calendar->getUid() . ');' . "\n";
        }
        $calendarArray = $this->modelObj->findAllCalendar('tx_cal_calendar');

        foreach ($calendarArray['tx_cal_calendar'] as $calendar) {
            $ajaxString .= 'var tmpCal' . $calendar->getUid() . ' = new Array();';
            $calendarValues = $calendar->getValuesAsArray();
            if (!in_array($calendar->getUid(), $calendarIds, true)) {
                foreach ($calendarValues as $key => $value) {
                    if ($key !== 'l18n_diffsource') {
                        $ajaxString .= 'tmpCal' . $calendar->getUid() . '[\'' . $key . '\']=' . '\'' . $value . '\';';
                    }
                }
                $ajaxString .= 'tmpCal' . $calendar->getUid() . '[\'enabled\']=' . 'true;';
                $ajaxString .= 'availableCalendar[' . $calendar->getUid() . '] = new calCalendar(tmpCal' . $calendar->getUid() . ');' . "\n";
            }
        }
        $sims['###AVAILABLE_CALENDAR###'] = $ajaxString;
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getPidMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###PID###'] = $GLOBALS['TSFE']->id;
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getImgPathMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###IMG_PATH###'] = Functions::expandPath($this->conf['view.']['imagePath']);
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getJsPathMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###JS_PATH###'] = Functions::expandPath($this->conf['view.']['javascriptPath']);
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getCategoryurlMarker(&$page, &$sims, &$rems, $view)
    {
        $categoryOverrule = [];
        foreach ((array)$this->controller->piVars['category'] as $id => $categoryId) {
            $categoryOverrule[$id] = '';
        }
        $sims['###CATEGORYURL###'] = htmlspecialchars($this->controller->pi_linkTP_keepPIvars_url([
            'view' => $this->conf['view'],
            'categorySelection' => '1',
            $this->pointerName => null,
            'category' => $categoryOverrule
        ], $this->conf['cache'], $this->conf['clear_anyway']));
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getMonthMenuMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###MONTH_MENU###'] = $this->getMonthMenu($this->conf['view.']['other.']['monthMenu.']);
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     * @param string $view
     */
    public function getMarker(& $template, & $sims, & $rems, & $wrapped, $view = '')
    {
        if ($view === '') {
            $view = $this->conf['view'];
        }
        preg_match_all('!\<\!--[a-zA-Z0-9 ]*###([A-Z0-9_-|]*)\###[a-zA-Z0-9 ]*-->!is', $template, $match);
        $allMarkers = array_unique($match[1]);

        foreach ($allMarkers as $marker) {
            if (preg_match('/MODULE__([A-Z0-9_-])*/', $marker)) {
                $module = GeneralUtility:: makeInstanceService(substr($marker, 8), 'module');
                if (is_object($module)) {
                    $rems['###' . $marker . '###'] = $module->start($this);
                }
            }
            $funcFromMarker = 'get' . str_replace(
                ' ',
                '',
                ucwords(str_replace('_', ' ', strtolower($marker)))
                ) . 'Marker';
            if (method_exists($this, $funcFromMarker)) {
                $this->$funcFromMarker($template, $sims, $rems, $wrapped, $view);
            }
        }

        // todo: find a way to already exclude label markers in the regexp.
        preg_match_all('!\###([A-Z0-9_-|]*)\###!is', $template, $match);
        $allSingleMarkers = array_unique($match[1]);
        $allSingleMarkers = array_diff($allSingleMarkers, $allMarkers);

        foreach ($allSingleMarkers as $marker) {
            if ($marker !== 'IMG_PATH') {
                if (preg_match('/.*_LABEL$/', $marker) || preg_match('/^L_.*/', $marker)) {
                    continue;
                }
                $funcFromMarker = 'get' . str_replace(
                    ' ',
                    '',
                    ucwords(str_replace('_', ' ', strtolower($marker)))
                    ) . 'Marker';
                if (preg_match('/MODULE__([A-Z0-9_-])*/', $marker)) {
                    $module = GeneralUtility:: makeInstanceService(substr($marker, 8), 'module');
                    if (is_object($module)) {
                        $sims['###' . $marker . '###'] = $module->start($this);
                    }
                } elseif (method_exists($this, $funcFromMarker)) {
                    $this->$funcFromMarker($template, $sims, $rems, $view);
                } elseif (preg_match('/MODULE__([A-Z0-9_-|])*/', $marker)) {
                    $tmp = explode('___', substr($marker, 8));
                    $modules[$tmp[0]][] = $tmp[1];
                } elseif ($this->conf['view.'][$view . '.'][strtolower($marker)]) {
                    $this->initLocalCObject();
                    $current = '';
                    if ($this->row[strtolower($marker)] !== '') {
                        $current = $this->row[strtolower($marker)];
                    }
                    $this->local_cObj->setCurrentVal($current);
                    $sims['###' . $marker . '###'] = $this->local_cObj->cObjGetSingle(
                        $this->conf['view.'][$view . '.'][strtolower($marker)],
                        $this->conf['view.'][$view . '.'][strtolower($marker) . '.']
                    );
                } else {
                    $sims['###' . $marker . '###'] = '';
                }
            }
        }

        //use alternativ way of MODULE__MARKER
        //syntax: ###MODULE__MODULENAME___MODULEMARKER###
        //collect them, call each Modul, retrieve Array of Markers and replace them
        //this allows to spread the Module-Markers over complete template instead of one time
        //also work with old way of MODULE__-Marker

        if (isset($modules)) {  //MODULE-MARKER FOUND
            foreach ($modules as $themodule => $markerArray) {
                $module = GeneralUtility:: makeInstanceService($themodule, 'module');
                if (is_object($module)) {
                    if ($markerArray[0] === '') {
                        $sims['###MODULE__' . $themodule . '###'] = $module->start($this); //old way
                    } else {
                        $moduleMarker = $module->start($this); // get Markerarray from Module
                        foreach ($moduleMarker as $key => $val) {
                            $sims['###MODULE__' . $themodule . '___' . $key . '###'] = $val;
                        }
                    }
                }
            }
        }

        $hookObjectsArr = Functions::getHookObjectsArray(
            'tx_cal_base_view',
            'searchForViewMarker',
            'view'
        );
        // Hook: postSearchForObjectMarker
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postSearchForViewMarker')) {
                $hookObj->postSearchForViewMarker($this, $template, $sims, $rems, $wrapped, $view);
            }
        }
    }

    /**
     * @param $page
     * @param $rems
     * @return mixed
     */
    public function finish(&$page, &$rems)
    {
        $sims = [];
        $wrapped = [];

        $this->getSidebarMarker($page, $sims, $rems, $this->conf['view']);
        $this->getCalendarNavMarker($page, $sims, $rems, $this->conf['view']);
        $page = $this->checkForMonthMarker($page);
        $page = $this->replaceViewMarker($page);

        $this->getMarker($page, $sims, $rems, $wrapped);
        $sims['###VIEW###'] = $this->conf['view'];
        $page = Functions::substituteMarkerArrayNotCached($page, $sims, $rems, $wrapped);

        $sims = [];
        $rems = [];
        $this->getImgPathMarker($page, $sims, $rems, $this->conf['view']);

        return Functions::substituteMarkerArrayNotCached($page, $sims, $rems, $wrapped);
    }

    /**
     * @param $page
     * @return string
     */
    public function replaceViewMarker($page): string
    {
        $next_day = new CalDate();
        $next_day->copy($this->controller->getDateTimeObject);
        $next_day->addSeconds(86400);

        $prev_day = new CalDate();
        $prev_day->copy($this->controller->getDateTimeObject);
        $prev_day->subtractSeconds(86400);

        $next_week = new CalDate(Calc::beginOfNextWeek(
            $this->conf['day'],
            $this->conf['month'],
            $this->conf['year']
        ));
        $prev_week = new CalDate(Calc::beginOfPrevWeek(
            $this->conf['day'],
            $this->conf['month'],
            $this->conf['year']
        ));

        $next_year = ($this->conf['year'] + 1) . sprintf('%02d', $this->conf['month']) . sprintf(
            '%02d',
            $this->conf['day']
            );
        $prev_year = ($this->conf['year'] - 1) . sprintf('%02d', $this->conf['month']) . sprintf(
            '%02d',
            $this->conf['day']
            );

        $endOfNextMonth = new CalDate(Calc::endOfNextMonth(
            $this->conf['day'],
            $this->conf['month'],
            $this->conf['year']
        ));
        $endOfNextMonth->setDay($this->conf['day']);

        $startOfPrevMonth = new CalDate(Calc::endOfPrevMonth(
            $this->conf['day'],
            $this->conf['month'],
            $this->conf['year']
        ));
        $startOfPrevMonth->setDay($this->conf['day']);

        $next_month = $endOfNextMonth->format('%Y%m%d');
        $prev_month = $startOfPrevMonth->format('%Y%m%d');

        $startOfThisWeek = new CalDate(Calc::beginOfWeek(
            $this->conf['day'],
            $this->conf['month'],
            $this->conf['year']
        ));
        $endOfThisWeek = new CalDate(Calc::endOfWeek(
            $this->conf['day'],
            $this->conf['month'],
            $this->conf['year']
        ));
        $GLOBALS['TSFE']->register['cal_week_starttime'] = $startOfThisWeek->getTime();
        $GLOBALS['TSFE']->register['cal_week_endtime'] = $endOfThisWeek->getTime();

        $this->initLocalCObject();

        $dayViewPid = $this->conf['view.']['day.']['dayViewPid'] ?: false;
        $weekViewPid = $this->conf['view.']['week.']['weekViewPid'] ?: false;
        $monthViewPid = $this->conf['view.']['month.']['monthViewPid'] ?: false;
        $yearViewPid = $this->conf['view.']['year.']['yearViewPid'] ?: false;

        // next day
        $nextdaylinktext = $this->markerBasedTemplateService->getSubpart($page, '###NEXT_DAYLINKTEXT###');
        $this->local_cObj->setCurrentVal($nextdaylinktext);
        $this->controller->getParametersForTyposcriptLink($this->local_cObj->data, [
            'getdate' => $next_day->format('%Y%m%d'),
            'view' => $this->conf['view.']['dayLinkTarget'],
            $this->pointerName => null
        ], $this->conf['cache'], $this->conf['clear_anyway'], $dayViewPid);
        $rems['###NEXT_DAYLINK###'] = $this->local_cObj->cObjGetSingle(
            $this->conf['view.']['day.']['nextDayLink'],
            $this->conf['view.']['day.']['nextDayLink.']
        );

        // prev day
        $prevdaylinktext = $this->markerBasedTemplateService->getSubpart($page, '###PREV_DAYLINKTEXT###');
        $this->local_cObj->setCurrentVal($prevdaylinktext);
        $this->controller->getParametersForTyposcriptLink($this->local_cObj->data, [
            'getdate' => $prev_day->format('%Y%m%d'),
            'view' => $this->conf['view.']['dayLinkTarget'],
            $this->pointerName => null
        ], $this->conf['cache'], $this->conf['clear_anyway'], $dayViewPid);
        $rems['###PREV_DAYLINK###'] = $this->local_cObj->cObjGetSingle(
            $this->conf['view.']['day.']['prevDayLink'],
            $this->conf['view.']['day.']['prevDayLink.']
        );

        // next week
        $nextweeklinktext = $this->markerBasedTemplateService->getSubpart($page, '###NEXT_WEEKLINKTEXT###');
        $this->local_cObj->setCurrentVal($nextweeklinktext);
        $this->controller->getParametersForTyposcriptLink($this->local_cObj->data, [
            'getdate' => $next_week->format('%Y%m%d'),
            'view' => $this->conf['view.']['weekLinkTarget'],
            $this->pointerName => null
        ], $this->conf['cache'], $this->conf['clear_anyway'], $weekViewPid);
        $rems['###NEXT_WEEKLINK###'] = $this->local_cObj->cObjGetSingle(
            $this->conf['view.']['week.']['nextWeekLink'],
            $this->conf['view.']['week.']['nextWeekLink.']
        );

        // prev week
        $prevweeklinktext = $this->markerBasedTemplateService->getSubpart($page, '###PREV_WEEKLINKTEXT###');
        $this->local_cObj->setCurrentVal($prevweeklinktext);
        $this->controller->getParametersForTyposcriptLink($this->local_cObj->data, [
            'getdate' => $prev_week->format('%Y%m%d'),
            'view' => $this->conf['view.']['weekLinkTarget'],
            $this->pointerName => null
        ], $this->conf['cache'], $this->conf['clear_anyway'], $weekViewPid);
        $rems['###PREV_WEEKLINK###'] = $this->local_cObj->cObjGetSingle(
            $this->conf['view.']['week.']['prevWeekLink'],
            $this->conf['view.']['week.']['prevWeekLink.']
        );

        // next month
        $nextmonthlinktext = $this->markerBasedTemplateService->getSubpart($page, '###NEXT_MONTHLINKTEXT###');
        $this->local_cObj->setCurrentVal($nextmonthlinktext);
        $this->controller->getParametersForTyposcriptLink(
            $this->local_cObj->data,
            ['getdate' => $next_month, 'view' => $this->conf['view.']['monthLinkTarget'], $this->pointerName => null],
            $this->conf['cache'],
            $this->conf['clear_anyway'],
            $monthViewPid
        );
        $rems['###NEXT_MONTHLINK###'] = $this->local_cObj->cObjGetSingle(
            $this->conf['view.']['month.']['nextMonthLink'],
            $this->conf['view.']['month.']['nextMonthLink.']
        );

        // prev month
        $prevmonthlinktext = $this->markerBasedTemplateService->getSubpart($page, '###PREV_MONTHLINKTEXT###');
        $this->local_cObj->setCurrentVal($prevmonthlinktext);
        $this->controller->getParametersForTyposcriptLink(
            $this->local_cObj->data,
            ['getdate' => $prev_month, 'view' => $this->conf['view.']['monthLinkTarget'], $this->pointerName => null],
            $this->conf['cache'],
            $this->conf['clear_anyway'],
            $monthViewPid
        );
        $rems['###PREV_MONTHLINK###'] = $this->local_cObj->cObjGetSingle(
            $this->conf['view.']['month.']['prevMonthLink'],
            $this->conf['view.']['month.']['prevMonthLink.']
        );

        // next year
        $nextyearlinktext = $this->markerBasedTemplateService->getSubpart($page, '###NEXT_YEARLINKTEXT###');
        $this->local_cObj->setCurrentVal($nextyearlinktext);
        $this->controller->getParametersForTyposcriptLink(
            $this->local_cObj->data,
            ['getdate' => $next_year, 'view' => $this->conf['view.']['yearLinkTarget'], $this->pointerName => null],
            $this->conf['cache'],
            $this->conf['clear_anyway'],
            $yearViewPid
        );
        $rems['###NEXT_YEARLINK###'] = $this->local_cObj->cObjGetSingle(
            $this->conf['view.']['year.']['nextYearLink'],
            $this->conf['view.']['year.']['nextYearLink.']
        );

        // prev year
        $prevyearlinktext = $this->markerBasedTemplateService->getSubpart($page, '###PREV_YEARLINKTEXT###');
        $this->local_cObj->setCurrentVal($prevyearlinktext);
        $this->controller->getParametersForTyposcriptLink(
            $this->local_cObj->data,
            ['getdate' => $prev_year, 'view' => $this->conf['view.']['yearLinkTarget'], $this->pointerName => null],
            $this->conf['cache'],
            $this->conf['clear_anyway'],
            $yearViewPid
        );
        $rems['###PREV_YEARLINK###'] = $this->local_cObj->cObjGetSingle(
            $this->conf['view.']['year.']['prevYearLink'],
            $this->conf['view.']['year.']['prevYearLink.']
        );

        $this->local_cObj->setCurrentVal($this->controller->getDateTimeObject->getTime());

        $sims['###DISPLAY_DATE###'] = $this->local_cObj->cObjGetSingle(
            $this->conf['view.'][$this->conf['view'] . '.']['displayDate'],
            $this->conf['view.'][$this->conf['view'] . '.']['displayDate.']
        );

        $wrapped = [];
        $hookObjectsArr = Functions::getHookObjectsArray(
            'tx_cal_base_model',
            'searchForObjectMarker',
            'model'
        );
        // Hook: postSearchForObjectMarker
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'postSearchForObjectMarker')) {
                $hookObj->postSearchForObjectMarker($this, $page, $sims, $rems, $wrapped, $this->conf['view']);
            }
        }

        $page = Functions::substituteMarkerArrayNotCached($page, $sims, $rems, []);

        $languageArray = [
            'getdate' => $this->conf['getdate'],
            'next_month' => $next_month,
            'prev_month' => $prev_month,
            'calendar_name' => $this->conf['calendarName'],
            'this_year' => $this->conf['year'],
            'next_year' => $next_year,
            'prev_year' => $prev_year
        ];

        return Controller::replace_tags($languageArray, $page);
    }

    /**
     * @param $page
     * @return mixed
     */
    public function checkForMonthMarker($page)
    {
        $match = [];
        preg_match_all('!\###MONTH_([A-Z]*)\|?([+|-])?([0-9]{1,2})\###!is', $page, $match);
        if (count($match) > 0) {
            $i = 0;
            foreach ($match[1] as $key => $val) {
                $offset = $match[2][$i] . $match[3][$i];
                if ($match[1][$i] === 'SMALL') {
                    $template_file = Functions::getContent($this->conf['view.']['month.']['monthSmallTemplate']);
                    $type = 'small';
                } elseif ($match[1][$i] === 'MEDIUM') {
                    $template_file = Functions::getContent($this->conf['view.']['month.']['monthMediumTemplate']);
                    $type = 'medium';
                } else {
                    $template_file = Functions::getContent($this->conf['view.']['month.']['monthLargeTemplate']);
                    $type = 'large';
                }

                if ($this->conf['useNewTemplatesAndRendering']) {
                    $data = $this->_draw_month_new($offset, $type);
                } else {
                    $data = $this->_draw_month($template_file, $offset, $type);
                }

                $page = str_replace($match[0][$i], $data, $page);
                $i++;
            }
        }
        return $page;
    }

    /**
     * @param $page
     * @param array $tags
     * @return mixed|string|string[]|null
     */
    public function replace_files($page, $tags = [])
    {
        if (count($tags) > 0) {
            foreach ($tags as $tag => $data) {

                // This opens up another template and parses it as well.
                $data = $GLOBALS['TSFE']->tmpl->getFileName($data);
                $data = file_exists($data) ? Functions::getContent($data) : $data;
                // This removes any unfilled tags
                if (!$data) {
                    $page = preg_replace(
                        '!<\!-- ###' . $tag . '### start -->(.*)<\!-- ###' . $tag . '### end -->!is',
                        '',
                        $data
                    );
                }

                // This replaces any tags
                $page = str_replace('###' . strtoupper($tag) . '###', $data, $page);
            }
        }
        //die('No tags designated for replacement.');

        return $page;
    }

    /**
     * @param $view
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getViewLinkMarker($view, &$template, &$sims, &$rems, &$wrapped)
    {
        $viewMarker = '###' . strtoupper($view) . 'VIEWLINK###';
        $viewTarget = $this->conf['view.'][strtolower($view) . 'LinkTarget'];
        $rems[$viewMarker] = '';
        if ($this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid'] || $this->rightsObj->isViewEnabled($viewTarget)) {
            $this->initLocalCObject();
            if ($viewTarget === $this->conf['view']) {
                $this->local_cObj->data['link_ATagParams'] = 'class="current"';
            }
            $this->local_cObj->setCurrentVal($this->markerBasedTemplateService->getSubpart(
                $template,
                '###' . strtoupper($view) . 'VIEWLINKTEXT###'
            ));
            $this->local_cObj->data['view'] = $viewTarget;
            if ($viewTarget === 'week' && DATE_CALC_BEGIN_WEEKDAY === 0) {
                $date = new CalDate($this->conf['getdate']);
                if ((int)$date->format('%w') === 0) {
                    $date->addSeconds(86400);
                }
                $this->controller->getParametersForTyposcriptLink(
                    $this->local_cObj->data,
                    ['getdate' => $date->format('%Y%m%d'), 'view' => $viewTarget, $this->pointerName => null],
                    $this->conf['cache'],
                    $this->conf['clear_anyway'],
                    $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid']
                );
            } else {
                $this->controller->getParametersForTyposcriptLink(
                    $this->local_cObj->data,
                    ['getdate' => $this->conf['getdate'], 'view' => $viewTarget, $this->pointerName => null],
                    $this->conf['cache'],
                    $this->conf['clear_anyway'],
                    $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid']
                );
            }
            $rems[$viewMarker] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewLink'],
                $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewLink.']
            );
        }
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getDayviewlinkMarker(&$template, &$sims, &$rems, &$wrapped)
    {
        $this->getViewLinkMarker('day', $template, $sims, $rems, $wrapped);
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getWeekviewlinkMarker(&$template, &$sims, &$rems, &$wrapped)
    {
        $this->getViewLinkMarker('week', $template, $sims, $rems, $wrapped);
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getMonthviewlinkMarker(&$template, &$sims, &$rems, &$wrapped)
    {
        $this->getViewLinkMarker('month', $template, $sims, $rems, $wrapped);
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getYearviewlinkMarker(&$template, &$sims, &$rems, &$wrapped)
    {
        $this->getViewLinkMarker('year', $template, $sims, $rems, $wrapped);
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     */
    public function getListviewlinkMarker(&$template, &$sims, &$rems, &$wrapped)
    {
        $this->getViewLinkMarker('list', $template, $sims, $rems, $wrapped);
    }

    /**
     * @return string
     */
    public function list_jumps(): string
    {
        $day_array2 = [];
        preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $getdate, $day_array2);

        // gmdate is ok.
        $return = sprintf(
            $this->conf['view.']['other.']['optionString'],
            gmdate('Ymd'),
            $this->controller->pi_getLL('l_jump')
        );
        $return .= $this->createJumpEntry('day');
        $return .= $this->createJumpEntry('week');
        $return .= $this->createJumpEntry('month');
        $return .= $this->createJumpEntry('year');
        return $return;
    }

    /**
     * @param $view
     * @return string
     */
    public function createJumpEntry($view): string
    {
        $viewTarget = $this->conf['view.'][strtolower($view) . 'LinkTarget'];
        if (!empty($this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid'])) {
            $link = $this->controller->pi_linkTP_keepPIvars_url(
                [
                    'getdate' => $today,
                    'view' => $viewTarget,
                    $this->pointerName => null
                ],
                $this->conf['cache'],
                $this->conf['clear_anyway'],
                $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid']
            );
        } else {
            $link = $this->controller->pi_linkTP_keepPIvars_url([
                'getdate' => $today,
                'view' => $viewTarget,
                $this->pointerName => null
            ], $this->conf['cache'], $this->conf['clear_anyway']);
        }
        return sprintf(
            $this->conf['view.']['other.']['optionString'],
            GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . $link,
            $this->controller->pi_getLL('l_go' . $viewTarget)
        );
    }

    /**
     * @param $return
     */
    public function list_legend(&$return)
    {
        $this->conf['view.']['category.']['tree.']['category'] = $this->conf['category'];
        $this->conf['view.']['category.']['tree.']['calendar'] = '0,' . $this->conf['calendar'];
        $categoryArray = $this->modelObj->findAllCategories(
            'cal_category_model',
            'sys_category',
            $this->conf['pidList']
        );

        $return = $this->getCategorySelectionTree(
            $this->conf['view.']['category.']['tree.'],
            $categoryArray,
            $this->conf['view.']['other.']['showCategorySelection']
        );
        $return = $this->cObj->stdWrap($return, $this->conf['view.']['other.']['legend_stdWrap.']);
    }

    /**
     * @param $conf
     * @return string
     */
    public function getMonthMenu($conf): string
    {
        // gmdate is ok.
        if ($conf['monthStart.']['thisMonth']) {
            $month_time = Calendar::calculateStartMonthTime();
        } else {
            $month_time = Calendar::calculateStartDayTime();
            $month_time->setDay(1);
            $this->initLocalCObject();
            $month_time->setMonth($this->local_cObj->cObjGetSingle($conf['monthStart'], $conf['monthStart.']));
            $this->initLocalCObject();
            $month_time->setYear($this->local_cObj->cObjGetSingle($conf['yearStart'], $conf['yearStart.']));
        }

        $return = '';
        for ($i = 0; $i < $conf['count']; $i++) {
            $monthdate = $month_time->format('%Y%m%d');
            $select_month = $month_time->format($conf['format']);

            $this->initLocalCObject();
            $this->local_cObj->setCurrentVal($select_month);
            if ($this->conf['view.']['month.']['monthViewPid'] || $this->rightsObj->isViewEnabled('month')) {
                $this->controller->getParametersForTyposcriptLink(
                    $this->local_cObj->data,
                    ['getdate' => $monthdate, 'view' => 'month', $this->pointerName => null],
                    $this->conf['cache'],
                    $this->conf['clear_anyway'],
                    $this->conf['view.']['month.']['monthViewPid']
                );
            }
            $link = $this->local_cObj->cObjGetSingle(
                $this->conf['view.']['month.']['monthViewLink'],
                $this->conf['view.']['month.']['monthViewLink.']
            );

            $return .= $this->cObj->stdWrap($link, $conf['month_stdWrap.']);

            $month_time->addSeconds(86400 * 32);
            $month_time = Calendar::calculateStartMonthTime($month_time);
        }
        return $return;
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getYearMenuMarker(&$page, &$sims, &$rems, $view)
    {
        // gmdate is ok.
        $conf = $this->conf['view.']['other.']['yearMenu.'];
        if ($conf['yearStart.']['thisYear']) {
            $year_time = Calendar::calculateStartYearTime();
        } else {
            $year_time = Calendar::calculateStartYearTime();
            $this->initLocalCObject();
            $year_time->setYear($this->local_cObj->cObjGetSingle($conf['yearStart'], $conf['yearStart.']));
        }
        $return = '';
        for ($i = 0; $i < $conf['count']; $i++) {
            $yeardate = $year_time->format('%Y%m%d');
            $select_year = $year_time->format($conf['format']);

            $this->initLocalCObject();
            $this->local_cObj->setCurrentVal($select_year);
            if ($this->conf['view.']['year.']['yearViewPid'] || $this->rightsObj->isViewEnabled('year')) {
                $this->controller->getParametersForTyposcriptLink(
                    $this->local_cObj->data,
                    ['getdate' => $yeardate, 'view' => 'year', $this->pointerName => null],
                    $this->conf['cache'],
                    $this->conf['clear_anyway'],
                    $this->conf['view.']['year.']['yearViewPid']
                );
            }
            $link = $this->local_cObj->cObjGetSingle(
                $this->conf['view.']['year.']['yearViewLink'],
                $this->conf['view.']['year.']['yearViewLink.']
            );

            $return .= $this->cObj->stdWrap($link, $conf['year_stdWrap.']);

            $year_time->setYear($year_time->getYear() + 1);
        }
        $sims['###YEAR_MENU###'] = $return;
    }

    /**
     * @param $treeConf
     * @param $categoryArray
     * @param bool $renderAsForm
     * @return string
     */
    public function getCategorySelectionTree($treeConf, $categoryArray, $renderAsForm = false): string
    {
        $treeHtml = '';
        foreach ($categoryArray as $categoryServiceKey => $categoryServiceResult) {
            foreach ($categoryServiceResult as $modelCategoryArray) {
                $categoryArrayByUid = $modelCategoryArray[0];
                $categoryArrayByCalendarUid = $modelCategoryArray[2];

                $parentCategoryArray = [];
                /** @var CalendarModel $category */
                foreach ($categoryArrayByUid as $category) {
                    $parentCategoryArray[$category->getParentUid()][] = $category;
                }

                foreach ($categoryArrayByCalendarUid as $calendarTitle => $calendarCategoryArray) {
                    $calendarParams = explode('###', $calendarTitle);
                    $calendarTitle = $calendarParams[1];
                    $calendarUid = $calendarParams[0];
                    if ($calendarParams[2]) {
                        /** @var CalendarService $calendarService */
                        $calendarService = &$this->modelObj->getServiceObjByKey(
                            'cal_calendar_model',
                            'calendar',
                            'tx_cal_calendar'
                        );
                        $calendar = $calendarService->find($calendarUid, $this->conf['pidList']);
                        $calendarTitle = $calendar->getTitle();//.$calendar->getEditLink();
                    }

                    if (intval($treeConf['calendar']) === $treeConf['calendar']) {
                        $ids = explode(',', $treeConf['calendar']);
                        if (!in_array($calendarUid, $ids, true)) {
                            continue;
                        }
                    } else {
                        continue;
                    }
                    $treeConf['calendarTitle.']['value'] = $calendarTitle;
                    $treeHtml .= $this->cObj->cObjGetSingle($treeConf['calendarTitle'], $treeConf['calendarTitle.']);
                    if (empty($calendarCategoryArray)) {
                        $treeHtml .= $this->cObj->stdWrap($treeConf['emptyElement'], $treeConf['emptyElement.']);
                    } else {
                        foreach ($calendarCategoryArray as $rootCategoryId) {
                            /** @var CategoryModel $rootCategory */
                            $rootCategory = $categoryArrayByUid[$rootCategoryId];
                            if ($rootCategory->getParentUid() === 0 || !$categoryArrayByUid[$rootCategory->getParentUid()]) {
                                $treeHtml .= $this->cObj->stdWrap($this->addSubCategory(
                                    $treeConf,
                                    $parentCategoryArray,
                                    $rootCategory,
                                    0,
                                    $renderAsForm
                                ), $treeConf['rootElement.']);
                            }
                        }
                    }
                }
            }
        }

        if ($renderAsForm) {
            $treeHtml .= $treeConf['categorySelectorSubmit'];
        }
        return $treeHtml;
    }

    /**
     * @param $treeConf
     * @param $parentCategoryArray
     * @param CategoryModel $parentCategory
     * @param $level
     * @param $renderAsForm
     * @return mixed|string
     */
    public function addSubCategory(&$treeConf, &$parentCategoryArray, &$parentCategory, $level, $renderAsForm)
    {
        $level++;
        $treeHtml = '';
        if ($renderAsForm) {
            $selectedCategories = [];
            if ($treeConf['category'] !== '') {
                $selectedCategories = explode(',', $treeConf['category']);
            }
            if ($treeConf['selector.']) {
                $treeHtml .= $this->cObj->stdWrap(
                    ((in_array(
                        $parentCategory->getUid(),
                        $selectedCategories,
                        true
                    ) || empty($selectedCategories)) ? ' checked="checked"' : ''),
                    $treeConf['selector.']
                );
            } else {
                $catValues = $parentCategory->getValuesAsArray();
                $allowedCategoryArray = GeneralUtility::trimExplode(',', $this->conf['view.']['category'], 1);
                $notSelectedCategories = array_diff($allowedCategoryArray, $selectedCategories);
                if (!empty($notSelectedCategories) && in_array($parentCategory->getUid(), $selectedCategories, true)) {
                    $catValues['cur'] = 1;
                }
                $this->initLocalCObject($catValues);
                $this->local_cObj->setCurrentVal($parentCategory->getTitle());
                $treeHtml .= $this->local_cObj->cObjGetSingle(
                    $treeConf['alternativeSelect'],
                    $treeConf['alternativeSelect.']
                );
            }
        }
        $treeHtml .= $treeConf['element'];
        $sims = [];
        $rems = [];
        $wrapper = [];
        $parentCategory->getMarker($treeHtml, $sims, $rems, $wrapper);
        $sims['###LEVEL###'] = $level;
        $treeHtml = Functions::substituteMarkerArrayNotCached($treeHtml, $sims, $rems, $wrapper);

        $categoryArray = $parentCategoryArray[$parentCategory->getUid()];
        if (is_array($categoryArray)) {
            $tempHtml = $treeConf['subElement'];
            $sims = [];
            $rems = [];
            $wrapper = [];
            $parentCategory->getMarker($tempHtml, $sims, $rems, $wrapper);
            $sims['###LEVEL###'] = $level;
            $treeHtml .= Functions::substituteMarkerArrayNotCached(
                $tempHtml,
                $sims,
                $rems,
                $wrapper
            );

            foreach ($categoryArray as $category) {
                $treeHtml .= $this->cObj->stdWrap($this->addSubCategory(
                    $treeConf,
                    $parentCategoryArray,
                    $category,
                    $level,
                    $renderAsForm
                ), $treeConf['subElement_wrap.']);
            }
            $treeHtml .= $treeConf['subElement_pre'];
        }
        return $treeHtml;
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getCreateCalendarLinkMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###CREATE_CALENDAR_LINK###'] = '';
        if ($this->rightsObj->isAllowedToCreateCalendar()) {
            $this->initLocalCObject();
            $this->local_cObj->setCurrentVal($this->conf['view.']['calendar.']['calendar.']['addIcon']);
            $this->controller->getParametersForTyposcriptLink(
                $this->local_cObj->data,
                [
                    'view' => 'create_calendar',
                    'type' => 'tx_cal_calendar'
                ],
                $this->conf['cache'],
                $this->conf['clear_anyway'],
                $this->conf['view.']['calendar.']['createCalendarViewPid']
            );
            $sims['###CREATE_CALENDAR_LINK###'] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.']['calendar.']['calendar.']['addLink'],
                $this->conf['view.']['calendar.']['calendar.']['addLink.']
            );
        }
    }

    /**
     * @param $this_year
     * @param $dateFormat_month
     * @return string
     */
    public function list_months($this_year, $dateFormat_month): string
    {
        if ((int)$this->conf['view.']['other.']['listMonth_referenceToday'] === 1) {
            $this_year = strftime('%Y');
        }

        $viewTarget = $this->conf['view.']['monthLinkTarget'];
        $day_array2 = [];
        preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $this->conf['getdate'], $day_array2);
        $this_month = $day_array2[2];

        if ($this->conf['view.']['other.']['listMonth_onlyShowCurrentYear']) {
            $month = 1;
            $monthSize = 12;
        } else {
            $monthSize = intval($this->conf['view.']['other.']['listMonth_totalMonthCount']);
            $monthSize = $monthSize ?: 12; // ensure valid data

            $monthOffset = intval($this->conf['view.']['other.']['listMonth_previousMonthCount']);
            $monthOffset = ($monthOffset < $monthSize) ? $monthOffset : intval($monthSize / 2);

            $month = $this_month - $monthOffset; // calc start month
            if ($month < 1) { // the year needs to be switched
                $this_year = $this_year - intval(abs($month) / 12) - 1; // calc the year
                $month = 12 + ($month % 12);
            }
        }

        $month_time = Calendar::calculateStartDayTime();
        $month_time->setDay(1);
        $month_time->setMonth($month);
        $month_time->setYear($this_year);

        $return = '';

        for ($i = 0; $i < $monthSize; $i++) {
            $monthdate = $month_time->format('%Y%m%d');
            $month_month = $month_time->getMonth();
            $select_month = $month_time->format($dateFormat_month);

            if (!empty($this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid'])) {
                $link = $this->controller->pi_linkTP_keepPIvars_url(
                    [
                        'getdate' => $monthdate,
                        'view' => $viewTarget,
                        $this->pointerName => null
                    ],
                    $this->conf['cache'],
                    $this->conf['clear_anyway'],
                    $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid']
                );
            } else {
                $link = $this->controller->pi_linkTP_keepPIvars_url([
                    'getdate' => $monthdate,
                    'view' => $viewTarget,
                    $this->pointerName => null
                ], $this->conf['cache'], $this->conf['clear_anyway']);
            }
            $link = GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . $link;

            if ($month_month === $this_month) {
                $tmp = $this->cObj->stdWrap($link, $this->conf['view.']['other.']['listMonthSelected_stdWrap.']);
                $return .= str_replace('###MONTH###', $select_month, $tmp);
            } else {
                $tmp = $this->cObj->stdWrap($link, $this->conf['view.']['other.']['listMonth_stdWrap.']);
                $return .= str_replace('###MONTH###', $select_month, $tmp);
            }
            $month_time->addSeconds(86400 * 32);
            $month_time = Calendar::calculateStartMonthTime($month_time);
        }
        return $return;
    }

    /**
     * @param $this_year
     * @param $dateFormat
     * @return string
     */
    public function list_years($this_year, $dateFormat): string
    {
        $viewTarget = $this->conf['view.']['yearLinkTarget'];
        $day_array2 = [];
        preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $this->conf['getdate'], $day_array2);
        list($this_year, $this_month, $this_day) = $day_array2;

        $yearSize = intval($this->conf['view.']['other.']['listYear_totalYearCount']);
        $yearSize = $yearSize ?: 3; // ensure valid data

        $yearOffset = intval($this->conf['view.']['other.']['listYear_previousYearCount']);
        $yearOffset = ($yearOffset < $yearSize) ? $yearOffset : intval($yearSize / 2);

        $currentYear = $this_year - $yearOffset;

        $return = '';

        for ($i = 0; $i < $yearSize; $i++) {
            $date = $currentYear . $this_month . $this_day;
            $year = gmstrftime($dateFormat, gmmktime(0, 0, 0, $this_month, $this_day, $currentYear));

            if (!empty($this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid'])) {
                $link = $this->controller->pi_linkTP_keepPIvars_url(
                    [
                        'getdate' => $date,
                        'view' => $viewTarget,
                        $this->pointerName => null
                    ],
                    $this->conf['cache'],
                    $this->conf['clear_anyway'],
                    $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid']
                );
            } else {
                $link = $this->controller->pi_linkTP_keepPIvars_url([
                    'getdate' => $date,
                    'view' => $viewTarget,
                    $this->pointerName => null
                ], $this->conf['cache'], $this->conf['clear_anyway']);
            }
            $link = GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . $link;
            if ($currentYear === $this_year) {
                $tmp = $this->cObj->stdWrap($link, $this->conf['view.']['other.']['listYearSelected_stdWrap.']);
            } else {
                $tmp = $this->cObj->stdWrap($link, $this->conf['view.']['other.']['listYear_stdWrap.']);
            }
            $return .= str_replace('###YEAR###', $year, $tmp);

            $currentYear++;
        }

        return $return;
    }

    /**
     * @param $this_year
     * @param $dateFormat_week_jump
     * @return string
     */
    public function list_weeks($this_year, $dateFormat_week_jump): string
    {
        $viewTarget = $this->conf['view.']['weekLinkTarget'];

        if ($this->conf['view.']['other.']['listWeek_onlyShowCurrentYear']) {
            $weekSize = 52;

            $start_week_time = new CalDate($this->controller->getDateTimeObject->getYear() . '0101000000');
            $start_week_time->setTZbyID('UTC');
        } else {
            $weekSize = intval($this->conf['view.']['other.']['listWeek_totalWeekCount']);
            $weekSize = $weekSize ?: 10; // ensure valid data

            $weekOffset = intval($this->conf['view.']['other.']['listWeek_previousWeekCount']);
            $weekOffset = ($weekOffset < $weekSize) ? $weekOffset : intval($weekSize / 2);

            $start_week_time = new CalDate();
            $start_week_time->copy($this->controller->getDateTimeObject);
            $start_week_time->subtractSeconds(604800 * $weekOffset);
        }

        $start_week_time = Calendar::calculateStartWeekTime($start_week_time);
        $end_week_time = Calendar::calculateEndWeekTime($start_week_time);
        $formattedGetdate = intval($this->conf['getdate']);

        $return = '';

        for ($i = 0; $i < $weekSize; $i++) {
            $weekdate = $start_week_time->format('%Y%m%d');
            $select_week1 = $start_week_time->format($dateFormat_week_jump);
            $select_week2 = $end_week_time->format($dateFormat_week_jump);

            if (!empty($this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid'])) {
                $link = $this->controller->pi_linkTP_keepPIvars_url(
                    [
                        'getdate' => $weekdate,
                        'view' => $viewTarget,
                        $this->pointerName => null
                    ],
                    $this->conf['cache'],
                    $this->conf['clear_anyway'],
                    $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid']
                );
            } else {
                $link = $this->controller->pi_linkTP_keepPIvars_url([
                    'getdate' => $weekdate,
                    'view' => $viewTarget,
                    $this->pointerName => null
                ], $this->conf['cache'], $this->conf['clear_anyway']);
            }
            $link = GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . $link;
            $formattedStart = $start_week_time->format('%Y%m%d');
            $formattedEnd = $end_week_time->format('%Y%m%d');
            if (($formattedGetdate >= $formattedStart) && ($formattedGetdate <= $formattedEnd)) {
                $tmp = $this->cObj->stdWrap($link, $this->conf['view.']['other.']['listWeeksSelected_stdWrap.']);
                $tmp = str_replace('###WEEK1###', $select_week1, $tmp);
                $return .= str_replace('###WEEK2###', $select_week2, $tmp);
            } else {
                $tmp = $this->cObj->stdWrap($link, $this->conf['view.']['other.']['listWeeks_stdWrap.']);
                $tmp = str_replace('###WEEK1###', $select_week1, $tmp);
                $return .= str_replace('###WEEK2###', $select_week2, $tmp);
            }
            $start_week_time->addSeconds(604800);
            $end_week_time->addSeconds(604800);
        }

        return $return;
    }

    /**
     * @param $template
     * @return mixed
     */
    public function tomorrows_events($template)
    {
        $starttime = new CalDate($this->conf['getdate'] . '000000');
        $starttime->setTZbyID('UTC');

        $starttime->addSeconds(86400);
        $next_day = $starttime->format('%Y%m%d');

        $match1 = $this->markerBasedTemplateService->getSubpart($template, '###T_ALLDAY_SWITCH###');
        $match2 = $this->markerBasedTemplateService->getSubpart($template, '###T_EVENT_SWITCH###');
        $loop_t_ad = trim($match1);
        $loop_t_e = trim($match2);

        if (is_array($this->master_array[$next_day]) && count($this->master_array[$next_day]) > 0) {
            $replace_ad = '';
            $replace_e = '';
            foreach ($this->master_array[$next_day] as $cal_time => $event_times) {
                /**
                 * @var int $uid
                 * @var EventModel $event
                 */
                foreach ($event_times as $uid => $event) {
                    $wrapped['###EVENT_LINK###'] = explode(
                        '|',
                        $event->getLinkToEvent(
                            '|',
                            $this->conf['view'],
                            $next_day,
                            $this->conf['view.']['other.']['tomorrowsEvents_stdWrap.']
                        )
                    );
                    $return = $wrapped['###EVENT_LINK###'][0] . $event->renderTomorrowsEvent() . $wrapped['###EVENT_LINK###'][1];
                    $eventStart = $event->getStart();
                    if ($eventStart->getHour() === 0 && $eventStart->getMinute() === 0) {
                        $replace_ad .= $return;
                    } else {
                        $replace_e .= $return;
                    }
                }
            }

            $rems['###T_ALLDAY_SWITCH###'] = str_replace('###T_ALLDAY###', $replace_ad, $loop_t_ad);
            $rems['###T_EVENT_SWITCH###'] = str_replace('###T_EVENT###', $replace_e, $loop_t_e);
            return Functions::substituteMarkerArrayNotCached($template, [], $rems, []);
        }
        $rems['###T_ALLDAY_SWITCH###'] = '';
        $rems['###T_EVENT_SWITCH###'] = '';
        return Functions::substituteMarkerArrayNotCached($template, [], $rems, []);
    }

    /**
     * @param $eventFreq
     * @return string
     */
    public function getFreq($eventFreq): string
    {
        $freq_type = '';
        switch ($eventFreq) {
            case 'year':
                $freq_type = 'YEARLY';
                break;
            case 'month':
                $freq_type = 'MONTHLY';
                break;
            case 'week':
                $freq_type = 'WEEKLY';
                break;
            case 'day':
                $freq_type = 'DAILY';
                break;
            case 'hour':
                $freq_type = 'HOURLY';
                break;
            case 'minute':
                $freq_type = 'MINUTELY';
                break;
            case 'second':
                $freq_type = 'SECONDLY';
                break;
        }
        return $freq_type;
    }

    /**
     * @param string $offset
     * @param $type
     * @return string
     */
    public function _draw_month_new($offset, $type): string
    {
        if (preg_match('![+|-][0-9]{1,2}!is', $offset)) { // new one
            $monthDate = new CalDate();
            $monthDate->copy($this->controller->getDateTimeObject);
            $monthDate->setDay(15);
            if (intval($offset) < 0) {
                $monthDate->subtractSeconds(abs(intval($offset)) * 2592000);
            } else {
                $monthDate->addSeconds(intval($offset) * 2592000);
            }
        } else {
            $monthDate = new CalDate();
            $monthDate->copy($this->controller->getDateTimeObject);
            $monthDate->setDay(15);
            if (intval($offset) > 12) {
                $monthDate->setYear($monthDate->getYear() + ($offset - ($offset % 12)) / 12);
                $monthDate->setMonth($offset % 12);
            } else {
                $monthDate->setMonth($offset);
            }
        }

        $page = Functions::getContent($this->conf['view.']['month.']['new' . ucwords($type) . 'MonthTemplate']);

        $monthModel = NewMonthView::getMonthView($monthDate->month, $monthDate->year);

        $today = new CalDate();
        $monthModel->setCurrent($today);

        $selected = new CalDate($this->conf['getdate']);
        $monthModel->setSelected($selected);

        $monthModel->setWeekDayFormat($this->conf['view.'][$this->conf['view'] . '.']['weekdayFormat' . ucwords($type) . 'Month']);
        $weekdayLength = intval($this->conf['view.'][$this->conf['view'] . '.']['weekdayLength' . ucwords($type) . 'Month']);
        if ($weekdayLength > 0) {
            $monthModel->setWeekDayLength($weekdayLength);
        }

        $masterArrayKeys = array_keys($this->master_array);
        foreach ($masterArrayKeys as $dateKey) {
            $dateArray = &$this->master_array[$dateKey];
            $dateArrayKeys = array_keys($dateArray);
            foreach ($dateArrayKeys as $timeKey) {
                $arrayOfEvents = &$dateArray[$timeKey];
                $eventKeys = array_keys($arrayOfEvents);
                foreach ($eventKeys as $eventKey) {
                    $monthModel->addEvent($arrayOfEvents[$eventKey]);
                }
            }
        }

        return $monthModel->render($page);
    }

    /**
     * Draws the month view
     * @param string       $page    string        The page template
     * @param string $offset integer        The month offset. Default = +0
     * @param  int      $type    integer        The date of the event
     * @return        string        The HTML output.
     */
    public function _draw_month($page, $offset, $type): string
    {
        $viewTarget = $this->conf['view.']['monthLinkTarget'];
        $monthTemplate = $this->markerBasedTemplateService->getSubpart($page, '###MONTH_TEMPLATE###');
        if ($monthTemplate !== '') {
            $loop_wd = $this->markerBasedTemplateService->getSubpart($monthTemplate, '###LOOPWEEKDAY###');
            $t_month = $this->markerBasedTemplateService->getSubpart($monthTemplate, '###SWITCHMONTHDAY###');
            $startweek = $this->markerBasedTemplateService->getSubpart($monthTemplate, '###LOOPMONTHWEEKS_DAYS###');
            $endweek = $this->markerBasedTemplateService->getSubpart($monthTemplate, '###LOOPMONTHDAYS_WEEKS###');
            $weeknum = $this->markerBasedTemplateService->getSubpart($monthTemplate, '###LOOPWEEK_NUMS###');
            $corner = $this->markerBasedTemplateService->getSubpart($monthTemplate, '###CORNER###');

            if (preg_match('![+|-][0-9]{1,2}!is', $offset)) { // new one
                $fake_getdate_time = new CalDate();
                $fake_getdate_time->copy($this->controller->getDateTimeObject);
                $fake_getdate_time->setDay(15);
                if (intval($offset) < 0) {
                    $fake_getdate_time->subtractSeconds(abs(intval($offset)) * 2592000);
                } else {
                    $fake_getdate_time->addSeconds(intval($offset) * 2592000);
                }
            } else {
                $fake_getdate_time = new CalDate();
                $fake_getdate_time->copy($this->controller->getDateTimeObject);
                $fake_getdate_time->setDay(15);
                $fake_getdate_time->setMonth($offset);
            }

            $minical_month = $fake_getdate_time->getMonth();
            $today = new CalDate();

            $month_title = $fake_getdate_time->format($this->conf['view.'][$viewTarget . '.']['dateFormatMonth']);
            $this->initLocalCObject();
            $this->local_cObj->setCurrentVal($month_title);
            $this->local_cObj->data['view'] = $viewTarget;
            $this->controller->getParametersForTyposcriptLink(
                $this->local_cObj->data,
                ['getdate' => $fake_getdate_time->format('%Y%m%d'), 'view' => $viewTarget, $this->pointerName => null],
                $this->conf['cache'],
                $this->conf['clear_anyway'],
                $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid']
            );
            $month_title = $this->local_cObj->cObjGetSingle(
                $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewLink'],
                $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewLink.']
            );
            $month_date = $fake_getdate_time->format('%Y%m%d');

            $view_array = [];

            if (!$this->viewarray) {
                $this->eventArray = [];
                if (!empty($this->master_array)) {
                    // use array keys for the loop in order to be able to use referenced events instead of copies and save some memory
                    $masterArrayKeys = array_keys($this->master_array);
                    foreach ($masterArrayKeys as $dateKey) {
                        $dateArray = &$this->master_array[$dateKey];
                        $dateArrayKeys = array_keys($dateArray);
                        foreach ($dateArrayKeys as $timeKey) {
                            $arrayOfEvents = &$dateArray[$timeKey];
                            $eventKeys = array_keys($arrayOfEvents);
                            foreach ($eventKeys as $eventKey) {
                                /** @var EventModel $event */
                                $event = &$arrayOfEvents[$eventKey];
                                $eventReferenceKey = $dateKey . '_' . $event->getType() . '_' . $event->getUid() . '_' . $event->getStart()->format('%Y%m%d%H%M%S');
                                $this->eventArray[$eventReferenceKey] = &$event;
                                $starttime = new CalDate();
                                $starttime->copy($event->getStart());
                                $endtime = new CalDate();
                                $endtime->copy($event->getEnd());
                                if ($timeKey === '-1') {
                                    $endtime->addSeconds(1); // needed to let allday events show up
                                }
                                $j = new CalDate();
                                $j->copy($starttime);
                                $j->setHour(0);
                                $j->setMinute(0);
                                $j->setSecond(0);
                                for (; $j->before($endtime); $j->addSeconds(60 * 60 * 24)) {
                                    $view_array[$j->format('%Y%m%d')]['000000'][count($view_array[$j->format('%Y%m%d')]['000000'])] = $eventReferenceKey;
                                }
                            }
                        }
                    }
                }
                $this->viewarray = &$view_array;
            }

            $monthTemplate = str_replace('###MONTH_TITLE###', $month_title, $monthTemplate);

            $langtype = $this->conf['view.']['month.']['weekdayFormat' . ucwords($type) . 'Month'];
            $typeSize = intval($this->conf['view.']['month.']['weekdayLength' . ucwords($type) . 'Month']);

            $dateOfWeek = Calc::beginOfWeek(15, $fake_getdate_time->getMonth(), $fake_getdate_time->getYear());
            $start_day = new CalDate($dateOfWeek . '000000');

            $weekday_loop = '';

            // backwardscompatibility with old templates
            if (!empty($corner)) {
                $weekday_loop .= str_replace(
                    '###ADDITIONAL_CLASSES###',
                    $this->conf['view.']['month.']['monthCornerStyle'],
                    $corner
                );
            } else {
                $weekday_loop .= sprintf($weeknum, $this->conf['view.']['month.']['monthCornerStyle'], '');
            }

            for ($i = 0; $i < 7; $i++) {
                $weekday = $start_day->format($langtype);
                $weekdayLong = $start_day->format('%A');
                if ($typeSize) {
                    $weekday = mb_substr(
                        $weekday,
                        0,
                        $typeSize,
                        Functions::getCharset()
                    );
                }
                $start_day->addSeconds(86400);

                $additionalClasses = trim(sprintf(
                    $this->conf['view.']['month.']['monthDayOfWeekStyle'],
                    $start_day->format('%w')
                ));
                $markerArray = [
                    '###WEEKDAY###' => $weekday,
                    '###WEEKDAY_LONG###' => $weekdayLong,
                    '###ADDITIONAL_CLASSES###' => ' ' . $additionalClasses,
                    '###CLASSES###' => !empty($additionalClasses) ? ' class="' . $additionalClasses . '" ' : '',
                ];
                $weekday_loop .= strtr($loop_wd, $markerArray);
            }
            $weekday_loop .= $endweek;

            $dateOfWeek = Calc::beginOfWeek(1, $fake_getdate_time->getMonth(), $fake_getdate_time->getYear());

            $start_day = new CalDate($dateOfWeek . '000000');
            $start_day->setTZbyID('UTC');

            $i = 0;
            $whole_month = true;
            $isAllowedToCreateEvent = $this->rightsObj->isAllowedToCreateEvent();

            $createOffset = intval($this->conf['rights.']['create.']['event.']['timeOffset']) * 60;

            $getdate = new CalDate($this->conf['getdate']);
            $getdate->setTZbyID('UTC');
            $startWeekTime = Calendar::calculateStartWeekTime($getdate);
            $endWeekTime = Calendar::calculateEndWeekTime($getdate);

            $formattedWeekStartTime = $startWeekTime->format('%Y%m%d');
            $formattedWeekEndTime = $endWeekTime->format('%Y%m%d');
            $middle = '';

            do {
                $daylink = new CalDate();
                $daylink->copy($start_day);

                $formatedGetdate = $daylink->format('%Y%m%d');
                $formatedDayDate = $daylink->format($this->conf['view.']['month.']['dateFormatDay']);

                $isCurrentWeek = false;
                $isSelectedWeek = false;
                if ($formatedGetdate >= $formattedWeekStartTime && $formatedGetdate <= $formattedWeekEndTime) {
                    $isSelectedWeek = true;
                }

                if ($start_day->format('%Y%U') === $today->format('%Y%U')) {
                    $isCurrentWeek = true;
                }

                if ($i === 0 && !empty($weeknum)) {
                    $start_day->addSeconds(86400);
                    $num = $numPlain = $start_day->getWeekOfYear();
                    $hasEvent = false;
                    $start_day->subtractSeconds(86400);
                    for ($j = 0; $j < 7; $j++) {
                        if ($isAllowedToCreateEvent || is_array($this->viewarray[$start_day->format('%Y%m%d')])) {
                            $hasEvent = true;
                            break;
                        }
                        $start_day->addSeconds(86400);
                    }
                    $start_day->copy($daylink);
                    $weekLinkViewTarget = $this->conf['view.']['weekLinkTarget'];
                    if ($hasEvent && ($this->rightsObj->isViewEnabled($weekLinkViewTarget) || $this->conf['view.'][$weekLinkViewTarget . '.'][$weekLinkViewTarget . 'ViewPid'])) {
                        $this->initLocalCObject();
                        $this->local_cObj->setCurrentVal($num);
                        $this->local_cObj->data['view'] = $weekLinkViewTarget;
                        $this->controller->getParametersForTyposcriptLink(
                            $this->local_cObj->data,
                            ['getdate' => $formatedGetdate, 'view' => $weekLinkViewTarget, $this->pointerName => null],
                            $this->conf['cache'],
                            $this->conf['clear_anyway'],
                            $this->conf['view.'][$weekLinkViewTarget . '.'][$weekLinkViewTarget . 'ViewPid']
                        );
                        $num = $this->local_cObj->cObjGetSingle(
                            $this->conf['view.'][$weekLinkViewTarget . '.'][$weekLinkViewTarget . 'ViewLink'],
                            $this->conf['view.'][$weekLinkViewTarget . '.'][$weekLinkViewTarget . 'ViewLink.']
                        );
                    }

                    $className = [];
                    if ($isSelectedWeek && !empty($this->conf['view.']['month.']['monthSelectedWeekStyle'])) {
                        $className[] = $this->conf['view.']['month.']['monthSelectedWeekStyle'];
                    }
                    if ($isCurrentWeek && !empty($this->conf['view.']['month.']['monthCurrentWeekStyle'])) {
                        $className[] = $this->conf['view.']['month.']['monthCurrentWeekStyle'];
                    }
                    if ($hasEvent && !empty($this->conf['view.']['month.']['monthWeekWithEventStyle'])) {
                        $className[] = $this->conf['view.']['month.']['monthWeekWithEventStyle'];
                    }

                    $weekClasses = trim(implode(' ', $className));
                    $markerArray = [
                        '###ADDITIONAL_CLASSES###' => $weekClasses ? ' ' . $weekClasses : '',
                        '###CLASSES###' => $weekClasses ? ' class="' . $weekClasses . '" ' : '',
                        '###WEEKNUM###' => $num,
                        '###WEEKNUM_PLAIN###' => $numPlain,
                    ];
                    $middle .= strtr($startweek, $markerArray);
                    // we do this sprintf all only for backwards compatibility with old templates
                    $middle .= strtr(sprintf($weeknum, $markerArray['###ADDITIONAL_CLASSES###'], $num), $markerArray);
                }
                $i++;
                $switch = ['###ALLDAY###' => ''];
                $check_month = $start_day->getMonth();

                $switch['###LINK###'] = $this->getCreateEventLink(
                    'month',
                    '',
                    $start_day,
                    $createOffset,
                    $isAllowedToCreateEvent,
                    '',
                    '',
                    $this->conf['view.']['day.']['dayStart']
                );

                $style = [];

                $dayLinkViewTarget = $this->conf['view.']['dayLinkTarget'];
                if (($this->rightsObj->isViewEnabled($dayLinkViewTarget) || $this->conf['view.'][$dayLinkViewTarget . '.'][$dayLinkViewTarget . 'ViewPid']) && ($this->viewarray[$formatedGetdate] || $isAllowedToCreateEvent)) {
                    $this->initLocalCObject();
                    $this->local_cObj->setCurrentVal($formatedDayDate);
                    $this->local_cObj->data['view'] = $dayLinkViewTarget;
                    $this->controller->getParametersForTyposcriptLink(
                        $this->local_cObj->data,
                        ['getdate' => $formatedGetdate, 'view' => $dayLinkViewTarget, $this->pointerName => null],
                        $this->conf['cache'],
                        $this->conf['clear_anyway'],
                        $this->conf['view.'][$dayLinkViewTarget . '.'][$dayLinkViewTarget . 'ViewPid']
                    );
                    $switch['###LINK###'] .= $this->local_cObj->cObjGetSingle(
                        $this->conf['view.'][$dayLinkViewTarget . '.'][$dayLinkViewTarget . 'ViewLink'],
                        $this->conf['view.'][$dayLinkViewTarget . '.'][$dayLinkViewTarget . 'ViewLink.']
                    );
                    if ($switch['###LINK###'] === '') {
                        $switch['###LINK###'] .= $formatedDayDate;
                    }
                    $switch['###LINK###'] = $this->cObj->stdWrap(
                        $switch['###LINK###'],
                        $this->conf['view.']['month.'][$type . 'Link_stdWrap.']
                    );
                } else {
                    $switch['###LINK###'] .= $formatedDayDate;
                }
                // add a css class if the current day has a event - regardless if linked or not
                if ($this->viewarray[$formatedGetdate]) {
                    $style[] = $this->conf['view.']['month.']['eventDayStyle'];
                }
                $style[] = $this->conf['view.']['month.']['month' . ucfirst($type) . 'Style'];

                if ($check_month !== $minical_month) {
                    $style[] = $this->conf['view.']['month.']['monthOffStyle'];
                }
                if ($start_day->format('%w') === '0' || $start_day->format('%w') === '6') {
                    $style[] = $this->conf['view.']['month.']['monthDayWeekendStyle'];
                }
                if ($isSelectedWeek) {
                    $style[] = $this->conf['view.']['month.']['monthDaySelectedWeekStyle'];
                }
                if ($formatedGetdate === $this->conf['getdate']) {
                    $style[] = $this->conf['view.']['month.']['monthSelectedStyle'];
                }
                if ($isCurrentWeek) {
                    $style[] = $this->conf['view.']['month.']['monthDayCurrentWeekStyle'];
                }
                if ($formatedGetdate === $today->format('%Y%m%d')) {
                    $style[] = $this->conf['view.']['month.']['monthTodayStyle'];
                }
                if ($this->conf['view.']['month.']['monthDayOfWeekStyle']) {
                    $style[] = sprintf($this->conf['view.']['month.']['monthDayOfWeekStyle'], $start_day->format('%w'));
                }

                //clean up empty styles (code beautify)
                foreach ($style as $key => $classname) {
                    if ($classname === '') {
                        unset($style[$key]);
                    }
                }

                // Adds hook for processing of extra month day style markers
                if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['tx_cal_controller']['extraMonthDayStyleMarkerHook'])) {
                    foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['tx_cal_controller']['extraMonthDayStyleMarkerHook'] as $_classRef) {
                        $_procObj = &GeneralUtility::getUserObj($_classRef);
                        if (is_object($_procObj) && method_exists($_procObj, 'extraMonthDayStyleMarkerProcessor')) {
                            $_procObj->extraMonthDayStyleMarkerProcessor($this, $daylink, $switch, $type, $style);
                        }
                    }
                }

                $classesDay = implode(' ', $style);
                $markerArray = [
                    '###STYLE###' => $classesDay,
                    '###ADDITIONAL_CLASSES###' => $classesDay ? ' ' . $classesDay : '',
                    '###CLASSES###' => $classesDay ? ' class="' . $classesDay . '" ' : '',
                    '###DAY_ID###' => $formatedGetdate,
                ];

                $temp = strtr($t_month, $markerArray);

                $wraped = [];

                if ($this->viewarray[$formatedGetdate] && preg_match('!\###EVENT\###!is', $t_month)) {
                    foreach ($this->viewarray[$formatedGetdate] as $cal_time => $event_times) {
                        foreach ($event_times as $uid => $eventId) {
                            if ($type === 'large') {
                                $switch['###EVENT###'] .= $this->eventArray[$eventId]->renderEventForMonth();
                            } elseif ($type === 'medium') {
                                $switch['###EVENT###'] .= $this->eventArray[$eventId]->renderEventForYear();
                            } elseif ($type === 'small') {
                                $switch['###EVENT###'] .= $this->eventArray[$eventId]->renderEventForMiniMonth();
                            }
                        }
                    }
                }

                if (!isset($switch['###EVENT###'])) {
                    $this->initLocalCObject();
                    $switch['###EVENT###'] = $this->local_cObj->cObjGetSingle(
                        $this->conf['view.'][$viewTarget . '.']['event.']['noEventFound'],
                        $this->conf['view.'][$viewTarget . '.']['event.']['noEventFound.']
                    );
                }
                if (!isset($switch['###ALLDAY###'])) {
                    $this->initLocalCObject();
                    $switch['###ALLDAY###'] = $this->local_cObj->cObjGetSingle(
                        $this->conf['view.'][$viewTarget . '.']['event.']['noEventFound'],
                        $this->conf['view.'][$viewTarget . '.']['event.']['noEventFound.']
                    );
                }

                // Adds hook for processing of extra month day markers
                if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['tx_cal_controller']['extraMonthDayMarkerHook'])) {
                    foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['tx_cal_controller']['extraMonthDayMarkerHook'] as $_classRef) {
                        $_procObj = &GeneralUtility::getUserObj($_classRef);
                        if (is_object($_procObj) && method_exists($_procObj, 'extraMonthDayMarkerProcessor')) {
                            $switch = $_procObj->extraMonthDayMarkerProcessor($this, $daylink, $switch, $type);
                        }
                    }
                }

                $middle .= Functions::substituteMarkerArrayNotCached(
                    $temp,
                    $switch,
                    [],
                    $wraped
                );

                $start_day->addSeconds(86400); // 60 * 60 *24 -> strtotime('+1 day', $start_day);
                if ($i === 7) {
                    $i = 0;
                    $middle .= $endweek;
                    $checkagain = $start_day->getMonth();
                    if ($checkagain !== $minical_month) {
                        $whole_month = false;
                    }
                }
            } while ($whole_month);

            $rems['###LOOPWEEKDAY###'] = $weekday_loop;
            $rems['###LOOPMONTHWEEKS###'] = $middle;
            $rems['###LOOPMONTHWEEKS_DAYS###'] = '';
            $rems['###LOOPWEEK_NUMS###'] = '';
            $rems['###CORNER###'] = '';
            $monthTemplate = Functions::substituteMarkerArrayNotCached(
                $monthTemplate,
                [],
                $rems,
                []
            );
            $page = Functions::substituteMarkerArrayNotCached(
                $page,
                [],
                ['###MONTH_TEMPLATE###' => $monthTemplate],
                []
            );
        }

        $listTemplate = $this->markerBasedTemplateService->getSubpart($page, '###LIST###');
        if ($listTemplate !== '') {
            $tx_cal_listview = &GeneralUtility::makeInstanceService('cal_view', 'list', 'list');
            $starttime = gmmktime(0, 0, 0, $this_month, 1, $this_year);
            $endtime = gmmktime(0, 0, 0, $this_month + 1, 1, $this_year);
            $rems['###LIST###'] = $tx_cal_listview->drawList($this->master_array, $listTemplate, $starttime, $endtime);
        }

        $return = Functions::substituteMarkerArrayNotCached($page, [], $rems, []);

        if ($this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid'] || $this->rightsObj->isViewEnabled($viewTarget)) {
            $this->initLocalCObject();
            $this->local_cObj->setCurrentVal($month_title);
            $this->local_cObj->data['view'] = $viewTarget;
            $this->controller->getParametersForTyposcriptLink(
                $this->local_cObj->data,
                ['getdate' => $month_date, 'view' => $viewTarget, $this->pointerName => null],
                $this->conf['cache'],
                $this->conf['clear_anyway'],
                $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewPid']
            );
            $month_link = $this->local_cObj->cObjGetSingle(
                $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewLink'],
                $this->conf['view.'][$viewTarget . '.'][$viewTarget . 'ViewLink.']
            );
        } else {
            $month_link = $month_title;
        }

        $return = str_replace('###MONTH_LINK###', $month_link, $return);

        return $return;
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getMeetingInformationMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###MEETING_INFORMATION###'] = '';
        $foundEvents = [];
        $eventService = &Functions::getEventService();
        $eventDateArray = $eventService->findMeetingEventsWithEmptyStatus($this->conf['pidList']);
        if (!empty($eventDateArray)) {
            $foundEvents[] = 'These meetings require your action:';
        }
        if (is_array($eventDateArray)) {
            foreach ($eventDateArray as $eventTimeArray) {
                foreach ($eventTimeArray as $eventArray) {
                    /** @var EventModel  $event */
                    foreach ($eventArray as $event) {
                        $foundEvents[] = $event->getLinkToEvent(
                            $event->getTitle(),
                            $this->conf['view'],
                            $this->conf['getdate']
                        );
                    }
                }
            }
        }
        $sims['###MEETING_INFORMATION###'] = implode('<br/>', $foundEvents);
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getSidebarMarker(&$page, &$sims, &$rems, $view)
    {
        $page = $this->replace_files(
            $page,
            [
                'sidebar' => $this->conf['view.']['other.']['sidebarTemplate']
            ]
        );
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getCalendarNavMarker(&$page, &$sims, &$rems, $view)
    {
        if ((int)$this->conf['view.']['month.']['navigation'] === 0) {
            $page = str_replace('###CALENDAR_NAV###', '', $page);
        } else {
            $template = Functions::getContent($this->conf['view.']['month.']['horizontalSidebarTemplate']);
            if ($template === '') {
                $template = '<h3>calendar: no calendar_nav template file found:</h3>' . $this->conf['view.']['month.']['horizontalSidebarTemplate'];
            }
            $page = str_replace('###CALENDAR_NAV###', $template, $page);
        }
    }

    /**
     * Method to initialise a local content object, that can be used for customized TS rendering with own db values
     * @param bool $customData
     */
    public function initLocalCObject($customData = false)
    {
        if (!is_object($this->local_cObj)) {
            $this->local_cObj = &Registry::Registry('basic', 'local_cObj');
        }
        if ($customData && is_array($customData)) {
            $this->local_cObj->data = $customData;
        } else {
            $this->local_cObj->data = $this->cachedValueArray;
        }
    }

    /**
     * Method to return all values from current view, that might be interresting for rendering TS objects
     * @return    array    Array with key => value pairs that might be interresting
     */
    public function getValuesAsArray(): array
    {
        if (!is_array($this->cachedValueArray) || (is_array($this->cachedValueArray) && !count($this->cachedValueArray))) {
            // for now, just return the data of the parent cObject
            $this->cachedValueArray = &$this->cObj->data;
        }
        return $this->cachedValueArray;
    }

    /**
     * @param $page
     * @param $sims
     * @param $rems
     * @param $view
     */
    public function getAllowedToCreateEventsMarker(&$page, &$sims, &$rems, $view)
    {
        $sims['###ALLOWED_TO_CREATE_EVENTS###'] = $this->rightsObj->isAllowedToCreateEvent();
    }

    /**
     * @param null $object
     * @return string
     */
    public function renderWithFluid($object = null): string
    {
        $templateFile = GeneralUtility::getFileAbsFileName($this->conf['view.'][$this->conf['view'] . '.'][$this->conf['view'] . 'TemplateFluid']);
        /** @var $view StandaloneView */
        $view = new StandaloneView();
        $view->setTemplatePathAndFilename($templateFile);
        $view->setPartialRootPaths([GeneralUtility::getFileAbsFileName($this->conf['fluidPartialsPath'])]);
        $view->assign($this->conf['view'] . 'View', $this);
        if (is_object($object)) {
            $view->assign($this->conf['view'], $object);
        }
        $view->assign('settings', Functions::getTsSetupAsPlainArray($this->conf));
        return $view->render();
    }

    /**
     * @return int
     */
    public function getCount(): int
    {
        $count = 0;
        if (count($this->master_array)) {

            // parse the master_array for "valid" events of the current listView and reference them in a separate array that is used for rendering
            // use array keys for the loops, so that references can be used and less memory is needed :)
            $master_array_keys = array_keys($this->master_array);

            foreach ($master_array_keys as $cal_time) {
                // create a reference
                $event_times = &$this->master_array[$cal_time];
                if (is_array($event_times)) {
                    $event_times_keys = array_keys($event_times);
                    foreach ($event_times_keys as $a_key) {
                        $a = &$event_times[$a_key];
                        if (is_array($a)) {
                            $a_keys = array_keys($a);
                            foreach ($a_keys as $uid) {
                                $event = &$a[$uid];

                                if (!is_object($event)) {
                                    unset($this->master_array[$cal_time][$event_times][$a_keys]);
                                    continue;
                                }
                                if ((int)$this->conf['view.'][$this->conf['view'] . '.']['hideStartedEvents'] === 1 && $event->getStart()->before($this->starttime)) {
                                    unset($this->master_array[$cal_time][$event_times][$a_keys]);
                                    continue;
                                }

                                if ($event->getEnd()->before($this->starttime) || $event->getStart()->after($this->endtime)) {
                                    unset($this->master_array[$cal_time][$event_times][$a_keys]);
                                    continue;
                                }
                                $count++;
                            }
                        }
                    }
                }
            }
        }
        return $count;
    }
}
