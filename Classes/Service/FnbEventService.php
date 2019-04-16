<?php

namespace TYPO3\CMS\Cal\Service;

use TYPO3\CMS\Cal\Model\CalDate;
use TYPO3\CMS\Cal\Model\EventModel;
use TYPO3\CMS\Cal\Utility\Functions;

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

/**
 * A concrete model for the calendar.
 */
class FnbEventService extends EventService
{
    protected $fnbCalendarSearchString;
    protected $calendarIds;
    protected $calendarOwner;

    /**
     * @param $start_date
     * @param $end_date
     * @param $pidList
     * @param string $eventType
     * @param string $additionalWhere
     * @return array
     */
    public function findAllWithin(&$start_date, &$end_date, $pidList, $eventType = '0,1,2,3', $additionalWhere = ''): array
    {

        // How to get the events
        // 1st get Calendar specified
        // 2nd get categories specified
        // 3rd get all related events
        // make an array out of the list, so we can handle it better
        $includeRecurring = true;
        if ($this->conf['view'] === 'ics' || $this->conf['view'] === 'single_ics') {
            $includeRecurring = false;
        }

        $this->setStartAndEndPoint($start_date, $end_date);
        $dontShowOldEvents = (int)$this->conf['view.'][$this->conf['view'] . '.']['dontShowOldEvents'];
        if ($dontShowOldEvents > 0) {
            $now = new CalDate();
            if ($dontShowOldEvents === 2) {
                $now->setHour(0);
                $now->setMinute(0);
                $now->setSecond(0);
            }

            if ($start_date->getTime() <= $now->getTime()) {
                $start_date->copy($now);
            }
            if ($end_date->getTime() <= $now->getTime()) {
                $end_date->copy($now);
                $end_date->addSeconds(86400);
            }
            $this->starttime->copy($start_date);
            $this->endtime->copy($end_date);
        }
        $formattedStarttime = $this->starttime->format('Ymd');
        $formattedEndtime = $this->endtime->format('Ymd');
        $categoryService = &$this->modelObj->getServiceObjByKey(
            'cal_category_model',
            'category',
            $this->extConf['categoryService']
        );

        $calendarSearchString = $this->getFreeAndBusyCalendarSearchString(
            $pidList,
            true,
            $this->conf['calendar'] ?: ''
        );

        $recurringClause = '';
        // only include the recurring clause if we don't use the new recurring model or a view not needing recurring events.
        if ($includeRecurring) {
            // get the uids of recurring events from index
            $select = 'event_uid';
            $table = 'tx_cal_index';
            $where = 'start_datetime >= ' . $this->starttime->format('YmdHMS') . ' AND start_datetime <= ' . $this->endtime->format('YmdHMS');
            $group = 'event_uid';
            $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery($select, $table, $where, $group);
            $tmpUids = [];
            if ($result) {
                while ($tmp = $GLOBALS['TYPO3_DB']->sql_fetch_row($result)) {
                    $tmpUids[] = $tmp[0];
                }
                $GLOBALS['TYPO3_DB']->sql_free_result($result);
            }
            if (count($tmpUids)) {
                $recurringClause = ' OR (tx_cal_event.uid IN (' . implode(',', $tmpUids) . ')) ';
            }
        }

        // putting everything together
        // Franz: added simple check/include for rdate events at the end of this where clause.
        // But we need to find a way to only include rdate events within the searched timerange
        // - otherwise we'll flood the results after some time. I think we need a mm-table for that!
        $additionalWhere = $calendarSearchString . ' AND tx_cal_event.pid IN (' . $pidList . ') ' . $this->cObj->enableFields('tx_cal_event') . ' AND ((tx_cal_event.start_date>=' . $formattedStarttime . ' AND tx_cal_event.start_date<=' . $formattedEndtime . ') OR (tx_cal_event.end_date<=' . $formattedEndtime . ' AND tx_cal_event.end_date>=' . $formattedStarttime . ') OR (tx_cal_event.end_date>=' . $formattedEndtime . ' AND tx_cal_event.start_date<=' . $formattedStarttime . ')' . $recurringClause . ')' . $additionalWhere;
        // $additionalWhere = $calendarSearchString.' AND tx_cal_event.pid IN ('.$pidList.') '.$this->cObj->enableFields('tx_cal_event').' AND ((tx_cal_event.start_date>='.$formattedEndtime.' OR tx_cal_event.end_date>='.$formattedStarttime.')' . $recurringClause . ')'.$additionalWhere;

        // creating the arrays the user is allowed to see
        $categories = [];

        $categoryService->getCategoryArray($pidList, $categories);

        // creating events
        return $this->getEventsFromTable(
            $categories[0][0],
            $includeRecurring,
            $additionalWhere,
            $this->getServiceKey(),
            true,
            false,
            $eventType
        );
    }

    /**
     * @param $pidList
     * @param $includePublic
     * @param $linkIds
     * @return string
     */
    public function getFreeAndBusyCalendarSearchString($pidList, $includePublic, $linkIds): string
    {
        $hash = md5($pidList . ' ' . $includePublic . ' ' . $linkIds);
        if ($this->fnbCalendarSearchStringCache[$hash]) {
            return $this->fnbCalendarSearchStringCache[$hash];
        }

        $ids = [];
        $idArray = $this->getIdsFromTable($linkIds, $pidList, $includePublic);

        $excludeIds = array_keys($this->getCalendarOwner());

        if ($this->rightsObj->isLoggedIn()) {
            $groups = $this->rightsObj->getUserGroups();
            $userId = $this->rightsObj->getUserId();
            $where = '(tablenames = "fe_users" AND uid_foreign = ' . $userId . ') OR (tablenames = "fe_groups" AND uid_foreign in (' . implode(
                ',',
                $groups
                ) . '))';
            $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*', 'tx_cal_calendar_fnb_user_group_mm', $where);
            if ($result) {
                while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
                    $ids[] = $row['uid_local'];
                    $this->calendarOwner[$row['uid_local']][$row['tablenames']][] = $row['uid_foreign'];
                }
                $GLOBALS['TYPO3_DB']->sql_free_result($result);
            }
        }

        $where = 'tx_cal_calendar.activate_fnb = 1';
        if (!empty($excludeIds)) {
            $where .= ' AND uid not in (' . implode(',', $excludeIds) . ')';
        }

        $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*', 'tx_cal_calendar', $where);
        if ($result) {
            while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
                $ids[] = $row['uid'];
            }
            $GLOBALS['TYPO3_DB']->sql_free_result($result);
        }

        $calendarSearchString = ' AND tx_cal_calendar.activate_fnb = 1';
        // $idString = implode(',',$ids);
        if (!empty($idArray)) {
            // compare the allowed ids with the ids available and retrieve the intersects
            $calendarIds = array_intersect($idArray, $ids);
            if (empty($calendarIds)) {
                // No intersects -> show none
                $calendarSearchString .= ' AND tx_cal_calendar.uid IN (0)';
            } else {
                // create a string for the query
                $calendarIds = implode(',', $calendarIds);
                $calendarSearchString .= ' AND tx_cal_calendar.uid IN (' . $calendarIds . ')';
            }
        }

        $this->fnbCalendarSearchStringCache[$hash] = $calendarSearchString;

        return $calendarSearchString;
    }

    /**
     * Call this after you have called getCalendarSearchString or getFreeAndBusyCalendarSearchString
     */
    public function getCalendarOwner(): array
    {
        if (empty($this->calendarOwner)) {
            $this->calendarOwner = [];
            $table = 'tx_cal_calendar_fnb_user_group_mm';
            $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*', $table, '');
            if ($result) {
                while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
                    $ids[] = $row['uid_local'];
                    $this->calendarOwner[$row['uid_local']][$row['tablenames']][] = $row['uid_foreign'];
                }
                $GLOBALS['TYPO3_DB']->sql_free_result($result);
            }
        }
        return $this->calendarOwner;
    }

    /**
     * @param $list
     * @param $pidList
     * @param $includePublic
     * @param bool $includeData
     * @param bool $onlyPublic
     * @return array
     */
    public function getIdsFromTable($list, $pidList, $includePublic, $includeData = false, $onlyPublic = false): array
    {
        $this->calendarIds = [];
        $collectedIds = [];

        $limitationList = '';
        if ($list !== '') {
            $limitationList = $list;
        }

        $ids = [];

        $orderBy = Functions::getOrderBy('tx_cal_calendar');
        $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
            'tx_cal_calendar_fnb_user_group_mm.uid_local',
            'tx_cal_calendar_fnb_user_group_mm LEFT JOIN tx_cal_calendar ON tx_cal_calendar.uid=tx_cal_calendar_fnb_user_group_mm.uid_local',
            '1=1 ' . $this->cObj->enableFields('tx_cal_calendar'),
            '',
            $orderBy
        );
        if ($result) {
            while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
                $ids[] = $row['uid_local'];
            }
            $GLOBALS['TYPO3_DB']->sql_free_result($result);
        }

        $ids = array_unique($ids);
        if ($includePublic) {
            $where = '';
            if (!empty($ids)) {
                $where .= 'uid NOT IN (' . implode(',', $ids) . ') AND ';
            }
            $where .= 'tx_cal_calendar.activate_fnb = 1 ' . $this->cObj->enableFields('tx_cal_calendar');
            if ($pidList !== '') {
                $where .= ' AND pid IN (' . $pidList . ')';
            }

            if ($includeData) {
                $select = '*';
            } else {
                $select = 'uid';
            }
            $table = 'tx_cal_calendar';

            $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery($select, $table, $where, '', $orderBy);
            if ($result) {
                while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
                    if (!in_array($row['uid'], $collectedIds, true)) {
                        if ($includeData) {
                            $this->calendarIds[] = $row;
                        } else {
                            $this->calendarIds[] = $row['uid'];
                        }
                        $collectedIds[] = $row['uid'];
                    }
                }
                $GLOBALS['TYPO3_DB']->sql_free_result($result);
            }
        }

        if ($limitationList !== '' && !empty($this->calendarIds)) {
            $limitationArray = explode(',', $limitationList);
            $this->calendarIds = array_intersect($this->calendarIds, $limitationArray);
        }
        return $this->calendarIds;
    }

    /**
     * @param $row
     * @param $isException
     * @return EventModel
     */
    public function createEvent($row, $isException): EventModel
    {
        $event = new EventModel($row, $isException, $this->getServiceKey());
        $event->getRow()['isFreeAndBusyEvent'] = 1;
        return $event;
    }
}
