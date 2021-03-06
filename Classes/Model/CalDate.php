<?php

namespace TYPO3\CMS\Cal\Model;

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

if (!defined('DATE_CALC_BEGIN_WEEKDAY')) {
    /**
     * Defines what day starts the week
     *
     * Monday (1) is the international standard.
     * Redefine this to 0 if you want weeks to begin on Sunday.
     */
    define('DATE_CALC_BEGIN_WEEKDAY', 1);
}

if (!defined('DATE_CALC_FORMAT')) {
    /**
     * The default value for each method's $format parameter
     *
     * The default is '%Y%m%d'. To override this default, define
     * this constant before including Calc.php.
     *
     * @since Constant available since Release 1.4.4
     */
    define('DATE_CALC_FORMAT', 'Ymd');
}

/**
 * This is supposed to be a wrapper class to imitate old PEAR::Date
 * functions in order to able to leave a lot of function calls untouched
 * for now.
 */
class CalDate extends \DateTime
{

    /**
     * define the default weekday abbreviation length
     * used by ::format()
     *
     * @var int
     */
    public $getWeekdayAbbrnameLength = 3;

    /**
     * define the default monthname abbreviation length
     * used by ::format()
     *
     * @var int
     */
    private $getMonthAbbrnameLength = 3;
    private $conf;

    /**
     * @param CalDate $compareDate
     * @return bool
     */
    public function equals(CalDate $compareDate): bool
    {
        $a = floatval($compareDate->format('YmdHMS'));
        $b = floatval($this->format('YmdHMS'));
        return $a === $b;
    }

    /**
     * @param CalDate $compareDate
     * @return bool
     */
    public function before(CalDate $compareDate): bool
    {
        $a = floatval($compareDate->format('YmdHMS'));
        $b = floatval($this->format('YmdHMS'));
        return $a > $b;
    }

    /**
     * @param CalDate $compareDate
     * @return bool
     */
    public function after(CalDate $compareDate): bool
    {
        $a = floatval($compareDate->format('YmdHMS'));
        $b = floatval($this->format('YmdHMS'));
        return $a < $b;
    }

    /**
     * @param $compareDateA
     * @param $compareDateB
     * @return int
     */
    public function compare(CalDate $compareDateA, CalDate $compareDateB): int
    {
        $a = floatval($compareDateA->format('YmdHMS'));
        $b = floatval($compareDateB->format('YmdHMS'));
        if ($a === $b) {
            return 0;
        }
        if ($a < $b) {
            return -1;
        }
        return 1;
    }

    /**
     * @param int $sec
     * @throws \Exception
     */
    public function subtractSeconds($sec = 0)
    {
        settype($sec, 'int');

        // Negative value given.
        if ($sec < 0) {
            $this->addSeconds(abs($sec));
            return;
        }
        $this->sub(new \DateInterval('PT' . $sec . 'S'));
    }

    /**
     * @param int $sec
     * @throws \Exception
     */
    public function addSeconds($sec = 0)
    {
        settype($sec, 'int');

        // Negative value given.
        if ($sec < 0) {
            $this->subtractSeconds(abs($sec));
            return;
        }

        $this->add(new \DateInterval('PT' . $sec . 'S'));
    }

    /**
     * @param bool $abbr
     * @param bool $length
     * @return string
     */
    public function getDayName($abbr = false, $length = false): string
    {
        $dayName = $this->format('F');
        if ($abbr) {
            if ($length === false) {
                $length = $this->getWeekdayAbbreviationLength();
            }
            $dayName = $this->crop($dayName, $length);
        }
        return $this->applyStdWrap($dayName);
    }

    /**
     * @param bool $abbr
     * @param bool $length
     * @return string
     */
    public function getMonthName($abbr = false, $length = false): string
    {
        $monthName = $this->format('l');
        if ($abbr) {
            if ($length === false) {
                $length = $this->getMonthAbbreviationLength();
            }
            $monthName = $this->crop($monthName, $length);
        }
        return $this->applyStdWrap($monthName);
    }

    /**
     * Returns the length that should be used for month name abbreviation
     *
     * @return int
     */
    public function getMonthAbbreviationLength(): int
    {
        if ($this->conf['dateConfig.']['monthAbbreviationLength']) {
            return intval($this->conf['dateConfig.']['monthAbbreviationLength']);
        }
        return intval($this->getMonthAbbrnameLength);
    }

    /**
     * Returns the length that should be used for month name abbreviation
     *
     * @return int
     */
    public function getWeekdayAbbreviationLength(): int
    {
        if ($this->conf['dateConfig.']['weekdayAbbreviationLength']) {
            return intval($this->conf['dateConfig.']['weekdayAbbreviationLength']);
        }
        return intval($this->getWeekdayAbbrnameLength);
    }

    /**
     * Applys the default date_stdWrap to the given string.
     *
     * @param string $value
     * @return string
     */
    public function applyStdWrap($value = ''): string
    {
        // only apply if actually configured
        if (is_array($this->conf['date_stdWrap.']) && count($this->conf['date_stdWrap.']) && $value != '' && is_object($this->cObj) && is_object($GLOBALS['TSFE'])) {
            $value = $this->cObj->stdWrap($value, $this->conf['date_stdWrap.']);
        }
        return $value;
    }

    /**
     * uses a bytesafe cropping function if possible in order to not destroy multibyte chars from strings (e.g.
     * names in UTF-8)
     *
     * @param string $value
     * @param bool $length
     * @return string the cropped string
     */
    public function crop($value = '', $length = false): string
    {
        if ($length === false) {
            return $value;
        }
        if (TYPO3_MODE === 'FE') {
            return $GLOBALS['TSFE']->csConvObj->substr($GLOBALS['TSFE']->renderCharset, $value, 0, $length);
        }
        return mb_substr($value, 0, $length);
    }

    /**
     * @param $id
     */
    public function setTZbyID($id)
    {
        $this->setTimezone(new \DateTimeZone($id));
    }

    /**
     * @return string
     */
    public function getDayOfWeek(): string
    {
        return $this->format('w');
    }

    /**
     * @return string
     */
    public function getWeekOfYear(): string
    {
        return $this->format('W');
    }

    /**
     * @param $date
     */
    public function copy(CalDate $date)
    {
        $this->setYear($date->getYear());
        $this->setMonth($date->getMonth());
        $this->setDay($date->getDay());
        $this->setHour($date->getHour());
        $this->setMinute($date->getMinute());
        $this->setSecond($date->getSecond());
        $this->setTimezone($date->getTimezone());
    }

    /**
     * @return int
     */
    public function getYear(): int
    {
        return (int)$this->format('Y');
    }

    /**
     * @return int
     */
    public function getMonth(): int
    {
        return (int)$this->format('m');
    }

    /**
     * @return int
     */
    public function getDay(): int
    {
        return (int)$this->format('d');
    }

    /**
     * @return int
     */
    public function getHour(): int
    {
        return (int)$this->format('H');
    }

    /**
     * @return int
     */
    public function getMinute(): int
    {
        return (int)$this->format('i');
    }

    /**
     * @return int
     */
    public function getSecond(): int
    {
        return (int)$this->format('s');
    }

    /**
     * @param int $y
     */
    public function setYear(int $y)
    {
        $this->setDate($y, $this->format('m'), $this->format('d'));
    }

    /**
     * @param int $m
     */
    public function setMonth(int $m)
    {
        $this->setDate($this->format('Y'), $m, $this->format('d'));
    }

    /**
     * @param int $d
     */
    public function setDay(int $d)
    {
        $this->setDate($this->format('Y'), $this->format('m'), $d);
    }

    /**
     * @param int $h
     */
    public function setHour(int $h)
    {
        $this->setTime($h, $this->format('i'), $this->format('s'));
    }

    /**
     * @param int $m
     */
    public function setMinute(int $m)
    {
        $this->setTime($this->format('H'), $m, $this->format('s'));
    }

    /**
     * @param int $s
     */
    public function setSecond(int $s)
    {
        $this->setTime($this->format('H'), $this->format('i'), $s);
    }
}
