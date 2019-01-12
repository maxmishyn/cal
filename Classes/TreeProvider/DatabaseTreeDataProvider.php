<?php

namespace TYPO3\CMS\Cal\TreeProvider;

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
use TYPO3\CMS\Backend\Tree\SortedTreeNodeCollection;
use TYPO3\CMS\Backend\Tree\TreeNode;
use TYPO3\CMS\Backend\Tree\TreeNodeCollection;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Tree\TableConfiguration\DatabaseTreeNode;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * TCA tree data provider which considers
 */
class DatabaseTreeDataProvider extends \TYPO3\CMS\Core\Tree\TableConfiguration\DatabaseTreeDataProvider
{

    /**
     * @var BackendUserAuthentication
     */
    protected $backendUserAuthentication;

    protected $parentRow;

    protected $table;

    protected $field;

    protected $currentValue;

    protected $conf;

    protected $calConfiguration;

    const CALENDAR_PREFIX = 'calendar_';
    const GLOBAL_PREFIX = 'global';

    /**
     * Required constructor
     *
     * @param array $configuration TCA configuration
     * @param $table
     * @param $field
     * @param $currentValue
     */
    public function __construct(array $configuration, $table, $field, $currentValue)
    {
        $this->table = $table;
        $this->field = $field;
        $this->conf = $configuration;
        $this->currentValue = $currentValue;
        $this->backendUserAuthentication = $GLOBALS['BE_USER'];
        $this->calConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['cal']);
    }

    /**
     * Sets the list for selected nodes
     *
     * @param string $selectedList
     */
    public function setSelectedList($selectedList)
    {
        // During initialization the first set contains the parent object row.
        // Where as the second call really fills the correct values.
        if ($this->selectedList == '') {
            $this->parentRow = $selectedList;
        }
        $this->selectedList = $selectedList;
    }

    /**
     * @param $level
     * @param $parentChildNodes
     */
    protected function appendGlobalCategories($level, $parentChildNodes)
    {
        $node = GeneralUtility::makeInstance(TreeNode::class);
        $node->setId(GLOBAL_PREFIX);

        $childNodes = GeneralUtility::makeInstance(TreeNodeCollection::class);

        $where = 'l18n_parent = 0 and deleted = 0 and parent_category = 0 and calendar_id = 0';
        $this->appendCategories($level, $childNodes, $where);
        if ($childNodes !== null) {
            $node->setChildNodes($childNodes);
        }

        $parentChildNodes->append($node);
    }

    /**
     * @param $level
     * @param $childNodes
     */
    protected function appendCalendarCategories($level, $childNodes)
    {
        $calendarId = 0;
        if (isset($this->currentValue['calendar_id'])) {
            $calendarId = $this->currentValue['calendar_id'];
        }
        if ($calendarId > 0) {
            $calres = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
                'tx_cal_calendar.uid, tx_cal_calendar.title',
                'tx_cal_calendar',
                $this->getCalendarWhere($calendarId)
            );
            if ($calres) {
                while ($calrow = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($calres)) {
                    $node = GeneralUtility::makeInstance(TreeNode::class);
                    $node->setId(CALENDAR_PREFIX . $calrow['uid']);

                    if ($level < $this->levelMaximum) {
                        $where = 'l18n_parent = 0 and tx_cal_category.deleted = 0 and tx_cal_category.calendar_id = ' . $calrow['uid'];
                        $calendarChildNodes = GeneralUtility::makeInstance(TreeNodeCollection::class);

                        $this->appendCategories($level + 1, $calendarChildNodes, $where);
                        if ($calendarChildNodes !== null) {
                            $node->setChildNodes($calendarChildNodes);
                        }
                    }
                    $childNodes->append($node);
                }
                $GLOBALS['TYPO3_DB']->sql_free_result($calres);
            }
        }
    }

    /**
     * @param $level
     * @param $childNodes
     * @param $where
     */
    protected function appendCategories($level, $childNodes, $where)
    {
        $categoryResult = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
            'tx_cal_category.uid, tx_cal_category.title',
            'tx_cal_category',
            $where
        );
        $usedCategories = [];
        if ($categoryResult) {
            while ($categoryRow = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($categoryResult)) {
                $categoryNode = GeneralUtility::makeInstance(TreeNode::class);
                $categoryNode->setId($categoryRow['uid']);
                if ($level < $this->levelMaximum) {
                    $children = $this->getChildrenOf($categoryNode, $level + 1);
                    if ($children !== null) {
                        foreach ($children as $child) {
                            $usedCategories[$child->getId()] = true;
                        }
                        $categoryNode->setChildNodes($children);
                    }
                }
                if (!$usedCategories[$categoryRow['uid']]) {
                    $usedCategories[$categoryRow['uid']] = true;
                    $childNodes->append($categoryNode);
                }
            }
            $GLOBALS['TYPO3_DB']->sql_free_result($categoryResult);
        }
    }

    /**
     * @param $calendarId
     * @return string
     */
    protected function getCalendarWhere($calendarId)
    {
        $calWhere = 'l18n_parent = 0  AND tx_cal_calendar.uid = ' . $calendarId;

        if ((TYPO3_MODE == 'BE') || ($GLOBALS['TSFE']->beUserLogin && $GLOBALS['BE_USER']->extAdmEnabled)) {
            $calWhere .= BackendUtility::BEenableFields('tx_cal_calendar') . ' AND tx_cal_calendar.deleted = 0';
        }
        return $calWhere;
    }

    /**
     * Builds a complete node including children
     *
     * @param TreeNode|TreeNode $basicNode
     * @param DatabaseTreeNode|null $parent
     * @param int $level
     * @param bool $restriction
     * @return DatabaseTreeNode node
     */
    protected function buildRepresentationForNode(
        TreeNode $basicNode,
        DatabaseTreeNode $parent = null,
        $level = 0,
        $restriction = false
    ) {
        /**@param $node DatabaseTreeNode */
        $node = GeneralUtility::makeInstance(DatabaseTreeNode::class);
        $row = [];
        $node->setSelected(false);
        $node->setExpanded(true);
        $node->setSelectable(false);

        if (strrpos($basicNode->getId(), CALENDAR_PREFIX, -strlen($basicNode->getId())) !== false) {
            $id = intval(substr($basicNode->getId(), strlen(CALENDAR_PREFIX)));
            $row = BackendUtility::getRecordWSOL('tx_cal_calendar', $id, '*', '', false);
            $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
            $icon = $iconFactory->getIconForRecord('tx_cal_calendar', $row, Icon::SIZE_SMALL);
            $node->setIcon($icon);
            $node->setLabel($row['title']);
            $node->setSortValue($id);
        } elseif ($basicNode->getId() === GLOBAL_PREFIX) {
            $node->setLabel($GLOBALS['LANG']->sL('LLL:EXT:cal/Resources/Private/Language/locallang_db.xml:tx_cal_category.global'));
            $node->setSortValue(0);
        } elseif ($basicNode->getId() == 0) {
            $node->setLabel($GLOBALS['LANG']->sL($GLOBALS['TCA'][$this->tableName]['ctrl']['title']));
        } else {
            $row = BackendUtility::getRecordWSOL($this->tableName, $basicNode->getId(), '*', '', false);

            if ($this->getLabelField() !== '') {
                $node->setLabel($row[$this->getLabelField()]);
            } else {
                $node->setLabel($basicNode->getId());
            }
            $node->setSelected(GeneralUtility::inList($this->getSelectedList(), $basicNode->getId()));
            $node->setExpanded($this->isExpanded($basicNode));
            $node->setLabel($node->getLabel());
            $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
            $icon = $iconFactory->getIconForRecord($this->tableName, $row, Icon::SIZE_SMALL);
            $node->setIcon($icon);
            $node->setSelectable(!GeneralUtility::inList(
                    $this->getNonSelectableLevelList(),
                    $level
                ) && !in_array($basicNode->getId(), $this->getItemUnselectableList()));
            $node->setSortValue($this->nodeSortValues[$basicNode->getId()]);
        }

        $node->setId($basicNode->getId());

        // Break to force single category activation
        if ($parent != null && $level != 0 && $this->isSingleCategoryAclActivated() && !$this->isCategoryAllowed($node)) {
            return null;
        }

        $node->setParentNode($parent);
        if ($basicNode->hasChildNodes()) {

            /** @var SortedTreeNodeCollection $childNodes */
            $childNodes = GeneralUtility::makeInstance(SortedTreeNodeCollection::class);
            $foundSomeChild = false;
            foreach ($basicNode->getChildNodes() as $child) {
                // Change in custom TreeDataProvider by adding the if clause
                if ($restriction || $this->isCategoryAllowed($child)) {
                    $returnedChild = $this->buildRepresentationForNode($child, $node, $level + 1, $restriction);

                    if (!is_null($returnedChild)) {
                        $foundSomeChild = true;
                        $childNodes->append($returnedChild);
                    } else {
                        $node->setParentNode(null);
                        $node->setHasChildren(false);
                    }
                }
                // Change in custom TreeDataProvider end
            }

            if ($foundSomeChild) {
                $node->setHasChildren(true);
                $node->setChildNodes($childNodes);
            }
        }
        return $node;
    }

    /**
     * Check if given category is allowed by the access rights
     *
     * @param TreeNode $child
     * @return bool
     */
    protected function isCategoryAllowed($child)
    {
        $mounts = $this->backendUserAuthentication->getCategoryMountPoints();
        if (empty($mounts)) {
            return true;
        }

        return in_array($child->getId(), $mounts);
    }

    /**
     * @return bool
     */
    protected function isSingleCategoryAclActivated()
    {
        return false;
    }
}
