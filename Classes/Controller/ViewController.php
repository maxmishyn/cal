<?php

namespace TYPO3\CMS\Cal\Controller;

use TYPO3\CMS\Core\Utility\GeneralUtility;

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
 * Front controller for the calendar base.
 * Takes requests from the main
 * controller and starts rendering in the appropriate calendar view by
 * utilizing TYPO3 services.
 */
class ViewController extends BaseController
{

    /**
     * Draws the day view.
     *
     * @param $master_array
     * @param $getdate
     * @return string HTML output of the specified view.
     */
    public function drawDay(&$master_array, $getdate): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'day', '_day');
        $content = $viewObj->drawDay($master_array, $getdate);

        return $content;
    }

    /**
     * Draws the week view.
     *
     * @param $master_array
     * @param $getdate
     * @return string HTML output of the specified view.
     */
    public function drawWeek(&$master_array, $getdate): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'week', '_week');
        $content = $viewObj->drawWeek($master_array, $getdate);

        return $content;
    }

    /**
     * Draws the month view.
     *
     * @param $master_array
     * @param $getdate
     * @return string HTML output of the specified view.
     */
    public function drawMonth(&$master_array, $getdate): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'month', '_month');
        $content = $viewObj->drawMonth($master_array, $getdate);

        return $content;
    }

    /**
     * Draws the year view.
     *
     * @param $master_array
     * @param $getdate
     * @return string HTML output of the specified view.
     */
    public function drawYear(&$master_array, $getdate): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'year', '_year');
        $content = $viewObj->drawYear($master_array, $getdate);

        return $content;
    }

    /**
     * Draws the list view.
     *
     * @param $master_array
     * @param $starttime
     * @param $endtime
     * @return string HTML output of the specified view.
     */
    public function drawList(&$master_array, $starttime, $endtime): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'list', '_list');
        $content = $viewObj->drawList($master_array, '', $starttime, $endtime);

        return $content;
    }

    /**
     * Draws the ics list view.
     *
     * @param $master_array
     * @param $getdate
     * @return string HTML output of the specified view.
     */
    public function drawIcsList(&$master_array, $getdate): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'ics', '_icslist');
        $content = $viewObj->drawIcsList($master_array, $getdate);

        return $content;
    }

    /**
     * Draws the admin view.
     *
     * @return string HTML output of the specified view.
     */
    public function drawAdminPage(): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'admin', '_adminpage');
        $content = $viewObj->drawAdminPage();

        return $content;
    }

    /**
     * Draws the subscription manager view.
     *
     * @return string HTML output of the specified view.
     */
    public function drawSubscriptionManager(): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'subscription', '_subscription');
        $content = $viewObj->drawSubscriptionManager();

        return $content;
    }

    /**
     * Draws the meeting manager view.
     *
     * @return string HTML output of the specified view.
     */
    public function drawMeetingManager(): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'meeting', '_meeting');
        $content = $viewObj->drawMeetingManager();

        return $content;
    }

    /**
     * Draws the month view.
     *
     * @param $event
     * @param $getdate
     * @param array $relatedEvents
     * @return string HTML output of the specified view.
     */
    public function drawEvent(&$event, $getdate, $relatedEvents = []): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'event', '_event');
        $content = $viewObj->drawEvent($event, $getdate, $relatedEvents);

        return $content;
    }

    /**
     * Draws the ics view.
     *
     * @param $master_array
     * @param $getdate
     * @param bool $sendHeaders
     * @param string $limitAttendeeToThisEmail
     * @return string HTML output of the specified view.
     */
    public function drawIcs(&$master_array, $getdate, $sendHeaders = true, $limitAttendeeToThisEmail = ''): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'ics', '_ics');
        $content = $viewObj->drawIcs($master_array, $getdate, $sendHeaders, $limitAttendeeToThisEmail);
        return $content;
    }

    /**
     * Draws the rss view.
     *
     * @param $master_array
     * @param $getdate
     * @return string HTML output of the specified view.
     */
    public function drawRss(&$master_array, $getdate): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'rss', '_rss');
        $content = $viewObj->drawRss($master_array, $getdate);

        return $content;
    }

    /**
     * Draws the search view.
     *
     * @param $master_array
     * @param $starttime
     * @param $endtime
     * @param $searchword
     * @param string $locationIds
     * @param string $organizerIds
     * @return string HTML output of the specified view.
     */
    public function drawSearchAllResult(
        &$master_array,
        $starttime,
        $endtime,
        $searchword,
        $locationIds = '',
        $organizerIds = ''
    ): string {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'search', '_searchall');
        $content = $viewObj->drawSearchAllResult($master_array, $starttime, $endtime, $searchword, $locationIds, $organizerIds);

        return $content;
    }

    /**
     * Draws the search view.
     *
     * @param $master_array
     * @param $starttime
     * @param $endtime
     * @param $searchword
     * @param string $locationIds
     * @param string $organizerIds
     * @return string HTML output of the specified view.
     */
    public function drawSearchEventResult(
        &$master_array,
        $starttime,
        $endtime,
        $searchword,
        $locationIds = '',
        $organizerIds = ''
    ): string {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'search', '_searchevent');
        $content = $viewObj->drawSearchEventResult($master_array, $starttime, $endtime, $searchword, $locationIds, $organizerIds);

        return $content;
    }

    /**
     * Draws the search view.
     *
     * @param $master_array
     * @param $searchword
     * @return string HTML output of the specified view.
     */
    public function drawSearchLocationResult(&$master_array, $searchword): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'search', '_searchlocation');
        $content = $viewObj->drawSearchLocationResult($master_array, $searchword);

        return $content;
    }

    /**
     * Draws the search view.
     *
     * @param $master_array
     * @param $searchword
     * @return string HTML output of the specified view.
     */
    public function drawSearchOrganizerResult(&$master_array, $searchword): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'search', '_searchorganizer');
        $content = $viewObj->drawSearchOrganizerResult($master_array, $searchword);

        return $content;
    }

    /**
     * Draws the location view.
     *
     * @param  object        The event to be drawn.
     * @param array $relatedEvents
     * @return string HTML output of the specified view.
     */
    public function drawLocation(&$location, $relatedEvents = []): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'location', '_location');
        $content = $viewObj->drawLocation($location, $relatedEvents);

        return $content;
    }

    /**
     * Draws the organizer view.
     *
     * @param  object        The event to be drawn.
     * @param array $relatedEvents
     * @return string HTML output of the specified view.
     */
    public function drawOrganizer(&$organizer, $relatedEvents = []): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'organizer', '_organizer');
        $content = $viewObj->drawOrganizer($organizer, $relatedEvents);

        return $content;
    }

    /**
     * Draws the create event view.
     *
     * @param  object        The event to be drawn.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawCreateEvent($getdate, $pidList = ''): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'create_event', '_create_event');
        $content = $viewObj->drawCreateEvent($getdate, $pidList);

        return $content;
    }

    /**
     * Draws the confirm event view.
     *
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawConfirmEvent($pidList = ''): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'confirm_event', '_confirm_event');
        $content = $viewObj->drawConfirmEvent($pidList);

        return $content;
    }

    /**
     * Draws the edit event view.
     *
     * @param  object        The event to be edited.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawEditEvent(&$event, $pidList = ''): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'create_event', '_create_event');
        $content = $viewObj->drawCreateEvent($this->conf ['getdate'], $pidList, $event);

        return $content;
    }

    /**
     * Draws the delete event view.
     *
     * @param  object        The event to be deleted.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawDeleteEvent(&$event, $pidList = ''): string
    {
        /* Call the view and pass it the event to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'delete_event', '_delete_event');
        $content = $viewObj->drawDeleteEvent($event, $pidList);

        return $content;
    }

    /**
     * Draws the create location view.
     *
     * @param  object        The location to be drawn.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawCreateLocation($getdate, $pidList = ''): string
    {
        /* Call the view and pass it the location to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'create_location', '_create_location');
        $content = $viewObj->drawCreateLocationOrOrganizer(true, $pidList);
        return $content;
    }

    /**
     * Draws the confirm location view.
     *
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawConfirmLocation($pidList = ''): string
    {
        /* Call the view and pass it the location to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'confirm_location', '_confirm_location');
        $content = $viewObj->drawConfirmLocationOrOrganizer(true, $pidList);

        return $content;
    }

    /**
     * Draws the edit location view.
     *
     * @param  object        The location to be edited.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawEditLocation(&$location, $pidList = ''): string
    {
        /* Call the view and pass it the location to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'create_location', '_create_location');
        $content = $viewObj->drawCreateLocationOrOrganizer(true, $pidList, $location);

        return $content;
    }

    /**
     * Draws the delete location view.
     *
     * @param  object        The location to be deleted.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawDeleteLocation(&$location, $pidList = ''): string
    {
        /* Call the view and pass it the location to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'delete_location', '_delete_location');
        $content = $viewObj->drawDeleteLocationOrOrganizer(true, $location);

        return $content;
    }

    /**
     * Draws the create organizer view.
     *
     * @param  object        The organizer to be drawn.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawCreateOrganizer($getdate, $pidList = ''): string
    {
        /* Call the view and pass it the organizer to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'create_organizer', '_create_organizer');
        $content = $viewObj->drawCreateLocationOrOrganizer(false, $pidList);

        return $content;
    }

    /**
     * Draws the confirm organizer view.
     *
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawConfirmOrganizer($pidList = ''): string
    {
        /* Call the view and pass it the organizer to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'confirm_organizer', '_confirm_organizer');
        $content = $viewObj->drawConfirmLocationOrOrganizer(false, $pidList);

        return $content;
    }

    /**
     * Draws the edit event view.
     *
     * @param  object        The event to be edited.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawEditOrganizer(&$organizer, $pidList = ''): string
    {
        /* Call the view and pass it the organizer to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'create_organizer', '_create_organizer');
        $content = $viewObj->drawCreateLocationOrOrganizer(false, $pidList, $organizer);

        return $content;
    }

    /**
     * Draws the delete organizer view.
     *
     * @param  object        The organizer to be deleted.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawDeleteOrganizer(&$organizer, $pidList = ''): string
    {
        /* Call the view and pass it the organizer to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'delete_organizer', '_delete_organizer');
        $content = $viewObj->drawDeleteLocationOrOrganizer(false, $organizer);

        return $content;
    }

    /**
     * Draws the create calendar view.
     *
     * @param  object        The calendar to be drawn.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawCreateCalendar($getdate, $pidList = ''): string
    {
        /* Call the view and pass it the calendar to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'create_calendar', '_create_calendar');
        $content = $viewObj->drawCreateCalendar(false, $pidList);

        return $content;
    }

    /**
     * Draws the confirm calendar view.
     *
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawConfirmCalendar($pidList = ''): string
    {
        /* Call the view and pass it the calendar to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'confirm_calendar', '_confirm_calendar');
        $content = $viewObj->drawConfirmCalendar(false, $pidList);

        return $content;
    }

    /**
     * Draws the edit event view.
     *
     * @param  object        The event to be edited.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawEditCalendar(&$calendar, $pidList = ''): string
    {
        /* Call the view and pass it the calendar to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'create_calendar', '_create_calendar');
        $content = $viewObj->drawCreateCalendar($pidList, $calendar);

        return $content;
    }

    /**
     * Draws the delete calendar view.
     *
     * @param  object        The calendar to be deleted.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawDeleteCalendar(&$calendar, $pidList = ''): string
    {
        /* Call the view and pass it the calendar to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'delete_calendar', '_delete_calendar');
        $content = $viewObj->drawDeleteCalendar($calendar, $pidList, $calendar);

        return $content;
    }

    /**
     * Draws the create category view.
     *
     * @param  object        The category to be drawn.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawCreateCategory($getdate, $pidList = ''): string
    {
        /* Call the view and pass it the category to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'create_category', '_create_category');
        $content = $viewObj->drawCreateCategory(false, $pidList);

        return $content;
    }

    /**
     * Draws the confirm category view.
     *
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawConfirmCategory($pidList = ''): string
    {
        /* Call the view and pass it the category to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'confirm_category', '_confirm_category');
        $content = $viewObj->drawConfirmCategory(false, $pidList);

        return $content;
    }

    /**
     * Draws the edit event view.
     *
     * @param  object        The event to be edited.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawEditCategory(&$category, $pidList = ''): string
    {
        /* Call the view and pass it the category to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'create_category', '_create_category');
        $content = $viewObj->drawCreateCategory($pidList, $category);

        return $content;
    }

    /**
     * Draws the delete category view.
     *
     * @param  object        The category to be deleted.
     * @param string $pidList
     * @return string HTML output of the specified view.
     */
    public function drawDeleteCategory(&$category, $pidList = ''): string
    {
        /* Call the view and pass it the category to draw */
        $viewObj = $this->getServiceObjByKey('cal_view', 'delete_category', '_delete_category');
        $content = $viewObj->drawDeleteCategory($category, $pidList, $category);

        return $content;
    }

    /**
     * Helper function to return a service object with the given type, subtype, and serviceKey
     *
     * @param  string    The type of the service.
     * @param  string    The subtype of the service.
     * @param  string    The serviceKey.
     * @return object service object.
     */
    public function getServiceObjByKey($type, $subtype, $key)
    {
        $serviceChain = '';
        /* Loop over all services providign the specified service type and subtype */
        if (is_object($obj = &GeneralUtility::makeInstanceService($type, $subtype, $serviceChain))) {
            return $obj;
        }
        return null;
    }
}
