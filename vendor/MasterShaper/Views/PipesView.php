<?php

/**
 *
 * This file is part of MasterShaper.

 * MasterShaper, a web application to handle Linux's traffic shaping
 * Copyright (C) 2015 Andreas Unterkircher <unki@netshadow.net>

 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.

 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.

 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace MasterShaper\Views;

class PipesView extends DefaultView
{
    protected static $view_default_mode = 'list';
    protected static $view_class_name = 'pipes';
    private $pipes;

    public function __construct()
    {
        try {
            $this->pipes = new \MasterShaper\Models\PipesModel;
        } catch (\Exception $e) {
            $this->raiseError(__METHOD__ .'(), failed to load PipesModel!', false, $e);
            return false;
        }

        parent::__construct();
    }

    public function showList($pageno = null, $items_limit = null)
    {
        global $session, $tmpl;

        if (!isset($pageno) || empty($pageno) || !is_numeric($pageno)) {
            if (($current_page = $this->getSessionVar("current_page")) === false) {
                $current_page = 1;
            }
        } else {
            $current_page = $pageno;
        }

        if (!isset($items_limit) || is_null($items_limit) || !is_numeric($items_limit)) {
            if (($current_items_limit = $this->getSessionVar("current_items_limit")) === false) {
                $current_items_limit = -1;
            }
        } else {
            $current_items_limit = $items_limit;
        }

        if (!$this->pipes->hasItems()) {
            return parent::showList();
        }

        try {
            $pager = new \MasterShaper\Controllers\PagingController(array(
                'delta' => 2,
            ));
        } catch (\Exception $e) {
            $this->raiseError(__METHOD__ .'(), failed to load PagingController!', false, $e);
            return false;
        }

        if (!$pager->setPagingData($this->pipes->getItems())) {
            $this->raiseError(get_class($pager) .'::setPagingData() returned false!');
            return false;
        }

        if (!$pager->setCurrentPage($current_page)) {
            $this->raiseError(get_class($pager) .'::setCurrentPage() returned false!');
            return false;
        }

        if (!$pager->setItemsLimit($current_items_limit)) {
            $this->raiseError(get_class($pager) .'::setItemsLimit() returned false!');
            return false;
        }

        global $tmpl;
        $tmpl->assign('pager', $pager);

        if (($data = $pager->getPageData()) === false) {
            $this->raiseError(get_class($pager) .'::getPageData() returned false!');
            return false;
        }

        if (!isset($data) || empty($data) || !is_array($data)) {
            $this->raiseError(get_class($pager) .'::getPageData() returned invalid data!');
            return false;
        }

        $this->avail_items = array_keys($data);
        $this->items = $data;

        if (!$this->setSessionVar("current_page", $current_page)) {
            $this->raiseError(get_class($session) .'::setVariable() returned false!');
            return false;
        }

        if (!$this->setSessionVar("current_items_limit", $current_items_limit)) {
            $this->raiseError(get_class($session) .'::setVariable() returned false!');
            return false;
        }

        return parent::showList();

    } // showList()

    public function pipesList($params, $content, &$smarty, &$repeat)
    {
        $index = $smarty->getTemplateVars('smarty.IB.item_list.index');

        if (!isset($index) || empty($index)) {
            $index = 0;
        }

        if (!isset($this->avail_items) || empty($this->avail_items)) {
            $repeat = false;
            return $content;
        }

        if ($index >= count($this->avail_items)) {
            $repeat = false;
            return $content;
        }

        $item_idx = $this->avail_items[$index];
        $item =  $this->items[$item_idx];

        $smarty->assign("item", $item);

        $index++;
        $smarty->assign('smarty.IB.item_list.index', $index);
        $repeat = true;

        return $content;
    }

    public function showEdit($id, $guid)
    {
        global $tmpl;

        try {
            $item = new \MasterShaper\Models\PipeModel(array(
                'idx' => $id,
                'guid' => $guid
            ));
        } catch (\Exception $e) {
            $this->raiseError(__METHOD__ .'(), failed to load PipeModel!', false, $e);
            return false;
        }

        $tmpl->registerPlugin(
            "function",
            "unused_filters_select_list",
            array(&$this, "smartyUnusedFiltersSelectList"),
            false
        );
        $tmpl->registerPlugin(
            "function",
            "used_filters_select_list",
            array(&$this, "smartyUsedFiltersSelectList"),
            false
        );

        $tmpl->assign('pipe', $item);
        return parent::showEdit($id, $guid);
    }

    public function smartyUnusedFiltersSelectList($params, &$smarty)
    {
        if (!array_key_exists('pipe_idx', $params)) {
            static::raiseError("smartyUnusedFiltersSelectList: missing 'pipe_idx' parameter");
            $repeat = false;
            return;
        }

        global $db;

        if (!isset($params['pipe_idx'])) {
            $sth = $db->query(
                "SELECT
                    filter_idx, filter_name
                FROM
                    TABLEPREFIXfilters
                ORDER BY
                    filter_name"
            );
        } else {
            $sth = $db->prepare(
                "SELECT DISTINCT
                    f.filter_idx, f.filter_name
                FROM
                    TABLEPREFIXfilters f
                LEFT OUTER JOIN (
                    SELECT DISTINCT
                        apf_filter_idx, apf_pipe_idx
                    FROM
                        TABLEPREFIXassign_filters_to_pipes
                    WHERE
                        apf_pipe_idx LIKE ?
                    ) apf
                ON
                    apf.apf_filter_idx=f.filter_idx
                WHERE
                    apf.apf_pipe_idx IS NULL"
            );

            $db->execute($sth, array(
                $params['pipe_idx']
            ));
        }

        $string = "";
        while ($filter = $sth->fetch()) {
            $string.= "<option value=\"". $filter->filter_idx ."\">". $filter->filter_name ."</option>\n";
        }

        $db->freeStatement($sth);

        return $string;

    } // smartyUnusedFiltersSelectList()

    public function smartyUsedFiltersSelectList($params, &$smarty)
    {
        if (!array_key_exists('pipe_idx', $params)) {
            static::raiseError("smartyUsedFiltersSelectList: missing 'pipe_idx' parameter");
            $repeat = false;
            return;
        }

        global $db;

        $sth = $db->prepare(
            "SELECT DISTINCT
                f.filter_idx,
                f.filter_name
            FROM
                TABLEPREFIXfilters f
            INNER JOIN (
                SELECT
                    apf_filter_idx
                FROM
                    TABLEPREFIXassign_filters_to_pipes
                WHERE
                    apf_pipe_idx LIKE ?
                ) apf
            ON
                apf.apf_filter_idx=f.filter_idx"
        );

        $db->execute($sth, array(
            $params['pipe_idx']
        ));

        $string = "";
        while ($filter = $sth->fetch()) {
            $string.= "<option value=\"". $filter->filter_idx ."\">". $filter->filter_name ."</option>\n";
        }

        $db->freeStatement($sth);

        return $string;

    } // smarty_used_filters_select_list()
}

// vim: set filetype=php expandtab softtabstop=4 tabstop=4 shiftwidth=4:
