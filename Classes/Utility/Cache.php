<?php

namespace TYPO3\CMS\Cal\Utility;

use TYPO3\CMS\Core\Cache\CacheManager;
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
class Cache
{
    public $cachingEngine;
    public $tx_cal_cache;
    public $lifetime = 0;
    public $ACCESS_TIME = 0;

    /**
     * Cache constructor.
     * @param $cachingEngine
     */
    public function __construct($cachingEngine)
    {
        $this->cachingEngine = $cachingEngine;
        switch ($this->cachingEngine) {
            case 'cachingFramework':
                $this->initCachingFramework();
                break;

            case 'memcached':
                $this->initMemcached();
                break;

            // default = internal
        }
    }

    public function initMemcached()
    {
        $this->tx_cal_cache = new Memcache();
        $this->tx_cal_cache->connect('localhost', 11211);
    }

    public function initCachingFramework()
    {
        $this->tx_cal_cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('tx_cal_cache');
    }

    /**
     * @param $hash
     * @param $content
     * @param $ident
     * @param int $lifetime
     */
    public function set($hash, $content, $ident, $lifetime = 0)
    {
        if ($lifetime == 0) {
            $lifetime = $this->lifetime;
        }

        if ($this->cachingEngine == 'cachingFramework') {
            $this->tx_cal_cache->set($hash, $content, [
                'ident_' . $ident
            ], $lifetime);
        } elseif ($this->cachingEngine == 'memcached') {
            $this->tx_cal_cache->set($hash, $content, false, $lifetime);
        } else {
            $table = 'tx_cal_cache';
            $fields_values = [
                'identifier' => $hash,
                'content' => $content,
                'crdate' => $GLOBALS['EXEC_TIME'],
                'lifetime' => $lifetime
            ];
            $GLOBALS['TYPO3_DB']->exec_DELETEquery(
                $table,
                'identifier=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($hash, $table)
            );
            $result = $GLOBALS['TYPO3_DB']->exec_INSERTquery($table, $fields_values);
            if (false === $result) {
                throw new \RuntimeException(
                    'Could not write cache record to database: ' . $GLOBALS['TYPO3_DB']->sql_error(),
                    1431458130
                );
            }
        }
    }

    /**
     * @param $hash
     * @return bool|mixed
     */
    public function get($hash)
    {
        $cacheEntry = false;
        if ($this->cachingEngine == 'cachingFramework' || $this->cachingEngine == 'memcached') {
            $cacheEntry = $this->tx_cal_cache->get($hash);
        } else {
            $select_fields = 'content';
            $from_table = 'tx_cal_cache';
            $where_clause = 'identifier=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($hash, $from_table);

            // if ($period > 0) {
            $where_clause .= ' AND (crdate+lifetime>' . $this->ACCESS_TIME . ' OR lifetime=0)';
            // }

            $cRec = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($select_fields, $from_table, $where_clause);

            if (is_array($cRec[0]) && $cRec[0]['content'] != '') {
                $cacheEntry = $cRec[0]['content'];
            }
        }

        return $cacheEntry;
    }
}
