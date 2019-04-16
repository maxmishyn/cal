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
use TYPO3\CMS\Cal\Model\CalDate;
use TYPO3\CMS\Cal\Model\Pear\Date\Calc;
use TYPO3\CMS\Cal\Service\DateCalculationService;

/**
 * This class combines all the time related functions
 */
class Calendar
{

    /**
     * Takes iCalendar 2 day format and makes it into 3 characters
     * if $txt is true, it returns the 3 letters, otherwise it returns the
     * integer of that day; 0=Sun, 1=Mon, etc.
     * @param string $day
     * @param bool $txt
     * @return string
     */
    public static function two2threeCharDays($day, $txt = true): string
    {
        switch ($day) {
            case 'SU':
                return $txt ? 'sun' : '0';
            case 'MO':
                return $txt ? 'mon' : '1';
            case 'TU':
                return $txt ? 'tue' : '2';
            case 'WE':
                return $txt ? 'wed' : '3';
            case 'TH':
                return $txt ? 'thu' : '4';
            case 'FR':
                return $txt ? 'fri' : '5';
            case 'SA':
                return $txt ? 'sat' : '6';
        }
        return '';
    }

    /**
     * @param $date
     * @return mixed
     */
    public static function getYear($date)
    {
        $day_array2 = [];
        preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $date, $day_array2);
        return $day_array2[1];
    }

    /**
     * @param $date
     * @return mixed
     */
    public static function getMonth($date)
    {
        $day_array2 = [];
        preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $date, $day_array2);
        return $day_array2[2];
    }

    /**
     * @param $date
     * @return mixed
     */
    public static function getDay($date)
    {
        $day_array2 = [];
        preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $date, $day_array2);
        return $day_array2[3];
    }

    /**
     * @param string $dateObject
     * @return CalDate
     */
    public static function calculateStartDayTime($dateObject = ''): CalDate
    {
        $timeObj = new CalDate();
        $timeObj->setTZbyID('UTC');
        if ($dateObject) {
            $timeObj->copy($dateObject);
        }
        $timeObj->setHour(0);
        $timeObj->setMinute(0);
        $timeObj->setSecond(0);
        return $timeObj;
    }

    /**
     * @param string $dateObject
     * @return CalDate
     */
    public static function calculateEndDayTime($dateObject = ''): CalDate
    {
        $timeObj = new CalDate();
        $timeObj->setTZbyID('UTC');
        if ($dateObject) {
            $timeObj->copy($dateObject);
        }
        $timeObj->setHour(23);
        $timeObj->setMinute(59);
        $timeObj->setSecond(59);
        return $timeObj;
    }

    /**
     * @param string $dateObject
     * @return CalDate
     */
    public static function calculateStartWeekTime($dateObject = ''): CalDate
    {
        $timeObj = self::calculateStartDayTime($dateObject);
        $timeObj = new CalDate(Calc::beginOfWeek(
            $timeObj->getDay(),
            $timeObj->getMonth(),
            $timeObj->getYear()
        ));
        $timeObj->setTZbyID('UTC');
        return $timeObj;
    }

    /**
     * @param string $dateObject
     * @return CalDate
     */
    public static function calculateEndWeekTime($dateObject = ''): CalDate
    {
        $timeObj = self::calculateStartWeekTime($dateObject);
        $timeObj->addSeconds(604799);
        return $timeObj;
    }

    /**
     * @param string $dateObject
     * @return CalDate
     */
    public static function calculateStartMonthTime($dateObject = ''): CalDate
    {
        $timeObj = self::calculateStartDayTime($dateObject);
        $timeObj->setDay(1);
        return $timeObj;
    }

    /**
     * @param string $dateObject
     * @return CalDate
     */
    public static function calculateEndMonthTime($dateObject = ''): CalDate
    {
        $timeObj = self::calculateStartDayTime($dateObject);
        $timeObj = new CalDate(DateCalculationService::endOfMonthBySpan(
            $timeObj->getDay(),
            $timeObj->getMonth(),
            $timeObj->getYear()
        ));
        $timeObj->setDay(1);
        $timeObj->subtractSeconds(1);
        $timeObj->setTZbyID('UTC');
        return $timeObj;
    }

    /**
     * @param string $dateObject
     * @return CalDate
     */
    public static function calculateStartYearTime($dateObject = ''): CalDate
    {
        $timeObj = self::calculateStartMonthTime($dateObject);
        $timeObj->setMonth(1);
        return $timeObj;
    }

    /**
     * @param string $dateObject
     * @return CalDate
     */
    public static function calculateEndYearTime($dateObject = ''): CalDate
    {
        $timeObj = self::calculateStartYearTime($dateObject);
        $timeObj->setYear($timeObj->getYear() + 1);
        $timeObj->subtractSeconds(1);
        return $timeObj;
    }

    /**
     * @param $time
     * @return string
     */
    public static function getHourFromTime($time): string
    {
        $retVal = '';
        $time = str_replace(':', '', $time);

        if ($time) {
            $retVal = substr($time, 0, -2);
        }
        return $retVal;
    }

    /**
     * @param $time
     * @return string
     */
    public static function getMinutesFromTime($time): string
    {
        $retVal = '';
        $time = str_replace(':', '', $time);
        if ($time) {
            $retVal = substr($time, -2);
        }
        return $retVal;
    }

    /**
     * @param int $timestamp
     * @return number
     */
    public static function getTimeFromTimestamp($timestamp = 0)
    {
        if ($timestamp > 0) {
            // gmdate and gmmktime are ok, as long as the timestamp just holds information about 24h.
            return gmmktime(gmdate('H', $timestamp), gmdate('i', $timestamp), 0, 0, 0, 1) - gmmktime(0, 0, 0, 0, 0, 1);
        }
        return 0;
    }
}
