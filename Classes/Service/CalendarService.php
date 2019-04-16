<?php

namespace TYPO3\CMS\Cal\Service;

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
use RuntimeException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Cal\Domain\Repository\FnbUserGroupMMRepository;
use TYPO3\CMS\Cal\Domain\Repository\UserGroupMMRepository;
use TYPO3\CMS\Cal\Hooks\TceMainProcessdatamap;
use TYPO3\CMS\Cal\Model\CalendarModel;
use TYPO3\CMS\Cal\Utility\Functions;
use TYPO3\CMS\Cal\Utility\RecurrenceGenerator;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class CalendarService
 */
class CalendarService extends BaseService
{
    public $calendarSearchStringCache = [];
    public $calendarOwner;
    public $calendarIds;

    /**
     * @var UserGroupMMRepository
     */
    protected $userGroupMMRepository;

    /**
     * @var FnbUserGroupMMRepository
     */
    protected $fnbUserGroupMMRepository;

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->userGroupMMRepository = GeneralUtility::makeInstance(UserGroupMMRepository::class);
        $this->fnbUserGroupMMRepository = GeneralUtility::makeInstance(FnbUserGroupMMRepository::class);
    }

    /**
     * @param $row
     * @return CalendarModel
     */
    public function createCalendar($row): CalendarModel
    {
        return new CalendarModel($row, $this->getServiceKey());
    }

    /**
     * Looks for a calendar with a given uid on a certain pid-list
     *
     * @param int $uid
     *            to search for
     * @param string $pidList
     *            to search in
     * @return array array ($row)
     */
    public function find($uid, $pidList): array
    {
        $calendarArray = $this->getCalendarFromTable($pidList, ' AND uid=' . $uid);
        return $calendarArray;
    }

    /**
     * Looks for all calendars on a certain pid-list
     *
     * @param string $pidList
     *            to search in
     * @return array array of array (array of $rows)
     */
    public function findAll($pidList): array
    {
        return $this->getCalendarFromTable(
            $pidList,
            $this->getCalendarSearchString($pidList, true, $this->conf['calendar'])
        );
    }

    /**
     * @param string $pidList
     * @param string $additionalWhere
     * @return array
     */
    public function getCalendarFromTable($pidList, $additionalWhere = ''): array
    {
        $return = [];
        $orderBy = Functions::getOrderBy('tx_cal_calendar');
        if ($pidList !== '') {
            $additionalWhere .= ' AND pid IN (' . $pidList . ')';
        }
        $additionalWhere .= $this->getAdditionalWhereForLocalizationAndVersioning('tx_cal_calendar');

        $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
            '*',
            'tx_cal_calendar',
            '1=1' . $this->cObj->enableFields('tx_cal_calendar') . $additionalWhere,
            '',
            $orderBy
        );
        if ($result) {
            while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
                if ($GLOBALS['TSFE']->sys_language_content) {
                    $row = $GLOBALS['TSFE']->sys_page->getRecordOverlay(
                        'tx_cal_calendar',
                        $row,
                        $GLOBALS['TSFE']->sys_language_content,
                        $GLOBALS['TSFE']->sys_language_contentOL
                    );
                }
                if (!$row['uid']) {
                    continue;
                }

                $GLOBALS['TSFE']->sys_page->versionOL('tx_cal_calendar', $row);
                $GLOBALS['TSFE']->sys_page->fixVersioningPid('tx_cal_calendar', $row);

                if (!$row['uid']) {
                    continue;
                }
                $return[] = $this->createCalendar($row);
            }
            $GLOBALS['TYPO3_DB']->sql_free_result($result);
        }
        return $return;
    }

    /**
     * @param $uid
     * @return array
     * @throws \TYPO3\CMS\Core\Exception
     */
    public function updateCalendar($uid): array
    {
        $insertFields = [
            'tstamp' => time()
        ];
        // TODO: Check if all values are correct
        $this->searchForAdditionalFieldsToAddFromPostData($insertFields, 'calendar', false);
        $this->retrievePostData($insertFields);
        $uid = self::checkUidForLanguageOverlay($uid, 'tx_cal_calendar');

        if ($this->rightsObj->isAllowedToEditCalendarType()) {
            $this->checkOnNewOrDeletableFiles('tx_cal_calendar', 'ics_file', $insertFields, $uid);
        }

        $service = new ICalendarService();

        if (((int)$insertFields['type'] === 1 && $insertFields['ext_url']) || ((int)$insertFields['type'] === 2 && $insertFields['ics_file'])) {
            TceMainProcessdatamap::processICS(BackendUtility::getRecord(
                'tx_cal_calendar',
                $uid
            ), $insertFields, $service);

            /** @var RecurrenceGenerator $rgc */
            $rgc = GeneralUtility::makeInstance(RecurrenceGenerator::class, $GLOBALS['TSFE']->id);
            $rgc->generateIndexForCalendarUid($uid);
        } else {
            $service->deleteTemporaryEvents($uid);

            /** @var RecurrenceGenerator $rgc */
            $rgc = GeneralUtility::makeInstance(RecurrenceGenerator::class, $uid);
            $rgc->cleanIndexTableOfCalendarUid($uid);
        }

        if ($this->rightsObj->isAllowedToEditCalendarOwner()) {
            $this->userGroupMMRepository->deleteByCalendarUid($uid);
            if ($this->controller->piVars['owner_ids'] !== '') {
                $user = [];
                $group = [];
                self::splitUserAndGroupIds(
                    explode(',', strip_tags($this->controller->piVars['owner_ids'])),
                    $user,
                    $group
                );
                $this->userGroupMMRepository->insertIdsIntoTableWithMMRelation($user, $uid, 'fe_users');
                $this->userGroupMMRepository->insertIdsIntoTableWithMMRelation($group, $uid, 'fe_groups');
            }
        }
        if ($this->rightsObj->isAllowedToEditCalendarFreeAndBusyUser()) {
            $GLOBALS['TYPO3_DB']->exec_DELETEquery('tx_cal_calendar_fnb_user_group_mm', 'uid_local =' . $uid);
            if ($this->controller->piVars['freeAndBusyUser_ids'] !== '') {
                $user = [];
                $group = [];
                self::splitUserAndGroupIds(
                    explode(',', strip_tags($this->controller->piVars['freeAndBusyUser_ids'])),
                    $user,
                    $group
                );
                $this->fnbUserGroupMMRepository->insertIdsIntoTableWithMMRelation($user, $uid, 'fe_users');
                $this->fnbUserGroupMMRepository->insertIdsIntoTableWithMMRelation($group, $uid, 'fe_groups');
            }
        }
        $this->unsetPiVars();
        Functions::clearCache();
        return $this->find($uid, $this->conf['pidList']);
    }

    /**
     * @param $uid
     */
    public function removeCalendar($uid)
    {
        if ($this->rightsObj->isAllowedToDeleteCalendar()) {
            // 'delete' the calendar object
            $updateFields = [
                'tstamp' => time(),
                'deleted' => 1
            ];
            $table = 'tx_cal_calendar';
            $where = 'uid = ' . $uid;
            $GLOBALS['TYPO3_DB']->exec_UPDATEquery($table, $where, $updateFields);

            // 'delete' all the events related to the calendar
            $table = 'tx_cal_event';
            $where = 'calendar_id = ' . $uid;
            $GLOBALS['TYPO3_DB']->exec_UPDATEquery($table, $where, $updateFields);
        }
        $this->unsetPiVars();
        Functions::clearCache();
        /** @var RecurrenceGenerator $rgc */
        $rgc = GeneralUtility::makeInstance(RecurrenceGenerator::class, $uid);
        $rgc->cleanIndexTableOfCalendarUid($uid);
    }

    /**
     * @param $insertFields
     */
    public function retrievePostData(&$insertFields)
    {
        $hidden = 0;
        if ($this->controller->piVars['hidden'] === 'true' && ($this->rightsObj->isAllowedToEditCalendarHidden() || $this->rightsObj->isAllowedToCreateCalendarHidden())) {
            $hidden = 1;
        }
        $insertFields['hidden'] = $hidden;

        if ($this->rightsObj->isAllowedToEditCalendarTitle() || $this->rightsObj->isAllowedToCreateCalendarTitle()) {
            $insertFields['title'] = strip_tags($this->controller->piVars['title']);
        }

        if ($this->rightsObj->isAllowedToEditCalendarType() || $this->rightsObj->isAllowedToCreateCalendarType()) {
            $insertFields['type'] = strip_tags($this->controller->piVars['calendarType']);
        }

        if ($this->rightsObj->isAllowedToEditCalendarType() || $this->rightsObj->isAllowedToCreateCalendarType()) {
            $insertFields['ext_url'] = strip_tags($this->controller->piVars['exturl']);
        }

        if ($this->rightsObj->isAllowedToEditCalendarType() || $this->rightsObj->isAllowedToCreateCalendarType()) {
            $insertFields['refresh'] = strip_tags($this->controller->piVars['refresh']);
        }

        if ($this->rightsObj->isAllowedToEditCalendarActivateFreeAndBusy() || $this->rightsObj->isAllowedToCreateCalendarActivateFreeAndBusy()) {
            $insertFields['activate_fnb'] = strip_tags($this->controller->piVars['activateFreeAndBusy']);
        }

        if ($this->rightsObj->isAllowedTo('edit', 'calendar', 'headerstyle') || $this->rightsObj->isAllowedTo(
            'create',
            'calendar',
            'headerstyle'
            )) {
            $insertFields['headerstyle'] = strip_tags($this->controller->piVars['headerstyle']);
        }

        if ($this->rightsObj->isAllowedTo('edit', 'calendar', 'bodystyle') || $this->rightsObj->isAllowedTo(
            'create',
            'calendar',
            'bodystyle'
            )) {
            $insertFields['bodystyle'] = strip_tags($this->controller->piVars['bodystyle']);
        }
    }

    /**
     * @param $pid
     * @return array
     */
    public function saveCalendar($pid): array
    {
        $crdate = time();
        $insertFields = [
            'pid' => $this->conf['rights.']['create.']['calendar.']['saveCalendarToPid'] ?: $pid,
            'tstamp' => $crdate,
            'crdate' => $crdate
        ];
        // TODO: Check if all values are correct
        $this->searchForAdditionalFieldsToAddFromPostData($insertFields, 'calendar');
        $this->retrievePostData($insertFields);

        // Creating DB records
        $insertFields['cruser_id'] = $this->rightsObj->getUserId();
        $insertFields['owner_ids'] = strip_tags($this->controller->piVars['owner_ids']);
        $insertFields['freeAndBusyUser_ids'] = strip_tags($this->controller->piVars['freeAndBusyUser_ids']);

        $uid = $this->_saveCalendar($insertFields);

        if ($this->rightsObj->isAllowedToCreateCalendarType()) {
            $this->checkOnNewOrDeletableFiles('tx_cal_calendar', 'ics_file', $insertFields, $uid);
        }

        $this->unsetPiVars();
        Functions::clearCache();
        return $this->find($uid, $this->conf['pidList']);
    }

    /**
     * @param $insertFields
     * @return int
     * @throws \TYPO3\CMS\Core\Exception
     */
    public function _saveCalendar(&$insertFields): int
    {
        $tempValues = [];
        $tempValues['owner_ids'] = $insertFields['owner_ids'];
        unset($insertFields['owner_ids']);
        $tempValues['freeAndBusyUser_ids'] = $insertFields['freeAndBusyUser_ids'];
        unset($insertFields['freeAndBusyUser_ids']);

        $table = 'tx_cal_calendar';
        $result = $GLOBALS['TYPO3_DB']->exec_INSERTquery($table, $insertFields);
        if (false === $result) {
            throw new RuntimeException(
                'Could not write ' . $table . ' record to database: ' . $GLOBALS['TYPO3_DB']->sql_error(),
                1431458139
            );
        }
        $uid = $GLOBALS['TYPO3_DB']->sql_insert_id();

        if ((int)$insertFields['type'] === 1 || (int)$insertFields['type'] === 2) {
            $service = new ICalendarService();
            TcemainProcessdatamap::processICS(BackendUtility::getRecord(
                'tx_cal_calendar',
                $uid
            ), $insertFields, $service);

            /** @var RecurrenceGenerator $rgc */
            $rgc = GeneralUtility::makeInstance(RecurrenceGenerator::class, $GLOBALS['TSFE']->id);
            $rgc->generateIndexForCalendarUid($uid);
        }

        if ($this->rightsObj->isAllowedToCreateCalendarOwner()) {
            $this->userGroupMMRepository->deleteByCalendarUid($uid);
            if ($tempValues['owner_ids'] !== '') {
                $user = [];
                $group = [];
                self::splitUserAndGroupIds(explode(',', strip_tags($tempValues['owner_ids'])), $user, $group);
                $this->userGroupMMRepository->insertIdsIntoTableWithMMRelation($user, $uid, 'fe_users');
                $this->userGroupMMRepository->insertIdsIntoTableWithMMRelation($group, $uid, 'fe_groups');
            }
        }
        if ($this->rightsObj->isAllowedToCreateCalendarFreeAndBusyUser()) {
            $this->fnbUserGroupMMRepository->deleteByCalendarUid($uid);
            if ($tempValues['freeAndBusyUser_ids'] !== '') {
                $user = [];
                $group = [];
                self::splitUserAndGroupIds(
                    explode(',', strip_tags($tempValues['freeAndBusyUser_ids'])),
                    $user,
                    $group
                );
                $this->fnbUserGroupMMRepository->insertIdsIntoTableWithMMRelation($user, $uid, 'fe_users');
                $this->fnbUserGroupMMRepository->insertIdsIntoTableWithMMRelation($group, $uid, 'fe_groups');
            }
        }
        return $uid;
    }

    /**
     * @param $pidList
     * @param $includePublic
     * @param $linkIds
     * @return mixed|string
     */
    public function getCalendarSearchString($pidList, $includePublic, $linkIds)
    {
        $hash = md5($pidList . ' ' . $includePublic . ' ' . $linkIds);
        if ($this->calendarSearchStringCache[$hash]) {
            return $this->calendarSearchStringCache[$hash];
        }

        $calendarSearchString = '';

        $idArray = $this->getIdsFromTable($linkIds, $pidList, $includePublic);

        $ids = array_keys($this->getCalendarOwner());

        if (is_array($ids) && !empty($ids)) {
            $idString = implode(',', array_unique($ids));
            $calendarSearchString = ' AND tx_cal_calendar.uid NOT IN (' . $idString . ')';
        }
        if ($pidList > 0) {
            $calendarSearchString .= ' AND tx_cal_calendar.pid IN (' . $pidList . ')';
        }

        $calendarSearchString .= ' AND tx_cal_calendar.activate_fnb = 0';

        // Check the results
        if (empty($idArray)) {
            // No calendar ids specified for this user -> show default
        } elseif ($linkIds !== '') {
            // compair the allowed ids with the ids available and retrieve the intersects
            $calendarIds = array_intersect($idArray, explode(',', $linkIds));
            if (!empty($calendarIds)) {
                // create a string for the query
                $calendarIds = implode(',', $calendarIds);
                $calendarSearchString = ' AND tx_cal_calendar.uid IN (' . $calendarIds . ')';
            }
        } else {
            $calendarIds = implode(',', $idArray);
            $calendarSearchString = ' AND tx_cal_calendar.uid IN (' . $calendarIds . ')';
        }

        $calendarSearchString .= $this->cObj->enableFields('tx_cal_calendar') . ' AND tx_cal_calendar.pid IN (' . $pidList . ') ';

        $this->calendarSearchStringCache[$hash] = $calendarSearchString;

        return $calendarSearchString;
    }

    /**
     * @param string $list
     * @param $pidList
     * @param $includePublic
     * @param bool $includeData
     * @param bool $onlyPublic
     * @return array
     */
    public function getIdsFromTable($list, $pidList, $includePublic, $includeData = false, $onlyPublic = false): array
    {
        $userId = 0;
        $groupIds = '';
        $this->calendarIds = [];
        $collectedIds = [];

        $limitationList = '';
        if ($list !== '') {
            $limitationList = $list;
        }

        // Lets see if the user is logged in
        if (!$onlyPublic && $this->rightsObj->isLoggedIn()) {
            $userId = $this->rightsObj->getUserId();
            $groupIds = implode(',', $this->rightsObj->getUserGroups());
        }

        $ids = [];
        if ($userId === '') { // && !$includePublic
            return $ids;
        }

        $userGroupMMs = $this->userGroupMMRepository->findAll();
        foreach ($userGroupMMs as $userGroupMM) {
            $ids[] = $userGroupMM['uid_local'];
        }

        $ids = array_unique($ids);
        if ($includePublic) {
            if (!empty($ids)) {
                $where = 'uid NOT IN (' . implode(',', $ids) . ') ' . $this->cObj->enableFields('tx_cal_calendar');
            } else {
                $where = '0=0 ' . $this->cObj->enableFields('tx_cal_calendar');
            }
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

        if (!$onlyPublic) {
            if (!empty($ids)) {
                $where = 'uid NOT IN (' . implode(',', $ids) . ')';
            } else {
                $where = '';
            }
            $table = 'tx_cal_calendar';
            if ($includeData) {
                $select = '*';
            } else {
                $select = 'uid';
            }
            if ($userId) {
                $where = '((tx_cal_calendar_user_group_mm.uid_foreign IN (' . $userId . ') AND tx_cal_calendar_user_group_mm.tablenames="fe_users" AND tx_cal_calendar_user_group_mm.uid_local=tx_cal_calendar.uid)';
                $where .= 'OR (tx_cal_calendar_user_group_mm.uid_foreign IN (' . $groupIds . ') AND tx_cal_calendar_user_group_mm.tablenames="fe_groups"))';
                $table .= ' LEFT JOIN tx_cal_calendar_user_group_mm ON tx_cal_calendar_user_group_mm.uid_local=tx_cal_calendar.uid';
            }

            if ($pidList !== '') {
                $where .= $where !== '' ? ' AND pid IN (' . $pidList . ')' : ' pid IN (' . $pidList . ')';
            }
            if ($where === '') {
                $where .= ' 0=0 ' . $this->cObj->enableFields('tx_cal_calendar');
            } else {
                $where .= $this->cObj->enableFields('tx_cal_calendar');
            }
            if ($limitationList !== '') {
                $where .= ' AND uid IN (' . $limitationList . ')';
            }
            $groupBy = 'tx_cal_calendar.uid';
            $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery($select, $table, $where, $groupBy, $orderBy);
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
     * Call this after you have called getCalendarSearchString or getFreeAndBusyCalendarSearchString
     */
    public function getCalendarOwner(): array
    {
        if (empty($this->calendarOwner)) {
            $this->calendarOwner = [];
            if ($this->conf['option'] === 'freeandbusy') {
                $groupMMs = $this->fnbUserGroupMMRepository->findAll();
            } else {
                $groupMMs = $this->userGroupMMRepository->findAll();
            }
            foreach ($groupMMs as $groupMM) {
                $this->calendarOwner[$groupMM['uid_local']][$groupMM['tablenames']][] = $groupMM['uid_foreign'];
            }
        }
        return $this->calendarOwner;
    }

    public function unsetPiVars()
    {
        unset($this->controller->piVars['hidden'], $this->controller->piVars['uid'], $this->controller->piVars['calendar'], $this->controller->piVars['type'], $this->controller->piVars['calendarType'], $this->controller->piVars['owner'], $this->controller->piVars['owner_single'], $this->controller->piVars['owner_group'], $this->controller->piVars['freeAndBusyUser_single'], $this->controller->piVars['freeAndBusyUser_group'], $this->controller->piVars['freeAndBusyUser'], $this->controller->piVars['refresh'], $this->controller->piVars['title'], $this->controller->piVars['activateFreeAndBusy']);
    }

    /**
     * @param $uid
     * @param $overlay
     * @deprecated since ext:cal v2, will be removed in ext:cal v3
     */
    public function createTranslation($uid, $overlay)
    {
        trigger_error('Deprecated since ext:cal v2, will be removed in ext:cal v3.', E_USER_DEPRECATED);

        $table = 'tx_cal_calendar';
        $select = $table . '.*';
        $where = $table . '.uid = ' . $uid;
        $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery($select, $table, $where);
        if ($result) {
            $row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result);
            unset($row['uid']);
            $crdate = time();
            $row['tstamp'] = $crdate;
            $row['crdate'] = $crdate;
            $row['l18n_parent'] = $uid;
            $row['sys_language_uid'] = $overlay;
            $this->_saveCalendar($row);
            $GLOBALS['TYPO3_DB']->sql_free_result($result);
        }
    }

    /**
     * @param $calendarSearchString
     * @param $calendarUids
     * @param $categoryArrayByCalendarUid
     */
    public function getCalendarsWithoutCategory($calendarSearchString, $calendarUids, &$categoryArrayByCalendarUid)
    {
        $calendarsWithoutCategory = array_diff(GeneralUtility::intExplode(
            ',',
            $this->conf['view.']['calendar']
        ), array_unique($calendarUids));
        if (!empty($calendarsWithoutCategory)) {
            $select = 'tx_cal_calendar.*';
            $table = 'tx_cal_calendar';
            $groupby = 'tx_cal_calendar.uid';
            $orderby = 'tx_cal_calendar.title ASC';
            $where = 'tx_cal_calendar.uid IN (' . implode(',', $calendarsWithoutCategory) . ')' . $calendarSearchString;
            $where .= $this->getAdditionalWhereForLocalizationAndVersioning('tx_cal_calendar');

            $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery($select, $table, $where, $groupby, $orderby);
            if ($result) {
                while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
                    if (!$row['uid']) {
                        continue;
                    }

                    // TODO: Why do we need a translation of the title here? (Mario)
                    if ($GLOBALS['TSFE']->sys_language_content) {
                        $row = $GLOBALS['TSFE']->sys_page->getRecordOverlay(
                            'tx_cal_calendar',
                            $row,
                            $GLOBALS['TSFE']->sys_language_content,
                            $GLOBALS['TSFE']->sys_language_contentOL
                        );
                    }

                    if ($GLOBALS['TSFE']->sys_page->versioningPreview == true) {
                        // get workspaces Overlay
                        $GLOBALS['TSFE']->sys_page->versionOL('tx_cal_calendar', $row);
                    }
                    if (!$row['uid']) {
                        continue;
                    }
                    $categoryArrayByCalendarUid[$row['uid'] . '###' . $row['title'] . '###tx_cal_calendar'] = [];
                }
                $GLOBALS['TYPO3_DB']->sql_free_result($result);
            }
        }
    }
}
