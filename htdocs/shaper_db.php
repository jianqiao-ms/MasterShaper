<?php

define('VERSION', '0.60');
define('SCHEMA_VERSION', '2');

/***************************************************************************
 *
 * Copyright (c) by Andreas Unterkircher, unki@netshadow.at
 * All rights reserved
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 *
 ***************************************************************************/

/* from pear "MDB2" package. use "pear install MDB2" if you don't have this! */
// require_once('MDB2.php');

class MASTERSHAPER_DB {

   private $db;
   private $parent;
   private $is_connected;
   private $last_error;
   /* the _real_ schema version is defined as constant */
   /* that one just holds the "current" version number */
   /* during upgrades.                                 */
   private $schema_version;

   /**
    * MASTERSHAPER_DB class constructor
    *
    * This constructor initially connect to the database.
    */
   public function __construct($parent)
   {
      $this->parent = $parent;

      /* We are starting disconnected */
      $this->setConnStatus(false);

      /* Connect to MySQL Database */
      $this->db_connect();

   } // __construct()
	 
   /**
    * MASTERSHAPER_DB class deconstructor
    *
    * This destructor will close the current database connection.
    */ 
   public function __destruct()
   {
      if($this->getConnStatus())
         $this->db_disconnect();

   } // _destruct()

   /**
    * MASTERSHAPER_DB database connect
    *
    * This function will connect to the database via MDB2
    */
   private function db_connect()
   {
      $options = array(
         'debug' => 2,
         'portability' => 'DB_PORTABILITY_ALL'
      );

      if(!defined('MYSQL_USER') ||
         !defined('MYSQL_PASS') ||
         !defined('MYSQL_HOST') ||
         !defined('MYSQL_DB')) {

         $this->parent->throwError("Missing MySQL configuration");
      }

      $dsn = "mysql://". MYSQL_USER .":". MYSQL_PASS ."@". MYSQL_HOST ."/". MYSQL_DB;
      $this->db = MDB2::connect($dsn, $options);

      if(PEAR::isError($this->db)) {
         $this->parent->throwError("Unable to connect to database: ". $this->db->getMessage() .' - '. $this->db->getUserInfo());
         $this->setConnStatus(false);
      }

      $this->setConnStatus(true);

   } // db_connect()

   /**
    * MASTERSHAPER_DB database disconnect
    *
    * This function will disconnected an established database connection.
    */
   private function db_disconnect()
   {
      $this->db->disconnect();

   } // db_disconnect()

   /**
    * MASTERSHAPER_DB database query
    *
    * This function will execute a SQL query and return the result as
    * object.
    */
   public function db_query($query = "", $mode = MDB2_FETCHMODE_OBJECT)
   {
      if($this->getConnStatus()) {

         $this->db->setFetchMode($mode);

         /* for manipulating queries use exec instead of query. can save
          * some resource because nothing has to be allocated for results.
          */
         if(preg_match('/^(update|insert)i/', $query)) {
            $result = $this->db->exec($query);
         }
         else {
            $result = $this->db->query($query);
         }
			
         if(PEAR::isError($result))
            $this->parent->throwError($result->getMessage() .' - '. $result->getUserInfo());
	
         return $result;
      }
      else 
         $this->parent->throwError("Can't execute query - we are not connected!");

   } // db_query()

   /**
    * MASTERSHAPER_DB fetch ONE row
    *
    * This function will execute the given but only return the
    * first result.
    */
   public function db_fetchSingleRow($query = "", $mode = MDB2_FETCHMODE_OBJECT)
   {
      if($this->getConnStatus()) {

         $row = $this->db->queryRow($query, array(), $mode);

         if(PEAR::isError($row))
            $this->parent->throwError($row->getMessage() .' - '. $row->getUserInfo());

         return $row;
	
      }
      else {
   
         $this->parent->throwError("Can't fetch row - we are not connected!");
      
      }
      
   } // db_fetchSingleRow()

   /**
    * MASTERSHAPER_DB number of affected rows
    *
    * This functions returns the number of affected rows but the
    * given SQL query.
    */
   public function db_getNumRows($query = "")
   {
      /* Execute query */
      $result = $this->db_query($query);

      /* Errors? */
      if(PEAR::isError($result)) 
         $this->parent->throwError($result->getMessage() .' - '. $result->getUserInfo());

      return $result->numRows();

   } // db_getNumRows()

   /**
    * MASTERSHAPER_DB get primary key
    *
    * This function returns the primary key of the last
    * operated insert SQL query.
    */
   public function db_getid()
   {
      /* Get the last primary key ID from execute query */
      return mysql_insert_id($this->db->connection);
      
   } // db_getid()

   /**
    * MASTERSHAPER_DB check table exists
    *
    * This function checks if the given table exists in the
    * database
    * @param string, table name
    * @return true if table found otherwise false
    */
   public function db_check_table_exists($table_name = "")
   {
      if($this->getConnStatus()) {
         $result = $this->db_query("SHOW TABLES");
         $tables_in = "Tables_in_". MYSQL_DB;
	
         while($row = $result->fetchRow()) {
            if($row->$tables_in == $table_name)
               return true;
         }
         return false;
      }
      else
         $this->parent->throwError("Can't check table - we are not connected!");
	 
   } // db_check_table_exists()

   /**
    * MASTERSHAPER_DB rename table
    * 
    * This function will rename an database table
    * @param old_name, new_name
    */
   public function db_rename_table($old, $new)
   {
      if($this->db_check_table_exists($old)) {
         if(!$this->db_check_table_exists($new))
            $this->db_query("RENAME TABLE ". $old ." TO ". $new);
         else
            $this->parent->throwError("Can't rename table ". $old ." - ". $new ." already exists!");
      }
	 
   } // db_rename_table()

   /**
    * MASTERSHAPER_DB drop table
    *
    * This function will delete the given table from database
    */
   public function db_drop_table($table_name)
   {
      if($this->db_check_table_exists($table_name))
         $this->db_query("DROP TABLE ". $table_name);

   } // db_drop_table()

   /**
    * MASTERSHAPER_DB truncate table
    *
    * This function will truncate (reset) the given table
    */
   public function db_truncate_table($table_name)
   {
      if($this->db_check_table_exists($table_name)) 
         $this->db_query("TRUNCATE TABLE ". $table_name);

   } // db_truncate_table()

   /**
    * MASTERSHAPER_DB check column exist
    *
    * This function checks if the given column exists within
    * the specified table.
    */
   public function db_check_column_exists($table_name, $column)
   {
      $result = $this->db_query("DESC ". $table_name, MDB2_FETCHMODE_ORDERED);
      while($row = $result->fetchRow()) {
         if(in_array($column, $row))
            return 1;
      }
      return 0;

   } // db_check_column_exists()

   /**
    * MASTERSHAPER_DB check index exists
    *
    * This function checks if the given index can be found
    * within the specified table.
    */
   public function db_check_index_exists($table_name, $index_name)
   {
      $result = $this->db_query("DESC ". $table_name, MDB2_FETCHMODE_ORDERED);

      while($row = $result->fetchRow()) {
         if(in_array("KEY `". $index_name ."`", $row))
            return 1;
      }

      return 0;

   } // db_check_index_exists()

   /**
    * MASTERSHAPER_DB alter table
    *
    * This function offers multiple methods to alter a table.
    * * add/modify/delete columns
    * * drop index
    */
   public function db_alter_table($table_name, $option, $column, $param1 = "", $param2 = "")
   {
      if($this->db_check_table_exists($table_name)) {

         switch(strtolower($option)) {
	
            case 'add':
               if(!$this->db_check_column_exists($table_name, $column))
                  $this->db_query("ALTER TABLE ". $table_name ." ADD ". $column ." ". $param1);
               break;

            case 'change':
            
               if($this->db_check_column_exists($table_name, $column))
                  $this->db_query("ALTER TABLE ". $table_name ." CHANGE ". $column ." ". $param1);
               break;

            case 'drop':

               if($this->db_check_column_exists($table_name, $column))
                  $this->db_query("ALTER TABLE ". $table_name ." DROP ". $column);
               break;

            case 'dropidx':
	          
               if($this->db_check_index_exists($table_name, $column))
                  $this->db_query("ALTER TABLE ". $table_name ." DROP INDEX ". $column);
               break;

         }
      }

   } // db_alter_table()

   /**
    * MASTERSHAPER_DB get MasterShaper Version
    *
    * This functions returns the current MasterShaper (DB) version
    */
   public function getVersion()
   {
      if(!$this->db_check_table_exists(MYSQL_PREFIX ."meta"))
         return false;

      $result = $this->db_fetchSingleRow("
         SELECT
            meta_value
         FROM
            ". MYSQL_PREFIX ."meta
         WHERE
            meta_key LIKE 'schema version'
      ");

      if(isset($result->meta_value))
         return $result->meta_value;

      return 0;
	 
   } // getVersion()

   /**
    * MASTERSHAPER_DB set version
    *
    * This function sets the version name of MasterShaper (DB)
    */
   private function setVersion($version)
   {
      $this->db_query("
         REPLACE INTO ". MYSQL_PREFIX ."meta (
            meta_key, meta_value
         ) VALUES (
            'schema version',
            '". $version ."'
         )
      ");
      
   } // setVersion()

   /**
    * MASTERSHAPER_DB get connection status
    *
    * This function checks the internal state variable if already
    * connected to database.
    */
   private function setConnStatus($status)
   {
      $this->is_connected = $status;
      
   } // setConnStatus()

   /**
    * MASTERSHAPER_DB set connection status
    * This function sets the internal state variable to indicate
    * current database connection status.
    */
   private function getConnStatus()
   {
      return $this->is_connected;

   } // getConnStatus()

   /**
    * quote string
    *
    * this function handsover the provided string to the MDB2
    * quote() function which will return the, for the selected
    * database system, correctly quoted string.
    *
    * @param string $query
    * @return string
    */
   public function db_quote($obj)
   {
      return $this->db->quote($obj);

   } // db_quote()

   public function install_schema()
   {
      $this->schema_version = $this->getVersion();

      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'meta'))
         $this->install_tables();

      $this->upgrade_schema();

      return true;

   } // install_schema()

   private function install_tables()
   {
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'assign_filters_to_pipes')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX . "assign_filters_to_pipes` (
              `apf_idx` int(11) NOT NULL auto_increment,
              `apf_pipe_idx` int(11) default NULL,
              `apf_filter_idx` int(11) default NULL,
              PRIMARY KEY  (`apf_idx`),
              KEY `apf_pipe_idx` (`apf_pipe_idx`),
              KEY `apf_filter_idx` (`apf_filter_idx`)
            ) ENGINE=MyISAM AUTO_INCREMENT=62 DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'assign_l7_protocols_to_filters')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."assign_l7_protocols_to_filters` (
              `afl7_idx` int(11) NOT NULL auto_increment,
              `afl7_filter_idx` int(11) NOT NULL,
              `afl7_l7proto_idx` int(11) NOT NULL,
              PRIMARY KEY  (`afl7_idx`),
              KEY `afl7_filter_idx` (`afl7_filter_idx`),
              KEY `afl7_l7proto_idx` (`afl7_l7proto_idx`)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'assign_ports_to_filters')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."assign_ports_to_filters` (
              `afp_idx` int(11) NOT NULL auto_increment,
              `afp_filter_idx` int(11) default NULL,
              `afp_port_idx` int(11) default NULL,
              PRIMARY KEY  (`afp_idx`),
              KEY `afp_filter_idx` (`afp_filter_idx`),
              KEY `afp_port_idx` (`afp_port_idx`)
            ) ENGINE=MyISAM AUTO_INCREMENT=82 DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'assign_target_groups')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."assign_target_groups` (
              `atg_idx` int(11) NOT NULL auto_increment,
              `atg_group_idx` int(11) NOT NULL,
              `atg_target_idx` int(11) NOT NULL,
              PRIMARY KEY  (`atg_idx`)
            ) ENGINE=MyISAM AUTO_INCREMENT=10 DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'chains')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."chains` (
              `chain_idx` int(11) NOT NULL auto_increment,
              `chain_name` varchar(255) default NULL,
              `chain_active` char(1) default NULL,
              `chain_sl_idx` int(11) default NULL,
              `chain_src_target` int(11) default NULL,
              `chain_dst_target` int(11) default NULL,
              `chain_position` int(11) default NULL,
              `chain_direction` int(11) default NULL,
              `chain_fallback_idx` int(11) default NULL,
              `chain_action` varchar(16) default NULL,
              `chain_tc_id` varchar(16) default NULL,
              `chain_netpath_idx` int(11) default NULL,
              PRIMARY KEY  (`chain_idx`)
            ) ENGINE=MyISAM AUTO_INCREMENT=7 DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'filters')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."filters` (
              `filter_idx` int(11) NOT NULL auto_increment,
              `filter_name` varchar(255) default NULL,
              `filter_protocol_id` int(11) default NULL,
              `filter_tos` varchar(4) default NULL,
              `filter_tcpflag_syn` char(1) default NULL,
              `filter_tcpflag_ack` char(1) default NULL,
              `filter_tcpflag_fin` char(1) default NULL,
              `filter_tcpflag_rst` char(1) default NULL,
              `filter_tcpflag_urg` char(1) default NULL,
              `filter_tcpflag_psh` char(1) default NULL,
              `filter_packet_length` varchar(255) default NULL,
              `filter_p2p_edk` char(1) default NULL,
              `filter_p2p_kazaa` char(1) default NULL,
              `filter_p2p_dc` char(1) default NULL,
              `filter_p2p_gnu` char(1) default NULL,
              `filter_p2p_bit` char(1) default NULL,
              `filter_p2p_apple` char(1) default NULL,
              `filter_p2p_soul` char(1) default NULL,
              `filter_p2p_winmx` char(1) default NULL,
              `filter_p2p_ares` char(1) default NULL,
              `filter_time_use_range` char(1) default NULL,
              `filter_time_start` int(11) default NULL,
              `filter_time_stop` int(11) default NULL,
              `filter_time_day_mon` char(1) default NULL,
              `filter_time_day_tue` char(1) default NULL,
              `filter_time_day_wed` char(1) default NULL,
              `filter_time_day_thu` char(1) default NULL,
              `filter_time_day_fri` char(1) default NULL,
              `filter_time_day_sat` char(1) default NULL,
              `filter_time_day_sun` char(1) default NULL,
              `filter_match_ftp_data` char(1) default NULL,
              `filter_match_sip` char(1) default NULL,
              `filter_active` char(1) default NULL,
              PRIMARY KEY  (`filter_idx`)
            ) ENGINE=MyISAM AUTO_INCREMENT=10 DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'interfaces')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."interfaces` (
              `if_idx` int(11) NOT NULL auto_increment,
              `if_name` varchar(255) default NULL,
              `if_speed` varchar(255) default NULL,
              `if_ifb` char(1) default NULL,
              `if_active` char(1) default NULL,
              PRIMARY KEY  (`if_idx`)
            ) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=latin1;
         ");   
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'l7_protocols')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."l7_protocols` (
              `l7proto_idx` int(11) NOT NULL auto_increment,
              `l7proto_name` varchar(255) default NULL,
              PRIMARY KEY  (`l7proto_idx`)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'network_paths')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."network_paths` (
              `netpath_idx` int(11) NOT NULL auto_increment,
              `netpath_name` varchar(255) default NULL,
              `netpath_if1` int(11) default NULL,
              `netpath_if2` int(11) default NULL,
              `netpath_position` int(11) default NULL,
              `netpath_imq` varchar(1) default NULL,
              `netpath_active` varchar(1) default NULL,
              PRIMARY KEY  (`netpath_idx`)
            ) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'pipes')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."pipes` (
              `pipe_idx` int(11) NOT NULL auto_increment,
              `pipe_chain_idx` int(11) default NULL,
              `pipe_name` varchar(255) default NULL,
              `pipe_sl_idx` int(11) default NULL,
              `pipe_position` int(11) default NULL,
              `pipe_src_target` int(11) default NULL,
              `pipe_dst_target` int(11) default NULL,
              `pipe_direction` int(11) default NULL,
              `pipe_action` varchar(15) default NULL,
              `pipe_active` char(1) default NULL,
              `pipe_tc_id` varchar(16) default NULL,
              PRIMARY KEY  (`pipe_idx`)
            ) ENGINE=MyISAM AUTO_INCREMENT=11 DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'ports')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."ports` (
              `port_idx` int(11) NOT NULL auto_increment,
              `port_name` varchar(255) default NULL,
              `port_desc` varchar(255) default NULL,
              `port_number` varchar(255) default NULL,
              `port_user_defined` char(1) default NULL,
              PRIMARY KEY  (`port_idx`)
            ) ENGINE=MyISAM AUTO_INCREMENT=4483 DEFAULT CHARSET=latin1;
         ");
         $this->db_query(" 
            LOAD DATA INFILE
               '". BASE_PATH ."/contrib/port-numbers.csv'
            IGNORE INTO TABLE
               ". MYSQL_PREFIX ."ports
            FIELDS TERMINATED BY ','
            ENCLOSED BY '\"' LINES
            TERMINATED BY '\r\n'
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'protocols')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."protocols` (
              `proto_idx` int(11) NOT NULL auto_increment,
              `proto_number` varchar(255) default NULL,
              `proto_name` varchar(255) default NULL,
              `proto_desc` varchar(255) default NULL,
              `proto_user_defined` char(1) default NULL,
              PRIMARY KEY  (`proto_idx`)
            ) ENGINE=MyISAM AUTO_INCREMENT=147 DEFAULT CHARSET=latin1;
         ");
         $this->db_query("
            LOAD DATA INFILE
               '". BASE_PATH ."/contrib/protocol-numbers.csv'
            IGNORE INTO TABLE
               ". MYSQL_PREFIX ."protocols
            FIELDS TERMINATED BY ','
            ENCLOSED BY '\"'
            LINES TERMINATED BY '\r\n'
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'service_levels')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."service_levels` (
              `sl_idx` int(11) NOT NULL auto_increment,
              `sl_name` varchar(255) default NULL,
              `sl_htb_bw_in_rate` varchar(255) default NULL,
              `sl_htb_bw_in_ceil` varchar(255) default NULL,
              `sl_htb_bw_in_burst` varchar(255) default NULL,
              `sl_htb_bw_out_rate` varchar(255) default NULL,
              `sl_htb_bw_out_ceil` varchar(255) default NULL,
              `sl_htb_bw_out_burst` varchar(255) default NULL,
              `sl_htb_priority` varchar(255) default NULL,
              `sl_hfsc_in_umax` varchar(255) default NULL,
              `sl_hfsc_in_dmax` varchar(255) default NULL,
              `sl_hfsc_in_rate` varchar(255) default NULL,
              `sl_hfsc_in_ulrate` varchar(255) default NULL,
              `sl_hfsc_out_umax` varchar(255) default NULL,
              `sl_hfsc_out_dmax` varchar(255) default NULL,
              `sl_hfsc_out_rate` varchar(255) default NULL,
              `sl_hfsc_out_ulrate` varchar(255) default NULL,
              `sl_cbq_in_rate` varchar(255) default NULL,
              `sl_cbq_in_priority` varchar(255) default NULL,
              `sl_cbq_out_rate` varchar(255) default NULL,
              `sl_cbq_out_priority` varchar(255) default NULL,
              `sl_cbq_bounded` char(1) default NULL,
              `sl_qdisc` varchar(255) default NULL,
              `sl_netem_delay` varchar(255) default NULL,
              `sl_netem_jitter` varchar(255) default NULL,
              `sl_netem_random` varchar(255) default NULL,
              `sl_netem_distribution` varchar(255) default NULL,
              `sl_netem_loss` varchar(255) default NULL,
              `sl_netem_duplication` varchar(255) default NULL,
              `sl_netem_gap` varchar(255) default NULL,
              `sl_netem_reorder_percentage` varchar(255) default NULL,
              `sl_netem_reorder_correlation` varchar(255) default NULL,
              `sl_esfq_perturb` varchar(255) default NULL,
              `sl_esfq_limit` varchar(255) default NULL,
              `sl_esfq_depth` varchar(255) default NULL,
              `sl_esfq_divisor` varchar(255) default NULL,
              `sl_esfq_hash` varchar(255) default NULL,
              PRIMARY KEY  (`sl_idx`)
               ) ENGINE=MyISAM AUTO_INCREMENT=10 DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'settings')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."settings` (
              `setting_key` varchar(255) NOT NULL default '',
              `setting_value` varchar(255) default NULL,
              PRIMARY KEY  (`setting_key`)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'stats')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."stats` (
              `stat_time` int(11) NOT NULL default '0',
              `stat_data` text,
              PRIMARY KEY  (`stat_time`)
            ) ENGINE=MyISAM DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'targets')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."targets` (
              `target_idx` int(11) NOT NULL auto_increment,
              `target_name` varchar(255) default NULL,
              `target_match` varchar(16) default NULL,
              `target_ip` varchar(255) default NULL,
              `target_mac` varchar(255) default NULL,
              PRIMARY KEY  (`target_idx`)
            ) ENGINE=MyISAM AUTO_INCREMENT=14 DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'tc_ids')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."tc_ids` (
              `id_pipe_idx` int(11) default NULL,
              `id_chain_idx` int(11) default NULL,
              `id_if` varchar(255) default NULL,
              `id_tc_id` varchar(255) default NULL,
              `id_color` varchar(7) default NULL,
              KEY `id_pipe_idx` (`id_pipe_idx`),
              KEY `id_chain_idx` (`id_chain_idx`),
              KEY `id_if` (`id_if`),
              KEY `id_tc_id` (`id_tc_id`),
              KEY `id_color` (`id_color`)
            ) ENGINE=MEMORY DEFAULT CHARSET=latin1;
         ");
      }
      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'users')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."users` (
              `user_idx` int(11) NOT NULL auto_increment,
              `user_name` varchar(32) default NULL,
              `user_pass` varchar(32) default NULL,
              `user_manage_chains` char(1) default NULL,
              `user_manage_pipes` char(1) default NULL,
              `user_manage_filters` char(1) default NULL,
              `user_manage_ports` char(1) default NULL,
              `user_manage_protocols` char(1) default NULL,
              `user_manage_targets` char(1) default NULL,
              `user_manage_users` char(1) default NULL,
              `user_manage_options` char(1) default NULL,
              `user_manage_servicelevels` char(1) default NULL,
              `user_show_rules` char(1) default NULL,
              `user_load_rules` char(1) default NULL,
              `user_show_monitor` char(1) default NULL,
              `user_active` char(1) default NULL,
              PRIMARY KEY  (`user_idx`)
            ) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;
         ");
         $this->db_query("
            INSERT INTO ". MYSQL_PREFIX ."users VALUES (
               NULL,
               'admin',
               MD5('admin'),
               'Y',
               'Y',
               'Y',
               'Y',
               'Y',
               'Y',
               'Y',
               'Y',
               'Y',
               'Y',
               'Y',
               'Y',
               'Y'
            )");
      }

      if(!$this->db_check_table_exists(MYSQL_PREFIX . 'meta')) {
         $this->db_query("
            CREATE TABLE `". MYSQL_PREFIX ."meta` (
               `meta_idx` int(11) NOT NULL auto_increment,
               `meta_key` varchar(255) default NULL,
               `meta_value` varchar(255) default NULL,
               PRIMARY KEY  (`meta_idx`),
               UNIQUE KEY `meta_key` (`meta_key`)
            ) ENGINE=MyISAM AUTO_INCREMENT=0 DEFAULT CHARSET=utf8;
         ");
         $this->setVersion(SCHEMA_VERSION);
      }

   } // install_schema()

   private function upgrade_schema()
   {
      if($this->schema_version < 2) {

         $this->db_query("
            RENAME TABLE
               shaper2_assign_filters
            TO
               shaper2_assign_filters_to_pipes,
               shaper2_assign_l7_protocols
            TO
               shaper2_assign_l7_protocols_to_filters,
               shaper2_assign_ports
            TO
               shaper2_assign_ports_to_filters;
         ");

         $this->setVersion(2);
      }

   } // upgrade_schema()

}

?>
