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
use TYPO3\CMS\Cal\Model\CalDate;
use TYPO3\CMS\Cal\Model\CategoryModel;
use TYPO3\CMS\Cal\Model\EventModel;
use TYPO3\CMS\Cal\Model\Model;
use TYPO3\CMS\Cal\Model\TodoModel;
use TYPO3\CMS\Cal\Model\TodoRecModel;
use TYPO3\CMS\Cal\Utility\Functions;
use TYPO3\CMS\Cal\Utility\RecurrenceGenerator;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class TodoService
 */
class TodoService extends EventService
{
    /**
     * Finds all todos within a given range.
     *
     * @param $start_date
     * @param $end_date
     * @param $pidList
     * @param string $eventType
     * @param string $additionalWhere
     * @return array array of events represented by the model.
     */
    public function findAllWithin(&$start_date, &$end_date, $pidList, $eventType = '4', $additionalWhere = ''): array
    {
        return parent::findAllWithin($start_date, $end_date, $pidList, '4', $additionalWhere);
    }

    /**
     * Finds all events.
     *
     * @param $pidList
     * @param string $eventType
     * @return array array of todos represented by the model.
     */
    public function findAll($pidList, $eventType = '4'): array
    {
        return parent::findAll($pidList, '4');
    }

    /**
     * @param $row
     * @param $isException
     * @return EventModel|TodoModel
     */
    public function createEvent($row, $isException): EventModel
    {
        return new TodoModel($row, $this->getServiceKey());
    }

    /**
     * Finds a single event.
     *
     * @param $uid
     * @param $pidList
     * @param bool $showHiddenEvents
     * @param bool $showDeletedEvents
     * @param bool $getAllInstances
     * @param bool $disableCalendarSearchString
     * @param bool $disableCategorySearchString
     * @param string $eventType
     * @return EventModel
     */
    public function find(
        $uid,
        $pidList,
        $showHiddenEvents = false,
        $showDeletedEvents = false,
        $getAllInstances = false,
        $disableCalendarSearchString = false,
        $disableCategorySearchString = false,
        $eventType = '4'
    ): EventModel {
        return parent::find(
            $uid,
            $pidList,
            $showHiddenEvents,
            $showDeletedEvents,
            $getAllInstances,
            $disableCalendarSearchString,
            $disableCategorySearchString,
            '4'
        );
    }

    /**
     * @param bool $disableCalendarSearchString
     * @param bool $disableCategorySearchString
     * @return array
     */
    public function findCurrentTodos($disableCalendarSearchString = false, $disableCategorySearchString = false): array
    {
        $confArr = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['cal']);
        $this->starttime = new CalDate($confArr['recurrenceStart']);
        $this->endtime = new CalDate($confArr['recurrenceEnd']);
        $categories = &$this->modelObj->findAllCategories('cal_category_model', '', $this->conf['pidList']);
        $categories = [];

        $categoryService = GeneralUtility::makeInstance(SysCategoryService::class);
        $categoryService->getCategoryArray($this->conf['pidList'], $categories);

        $calendarSearchString = '';
        if (!$disableCalendarSearchString) {
            $calendarService = &$this->modelObj->getServiceObjByKey(
                'cal_calendar_model',
                'calendar',
                'tx_cal_calendar'
            );
            $calendarSearchString = $calendarService->getCalendarSearchString(
                $this->conf['pidList'],
                true,
                $this->conf['calendar'] ?: ''
            );
        }

        // putting everything together
        $additionalWhere = $calendarSearchString . ' AND tx_cal_event.completed < 100 AND tx_cal_event.pid IN (' . $this->conf['pidList'] . ') ' . $this->cObj->enableFields('tx_cal_event');
        $getAllInstances = true;
        $eventType = Model::EVENT_TYPE_TODO;

        return $this->getEventsFromTable(
            $categories[0][0],
            $getAllInstances,
            $additionalWhere,
            $this->getServiceKey(),
            !$disableCategorySearchString,
            false,
            $eventType
        );
    }

    /**
     * @param $pid
     * @return EventModel
     * @throws \TYPO3\CMS\Core\Exception
     */
    public function saveEvent($pid): EventModel
    {
        $object = $this->modelObj->createEvent('tx_cal_todo');
        $object->updateWithPIVars($this->controller->piVars);

        $crdate = time();
        $insertFields = [];
        $insertFields['pid'] = $pid;
        $insertFields['tstamp'] = $crdate;
        $insertFields['crdate'] = $crdate;

        if ($GLOBALS['TSFE']->sys_language_content > 0 && $this->conf['showRecordsWithoutDefaultTranslation'] == 1 && $this->rightsObj->isAllowedTo(
            'create',
            'translation'
            )) {
            $insertFields['sys_language_uid'] = $GLOBALS['TSFE']->sys_language_content;
        }

        // TODO: Check if all values are correct
        $this->searchForAdditionalFieldsToAddFromPostData($insertFields, 'event');
        $this->filterDataToBeSaved($insertFields, $object);

        if (!$insertFields['calendar_id'] && $this->conf['rights.']['create.']['todo.']['fields.']['calendar_id.']['default']) {
            $insertFields['calendar_id'] = $this->conf['rights.']['create.']['todo.']['fields.']['calendar_id.']['default'];
        }

        $insertFields['cruser_id'] = $this->rightsObj->getUserId();
        $insertFields['fe_cruser_id'] = $this->rightsObj->getUserId();

        if (is_array($this->controller->piVars['notify'])) {
            $insertFields['notify_ids'] = implode(',', $this->controller->piVars['notify']);
        } else {
            $insertFields['notify_ids'] = $this->controller->piVars['notify_ids'];
        }
        if (is_array($this->controller->piVars['exception_ids'])) {
            $insertFields['exception_ids'] = implode(',', $this->controller->piVars['exception_ids']);
        } else {
            $insertFields['exception_ids'] = $this->controller->piVars['exception_ids'];
        }

        $uid = $this->_saveEvent($insertFields, $object);

        $this->conf['category'] = $this->conf['view.']['allowedCategories'];
        $this->conf['calendar'] = $this->conf['view.']['allowedCalendar'];

        $this->unsetPiVars();
        $insertFields['uid'] = $uid;
        $insertFields['category'] = $this->controller->piVars['category_ids'];
        self::_notify($insertFields);
        if ($object->getSendOutInvitation()) {
            $object->setUid($uid);
            $this->_invite($object);
        }
        self::_scheduleReminder($uid);

        /** @var RecurrenceGenerator $rgc */
        $rgc = GeneralUtility::makeInstance(RecurrenceGenerator::class, $GLOBALS['TSFE']->id);
        $rgc->generateIndexForUid($uid, 'tx_cal_event');

        // Hook: saveEvent
        $hookObjectsArr = Functions::getHookObjectsArray(
            'tx_cal_todo_service',
            'todoServiceClass'
        );
        Functions::executeHookObjectsFunction($hookObjectsArr, 'saveTodo', $this, $object);

        Functions::clearCache();
        return $this->find($uid, $pid);
    }

    /**
     * @param $eventData
     * @param $object
     * @return int
     */
    public function _saveEvent(&$eventData, $object): int
    {
        $tempValues = [];
        $tempValues['notify_ids'] = $eventData['notify_ids'];
        unset($eventData['notify_ids']);
        $tempValues['exception_ids'] = $eventData['exception_ids'];
        unset($eventData['exception_ids']);
        $tempValues['attendee_ids'] = $eventData['attendee_ids'];
        unset($eventData['attendee_ids']);

        // Creating DB records
        $table = 'tx_cal_event';
        $result = $GLOBALS['TYPO3_DB']->exec_INSERTquery($table, $eventData);
        if (false === $result) {
            throw new RuntimeException(
                'Could not write ' . $table . ' record to database: ' . $GLOBALS['TYPO3_DB']->sql_error(),
                1431458159
            );
        }
        $uid = $GLOBALS['TYPO3_DB']->sql_insert_id();

        if ($this->rightsObj->isAllowedTo('create', 'todo', 'image')) {
            $this->checkOnNewOrDeletableFiles('tx_cal_event', 'image', $eventData);
        }

        if ($this->rightsObj->isAllowedTo('create', 'todo', 'attachment')) {
            $this->checkOnNewOrDeletableFiles('tx_cal_event', 'attachment', $eventData);
        }

        // creating relation records
        if ($this->rightsObj->isAllowedTo('create', 'todo', 'notify')) {
            if ($tempValues['notify_ids'] !== '') {
                $user = [];
                $group = [];
                self::splitUserAndGroupIds(explode(',', strip_tags($tempValues['notify_ids'])), $user, $group);
                $this->subscriptionRepository->insert(
                    [
                            'uid_local' => $uid,
                            'uid_foreign' => $user,
                            'tablenames' => 'fe_users',
                            'sorting' => 1
                        ]
                    );
                $ignore = GeneralUtility::trimExplode(
                    ',',
                    $this->conf['rights.']['create.']['todo.']['addFeGroupToNotify.']['ignore'],
                    1
                );
                $groupArray = array_diff($group, $ignore);
                foreach (array_unique($groupArray) as $key => $groupUid) {
                    $this->subscriptionRepository->insert(
                        [
                            'uid_local' => $uid,
                            'uid_foreign' => $groupUid,
                            'tablenames' => 'fe_groups',
                            'sorting' => $key + 1
                        ]
                    );
                }
            }
        } elseif ($this->conf['rights.']['create.']['todo.']['fields.']['notify.']['defaultUser'] || $this->conf['rights.']['create.']['todo.']['fields.']['notify.']['defaultGroup']) {
            $idArray = GeneralUtility::trimExplode(
                ',',
                $this->conf['rights.']['create.']['event.']['fields.']['notify.']['defaultUser'],
                1
            );
            if ($this->conf['rights.']['create.']['event.']['addFeUserToNotify']) {
                $idArray[] = $this->rightsObj->getUserId();
            }
            foreach (array_unique($idArray) as $key => $userUid) {
                $this->subscriptionRepository->insert(
                    [
                        'uid_local' => $uid,
                        'uid_foreign' => $userUid,
                        'tablenames' => 'fe_groups',
                        'sorting' => $key + 1
                    ]
                );
            }
            $idArray = GeneralUtility::trimExplode(
                ',',
                $this->conf['rights.']['create.']['todo.']['fields.']['notify.']['defaultGroup'],
                1
            );
            if ($this->conf['rights.']['create.']['todo.']['addFeGroupToNotify']) {
                $idArray = array_merge($idArray, $this->rightsObj->getUserGroups());
            }
            foreach (array_unique($idArray) as $key => $groupUid) {
                $this->subscriptionRepository->insert(
                    [
                        'uid_local' => $uid,
                        'uid_foreign' => $groupUid,
                        'tablenames' => 'fe_groups',
                        'sorting' => $key + 1
                    ]
                );
            }
        } elseif ($this->conf['rights.']['create.']['todo.']['addFeUserToNotify'] && $this->rightsObj->isLoggedIn()) {
            $this->subscriptionRepository->insert(
                [
                        'uid_local' => $uid,
                        'uid_foreign' => $this->rightsObj->getUserId(),
                        'tablenames' => 'fe_groups',
                        'sorting' => 1
                    ]
                );
        }
        if ($this->conf['rights.']['create.']['todo.']['public']) {
            foreach (GeneralUtility::trimExplode(',', $this->conf['rights.']['create.']['todo.']['notifyUsersOnPublicCreate'], 1) as $key => $userUid) {
                $this->subscriptionRepository->insert(
                    [
                        'uid_local' => $uid,
                        'uid_foreign' => $userUid,
                        'tablenames' => 'fe_users',
                        'sorting' => $key + 1
                    ]
                );
            }
        }

        if ($this->rightsObj->isAllowedTo('create', 'todo', 'shared')) {
            $user = $object->getSharedUsers();
            $group = $object->getSharedGroups();
            if ($this->conf['rights.']['create.']['todo.']['addFeUserToShared']) {
                $user[] = $this->rightsObj->getUserId();
            }
            $this->eventSharedUserMMRepository->insertIdsIntoTableWithMMRelation(array_unique($user), $uid, 'fe_users');
            $ignore = GeneralUtility::trimExplode(
                ',',
                $this->conf['rights.']['create.']['todo.']['addFeGroupToShared.']['ignore'],
                1
            );
            $groupArray = array_diff($group, $ignore);
            $this->eventSharedUserMMRepository->insertIdsIntoTableWithMMRelation(array_unique($groupArray), $uid, 'fe_groups');
        } else {
            $idArray = explode(',', $this->conf['rights.']['create.']['todo.']['fields.']['shared.']['defaultUser']);
            if ($this->conf['rights.']['create.']['todo.']['addFeUserToShared']) {
                $idArray[] = $this->rightsObj->getUserId();
            }
            $this->eventSharedUserMMRepository->insertIdsIntoTableWithMMRelation(array_unique($idArray), $uid, 'fe_users');

            $groupArray = GeneralUtility::trimExplode(
                ',',
                $this->conf['rights.']['create.']['todo.']['fields.']['shared.']['defaultGroup'],
                1
            );
            if ($this->conf['rights.']['create.']['todo.']['addFeGroupToShared']) {
                $idArray = $this->rightsObj->getUserGroups();
                $ignore = GeneralUtility::trimExplode(
                    ',',
                    $this->conf['rights.']['create.']['todo.']['addFeGroupToShared.']['ignore'],
                    1
                );
                $groupArray = array_diff($idArray, $ignore);
            }
            $this->eventSharedUserMMRepository->insertIdsIntoTableWithMMRelation(array_unique($groupArray), $uid, 'fe_groups');
        }

        if ($this->rightsObj->isAllowedTo('create', 'todo', 'category')) {
            $categoryIds = [];
            foreach ((array)$object->getCategories() as $category) {
                if (is_object($category)) {
                    $categoryIds[] = $category->getUid();
                }
            }
            self::insertIdsIntoTableWithMMRelation('tx_cal_event_category_mm', $categoryIds, $uid, '');
        } else {
            self::insertIdsIntoTableWithMMRelation('tx_cal_event_category_mm', [
                $this->conf['rights.']['create.']['todo.']['fields.']['category.']['default']
            ], $uid, '');
        }

        return $uid;
    }

    /**
     * @param $uid
     * @return EventModel
     * @throws \TYPO3\CMS\Core\Exception
     */
    public function updateEvent($uid): EventModel
    {
        $insertFields = [
            'tstamp' => time()
        ];

        $event = $this->find($uid, $this->conf['pidList'], true, true, false, false, false, '0,1,2,3,4');
        $event_old = $this->find($uid, $this->conf['pidList'], true, true, false, false, false, '0,1,2,3,4');
        // $event = new tx_cal_phpicalendar_model(null, false, '');
        $this->conf['category'] = $this->conf['view.']['allowedCategories'];
        $this->conf['calendar'] = $this->conf['view.']['allowedCalendar'];

        $event->updateWithPIVars($this->controller->piVars);
        $this->searchForAdditionalFieldsToAddFromPostData($insertFields, 'event', false);

        $this->filterDataToBeUpdated($insertFields, $event);

        $uid = self::checkUidForLanguageOverlay($uid, 'tx_cal_event');

        if (isset($this->controller->piVars['notify_ids'])) {
            $insertFields['notify_ids'] = strip_tags($this->controller->piVars['notify_ids']);
        } elseif (is_array($this->controller->piVars['notify'])) {
            $insertFields['notify_ids'] = strip_tags(implode(',', $this->controller->piVars['notify']));
        }
        if (isset($this->controller->piVars['exception_ids'])) {
            if (is_array($this->controller->piVars['exception_ids'])) {
                $insertFields['exception_ids'] = strip_tags(implode(',', $this->controller->piVars['exception_ids']));
            } else {
                $insertFields['exception_ids'] = strip_tags($this->controller->piVars['exception_ids']);
            }
        }

        $this->_updateEvent($uid, $insertFields, $event);

        self::_notifyOfChanges($event_old, $insertFields);
        if ($event->getSendOutInvitation()) {
            $this->_invite($event);
        }
        $this->unsetPiVars();

        /** @var RecurrenceGenerator $rgc */
        $rgc = GeneralUtility::makeInstance(RecurrenceGenerator::class, $GLOBALS['TSFE']->id);
        $rgc->generateIndexForUid($uid, 'tx_cal_event');

        // Hook: updateEvent
        $hookObjectsArr = Functions::getHookObjectsArray(
            'tx_cal_todo_service',
            'todoServiceClass'
        );
        Functions::executeHookObjectsFunction($hookObjectsArr, 'updateTodo', $this, $event);

        Functions::clearCache();
        return $event;
    }

    /**
     * @param $uid
     * @param $eventData
     * @param $object
     */
    public function _updateEvent($uid, $eventData, $object)
    {
        $tempValues = [];
        $tempValues['notify_ids'] = $eventData['notify_ids'];
        unset($eventData['notify_ids']);
        $tempValues['exception_ids'] = $eventData['exception_ids'];
        unset($eventData['exception_ids']);
        $tempValues['attendee_ids'] = $eventData['attendee_ids'];
        unset($eventData['attendee_ids']);

        // Creating DB records
        $table = 'tx_cal_event';
        $where = 'uid = ' . $uid;
        $result = $GLOBALS['TYPO3_DB']->exec_UPDATEquery($table, $where, $eventData);
        if (false === $result) {
            throw new RuntimeException(
                'Could not write todo record to database: ' . $GLOBALS['TYPO3_DB']->sql_error(),
                1453825432
            );
        }

        $eventData['pid'] = $object->getRow()['pid'];

        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'image')) {
            $this->checkOnNewOrDeletableFiles('tx_cal_event', 'image', $eventData);
        }

        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'attachment')) {
            $this->checkOnNewOrDeletableFiles('tx_cal_event', 'attachment', $eventData);
        }

        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'category')) {
            $categoryIds = [];
            /** @var CategoryModel $category */
            foreach ($object->getCategories() as $category) {
                if (is_object($category)) {
                    $categoryIds[] = $category->getUid();
                }
            }
            $table = 'tx_cal_event_category_mm';
            $where = 'uid_local = ' . $uid;
            $GLOBALS['TYPO3_DB']->exec_DELETEquery($table, $where);
            self::insertIdsIntoTableWithMMRelation($table, $categoryIds, $uid, '');
        }

        if ($tempValues['notify_ids'] !== null && $this->rightsObj->isAllowedTo('edit', 'todo', 'notify')) {
            $this->subscriptionRepository->deleteByEventUid($uid);
            if ($tempValues['notify_ids'] !== '') {
                $users = [];
                $groups = [];
                self::splitUserAndGroupIds(explode(',', strip_tags($tempValues['notify_ids'])), $users, $groups);
                foreach ($users as $key => $userUid) {
                    $this->subscriptionRepository->insert(
                        [
                            'uid_local' => $uid,
                            'uid_foreign' => $userUid,
                            'tablenames' => 'fe_users',
                            'sorting' => $key + 1
                        ]
                    );
                }
                foreach ($groups as $key => $groupUid) {
                    $this->subscriptionRepository->insert(
                        [
                            'uid_local' => $uid,
                            'uid_foreign' => $groupUid,
                            'tablenames' => 'fe_groups',
                            'sorting' => $key + 1
                        ]
                    );
                }
            }
        } else {
            $userIdArray = GeneralUtility::trimExplode(
                ',',
                $this->conf['rights.']['edit.']['todo.']['fields.']['notify.']['defaultUser'],
                1
            );
            if ($this->conf['rights.']['edit.']['event.']['addFeUserToNotify']) {
                $userIdArray[] = $this->rightsObj->getUserId();
            }

            $groupIdArray = GeneralUtility::trimExplode(
                ',',
                $this->conf['rights.']['edit.']['todo.']['fields.']['notify.']['defaultGroup'],
                1
            );
            if ($this->conf['rights.']['edit.']['todo.']['addFeGroupToNotify']) {
                $groupIdArray = $this->rightsObj->getUserGroups();
                $ignore = GeneralUtility::trimExplode(
                    ',',
                    $this->conf['rights.']['edit.']['todo.']['addFeGroupToNotify.']['ignore'],
                    1
                );
                $groupIdArray = array_diff($groupIdArray, $ignore);
            }
            if (!empty($userIdArray) || !empty($groupIdArray)) {
                $this->subscriptionRepository->deleteByEventUid($uid);
                foreach (array_unique($userIdArray) as $key => $userUid) {
                    $this->subscriptionRepository->insert(
                        [
                            'uid_local' => $uid,
                            'uid_foreign' => $userUid,
                            'tablenames' => 'fe_users',
                            'sorting' => $key + 1
                        ]
                    );
                }
                foreach (array_unique($groupIdArray) as $key => $groupUid) {
                    $this->subscriptionRepository->insert(
                        [
                            'uid_local' => $uid,
                            'uid_foreign' => $groupUid,
                            'tablenames' => 'fe_groups',
                            'sorting' => $key + 1
                        ]
                    );
                }
            }
        }

        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'shared')) {
            $this->eventSharedUserMMRepository->deleteByEventUid($uid);
            $this->eventSharedUserMMRepository->insertIdsIntoTableWithMMRelation(array_unique($object->getSharedUsers()), $uid, 'fe_users');
            $this->eventSharedUserMMRepository->insertIdsIntoTableWithMMRelation(array_unique($object->getSharedGroups()), $uid, 'fe_groups');
        } else {
            $userIdArray = GeneralUtility::trimExplode(
                ',',
                $this->conf['rights.']['edit.']['todo.']['fields.']['shared.']['defaultUser'],
                1
            );
            if ($this->conf['rights.']['edit.']['todo.']['addFeUserToShared']) {
                $userIdArray[] = $this->rightsObj->getUserId();
            }

            $groupIdArray = GeneralUtility::trimExplode(
                ',',
                $this->conf['rights.']['edit.']['todo.']['fields.']['shared.']['defaultGroup'],
                1
            );
            if ($this->conf['rights.']['edit.']['event.']['addFeGroupToShared']) {
                $groupIdArray = $this->rightsObj->getUserGroups();
                $ignore = GeneralUtility::trimExplode(
                    ',',
                    $this->conf['rights.']['edit.']['todo.']['addFeGroupToShared.']['ignore'],
                    1
                );
                $groupIdArray = array_diff($groupIdArray, $ignore);
            }
            if (!empty($userIdArray) || !empty($groupIdArray)) {
                $this->eventSharedUserMMRepository->deleteByEventUid($uid);
                $this->eventSharedUserMMRepository->insertIdsIntoTableWithMMRelation(array_unique($userIdArray), $uid, 'fe_users');
                $this->eventSharedUserMMRepository->insertIdsIntoTableWithMMRelation(array_unique($groupIdArray), $uid, 'fe_groups');
            }
        }
    }

    /**
     * @param $uid
     */
    public function removeEvent($uid)
    {
        $event = $this->find($uid, $this->conf['pidList'], true, true);
        if (is_object($event) && $event->isUserAllowedToDelete()) {
            $config = $this->conf['calendar'];
            $this->conf['calendar'] = intval($this->controller->piVars['calendar_id']);
            $event = $this->find($uid, $this->conf['pidList'], true, true);
            $this->conf['calendar'] = $config;

            $updateFields = [
                'tstamp' => time(),
                'deleted' => 1
            ];
            $table = 'tx_cal_event';
            $where = 'uid = ' . $uid;
            $result = $GLOBALS['TYPO3_DB']->exec_UPDATEquery($table, $where, $updateFields);
            if (false === $result) {
                throw new RuntimeException(
                    'Could not write todo record to database: ' . $GLOBALS['TYPO3_DB']->sql_error(),
                    1453825617
                );
            }

            $fields = $event->getValuesAsArray();
            $fields['deleted'] = 1;
            $fields['tstamp'] = $updateFields['tstamp'];
            self::_notify($fields);
            self::stopReminder($uid);

            /** @var RecurrenceGenerator $rgc */
            $rgc = GeneralUtility::makeInstance(RecurrenceGenerator::class);
            $rgc->cleanIndexTableOfUid($uid, $table);

            // Hook: removeEvent
            $hookObjectsArr = Functions::getHookObjectsArray(
                'tx_cal_todo_service',
                'todoServiceClass'
            );
            Functions::executeHookObjectsFunction($hookObjectsArr, 'removeTodo', $this, $event);
            Functions::clearCache();
            $this->unsetPiVars();
        }
    }

    /**
     * @param $insertFields
     * @param EventModel $object
     */
    public function filterDataToBeSaved(&$insertFields, &$object)
    {
        $hidden = 0;
        if (
            !$this->rightsObj->isAllowedTo(
                'edit',
                'todo',
                'hidden'
            )
            && !$this->rightsObj->isAllowedTo('create', 'todo', 'hidden')
            && isset($this->conf['rights.']['create.']['todo.']['fields.']['hidden.']['default'])
        ) {
            $hidden = $this->conf['rights.']['create.']['todo.']['fields.']['hidden.']['default'];
        } elseif ($object->isHidden() && $this->rightsObj->isAllowedTo('create', 'todo', 'hidden')) {
            $hidden = 1;
        }
        $insertFields['hidden'] = $hidden;
        $insertFields['type'] = $object->getEventType();

        $insertFields['allday'] = $object->isAllDay() ? '1' : '0';
        if (!$this->rightsObj->isAllowedTo('create', 'todo', 'allday')) {
            $insertFields['allday'] = $this->conf['rights.']['create.']['todo.']['fields.']['allday.']['default'];
        }
        if ($this->rightsObj->isAllowedTo('create', 'todo', 'calendar')) {
            if ($object->getCalendarUid() !== '') {
                $insertFields['calendar_id'] = $object->getCalendarUid();
            } elseif ($this->conf['rights.']['create.']['todo.']['fields.']['calendar.']['default']) {
                $insertFields['calendar_id'] = $this->conf['rights.']['create.']['todo.']['fields.']['calendar_id.']['default'];
            } else {
                $insertFields['calendar_id'] = ''; // TODO: Set the calendar_id to some value
            }
        }

        if ($this->rightsObj->isAllowedTo('create', 'todo', 'starttime') || $this->rightsObj->isAllowedTo(
            'create',
            'todo',
            'startdate'
            )) {
            if (is_object($object->getStart())) {
                $start = $object->getStart();
                $insertFields['start_date'] = $start->format('Ymd');
                $insertFields['start_time'] = intval($start->format('H')) * 3600 + intval($start->format('M')) * 60;
            } else {
                return;
            }
            if (is_object($object->getEnd())) {
                $end = $object->getEnd();
                $insertFields['end_date'] = $end->format('Ymd');
                $insertFields['end_time'] = intval($end->format('H')) * 3600 + intval($end->format('M')) * 60;
            } else {
                return;
            }
        }
        if ($this->rightsObj->isAllowedTo('create', 'todo', 'title')) {
            $insertFields['title'] = $object->getTitle();
        }

        if ($this->rightsObj->isAllowedTo('create', 'todo', 'organizer')) {
            $insertFields['organizer'] = $object->getOrganizer();
        }
        if ($this->rightsObj->isAllowedTo('create', 'todo', 'cal_organizer')) {
            $insertFields['organizer_id'] = $object->getOrganizerId();
        }
        if ($this->rightsObj->isAllowedTo('create', 'todo', 'location')) {
            $insertFields['location'] = $object->getLocation();
        }
        if ($this->rightsObj->isAllowedTo('create', 'todo', 'cal_location')) {
            $insertFields['location_id'] = $object->getLocationId();
        }
        if ($object->getDescription() !== '' && $this->rightsObj->isAllowedTo('create', 'todo', 'description')) {
            $insertFields['description'] = $object->getDescription();
        }
        if ($this->rightsObj->isAllowedTo('create', 'todo', 'recurring')) {
            $insertFields['freq'] = $object->getFreq();
            $insertFields['byday'] = strtolower(implode(',', $object->getByDay()));
            $insertFields['bymonthday'] = implode(',', $object->getByMonthDay());
            $insertFields['bymonth'] = implode(',', $object->getByMonth());
            $until = $object->getUntil();
            if (is_object($until)) {
                $insertFields['until'] = $until->format('Ymd');
            }
            $insertFields['cnt'] = $object->getCount();
            $insertFields['intrval'] = $object->getInterval();
        }

        if ($this->rightsObj->isAllowedTo('create', 'todo', 'status')) {
            $insertFields['status'] = $object->getStatus();
        }

        if ($this->rightsObj->isAllowedTo('create', 'todo', 'priority')) {
            $insertFields['priority'] = $object->getPriority();
        }

        if ($this->rightsObj->isAllowedTo('create', 'todo', 'completed')) {
            $insertFields['completed'] = $object->getCompleted();
        }

        // Hook initialization:
        $hookObjectsArr = [];
        if (is_array($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['EXTCONF']['ext/cal/service/class.tx_cal_todo_service.php']['addAdditionalField'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['EXTCONF']['ext/cal/service/class.tx_cal_todo_service.php']['addAdditionalField'] as $classRef) {
                $hookObjectsArr[] = &GeneralUtility::getUserObj($classRef);
            }
        }

        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'addAdditionalField')) {
                $hookObj->addAdditionalField($insertFields, $this);
            }
        }
    }

    /**
     * @param $insertFields
     * @param EventModel $object
     */
    public function filterDataToBeUpdated(&$insertFields, &$object)
    {
        $hidden = 0;
        if (
            !$this->rightsObj->isAllowedTo(
                'edit',
                'todo',
                'hidden'
                    )
            && !$this->rightsObj->isAllowedTo('create', 'todo', 'hidden')
            && isset($this->conf['rights.']['edit.']['todo.']['fields.']['hidden.']['default'])
        ) {
            $hidden = $this->conf['rights.']['edit.']['todo.']['fields.']['hidden.']['default'];
        } elseif ($object->isHidden() && $this->rightsObj->isAllowedToEditEventHidden()) {
            $hidden = 1;
        }
        $insertFields['hidden'] = $hidden;

        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'type')) {
            $insertFields['type'] = $object->getEventType();
        }

        $insertFields['allday'] = $object->isAllDay() ? '1' : '0';
        if (!$this->rightsObj->isAllowedTo('edit', 'todo', 'allday')) {
            $insertFields['allday'] = $this->conf['rights.']['edit.']['todo.']['fields.']['allday.']['default'];
        }

        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'calendar')) {
            if ($object->getCalendarUid() !== '') {
                $insertFields['calendar_id'] = $object->getCalendarUid();
            } elseif ($this->conf['rights.']['edit.']['todo.']['fields.']['calendar.']['default']) {
                $insertFields['calendar_id'] = $this->conf['rights.']['edit.']['todo.']['fields.']['calendar_id.']['default'];
            } else {
                $insertFields['calendar_id'] = ''; // TODO: Set the calendar_id to some value
            }
        }

        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'starttime') || $this->rightsObj->isAllowedTo(
            'edit',
            'todo',
            'startday'
            )) {
            if (is_object($object->getStart())) {
                $start = $object->getStart();
                $insertFields['start_date'] = $start->format('Ymd');
                $insertFields['start_time'] = intval($start->format('H')) * 3600 + intval($start->format('M')) * 60;
            } else {
                return;
            }
            if (is_object($object->getEnd())) {
                $end = $object->getEnd();
                $insertFields['end_date'] = $end->format('Ymd');
                $insertFields['end_time'] = intval($end->format('H')) * 3600 + intval($end->format('M')) * 60;
            } else {
                return;
            }
        }
        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'title')) {
            $insertFields['title'] = $object->getTitle();
        }

        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'organizer')) {
            $insertFields['organizer'] = $object->getOrganizer();
        }
        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'cal_organizer')) {
            $insertFields['organizer_id'] = $object->getOrganizerId();
        }
        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'location')) {
            $insertFields['location'] = $object->getLocation();
        }
        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'cal_location')) {
            $insertFields['location_id'] = $object->getLocationId();
        }
        if ($object->getDescription() !== '' && $this->rightsObj->isAllowedTo('edit', 'todo', 'description')) {
            $insertFields['description'] = $object->getDescription();
        }
        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'recurring')) {
            $insertFields['freq'] = $object->getFreq();
            $insertFields['byday'] = strtolower(implode(',', $object->getByDay()));
            $insertFields['bymonthday'] = implode(',', $object->getByMonthDay());
            $insertFields['bymonth'] = implode(',', $object->getByMonth());
            $until = $object->getUntil();
            $insertFields['until'] = $until->format('Ymd');
            $insertFields['cnt'] = $object->getCount();
            $insertFields['intrval'] = $object->getInterval();
            $insertFields['rdate_type'] = $object->getRdateType();
            $insertFields['rdate'] = $object->getRdate();
        }

        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'status')) {
            $insertFields['status'] = $object->getStatus();
        }

        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'priority')) {
            $insertFields['priority'] = $object->getPriority();
        }

        if ($this->rightsObj->isAllowedTo('edit', 'todo', 'completed')) {
            $insertFields['completed'] = $object->getCompleted();
        }

        // Hook initialization:
        $hookObjectsArr = [];
        if (is_array($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['EXTCONF']['ext/cal/service/class.tx_cal_todo_service.php']['addAdditionalField'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['EXTCONF']['ext/cal/service/class.tx_cal_todo_service.php']['addAdditionalField'] as $classRef) {
                $hookObjectsArr[] = &GeneralUtility::getUserObj($classRef);
            }
        }

        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'addAdditionalField')) {
                $hookObj->addAdditionalField($insertFields, $this);
            }
        }
    }

    /**
     * @param string $pidList
     * @param $start_date
     * @param $end_date
     * @param $searchword
     * @param string $locationIds
     * @param string $organizerIds
     * @param string $eventType
     * @return array
     */
    public function search(
        $pidList,
        $start_date,
        $end_date,
        $searchword,
        $locationIds = '',
        $organizerIds = '',
        $eventType = '0,1,2,3'
    ): array {
        return parent::search($pidList, $start_date, $end_date, $searchword, $locationIds, $organizerIds, '4');
    }

    /**
     * @param $event
     * @param array $ex_event_dates
     * @return array
     */
    public function getRecurringEventsFromIndex($event, $ex_event_dates = []): array
    {
        $master_array = [];
        $startDate = $event->getStart();
        $master_array[$startDate->format('Ymd')][$event->isAllDay() ? '-1' : $startDate->format('HM')][$event->getUid()] = &$event;
        $select = '*';
        $table = 'tx_cal_index';
        $where = 'event_uid = ' . $event->getUid() . ' AND start_datetime >= ' . $this->starttime->format('YmdHMS') . ' AND start_datetime <= ' . $this->endtime->format('YmdHMS');
        $result = $GLOBALS['TYPO3_DB']->exec_SELECTquery($select, $table, $where);
        if ($result) {
            while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result)) {
                $nextOccuranceTime = new CalDate($row['start_datetime']);
                $nextOccuranceEndTime = new CalDate($row['end_datetime']);
                $new_event = new TodoRecModel($event, $nextOccuranceTime, $nextOccuranceEndTime);
                if ($new_event->isAllDay()) {
                    $master_array[$nextOccuranceTime->format('Ymd')]['-1'][$event->getUid()] = $new_event;
                } else {
                    $master_array[$nextOccuranceTime->format('Ymd')][$nextOccuranceTime->format('HM')][$event->getUid()] = $new_event;
                }
            }
            $GLOBALS['TYPO3_DB']->sql_free_result($result);
        }
        return $master_array;
    }

    public function unsetPiVars()
    {
        parent::unsetPiVars();
        unset($this->controller->piVars['priority'], $this->controller->piVars['completed'], $this->controller->piVars['status']);
    }
}
