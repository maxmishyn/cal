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
use TYPO3\CMS\Cal\Utility\Functions;
use TYPO3\CMS\Cal\Utility\Registry;

/**
 * A concrete model for the calendar.
 */
class TodoModel extends EventModel
{
    /**
     * TodoModel constructor.
     * @param $row
     * @param $serviceKey
     */
    public function __construct($row, $serviceKey)
    {
        parent::__construct($row, false, $serviceKey);
        $this->setEventType(Model::EVENT_TYPE_TODO);
        $this->setType($serviceKey);
        $this->setObjectType('todo');
    }

    /**
     * @param $row
     * @param $isException
     */
    public function createEvent(&$row, $isException)
    {
        parent::createEvent($row, $isException);
        $this->setStatus($row['status']);
        $this->setPriority($row['priority']);
        $this->setCompleted($row['completed']);
    }

    /**
     * @return string|processed
     */
    public function renderEvent()
    {
        return $this->fillTemplate('###TEMPLATE_TODO###');
    }

    /**
     * @return string|processed
     */
    public function renderTodo()
    {
        return $this->renderEvent();
    }

    /**
     * @param $viewType
     * @return string|processed
     */
    public function renderTodoFor($viewType)
    {
        return $this->renderEventFor($viewType);
    }

    /**
     * @param $viewType
     * @return string|processed
     */
    public function renderEventFor($viewType)
    {
        if ($this->conf['view.']['freeAndBusy.']['enable'] == 1) {
            $viewType .= '_FNB';
        }
        // Need to check if _ALLDAY is already in viewType since handling changed from classic to new standard rendering
        if (($this->isAllday()) && (strpos($viewType, '_ALLDAY') < 1)) {
            $viewType .= '_ALLDAY';
        }
        return $this->fillTemplate('###TEMPLATE_TODO_' . strtoupper($viewType) . '###');
    }

    /**
     * @return string|processed
     */
    public function renderEventPreview()
    {
        $this->isPreview = true;
        return $this->fillTemplate('###TEMPLATE_TODO_PREVIEW###');
    }

    /**
     * @return string|processed
     */
    public function renderTodoPreview()
    {
        return $this->renderEventPreview();
    }

    /**
     * @return string|processed
     */
    public function renderTomorrowsEvent()
    {
        $this->isTomorrow = true;
        return $this->fillTemplate('###TEMPLATE_TODO_TOMORROW###');
    }

    /**
     * @return string|processed
     */
    public function renderTomorrowsTodo()
    {
        return $this->renderTomorrowsEvent();
    }

    /**
     * @param $subpartMarker
     * @return string|processed
     */
    public function fillTemplate($subpartMarker)
    {
        $cObj = &Registry::Registry('basic', 'cobj');
        $confArr = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['cal']);
        $page = '';
        if ($confArr['todoSubtype'] == 'event') {
            $resourcePath = $this->conf['view.']['todo.']['todoInlineModelTemplate'];
        } else {
            $resourcePath = $this->conf['view.']['todo.']['todoSeparateModelTemplate'];
        }
        $page = Functions::getContent($resourcePath);
        if ($page == '') {
            return '<h3>calendar: no todo model template file found:</h3>' . $resourcePath;
        }
        $page = $cObj->getSubpart($page, $subpartMarker);
        if (!$page) {
            return 'could not find the >' . str_replace(
                '###',
                '',
                    $subpartMarker
            ) . '< subpart-marker in ' . $resourcePath;
        }
        $rems = [];
        $sims = [];
        $wrapped = [];
        $this->getMarker($page, $sims, $rems, $wrapped, $this->conf['view']);
        return $this->finish(Functions::substituteMarkerArrayNotCached(
            $page,
            $sims,
            $rems,
            $wrapped
        ));
    }

    /**
     * @param $template
     * @param $sims
     * @param $rems
     * @param $wrapped
     * @param $view
     */
    public function getStatusMarker(& $template, & $sims, & $rems, & $wrapped, $view)
    {
        $sims['###STATUS###'] = '';
        if ($this->getEventType() == Model::EVENT_TYPE_TODO) {
            $this->initLocalCObject($this->getValuesAsArray());
            $this->local_cObj->setCurrentVal($this->getStatus());
            $sims['###STATUS###'] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.'][$view . '.']['todo.']['status'],
                $this->conf['view.'][$view . '.']['todo.']['status.']
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
    public function getPriorityMarker(& $template, & $sims, & $rems, & $wrapped, $view)
    {
        $sims['###PRIORITY###'] = '';
        if ($this->getEventType() == Model::EVENT_TYPE_TODO) {
            $this->initLocalCObject($this->getValuesAsArray());
            $this->local_cObj->setCurrentVal($this->getPriority());
            $sims['###PRIORITY###'] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.'][$view . '.']['todo.']['priority'],
                $this->conf['view.'][$view . '.']['todo.']['priority.']
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
    public function getCompletedMarker(& $template, & $sims, & $rems, & $wrapped, $view)
    {
        $sims['###COMPLETED###'] = '';
        if ($this->getEventType() == Model::EVENT_TYPE_TODO) {
            $this->initLocalCObject($this->getValuesAsArray());
            $this->local_cObj->setCurrentVal($this->getCompleted());
            $sims['###COMPLETED###'] = $this->local_cObj->cObjGetSingle(
                $this->conf['view.'][$view . '.']['todo.']['completed'],
                $this->conf['view.'][$view . '.']['todo.']['completed.']
            );
        }
    }

    /**
     * @param $piVars
     */
    public function updateWithPIVars(&$piVars)
    {
        $this->setStatus($piVars['status']);
        $this->setPriority($piVars['priority']);
        $this->setCompleted($piVars['completed']);
        parent::updateWithPIVars($piVars);
    }
}
