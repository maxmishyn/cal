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
use BackendUtilityReplacementUtility;
use OutOfBoundsException;
use RuntimeException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Cal\Cron\ReminderScheduler;
use TYPO3\CMS\Cal\Model\CalDate;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Execution;
use TYPO3\CMS\Scheduler\Scheduler;

/**
 * Class ReminderView
 */
class ReminderView extends NotificationView
{

    /**
     * @param $event
     * @param $eventMonitor
     */
    public function remind(&$event, $eventMonitor)
    {
        $this->startMailer();

        switch ($eventMonitor['tablenames']) {
            case 'fe_users':
                $feUserRec = BackendUtility::getRecord('fe_users', $eventMonitor['uid_foreign']);
                $this->process($event, $feUserRec['email'], $eventMonitor['tablenames'] . '_' . $feUserRec['uid']);
                break;
            case 'fe_groups':
                $subType = 'getGroupsFE';
                $groups = [];
                $serviceObj = GeneralUtility::makeInstanceService('auth', $subType);
                if ($serviceObj === null) {
                    return;
                }

                $serviceObj->getSubGroups($eventMonitor['uid_foreign'], '', $groups);

                $select = 'DISTINCT fe_users.email';
                $table = 'fe_groups, fe_users';
                $where = 'fe_groups.uid IN (' . implode(',', $groups) . ') 
						AND FIND_IN_SET(fe_groups.uid, fe_users.usergroup)
						AND fe_users.email != \'\' 
						AND fe_groups.deleted = 0 
						AND fe_groups.hidden = 0 
						AND fe_users.disable = 0
						AND fe_users.deleted = 0';
                $result2 = $GLOBALS['TYPO3_DB']->exec_SELECTquery($select, $table, $where);
                while ($row2 = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result2)) {
                    $this->process($event, $row2['email'], $eventMonitor['tablenames'] . '_' . $row2['uid']);
                }
                $GLOBALS['TYPO3_DB']->sql_free_result($result2);
                break;
            case 'tx_cal_unknown_users':
                $feUserRec = BackendUtility::getRecord('tx_cal_unknown_users', $eventMonitor['uid_foreign']);
                $this->process($event, $feUserRec['email'], $eventMonitor['tablenames'] . '_' . $feUserRec['uid']);
                break;
        }
    }

    /**
     * @param $event
     * @param $email
     * @param $userId
     */
    public function process(&$event, $email, $userId)
    {
        if ($email !== '' && GeneralUtility::validEmail($email)) {
            $template = $this->conf['view.']['event.']['remind.'][$userId . '.']['template'];
            if (!$template) {
                $template = $this->conf['view.']['event.']['remind.']['all.']['template'];
            }
            $titleText = $this->conf['view.']['event.']['remind.'][$userId . '.']['emailTitle'];
            if (!$titleText) {
                $titleText = $this->conf['view.']['event.']['remind.']['all.']['emailTitle'];
            }
            $this->sendNotification($event, $email, $template, $titleText, '');
        }
    }

    /* @todo    Figure out where this should live
     * @param int $calEventUID
     */
    public function scheduleReminder($calEventUID)
    {

        // Get complete record
        $eventRecord = BackendUtility::getRecord('tx_cal_event', $calEventUID);

        // get the related monitoring records
        $taskId = null;

        $events = $this->subscriptionRepository->findByEventUid($calEventUID);
        foreach ($events as $event) {
            $taskId = $event['schedulerId'];
            $offset = $event['offset'];

            // maybe there is a recurring instance
            // get the uids of recurring events from index
            $now = new  CalDate();
            $now->setTZbyID('UTC');
            $now->addSeconds($offset * 60);
            $startDateTimeObject = new  CalDate($eventRecord['start_date'] . '000000');
            $startDateTimeObject->setTZbyID('UTC');
            $startDateTimeObject->addSeconds($eventRecord['start_time']);
            $start_datetime = $startDateTimeObject->format('%Y%m%d%H%M%S');
            $select2 = '*';
            $table2 = 'tx_cal_index';
            $where2 = 'start_datetime >= ' . $now->format('%Y%m%d%H%M%S') . ' AND event_uid = ' . $calEventUID;
            $orderby2 = 'start_datetime asc';
            $result2 = $GLOBALS['TYPO3_DB']->exec_SELECTquery($select2, $table2, $where2, $orderby2);
            if ($result2) {
                $tmp = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result2);
                if (is_array($tmp)) {
                    $start_datetime = $tmp['start_datetime'];
                    $nextOccuranceTime = new  CalDate($tmp['start_datetime']);
                    $nextOccuranceTime->setTZbyID('UTC');
                    $nextOccuranceEndTime = new  CalDate($tmp['end_datetime']);
                    $nextOccuranceEndTime->setTZbyID('UTC');
                    $eventRecord['start_date'] = $nextOccuranceTime->format('%Y%m%d');
                    $eventRecord['start_time'] = $nextOccuranceTime->getHour() * 3600 + $nextOccuranceTime->getMinute() * 60 + $nextOccuranceTime->getSecond();
                    $eventRecord['end_date'] = $nextOccuranceEndTime->format('%Y%m%d');
                    $eventRecord['end_time'] = $nextOccuranceEndTime->getHour() * 3600 + $nextOccuranceEndTime->getMinute() * 60 + $nextOccuranceEndTime->getSecond();
                }
                $GLOBALS['TYPO3_DB']->sql_free_result($result2);
            }

            if (ExtensionManagementUtility::isLoaded('scheduler')) {
                $scheduler = new Scheduler();
                $date = new  CalDate($start_datetime);
                $date->setTZbyID('UTC');
                $timestamp = $date->getTime();
                $offsetTime = new  CalDate();
                $offsetTime->copy($date);
                $offsetTime->setTZbyID('UTC');
                $offsetTime->addSeconds(-1 * $offset * 60);
                if ($taskId > 0) {
                    if ($offsetTime->isFuture()) {
                        try {
                            $task = $scheduler->fetchTask($taskId);
                            $execution = new Execution();
                            $execution->setStart($timestamp - ($offset * 60));
                            $execution->setIsNewSingleExecution(true);
                            $execution->setMultiple(false);
                            $execution->setEnd(time() - 1);
                            $task->setExecution($execution);
                            $task->setDisabled(false);
                            $scheduler->saveTask($task);
                        } catch (OutOfBoundsException $e) {
                            $this->createSchedulerTask(
                                $scheduler,
                                $date,
                                $calEventUID,
                                $timestamp,
                                $offset,
                                $event['uid']
                            );
                        }
                    } else {
                        $this->deleteReminder($calEventUID);
                    }
                } else {
                    $this->createSchedulerTask($scheduler, $date, $calEventUID, $timestamp, $offset, $event['uid']);
                }
            }
        }
    }

    /**
     * @param $scheduler
     * @param CalDate $date
     * @param $calEventUID
     * @param $timestamp
     * @param $offset
     * @param $uid
     */
    public function createSchedulerTask(&$scheduler, $date, $calEventUID, $timestamp, $offset, $uid)
    {
        if ($date->isFuture()) {
            /* Set up the scheduler event */
            $task = new ReminderScheduler();
            $task->setUID($calEventUID);
            $taskGroup = BackendUtilityReplacementUtility::getRawRecord('tx_scheduler_task_group', 'groupName="cal"');
            if ($taskGroup['uid']) {
                $task->setTaskGroup($taskGroup['uid']);
            } else {
                $crdate = time();
                $insertFields = [];
                $insertFields['pid'] = 0;
                $insertFields['tstamp'] = $crdate;
                $insertFields['crdate'] = $crdate;
                $insertFields['cruser_id'] = 0;
                $insertFields['groupName'] = 'cal';
                $insertFields['description'] = 'Calendar Base';
                $table = 'tx_scheduler_task_group';
                $result = $GLOBALS['TYPO3_DB']->exec_INSERTquery($table, $insertFields);
                if (false === $result) {
                    throw new RuntimeException(
                        'Could not write ' . $table . ' record to database: ' . $GLOBALS['TYPO3_DB']->sql_error(),
                        1431458160
                    );
                }
                $uid = $GLOBALS['TYPO3_DB']->sql_insert_id();
                $task->setTaskGroup($uid);
            }
            $task->setDescription('Reminder of a calendar event (id=' . $calEventUID . ')');
            /* Schedule the event */
            $execution = new Execution();
            $execution->setStart($timestamp - ($offset * 60));
            $execution->setIsNewSingleExecution(true);
            $execution->setMultiple(false);
            $execution->setEnd(time() - 1);
            $task->setExecution($execution);
            $scheduler->addTask($task);
            $this->subscriptionRepository->updateByUid($uid, ['schedulerId' => $task->getTaskUid()]);
        }
    }

    /* @todo    Figure out where this should live
     * @param int $eventUid
     */
    public function deleteReminder($eventUid)
    {
        if (ExtensionManagementUtility::isLoaded('scheduler')) {
            $events = $this->subscriptionRepository->findByEventUid($eventUid);
            $taskId = $events[0]['schedulerId'];
            if ($taskId > 0) {
                $scheduler = new Scheduler();
                try {
                    $task = $scheduler->fetchTask($taskId);
                    $scheduler->removeTask($task);
                } catch (OutOfBoundsException $e) {
                }
            }
        }
    }

    /**
     * @param $eventUid
     */
    public function deleteReminderForEvent($eventUid)
    {
        $events = $this->subscriptionRepository->findByEventUid($eventUid);
        foreach ($events as $event) {
            $this->deleteReminder($event['uid_local']);
        }
    }
}
