<?php

/**

 * This file is part of MasterShaper.

 * MasterShaper, a web application to handle Linux's traffic shaping
 * Copyright (C) 2007-2016 Andreas Unterkircher <unki@netshadow.net>

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

namespace MasterShaper\Models;

class NetworkInterfaceModel extends DefaultModel
{
    protected static $model_table_name = 'interfaces';
    protected static $model_column_prefix = 'if';
    protected static $model_fields = array(
        'idx' => array(
            FIELD_TYPE => FIELD_INT,
        ),
        'guid' => array(
            FIELD_TYPE => FIELD_GUID,
        ),
        'name' => array(
            FIELD_TYPE => FIELD_STRING,
        ),
        'speed' => array(
            FIELD_TYPE => FIELD_STRING,
        ),
        'fallback_idx' => array(
            FIELD_TYPE => FIELD_INT,
            FIELD_DEFAULT => 0,
        ),
        'ifb' => array(
            FIELD_TYPE => FIELD_YESNO,
        ),
        'active' => array(
            FIELD_TYPE => FIELD_YESNO,
            FIELD_DEFAULT => 'Y',
        ),
        'host_idx' => array(
            FIELD_TYPE => FIELD_INT,
        ),
    );

    protected function __init()
    {
        $this->permitRpcUpdates(true);
        $this->addRpcAction('delete');
        $this->addRpcEnabledField('name');
        return true;
    }

    public function preSave()
    {
        global $session;

        if (isset($this->if_host_idx) && !empty($this->if_host_idx)) {
            return true;
        }

        if (($host_idx = $session->getCurrentHostProfile()) === false) {
            $this->raiseError(get_class($session) .'::getCurrentHostProfile() returned false!');
            return false;
        }
    
        $this->if_host_idx = $host_idx;
        return true;
    }
}

// vim: set filetype=php expandtab softtabstop=4 tabstop=4 shiftwidth=4: