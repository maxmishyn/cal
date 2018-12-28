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
use JBartels\WecMap\MapService\Google\Map;
use SJBR\StaticInfoTables\Utility\LocalizationUtility;
use TYPO3\CMS\Cal\Utility\Functions;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Base model for the calendar location.
 * Provides basic model functionality that other
 * models can use or override by extending the class.
 */
class LocationModel extends BaseModel
{
    public $row;
    public $name;
    public $description;
    public $street;
    public $zip;
    public $city;
    public $countryzone;
    public $country;
    public $phone;
    public $fax;
    public $mobilephone;
    public $email;
    public $link;
    public $longitude;
    public $latitude;
    public $eventLinks = [];
    public $sharedUsers = [];
    public $sharedGroups = [];

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param $t
     */
    public function setName($t)
    {
        $this->name = $t;
    }

    /**
     * @return mixed
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @param $d
     */
    public function setDescription($d)
    {
        $this->description = $d;
    }

    /**
     * @return mixed
     */
    public function getStreet()
    {
        return $this->street;
    }

    /**
     * @param $t
     */
    public function setStreet($t)
    {
        $this->street = $t;
    }

    /**
     * @return mixed
     */
    public function getZip()
    {
        return $this->zip;
    }

    /**
     * @param $t
     */
    public function setZip($t)
    {
        $this->zip = $t;
    }

    /**
     * @return mixed
     */
    public function getCity()
    {
        return $this->city;
    }

    /**
     * @param $t
     */
    public function setCity($t)
    {
        $this->city = $t;
    }

    /**
     * @return mixed
     */
    public function getCountryZone()
    {
        return $this->countryzone;
    }

    /**
     * @param $t
     */
    public function setCountryZone($t)
    {
        if ($t) {
            $this->countryzone = $t;
        }
    }

    /**
     * @return mixed
     */
    public function getCountry()
    {
        return $this->country;
    }

    /**
     * @param $t
     */
    public function setCountry($t)
    {
        if ($t) {
            $this->country = $t;
        }
    }

    /**
     * @return mixed
     */
    public function getPhone()
    {
        return $this->phone;
    }

    /**
     * @param $t
     */
    public function setPhone($t)
    {
        $this->phone = $t;
    }

    /**
     * @return mixed
     */
    public function getMobilephone()
    {
        return $this->mobilephone;
    }

    /**
     * @param $t
     */
    public function setMobilephone($t)
    {
        $this->mobilephone = $t;
    }

    /**
     * @return mixed
     */
    public function getFax()
    {
        return $this->fax;
    }

    /**
     * @param $t
     */
    public function setFax($t)
    {
        $this->fax = $t;
    }

    /**
     * @return mixed
     */
    public function getLink()
    {
        return $this->link;
    }

    /**
     * @param $t
     */
    public function setLink($t)
    {
        $this->link = $t;
    }

    /**
     * @return mixed
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * @param $t
     */
    public function setEmail($t)
    {
        $this->email = $t;
    }

    /**
     * @return mixed
     */
    public function getLongitude()
    {
        return $this->longitude;
    }

    /**
     * @return mixed
     */
    public function getLatitude()
    {
        return $this->latitude;
    }

    /**
     * @param $l
     */
    public function setLongitude($l)
    {
        $this->longitude = $l;
    }

    /**
     * @param $l
     */
    public function setLatitude($l)
    {
        $this->latitude = $l;
    }

    /**
     * @param $row
     */
    public function createLocation($row)
    {
        $this->row = $row;
        $this->setUid($row['uid']);
        $this->setName($row['name']);
        $this->setDescription($row['description']);
        $this->setStreet($row['street']);
        $this->setZip($row['zip']);
        $this->setCity($row['city']);
        $this->setCountryZone($row['country_zone']);
        $this->setCountry($row['country']);
        $this->setPhone($row['phone']);
        $this->setEmail($row['email']);
        $this->setImage(GeneralUtility::trimExplode(',', $row['image'], 1));
        $this->setLink($row['link']);
        $this->setLatitude($row['latitude']);
        $this->setLongitude($row['longitude']);
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     * @param $view
     */
    public function getMapMarker(& $template, & $sims, & $rems, & $wrapped, $view)
    {
        $sims['###MAP###'] = '';
        if ($this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['showMap'] && ExtensionManagementUtility::isLoaded('wec_map')) {
            $apiKey = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['apiKey'];
            $width = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['mapWidth'];
            $height = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['mapHeight'];

            $centerLat = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['centerLat'];
            $centerLong = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['centerLong'];
            $zoomLevel = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['zoomLevel'];
            $initialMapType = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['initialMapType'];

            $showMapType = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['showMapType'];
            $showScale = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['showScale'];
            $showInfoOnLoad = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['showInfoOnLoad'];
            $showDirections = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['showDirections'];
            $showWrittenDirections = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['showWrittenDirections'];
            $prefillAddress = $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['map.']['prefillAddress'];

            $mapName = 'map' . $this->getUid();
            /** @var Map $map */
            $map = GeneralUtility::makeInstance(
                Map::class,
                null,
                $width,
                $height,
                $centerLat,
                $centerLong,
                $zoomLevel,
                $mapName
            );

            // evaluate config to see which map controls we need to show
            $map->addControl('zoom');

            if ($showScale) {
                $map->addControl('scale');
            }
            if ($showMapType) {
                $map->addControl('mapType');
            }
            if ($initialMapType) {
                $map->setType($initialMapType);
            }

            // check whether to show the directions tab and/or prefill addresses and/or written directions
            if ($showDirections && $showWrittenDirections && $prefillAddress) {
                $map->enableDirections(true, 'directions-' . $mapName);
            }
            if ($showDirections && $showWrittenDirections && !$prefillAddress) {
                $map->enableDirections(false, '-directions-' . $mapName);
            }
            if ($showDirections && !$showWrittenDirections && $prefillAddress) {
                $map->enableDirections(true);
            }
            if ($showDirections && !$showWrittenDirections && !$prefillAddress) {
                $map->enableDirections();
            }

            // see if we need to open the marker bubble on load
            if ($showInfoOnLoad) {
                $map->showInfoOnLoad();
            }

            $map->addMarkerByAddress(
                $this->getStreet(),
                $this->getCity(),
                $this->getCountryZone(),
                $this->getZip(),
                $this->getCountry(),
                '<h3>' . $this->getName() . '</h3>',
                '<p>' . $this->getDescription() . '</p>'
            );

            /* Draw the map */
            $sims['###MAP###'] = $map->drawMap();
        }
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     * @param $view
     */
    public function getCountryMarker(& $template, & $sims, & $rems, & $wrapped, $view)
    {
        $this->initLocalCObject();
        if (ExtensionManagementUtility::isLoaded('static_info_tables')) {
            $staticInfo = Functions::makeInstance('SJBR\\StaticInfoTables\\PiBaseApi');
            $staticInfo->init();
            $current = LocalizationUtility::translate(
                ['uid' => $this->getCountry()],
                'static_countries',
                false
            );
            $this->local_cObj->setCurrentVal($current);
            $sims['###COUNTRY###'] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['countryStaticInfo'],
                $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['countryStaticInfo.']
            );
        } else {
            $current = $this->getCountry();
            $this->local_cObj->setCurrentVal($current);
            $sims['###COUNTRY###'] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['country'],
                $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['country.']
            );
        }
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     * @param $view
     */
    public function getCountryZoneMarker(& $template, & $sims, & $rems, & $wrapped, $view)
    {
        $this->initLocalCObject();
        if (ExtensionManagementUtility::isLoaded('static_info_tables')) {
            $staticInfo = Functions::makeInstance('SJBR\\StaticInfoTables\\PiBaseApi');
            $staticInfo->init();
            $current = LocalizationUtility::translate(
                ['uid' => $this->getCountryzone()],
                'static_country_zones',
                false
            );
            $this->local_cObj->setCurrentVal($current);
            $sims['###COUNTRYZONE###'] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['countryzoneStaticInfo'],
                $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['countryzoneStaticInfo.']
            );
        } else {
            $current = $this->getCountryzone();
            $this->local_cObj->setCurrentVal($current);
            $sims['###COUNTRYZONE###'] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['countryzone'],
                $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['countryzone.']
            );
        }
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     * @param $view
     */
    public function getLocationLinkMarker(& $template, & $sims, & $rems, & $wrapped, $view)
    {
        $wrapped['###LOCATION_LINK###'] = explode('$5&xs2', $this->getLinkToLocation('$5&xs2'));
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     * @param $view
     */
    public function getOrganizerLinkMarker(& $template, & $sims, & $rems, & $wrapped, $view)
    {
        $wrapped['###ORGANIZER_LINK###'] = explode('$5&xs2', $this->getLinkToOrganizer('$5&xs2'));
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     * @param $view
     */
    public function getEditLinkMarker(& $template, & $sims, & $rems, & $wrapped, $view)
    {
        $sims['###EDIT_LINK###'] = '';
        if ($this->isUserAllowedToEdit()) {
            $linkConf = $this->getValuesAsArray();
            if ($this->conf['view.']['enableAjax']) {
                $temp = sprintf(
                    $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['editLinkOnClick'],
                    $this->getUid(),
                    $this->getType()
                );
                $linkConf['link_ATagParams'] = ' onclick="' . $temp . '"';
            }
            $this->initLocalCObject($linkConf);
            $this->controller->getParametersForTyposcriptLink(
                $this->local_cObj->data,
                [
                'view' => 'edit_' . $this->getObjectType(),
                'type' => $this->getType(),
                'uid' => $this->getUid()
            ],
                $this->conf['cache'],
                $this->conf['clear_anyway'],
                $this->conf['view.'][$this->getObjectType() . '.']['edit' . ucwords($this->getObjectType()) . 'ViewPid']
            );
            $this->local_cObj->setCurrentVal($this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['editIcon']); // controller->pi_getLL('l_edit_'.$this->getObjectType())
            $sims['###EDIT_LINK###'] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['editLink'],
                $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['editLink.']
            );
        }
        if ($this->isUserAllowedToDelete()) {
            $linkConf = $this->getValuesAsArray();
            if ($this->conf['view.']['enableAjax']) {
                $temp = sprintf(
                    $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['deleteLinkOnClick'],
                    $this->getUid(),
                    $this->getType()
                );
                $linkConf['link_ATagParams'] = ' onclick="' . $temp . '"';
            }
            $this->controller->getParametersForTyposcriptLink(
                $this->local_cObj->data,
                [
                'view' => 'delete_' . $this->getObjectType(),
                'type' => $this->getType(),
                'uid' => $this->getUid()
            ],
                $this->conf['cache'],
                $this->conf['clear_anyway'],
                $this->conf['view.'][$this->getObjectType() . '.']['delete' . ucwords($this->getObjectType()) . 'ViewPid']
            );
            $this->initLocalCObject($linkConf);
            $this->local_cObj->setCurrentVal($this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['deleteIcon']); // controller->pi_getLL('l_delete_'.$this->getObjectType())
            $sims['###EDIT_LINK###'] .= $this->local_cObj->cObjGetSingle(
                $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['deleteLink'],
                $this->conf['view.'][$this->conf['view'] . '.'][$this->getObjectType() . '.']['deleteLink.']
            );
        }
    }

    /**
     * @param $key
     * @param $link
     */
    public function addEventLink($key, $link)
    {
        $this->eventLinks[$key] = $link;
    }

    /**
     * @return array
     */
    public function getEventLinks()
    {
        return $this->eventLinks;
    }

    /**
     * @param $linktext
     * @return string
     */
    public function getLinkToOrganizer($linktext)
    {
        return $this->getLinkToLocation($linktext);
    }

    /**
     * @param $linktext
     * @return string
     */
    public function getLinkToLocation($linktext)
    {
        if ($linktext == '') {
            $linktext = 'no title';
        }
        $rightsObj = &\TYPO3\CMS\Cal\Utility\Registry::Registry('basic', 'rightscontroller');
        if ($rightsObj->isViewEnabled($this->conf['view.'][$this->getObjectType() . 'LinkTarget']) || $this->conf['view.'][$this->getObjectType() . '.'][$this->getObjectType() . 'ViewPid']) {
            return $this->controller->pi_linkTP_keepPIvars(
                $linktext,
                [
                'view' => $this->getObjectType(),
                'uid' => $this->getUid(),
                'type' => $this->getType()
            ],
                $this->conf['cache'],
                $this->conf['clear_anyway'],
                $this->conf['view.'][$this->getObjectType() . '.'][$this->getObjectType() . 'ViewPid']
            );
        }
        return $linktext;
    }

    /**
     * @param $piVars
     */
    public function updateWithPIVars(&$piVars)
    {
        $modelObj = &\TYPO3\CMS\Cal\Utility\Registry::Registry('basic', 'modelController');
        $cObj = &$this->controller->cObj;

        foreach ($piVars as $key => $value) {
            switch ($key) {
                case 'uid':
                    $this->setUid(intval($piVars['uid']));
                    unset($piVars['uid']);
                    break;
                case 'hidden':
                    $this->setHidden(intval($piVars['hidden']));
                    unset($piVars['hidden']);
                    break;
                case 'name':
                    $this->setName(strip_tags($piVars['name']));
                    unset($piVars['name']);
                    break;
                case 'description':
                    $this->setDescription($cObj->removeBadHTML($piVars['description'], []));
                    unset($piVars['description']);
                    break;
                case 'street':
                    $this->setStreet(strip_tags($piVars['street']));
                    unset($piVars['street']);
                    break;
                case 'zip':
                    $this->setZip(strip_tags($piVars['zip']));
                    unset($piVars['zip']);
                    break;
                case 'city':
                    $this->setCity(strip_tags($piVars['city']));
                    unset($piVars['city']);
                    break;
                case 'phone':
                    $this->setPhone(strip_tags($piVars['phone']));
                    unset($piVars['phone']);
                    break;
                case 'fax':
                    $this->setFax(strip_tags($piVars['fax']));
                    unset($piVars['fax']);
                    break;
                case 'email':
                    $this->setEmail(strip_tags($piVars['email']));
                    unset($piVars['email']);
                    break;
                case 'image':
                    foreach ((array)$piVars['image'] as $image) {
                        $this->addImage(strip_tags($image));
                    }
                    unset($piVars['image']);
                    break;
                case 'country':
                    $this->setCountry(strip_tags($piVars['country']));
                    unset($piVars['country']);
                    break;
                case 'country_static_info':
                    $this->setCountry(strip_tags($piVars['country_static_info']));
                    unset($piVars['country_static_info']);
                    break;
                case 'countryzone':
                    $this->setCountryZone(strip_tags($piVars['countryzone']));
                    unset($piVars['countryzone']);
                    break;
                case 'countryzone_static_info':
                    $this->setCountryZone(strip_tags($piVars['countryzone_static_info']));
                    unset($piVars['countryzone_static_info']);
                    break;
                case 'link':
                    $this->setLink(strip_tags($piVars['link']));
                    unset($piVars['link']);
                    break;
                case 'longitude':
                    $this->setLongitude(strip_tags($piVars['longitude']));
                    unset($piVars['longitude']);
                    break;
                case 'latitude':
                    $this->setLatitude(strip_tags($piVars['latitude']));
                    unset($piVars['latitude']);
                    break;
                case 'shared':
                case 'shared_ids':
                    $this->setSharedGroups([]);
                    $this->setSharedUsers([]);
                    $values = $piVars[$key];
                    if (!is_array($piVars[$key])) {
                        $values = GeneralUtility::trimExplode(',', $piVars[$key], 1);
                    }
                    foreach ($values as $entry) {
                        preg_match('/(^[a-z])_([0-9]+)/', $entry, $idname);
                        if ($idname[1] == 'u') {
                            $this->addSharedUser($idname[2]);
                        } elseif ($idname[1] == 'g') {
                            $this->addSharedGroup($idname[2]);
                        }
                    }
                    break;
            }
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return get_class($this) . ': ' . implode(', ', $this->row);
    }

    /**
     * @param $id
     */
    public function addSharedUser($id)
    {
        $this->sharedUsers[] = $id;
    }

    /**
     * @param $id
     */
    public function addSharedGroup($id)
    {
        $this->sharedGroups[] = $id;
    }

    /**
     * @return array
     */
    public function getSharedUsers()
    {
        return $this->sharedUsers;
    }

    /**
     * @return array
     */
    public function getSharedGroups()
    {
        return $this->sharedGroups;
    }

    /**
     * @param $userIds
     */
    public function setSharedUsers($userIds)
    {
        $this->sharedUsers = $userIds;
    }

    /**
     * @param $groupIds
     */
    public function setSharedGroups($groupIds)
    {
        $this->sharedGroups = $groupIds;
    }

    /**
     * @param $userId
     * @param $groupIdArray
     * @return bool
     */
    public function isSharedUser($userId, $groupIdArray)
    {
        if (is_array($this->getSharedUsers()) && in_array($userId, $this->getSharedUsers())) {
            return true;
        }
        foreach ($groupIdArray as $id) {
            if (is_array($this->getSharedGroups()) && in_array($id, $this->getSharedGroups())) {
                return true;
            }
        }

        return false;
    }
}
