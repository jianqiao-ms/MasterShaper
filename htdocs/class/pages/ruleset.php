<?php

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

require_once "shaper_interface.php";

define("UNIDIRECTIONAL", 1);
define("BIDIRECTIONAL", 2);
define("IF_NOT_USED", -1);

define("MS_PRE", 10);
define("MS_POST", 13);

class MASTERSHAPER_RULESET {

   private $ms_pre;
   private $ms_post;
   private $classes;
   private $filters;
   private $interfaces;

   /**
    * MASTERSHAPER_RULESET constructor
    *
    * Initialize the MASTERSHAPER_RULESET class
    */
   public function __construct()
   {
      $this->ms_pre = Array();
      $this->ms_post = Array();
      $this->error  = Array();

      $this->classes = Array();
      $this->filters = Array();
      $this->interfaces = Array();

   } // __construct()

   public function handler()
   {
      global $tmpl, $page, $ms;

      if($this->rights) {
         /* If authentication is enabled, check permissions */
         if($ms->getOption("authentication") == "Y" && !$ms->checkPermissions($this->rights)) {
            $ms->throwError("<img src=\"". ICON_CHAINS ."\" alt=\"chain icon\" />&nbsp;". _("Manage Chains"), _("You do not have enough permissions to access this module!"));
            return 0;
         }
      }

      switch($page->action) {
         default:
         case 'show':
            $content = $this->show();
            break;
         case 'load':
            $content = $this->load();
            break;
         case 'load-debug':
            $content = $this->load(DEBUG);
            break;
         case 'unload':
            $content = $this->unload();
            break;
      }

      if(isset($content))
         $tmpl->assign('content', $content);

   } // handler()

   /* This function prepares the rule setup according configuration and calls tc with a batchjob */
   public function show($state = 0)
   {
      global $ms, $tmpl;

      /* If authentication is enabled, check permissions */
      if($ms->getOption("authentication") == "Y" &&
         !$ms->checkPermissions("user_show_rules")) {

         $ms->printError("<img src=\"". ICON_HOME ."\" alt=\"home icon\" />&nbsp;MasterShaper Ruleset - ". _("Show rules"), _("You do not have enough permissions to access this module!"));
         return 0;

      }

      $tmpl->register_function("ruleset_output", array(&$this, "smarty_ruleset_output"), false);
      return $tmpl->fetch("ruleset_show.tpl");

   } // show

   /**
    * load MasterShaper ruleset
    */
   public function load($debug = null)
   {
      global $ms;

      /* If authentication is enabled, check permissions */
      if($ms->getOption("authentication") == "Y" &&
         !$ms->checkPermissions("user_load_rules")) {

         $ms->printError("<img src=\"". ICON_HOME ."\" alt=\"home icon\" />&nbsp;MasterShaper Ruleset - ". _("Load rules"), _("You do not have enough permissions to access this module!"));
         return 0;
      }

      print _("Loading MasterShaper Ruleset");
      print _("Please wait...");

      $this->initRules();
      $retval = $debug ? $this->doItLineByLine() : $this->doIt();
      if(!$retval)
         $ms->setOption("reload_timestamp", mktime());

      return $retval;

   } // load()

   /**
    * unload MasterShaper ruleset
    */
   public function unload()
   {
      global $ms;

      /* If authentication is enabled, check permissions */
      if($ms->getOption("authentication") == "Y" &&
         !$ms->checkPermissions("user_load_rules")) {

         $ms->printError("<img src=\"". ICON_HOME ."\" alt=\"home icon\" />&nbsp;MasterShaper Ruleset - Unload rules", "You do not have enough permissions to access this module!");
         return 0;

      }

      $this->delActiveInterfaceQdiscs();
      $this->delIptablesRules();

      print "Unloading MasterShaper Ruleset";
      $ms->setShaperStatus(false);
      
   } // show()

   private function iptInitRules()
   {
      $this->addRule(MS_PRE, IPT_BIN ." -t mangle -N ms-forward");
      $this->addRule(MS_PRE, IPT_BIN ." -t mangle -N ms-postrouting");
      $this->addRule(MS_PRE, IPT_BIN ." -t mangle -N ms-prerouting");
      $this->addRule(MS_PRE, IPT_BIN ." -t mangle -A PREROUTING -j ms-prerouting");

      /* We must restore the connection mark in PREROUTING table first! */
      $this->addRule(MS_PRE, IPT_BIN ." -t mangle -A ms-prerouting -j CONNMARK --restore-mark");
      $this->addRule(MS_POST, IPT_BIN ." -t mangle -A ms-prerouting -j CONNMARK --save-mark");

   } // iptInitRules()

   private function addRuleComment($ruleset, $text)
   {
      $this->addRule($ruleset, "######### ". $text);

   } // addRuleComment()

   private function addRule($rule, $cmd)
   {
      switch($rule) {
         case MS_PRE:
            array_push($this->ms_pre, $cmd);
            break;

         case MS_POST:
            array_push($this->ms_post, $cmd);
            break;
      }

   } // addRule()

   private function getRules($rules)
   {
      switch($rules) {

         case MS_PRE:
            return $this->ms_pre;
            break;

         case MS_POST:
	         return $this->ms_post;
            break;
      }
      
   } // getRules()

   private function initRules()
   {
      global $ms, $db;

      /* The most tc_ids will change, so we delete the current known tc_ids */
      $db->db_query("DELETE FROM ". MYSQL_PREFIX ."tc_ids");

      /* Initial iptables rules */
      if($ms->getOption("filter") == "ipt")
         $this->iptInitRules();

      $netpaths = $this->getActiveNetpaths(); 

      while($netpath = $netpaths->fetchRow()) {

         $have_if2 = true;
         $do_nothing = false;

         if(!isset($this->interfaces[$netpath->netpath_if1])) 
            $this->interfaces[$netpath->netpath_if1] = new MASTERSHAPER_INTERFACE($netpath->netpath_if1, $netpath->netpath_if1_inside_gre, $db, $ms);
         /* the second interface of the interface is no must, only create it when necessary */
         if($netpath->netpath_if2 != -1 && !isset($this->interfaces[$netpath->netpath_if2])) 
            $this->interfaces[$netpath->netpath_if2] = new MASTERSHAPER_INTERFACE($netpath->netpath_if2, $netpath->netpath_if2_inside_gre, $db, $ms);

         /* get interface 2 parameters (if available) */
         if($netpath->netpath_if2 == IF_NOT_USED)
            $have_if2 = false;

         /* If a interface on this network path is inactive, ignore it completely */
         if($this->interfaces[$netpath->netpath_if1]->isActive() != "Y")
            $do_nothing = true;  
         if($have_if2 && $this->interfaces[$netpath->netpath_if2]->isActive() != "Y") 
            $do_nothing = true;  

         if(!$do_nothing) {

            $this->addRuleComment(MS_PRE, "Rules for Network Path ". $netpath->netpath_name);

            /* tc structure
               1: root qdisc
                1:1 root class (dev. bandwidth limit)
                 1:2
                 1:3
                 1:4
            */

            /* only initialize the interface if it isn't already */
            if(!$this->interfaces[$netpath->netpath_if1]->getStatus()) {
               $this->interfaces[$netpath->netpath_if1]->Initialize("in");
            }

            /* only initialize the interface if it isn't already */
            if($have_if2 && !$this->interfaces[$netpath->netpath_if2]->getStatus()) {
               $this->interfaces[$netpath->netpath_if2]->Initialize("out");
            }
		  
            if($netpath->netpath_imq == "Y") {
               $this->interfaces[$netpath->netpath_if1]->buildChains($netpath->netpath_idx, "in");
               if($have_if2)
                  $this->interfaces[$netpath->netpath_if2]->buildChains($netpath->netpath_idx, "out");
            }
            else {
               $this->interfaces[$netpath->netpath_if1]->buildChains($netpath->netpath_idx, "in");
               if($have_if2)
                  $this->interfaces[$netpath->netpath_if2]->buildChains($netpath->netpath_idx, "out");
            }
         }
      }

      return true;

   } // initRules()

   /* Delete parent qdiscs */
   private function delQdisc($interface)
   {
      $this->runProc("tc", TC_BIN . " qdisc del dev ". $interface ." root", true);

   } // delQdisc()

   private function delIptablesRules()
   {
      $this->runProc("cleanup", null, true);

   } // delIptablesRules

   private function doIt()
   {
      global $ms;

      $error = Array();
      $found_error = 0;

      /* Delete current root qdiscs */
      $this->delActiveInterfaceQdiscs();

      $this->delIptablesRules();

      /* Prepare the tc batch file */
      $temp_tc  = tempnam (TEMP_PATH, "FOOTC");
      $output_tc  = fopen($temp_tc, "w");

      /* If necessary prepare iptables batch files */
      if($ms->getOption("filter") == "ipt") {
         $temp_ipt = tempnam (TEMP_PATH, "FOOIPT");
         $output_ipt = fopen($temp_ipt, "w");
      }

      foreach($this->getCompleteRuleset() as $line) {
         $line = trim($line);
         if(!preg_match("/^#/", $line)) {
            /* tc filter task */
            if(strstr($line, TC_BIN) !== false && $line != "") {
               $line = str_replace(TC_BIN ." ", "", $line);
               fputs($output_tc, $line ."\n");
	         }
            /* iptables task */
            if(strstr($line, IPT_BIN) !== false && $ms->getOption("filter") == "ipt") {
               fputs($output_ipt, $line ."\n");
            }
         }
      }

      /* flush batch files */
      fclose($output_tc);

      if($ms->getOption("filter") == "ipt")
         fclose($output_ipt);

      /* load tc filter rules */
      if(($error = $this->runProc("tc", TC_BIN . " -b ". $temp_tc)) != true) {
         print _("Error on mass loading tc rules. Try load ruleset in debug mode to figure incorrect or not supported rule."); 
         $found_error = 1;
      }

      /* load iptables rules */
      if($ms->getOption("filter") == "ipt" && !$found_error) {
         if(($error = $this->runProc("iptables", $temp_ipt)) != true) {
            print _("Error on mass loading iptables rule. Try load ruleset in debug mode to figure incorrect or not supported rule.");
            $found_error = 1;
         }
      }

      if(!$found_error) {
         print _("Shaping enabled");
      }

      unlink($temp_tc);
      if($ms->getOption("filter") == "ipt")
         unlink($temp_ipt);

      if(!$found_error)
         $ms->setShaperStatus(true);
      else
         $ms->setShaperStatus(false);

      return $found_error;

   } // doIt()

   private function doItLineByLine()
   {
      /* Delete current root qdiscs */
      $this->delActiveInterfaceQdiscs();
      $this->delIptablesRules();

      $ipt_lines = array();

      foreach($this->getCompleteRuleset() as $line) {
         if(!preg_match("/^#/", $line)) {
            if(strstr($line, TC_BIN) !== false) {
            print $line."<br />\n";
               if(($tc = $this->runProc("tc", $line)) !== true)
                  print $tc."<br />\n";
            }
            if(strstr($line, IPT_BIN) !== false) 
               array_push($ipt_lines, $line);
         }
         else {
            print $line."<br />\n";
         }
      }

      foreach($ipt_lines as $line) {
         print $line."<br />\n";
         if(($tc = $this->runProc("iptables", $line)) !== true)
            print $tc."<br />\n";
      }

   } // doItLineByLine()

   private function output($text)
   {
      if($_GET['output'] == "noisy")
         print $text ."\n";

   } // output()

   private function getCompleteRuleset()
   {
      $ruleset = Array();
      foreach($this->ms_pre as $tmp) {
         array_push($ruleset, $tmp);
      }
      foreach($this->interfaces as $interface) {
         foreach($interface->getRules() as $rule) {
            array_push($ruleset, $rule);
         }
      }
      foreach($this->ms_post as $tmp) {
         array_push($ruleset, $tmp);
      }
      return $ruleset;
   
   } // getCompleteRuleset()

   private function showIt()
   {
      $string = "";
      foreach($this->getCompleteRuleset() as $tmp) {
         foreach(split("\n", $tmp) as $line) {
         $line = trim($line);
         if($line != "")
            $string.= "<font style='color: ". $this->getColor($line) .";'>". $line ."</font><br />\n";
         }
      }
      
      return $string;

   } // showIt()

   private function getColor($text)
   {
      if(strstr($text, "########"))
	 return "#666666";
      if(strstr($text, TC_BIN))
	 return "#AF0000";
      if(strstr($text, IPT_BIN))
	 return "#0000AF";

      return "#000000";

   } // getColor()

   private function runProc($option, $cmd = "", $ignore_err = null)
   {
      $retval = "";
      $error = "";

      $desc = array(
         0 => array('pipe','r'), /* STDIN */
         1 => array('pipe','w'), /* STDOUT */
         2 => array('pipe','w'), /* STDERR */ 
      );

      $process = proc_open(SUDO_BIN ." ". BASE_PATH ."/shaper_loader.sh ". $option ." \"". $cmd ."\"", $desc, $pipes);

      if(is_resource($process)) {
   
         $stdin = $pipes[0];
         $stdout = $pipes[1];
         $stderr = $pipes[2];

         while(!feof($stdout)) {
            $retval.= trim(fgets($stdout));
         }
         while(!feof($stderr)) {
            $error.= trim(fgets($stderr));
         }

         fclose($pipes[0]);
         fclose($pipes[1]);
         fclose($pipes[2]);

         $exit_code = proc_close($process);

      }
   
      if(is_null($ignore_err)) {
         if(!empty($error) || $retval != "OK")
            throw new Exception($error);
      }

      return $retval;

   } // runProc()

   private function delActiveInterfaceQdiscs()
   {
      global $ms;

      $result = $ms->getActiveInterfaces();
      while($row = $result->fetchRow()) {
         $this->delQdisc($row->if_name);
      }

   } // delActiveInterfaceQdiscs()

   private function getActiveNetpaths()
   {
      global $db;

      return $db->db_query("SELECT * FROM ". MYSQL_PREFIX ."network_paths WHERE netpath_active='Y' ORDER BY netpath_position");

   } // getActiveNetpaths()

   public function smarty_ruleset_output($params, &$smarty)
   {
      if($this->initRules()) {
         return $this->showIt();
      }

   } // smarty_ruleset_output()

}

$obj = new MASTERSHAPER_RULESET;
$obj->handler();

?>