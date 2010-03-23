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

define("UNIDIRECTIONAL", 1);
define("BIDIRECTIONAL", 2);

class Ruleset_Interface {

   private $initialized;
   private $major_class;
   private $minor_class;
   private $rules;
   private $if_id;
   private $db;
   private $parent;

   /**
    * Ruleset_Interface constructor
    *
    * Initialize the Ruleset_Interface class
    */
   public function __construct($if_id, $if_gre)
   {
      $this->initialized = false;
      $this->rules       = Array();

      $this->major_class    = 1;
      $this->minor_class    = 1;
      $this->current_chain  = 1;
      $this->current_class  = 1;
      $this->current_filter = 1;
      $this->current_pipe   = 1;

      $if = $this->getInterfaceDetails($if_id);

      $this->if_id          = $if_id;
      $this->if_name        = $if->if_name;
      $this->if_speed       = $if->if_speed;
      $this->if_active      = $if->if_active;
      $this->if_gre         = $if_gre;

   } // __construct()

   /**
    * set the status of the interface
    *
    * this function set a "initialized" flag to indicate whether
    * the interface has been already initialized or not.
    *
    * @param bool new status
    */ 
   private function setStatus($status) 
   {
      if($status == true or $status == false) 
         $this->initialized = $status;      

   } // setStatus()

   /**
    * return the current status of the interface
    *
    * this function return the current state of the "initialized flag to
    * indicate whether the interface has been already initialized or not.
    */
   public function getStatus() 
   {
      return $this->initialized;

   } // getStatus()

   private function getNextClassId()
   {
      $this->minor_class++;
      return $this->major_class .":". $this->minor_class;

   } // getNextClassId()

   /**
    * return ruleset
    *
    * this function will return the buffer in which all
    * the generated rules for this interface are stored.
    */
   public function getRules()
   {
      return $this->rules;

   } // getRules()

   /**
    * check if interface is active
    *
    * will return, if the interface assigned to this
    * class is enabled or disabled in MasterShaper
    * config.
    */
   public function isActive()
   {
      return $this->if_active;

   } // isActive()

   private function getSpeed()
   {
      global $ms;

      return $ms->getKbit($this->if_speed);

   } // getSpeed()

   /**
    * return interface id
    *
    * return the unique primary database key
    * as interface id.
    */
   private function getId()
   {
      return $this->if_id;

   } // getId()

   /**
    * return interface name
    *
    * returns the current interface name (ipsec0, eth0, ...)
    *
    * @return string
    */
   private function getName()
   {
      return $this->if_name;

   } // getName()

   /**
    * is matching inside GRE tunnel
    *
    * @param bool
    */
   private function isGRE()
   {
      if($this->if_gre == 'Y')
         return true;

      return false;

   } // isGRE()

   private function getInterfaceDetails($if_idx)
   {
      $if = new Network_Interface($if_idx);
      return $if;

   } // getInterfaceDetails()

   private function addRuleComment($text)
   {
      $this->addRule("######### ". $text);

   } // addRuleComment()

   private function addRule($cmd)
   {
      array_push($this->rules, $cmd);

   } // addRule()

   private function addRootQdisc($id)
   {
      global $ms;

      switch($ms->getOption("classifier")) {

         default:
         case 'HTB':
	         $this->addRule(TC_BIN ." qdisc add dev ". $this->getName() ." handle ". $id ." root htb default 1");
            break;

         case 'HFSC':
            $this->addRule(TC_BIN ." qdisc add dev ". $this->getName() ." handle ". $id ." root hfsc default 1");
            break;

         case 'CBQ':
            $this->addRule(TC_BIN ." qdisc add dev ". $this->getName() ." handle ". $id ." root cbq avpkt 1000 bandwidth ". $this->getSpeed() ."Kbit cell 8");
            break;

      }

   } // addRootQdisc()

   private function addInitClass($parent, $classid)
   {
      global $ms;

      $bw = $this->getSpeed();

      switch($ms->getOption("classifier")) {

         default:
         case 'HTB':
            $this->addRule(TC_BIN ." class add dev ". $this->getName() ." parent ". $parent ." classid ". $classid ." htb rate ". $bw ."Kbit");
            break;

         case 'HFSC':
            $this->addRule(TC_BIN ." class add dev ". $this->getName() ." parent ". $parent ." classid ". $classid ." hfsc sc rate ". $bw ."Kbit ul rate ". $bw ."Kbit");
            break;

         case 'CBQ':
            $this->addRule(TC_BIN ." class add dev ". $this->getName() ." parent ". $parent ." classid ". $classid ." cbq bandwidth ". $bw ."Kbit rate ". $bw ."Kbit allot 1000 prio 3 bounded");
            break;

      }

   } // addInitClass()

   /* Adds the top level filter which brings traffic into the initClass */
   private function addInitFilter($parent)
   {
      $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all u32 match u32 0 0 classid 1:1");

   } // addInitFilter()

   /* Adds a class definition for a inbound chain */
   private function addClass($parent, $classid, $sl, $direction = "in", $parent_sl = null)
   {
      global $ms;

      $string = TC_BIN ." class add dev ". $this->getName() ." parent ". $parent ." classid ". $classid;

      switch($direction) {

         case 'in':

            switch($ms->getOption("classifier")) {

               default:
               case 'HTB':

                  $string.= " htb ";
                  if($sl->sl_htb_bw_in_rate != "" && $sl->sl_htb_bw_in_rate > 0) {
                     $string.= " rate ". $sl->sl_htb_bw_in_rate ."Kbit ";
                     if($sl->sl_htb_bw_in_ceil != "" && $sl->sl_htb_bw_in_ceil > 0)
                        $string.= "ceil ". $sl->sl_htb_bw_in_ceil ."Kbit ";
                     if($sl->sl_htb_bw_in_burst != "" && $sl->sl_htb_bw_in_burst > 0)
                        $string.= "burst ". $sl->sl_htb_bw_in_burst ."Kbit ";
                     if($sl->sl_htb_priority > 0) 
                        $string.= "prio ". $sl->sl_htb_priority;
                  }	
                  else {
                     if(isset($parent_sl)) {
                        $string.= " rate ". $parent_sl->sl_htb_bw_in_rate ."Kbit ";
                        if(!empty($parent_sl->sl_htb_bw_in_ceil))
                           $string.= " ceil ". $parent_sl->sl_htb_bw_in_ceil ."Kbit ";
                     }
                     else
                        $string.= " rate 1Kbit ceil ". $this->getSpeed() ."Kbit ";

                     if($sl->sl_htb_priority > 0)
                        $string.= "prio ". $sl->sl_htb_priority;
                  }
                  $string.= " quantum 1532";
                  break;
				      
               case 'HFSC':

                  $string.= " hfsc sc ";
                  if(isset($sl->sl_hfsc_in_umax) && $sl->sl_hfsc_in_umax != "" && $sl->sl_hfsc_in_umax > 0) 
                     $string.= " umax ". $sl->sl_hfsc_in_umax ."b ";
                  if(isset($sl->sl_hfsc_in_dmax) && $sl->sl_hfsc_in_dmax != "" && $sl->sl_hfsc_in_dmax > 0)
                     $string.= " dmax ". $sl->sl_hfsc_in_dmax ."ms ";
                  if(isset($sl->sl_hfsc_in_rate) && $sl->sl_hfsc_in_rate != "" && $sl->sl_hfsc_in_rate > 0)
                     $string.= " rate ". $sl->sl_hfsc_in_rate ."Kbit ";
                  if(isset($sl->sl_hfsc_in_ulrate) && $sl->sl_hfsc_in_ulrate != "" && $sl->sl_hfsc_in_ulrate > 0)
                     $string.= " ul rate ". $sl->sl_hfsc_in_ulrate ."Kbit";

                  $string.= " rt ";

                  if(isset($sl->sl_hfsc_in_umax) && $sl->sl_hfsc_in_umax != "" && $sl->sl_hfsc_in_umax > 0) 
                     $string.= " umax ". $sl->sl_hfsc_in_umax ."b ";
                  if(isset($sl->sl_hfsc_in_dmax) && $sl->sl_hfsc_in_dmax != "" && $sl->sl_hfsc_in_dmax > 0)
                     $string.= " dmax ". $sl->sl_hfsc_in_dmax ."ms ";
                  if(isset($sl->sl_hfsc_in_rate) && $sl->sl_hfsc_in_rate != "" && $sl->sl_hfsc_in_rate > 0)
                     $string.= " rate ". $sl->sl_hfsc_in_rate ."Kbit ";
                  if(isset($sl->sl_hfsc_in_ulrate) && $sl->sl_hfsc_in_ulrate != "" && $sl->sl_hfsc_in_ulrate > 0)
                     $string.= " ul rate ". $sl->sl_hfsc_in_ulrate ."Kbit";
                  break;

               case 'CBQ':

                  $string.= " cbq bandwidth ". $this->inbound ."Kbit rate ". $sl->sl_cbq_in_rate ."Kbit allot 1500 prio ". $sl->sl_cbq_in_priority ." avpkt 1000";
                  if($sl->sl_cbq_bounded == "Y")
                     $string.= " bounded";
                  break;

            }
            break;

         case 'out':

            switch($ms->getOption("classifier")) {

               default:
               case 'HTB':

                  $string.= " htb ";

                  if($sl->sl_htb_bw_out_rate != "" && $sl->sl_htb_bw_out_rate > 0) {
                     $string.= " rate ". $sl->sl_htb_bw_out_rate ."Kbit ";
                     if($sl->sl_htb_bw_out_ceil != "" && $sl->sl_htb_bw_out_ceil > 0)
                        $string.= "ceil ". $sl->sl_htb_bw_out_ceil ."Kbit ";
                     if($sl->sl_htb_bw_out_burst != "" && $sl->sl_htb_bw_out_burst > 0)
                        $string.= "burst ". $sl->sl_htb_bw_out_burst ."Kbit ";
                     if($sl->sl_htb_priority > 0) 
                        $string.= "prio ". $sl->sl_htb_priority;
                  }	
                  else {
                     if(isset($parent_sl)) {
                        $string.= " rate ". $parent_sl->sl_htb_bw_out_rate ."Kbit ";
                        if(!empty($parent_sl->sl_htb_bw_out_ceil))
                           $string.= " ceil ". $parent_sl->sl_htb_bw_out_ceil ."Kbit ";
                     }
                     else
                        $string.= " rate 1Kbit ceil ". $this->getSpeed() ."Kbit ";

                     if($sl->sl_htb_priority > 0)
                        $string.= "prio ". $sl->sl_htb_priority;
                  }
                  $string.= " quantum 1532";
                  break;

               case 'HFSC':

                  $string.= " hfsc sc ";

                  if(isset($sl->sl_hfsc_out_umax) && $sl->sl_hfsc_out_umax != "" && $sl->sl_hfsc_out_umax > 0) 
                     $string.= " umax ". $sl->sl_hfsc_out_umax ."b ";
                  if(isset($sl->sl_hfsc_out_dmax) && $sl->sl_hfsc_out_dmax != "" && $sl->sl_hfsc_out_dmax > 0)
                     $string.= " dmax ". $sl->sl_hfsc_out_dmax ."ms ";
                  if(isset($sl->sl_hfsc_out_rate) && $sl->sl_hfsc_out_rate != "" && $sl->sl_hfsc_out_rate > 0)
                     $string.= " rate ". $sl->sl_hfsc_out_rate ."Kbit ";
                  if(isset($sl->sl_hfsc_out_ulrate) && $sl->sl_hfsc_out_ulrate != "" && $sl->sl_hfsc_out_ulrate > 0)
                     $string.= " ul rate ". $sl->sl_hfsc_out_ulrate ."Kbit";
                  $string.= " rt ";
                  if(isset($sl->sl_hfsc_out_umax) && $sl->sl_hfsc_out_umax != "" && $sl->sl_hfsc_out_umax > 0) 
                     $string.= " umax ". $sl->sl_hfsc_out_umax ."b ";
                  if(isset($sl->sl_hfsc_out_dmax) && $sl->sl_hfsc_out_dmax != "" && $sl->sl_hfsc_out_dmax > 0)
                     $string.= " dmax ". $sl->sl_hfsc_out_dmax ."ms ";
                  if(isset($sl->sl_hfsc_out_rate) && $sl->sl_hfsc_out_rate != "" && $sl->sl_hfsc_out_rate > 0)
                     $string.= " rate ". $sl->sl_hfsc_out_rate ."Kbit ";
                  if(isset($sl->sl_hfsc_out_ulrate) && $sl->sl_hfsc_out_ulrate != "" && $sl->sl_hfsc_out_ulrate > 0)
                     $string.= " ul rate ". $sl->sl_hfsc_out_ulrate ."Kbit";
                  break;

               case 'CBQ':

                  $string.= " cbq bandwidth ifspeedKbit rate ". $sl->sl_cbq_out_rate ."Kbit allot 1500 prio ". $sl->sl_cbq_out_priority ." avpkt 1000";
                  if($sl->sl_cbq_bounded == "Y")
                     $string.= " bounded";
                  break;

            }
            break;
      }

      $this->addRule($string);

   } // addClass()

   /* Adds qdisc at the end of class for final queuing mechanism */
   private function addSubQdisc($child, $parent, $sl)
   {
      global $ms;

      $string = TC_BIN ." qdisc add dev ". $this->getName() ." handle ". $child ." parent ". $parent ." ";

      switch($sl->sl_qdisc) {

         default:
         case 'SFQ':
            $string.="sfq";
            break;

         case 'ESFQ':
            $string.= "esfq ". $ms->getESFQParams($sl);
            break;

         case 'HFSC':
            $string.= "hfsc";
            break;

         case 'NETEM':
            $string.= "netem ". $ms->getNETEMParams($sl);
            break;

      }

      $this->addRule($string);

   } // addSubQdisc()

   private function addAckFilter($parent, $option, $id = "")
   {
      global $ms;

      switch($ms->getOption("filter")) {

         default:
         case 'tc':

            $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol ip prio 1 u32 match ip protocol 6 0xff match u8 0x05 0x0f at 0 match u16 0x0000 0xffc0 at 2 match u8 0x10 0xff at 33 flowid ". $id);

            break;

         case 'ipt':

            $this->addRule(IPT_BIN ." -t mangle -A ms-postrouting -p tcp -m length --length :64 -j CLASSIFY --set-class ". $id);
            $this->addRule(IPT_BIN ." -t mangle -A ms-postrouting -p tcp -m length --length :64 -j RETURN");
            break;

      }

   } // addAckFilter()
	
   /* create IP/host matching filters */
   private function addHostFilter($parent, $option, $params1 = "", $params2 = "", $chain_direction = "")
   {
      global $ms;

      switch($ms->getOption("filter")) {
	 
         default:
         case 'tc':

            if($chain_direction == "out") {
               $tmp = $params1->chain_src_target;
               $params1->chain_src_target = $params1->chain_dst_target;
               $params1->chain_dst_target = $tmp;
            }

            if($params1->chain_src_target != 0 && $params1->chain_dst_target == 0) {

               $hosts = $this->getTargetHosts($params1->chain_src_target);

               foreach($hosts as $host) {
                  if(!$this->check_if_mac($host)) {
                     if($this->isGRE()) {
                        $hex_host = $this->convertIpToHex($host);
                        switch($params1->chain_direction) {
                           case UNIDIRECTIONAL:
                              $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 36 flowid ". $params2 ."");
                              break;
                           case BIDIRECTIONAL:
                              $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 36 flowid ". $params2 ."");
                              $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 40 flowid ". $params2 ."");
                              break;
                        }
                     }
                     else {
                        switch($params1->chain_direction) {
                           case UNIDIRECTIONAL:
                              $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match ip src ". $host ." flowid ". $params2 ."");
                              break;
                           case BIDIRECTIONAL:
                              $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match ip src ". $host ." flowid ". $params2 ."");
                              $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match ip dst ". $host ." flowid ". $params2 ."");
                              break;
                        }
                     }
                  }
                  else {
                     if(preg_match("/(.*):(.*):(.*):(.*):(.*):(.*)/", $host))
                        list($m1, $m2, $m3, $m4, $m5, $m6) = split(":", $host);
                     else
                        list($m1, $m2, $m3, $m4, $m5, $m6) = split("-", $host);

                     $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match u16 0x0800 0xffff at -2 match u16 0x". $m5 . $m6 ." 0xffff at -4 match u32 0x". $m1 . $m2 . $m3 . $m4 ."  0xffffffff at -8 flowid ". $params2 ."");
                  }
               }
            }
            elseif($params1->chain_src_target == 0 && $params1->chain_dst_target != 0) {

               $hosts = $this->getTargetHosts($params1->chain_dst_target);

               foreach($hosts as $host) {
                  if(!$this->check_if_mac($host)) {
                     if($this->isGRE()) {
                        $hex_host = $this->convertIpToHex($host);
                        switch($params1->chain_direction) {
                           case UNIDIRECTIONAL:
                              $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 40 flowid ". $params2 ."");
                              break;
                           case BIDIRECTIONAL:
                              $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 36 flowid ". $params2 ."");
                              $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 40 flowid ". $params2 ."");
                              break;
                        }
                     }
                     else {
                        switch($params1->chain_direction) {
                           case UNIDIRECTIONAL:
                              $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match ip dst ". $host ." flowid ". $params2 ."");
                              break;
                           case BIDIRECTIONAL:
                              $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match ip src ". $host ." flowid ". $params2 ."");
                              $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match ip dst ". $host ." flowid ". $params2 ."");
                              break;
                        }
                     }
                  }
                  else {
                     if(preg_match("/(.*):(.*):(.*):(.*):(.*):(.*)/", $host))
                        list($m1, $m2, $m3, $m4, $m5, $m6) = split(":", $host);
                     else
                        list($m1, $m2, $m3, $m4, $m5, $m6) = split("-", $host);

                     $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match u16 0x0800 0xffff at -2 match u32 0x". $m3 . $m4 . $m5 .$m6 ." 0xffffffff at -12 match u16 0x". $m1 . $m2 ." 0xffff at -14 flowid ". $params2 ."");
                  }
               }
            }
            elseif($params1->chain_src_target != 0 && $params1->chain_dst_target != 0) {

               $src_hosts = $this->getTargetHosts($params1->chain_src_target);

               foreach($src_hosts as $src_host) {
                  if(!$this->check_if_mac($src_host)) {
                     if($this->isGRE()) {
                        $hex_host = $this->convertIpToHex($src_host);
                        $string = TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 36 ";
                     }
                     else {
                        $string = TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match ip src ". $src_host ." ";
                     }
                  }
                  else {
                     if(preg_match("/(.*):(.*):(.*):(.*):(.*):(.*)/", $src_host))
                        list($m1, $m2, $m3, $m4, $m5, $m6) = split(":", $src_host);
                     else
                        list($m1, $m2, $m3, $m4, $m5, $m6) = split("-", $src_host);
                     $string = TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match u16 0x0800 0xffff at -2 match u16 0x". $m5 . $m6 ." 0xffff at -4 match u32 0x". $m1 . $m2 . $m3 . $m4 ." 0xffffffff at -8 ";
                  }

                  $dst_hosts = $this->getTargetHosts($params1->chain_dst_target);

                  foreach($dst_hosts as $dst_host) {

                     if(!$this->check_if_mac($dst_host)) {
                        if($this->isGRE()) {
                           $hex_host = $this->convertIpToHex($dst_host);
                           $this->addRule($string . "match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 40 flowid ". $params2);
                        }
                        else {
                           $this->addRule($string . "match ip dst ". $dst_host ." flowid ". $params2);
                        }
                     }
                     else {
                        if(preg_match("/(.*):(.*):(.*):(.*):(.*):(.*)/", $dst_host))
                           list($m1, $m2, $m3, $m4, $m5, $m6) = split(":", $dst_host);
                        else
                           list($m1, $m2, $m3, $m4, $m5, $m6) = split("-", $dst_host);

                        $this->addRule($string . "match u16 0x0800 0xffff at -2 match u32 0x". $m3 . $m4 . $m5 .$m6 ." 0xffffffff at -12 match u16 0x". $m1 . $m2 ." 0xffff at -14 flowid ". $params2 ."");
                     }
                  }
               }
            }
            break;

         case 'ipt':

            if($ms->getOption("msmode") == "router") 
               $string = IPT_BIN ." -t mangle -A ms-forward -o ". $this->getName();
            elseif($ms->getOption("msmode") == "bridge") 
               $string = IPT_BIN ." -t mangle -A ms-forward -m physdev --physdev-in ". $params5;

            if($chain_direction == "out") {
               $tmp = $params1->chain_src_target;
               $params1->chain_src_target = $params1->chain_dst_target;
               $params1->chain_dst_target = $tmp;
            }

            if($params1->chain_src_target != 0 && $params1->chain_dst_target == 0) {

               $hosts = $this->getTargetHosts($params1->chain_src_target);

               foreach($hosts as $host) {
                  if($this->check_if_mac($host)) {
                     $this->addRule($string ." -m mac --mac-source ". $host ." -j MARK --set-mark ". $ms->getConnmarkId($this->getId(), $params2));
                     $this->addRule($string ." -m mac --mac-source ". $host ." -j RETURN");
                  }
                  else{
                     if(strstr($host, "-") === false) {
                        $this->addRule($string ." -s ". $host ." -j MARK --set-mark ". $ms->getConnmarkId($this->getId(), $params2));
                        $this->addRule($string ." -s ". $host ." -j RETURN");
                     }
                     else {
                        $this->addRule($string ." -m iprange --src-range ". $host ." -j MARK --set-mark ". $ms->getConnmarkId($this->getId(), $params2));
                        $this->addRule($string ." -m iprange --src-range ". $host ." -j RETURN");
                     }
                  }
               }
            }
            elseif($params1->chain_src_target == 0 && $params1->chain_dst_target != 0) {

               $hosts = $this->getTargetHosts($params1->chain_dst_target);

               foreach($hosts as $host) {
                  if($this->check_if_mac($host)) {
                     $this->addRule($string ." -m mac --mac-source ". $host ." -j MARK --set-mark ". $ms->getConnmarkId($this->getId(), $params2));
                     $this->addRule($string ." -m mac --mac-source ". $host ." -j RETURN");
                  }
                  else {
                     if(strstr($host, "-") === false) {
                        $this->addRule($string ." -d ". $host ." -j MARK --set-mark ". $ms->getConnmarkId($this->getId(), $params2));
                        $this->addRule($string ." -d ". $host ." -j RETURN");
                     }
                     else {
                        $this->addRule($string ." -m iprange --dst-range ". $host ." -j MARK --set-mark ". $ms->getConnmarkId($this->getId(), $params2));
                        $this->addRule($string ." -m iprange --dst-range ". $host ." -j RETURN");
                     }
                  }
               }
            }
            elseif($params1->chain_src_target != 0 && $params1->chain_dst_target != 0) {

               $src_hosts = $this->getTargetHosts($params1->chain_src_target);
               $dst_hosts = $this->getTargetHosts($params1->chain_dst_target);

               foreach($src_hosts as $src_host) {
                  if(!$this->check_if_mac($src_host)) {
                     foreach($dst_hosts as $dst_host) {
                        if($this->check_if_mac($dst_host)) {
                           $this->addRule($string ." -m mac --mac-source ". $src_host ." -j MARK --set-mark ". $ms->getConnmarkId($this->getId(), $params2));
                           $this->addRule($string ." -m mac --mac-source ". $dst_host ." -j RETURN");
                        }
                        else {
                           if(strstr($host, "-") === false) {
                              $this->addRule($string ." -s ". $src_host ." -d ". $dst_host ." -j MARK --set-mark ". $ms->getConnmarkId($this->getId(), $params2));
                              $this->addRule($string ." -s ". $src_host ." -d ". $dst_host ." -j RETURN");
                           }
                           else {
                              $this->addRule($string ." -m iprange --src-range ". $src_host ." --dst-range ". $dst_host ." -j MARK --set-mark ". $ms->getConnmarkId($this->getId(), $params2));
                              $this->addRule($string ." -m iprange --src-range ". $src_host ." --dst-range ". $dst_host ." -j RETURN");
                           }
                        }
                     }
                  }
               }
            }
            break;
      }

   } // addHostFilter()

   /**
    * return all host addresses
    *
    * this function returns a array of host addresses for a target definition
    */
   private function getTargetHosts($target_idx)
   {
      global $ms, $db;

      $row = new Target($target_idx);

      $targets = array();

      switch($row->target_match) {

         case 'IP':

            /* for tc-filter we need to need to resolve a IP range
               iptables will use the IPRANGE match for this            
            */
            if($ms->getOption("filter") == "tc") {

               if(strstr($row->target_ip, "-") !== false) {
                  list($host1, $host2) = split("-", $row->target_ip);
                  $host1 = ip2long($host1);
                  $host2 = ip2long($host2);

                  for($i = $host1; $i <= $host2; $i++) 
                     array_push($targets, long2ip($i));
               }
               else 
                  array_push($targets, $row->target_ip);
            }
            else 
               array_push($targets, $row->target_ip);

            break;

         case 'MAC':

            $row->target_mac = str_replace("-", ":", $row->target_mac);
            list($one, $two, $three, $four, $five, $six) = split(":", $row->target_mac);
            $row->target_mac = sprintf("%02s:%02s:%02s:%02s:%02s:%02s", $one, $two, $three, $four, $five, $six);
            array_push($targets, $row->target_mac);
            break;

         case 'GROUP':

            $result = $db->db_query("
               SELECT atg_target_idx
               FROM ". MYSQL_PREFIX ."assign_target_groups 
               WHERE atg_group_idx='". $target_idx ."'
            ");

            while($target = $result->fetchRow()) {
               $members = $this->getTargetHosts($target->atg_target_idx);
               $i = count($targets);
               foreach($members as $member) {
                  $targets[$i] = $member;
                  $i++;
               }
            }
            break;

      }

      return $targets;

   } // getTargetHosts()

   /* set the actually tc handle ID for a chain */
   private function setChainID($chain_idx, $chain_tc_id)
   {
      global $db;

      $db->db_query("INSERT INTO ". MYSQL_PREFIX ."tc_ids (id_pipe_idx, id_chain_idx, id_if, id_tc_id) "
			 ."VALUES ('0', '". $chain_idx ."', '". $this->getName() ."', '". $chain_tc_id ."')");

   } // setChainID()

   /* set the actually tc handle ID for a pipe */ 
   private function setPipeID($pipe_idx, $chain_tc_id, $pipe_tc_id)
   {
      global $db;

      $db->db_query("INSERT INTO ". MYSQL_PREFIX ."tc_ids (id_pipe_idx, id_chain_idx, id_if, id_tc_id) "
			 ."VALUES ('". $pipe_idx ."', '". $chain_tc_id ."', '". $this->getName() ."', '". $pipe_tc_id ."')");
   } // setPipeID()

   /**
     * Generate code to add a pipe filter
     *
     * This function generates the tc/iptables code to filter traffic into a pipe
     * @param string $parent
     * @param string $option
     * @param Filter $filter
     * @param string $my_id
     * @param Pipe $pipe
     */
   private function addPipeFilter($parent, $option, $filter, $my_id, $pipe)
   {
      global $ms;

      /* If this filter matches bidirectional, src & dst target has to be swapped */
      if($pipe->pipe_direction == BIDIRECTIONAL && $chain_direction == "out") {
         $pipe->swap_targets();
      }

      $tmp_str   = "";
      $tmp_array = array();

      switch($ms->getOption("filter")) {

         default:
         case 'tc':

            $string = TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 1 [HOST_DEFS] ";

            /* filter matches a specific network protocol */
            if($filter->filter_protocol_id >= 0) {

               switch($ms->getProtocolNumberById($filter->filter_protocol_id)) {

                  /* TCP */
                  case 6:
                  /* UDP */
                  case 17:
                  /* IP */
                  case 4:

                     $string.= "match ip ";
                     $str_ports = "";
                     $cnt_ports = 0;
                     $ports = $ms->getPorts($filter->filter_idx);

                     if($ports) {

                        while($port = $ports->fetchRow()) {
                           $dst_ports = $ms->extractPorts($port->port_number);
                           if($dst_ports != 0) {
                              foreach($dst_ports as $dst_port) {
                                 $tmp_str = $string ." [DIRECTION] ". $dst_port ." 0xffff ";
                                 if($filter->filter_tos > 0)
                                    $tmp_str.= "match ip tos ". $filter->filter_tos ." 0xff ";

                                 switch($pipe->pipe_direction) {
                                    case UNIDIRECTIONAL:
                                       array_push($tmp_array, str_replace("[DIRECTION]", "dport", $tmp_str));
                                       break;
                                    case BIDIRECTIONAL:
                                       array_push($tmp_array, str_replace("[DIRECTION]", "dport", $tmp_str));
                                       array_push($tmp_array, str_replace("[DIRECTION]", "sport", $tmp_str));
                                       break;
                                 }
                              }
                           }
                        }
                     }
                     break;

                  default:

                     $string.= "match ip protocol ". $ms->getProtocolNumberById($filter->filter_protocol_id) ." 0xff ";
                     array_push($tmp_array, $string);
                     break;
               }
            }
            else 
               array_push($tmp_array, $string);

            if($pipe->pipe_src_target != 0 && $pipe->pipe_dst_target == 0) {

               $hosts = $this->getTargetHosts($pipe->pipe_src_target);
               foreach($hosts as $host) {
                  if(!$this->check_if_mac($host)) {
                     if($this->isGRE()) {
                        foreach($tmp_array as $tmp_arr) {
                           $hex_host = $this->convertIpToHex($host);
                           switch($pipe->pipe_direction) {
                              case UNIDIRECTIONAL:
                                 $this->addRule(str_replace("[HOST_DEFS]", "u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 36", $tmp_arr) ." flowid ". $my_id);
                                 break;
                              case BIDIRECTIONAL:
                                 $this->addRule(str_replace("[HOST_DEFS]", "u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 36", $tmp_arr) ." flowid ". $my_id);
                                 $this->addRule(str_replace("[HOST_DEFS]", "u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 40", $tmp_arr) ." flowid ". $my_id);
                                 break;
                           }
                        }
                     }
                     else {
                        foreach($tmp_array as $tmp_arr) {
                           switch($pipe->pipe_direction) {
                              case UNIDIRECTIONAL:
                                 $this->addRule(str_replace("[HOST_DEFS]", "u32 match ip src ". $host, $tmp_arr) ." flowid ". $my_id);
                                 break;
                              case BIDIRECTIONAL:
                                 $this->addRule(str_replace("[HOST_DEFS]", "u32 match ip src ". $host, $tmp_arr) ." flowid ". $my_id);
                                 $this->addRule(str_replace("[HOST_DEFS]", "u32 match ip dst ". $host, $tmp_arr) ." flowid ". $my_id);
                                 break;
                           }
                        }
                     }  
                  }		 
                  else {
                     foreach($tmp_array as $tmp_arr) {

                        if(preg_match("/(.*):(.*):(.*):(.*):(.*):(.*)/", $host))
                           list($m1, $m2, $m3, $m4, $m5, $m6) = split(":", $host);
                        else
                           list($m1, $m2, $m3, $m4, $m5, $m6) = split("-", $host);

                        switch($pipe->pipe_direction) {
                           case UNIDIRECTIONAL:
                              $this->addRule(str_replace("[HOST_DEFS]", "u32 match u16 0x0800 0xffff at -2 match u16 0x". $m5 . $m6 ." 0xffff at -4 match u32 0x". $m1 . $m2 . $m3 . $m4 ." 0xffffffff at -8 ", $tmp_arr) ." flowid ". $my_id);
                              break;
                           case BIDIRECTIONAL:
                              $this->addRule(str_replace("[HOST_DEFS]", "u32 match u16 0x0800 0xffff at -2 match u16 0x". $m5 . $m6 ." 0xffff at -4 match u32 0x". $m1 . $m2 . $m3 . $m4 ." 0xffffffff at -8 ", $tmp_arr) ." flowid ". $my_id);
                              $this->addRule(str_replace("[HOST_DEFS]", "u32 match u16 0x0800 0xffff at -2 match u32 0x". $m3 . $m4 . $m5 .$m6 ." 0xffffffff at -12 match u16 0x". $m1 . $m2 ." 0xffff at -14 ", $tmp_arr) ." flowid ". $my_id);
                              break;
			               }
                     }
                  }
               }
            }
            elseif($pipe->pipe_src_target == 0 && $pipe->pipe_dst_target != 0) {

               $hosts = $this->getTargetHosts($pipe->pipe_dst_target);
               foreach($hosts as $host) {
                  if(!$this->check_if_mac($host)) {
                     if($this->isGRE()) {
                        foreach($tmp_array as $tmp_arr) {
                           $hex_host = $this->convertIpToHex($host);
                           switch($pipe->pipe_direction) {
                              case UNIDIRECTIONAL:
                                 $this->addRule(str_replace("[HOST_DEFS]", "u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 40", $tmp_arr) ." flowid ". $my_id);
                                 break;
                              case BIDIRECTIONAL:
                                 $this->addRule(str_replace("[HOST_DEFS]", "u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 40", $tmp_arr) ." flowid ". $my_id);
                                 $this->addRule(str_replace("[HOST_DEFS]", "u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at 36", $tmp_arr) ." flowid ". $my_id);
                                 break;
                           }
                        }
                     }
                     else {
                        foreach($tmp_array as $tmp_arr) {

                           switch($pipe->pipe_direction) {
                              case UNIDIRECTIONAL:
                                 $this->addRule(str_replace("[HOST_DEFS]", "u32 match ip dst ". $host, $tmp_arr) ." flowid ". $my_id);
                                 break;
                              case BIDIRECTIONAL:
                                 $this->addRule(str_replace("[HOST_DEFS]", "u32 match ip dst ". $host, $tmp_arr) ." flowid ". $my_id);
                                 $this->addRule(str_replace("[HOST_DEFS]", "u32 match ip src ". $host, $tmp_arr) ." flowid ". $my_id);
                                 break;
                           }
                        }
                     }
                  }
                  else {

                     foreach($tmp_array as $tmp_arr) {

                        if(preg_match("/(.*):(.*):(.*):(.*):(.*):(.*)/", $host))
                           list($m1, $m2, $m3, $m4, $m5, $m6) = split(":", $host);
                        else
                           list($m1, $m2, $m3, $m4, $m5, $m6) = split("-", $host);

                        switch($pipe->pipe_direction) {
                           case UNIDIRECTIONAL:
                              $this->addRule(str_replace("[HOST_DEFS]", "u32 match u16 0x0800 0xffff at -2 match u32 0x". $m3 . $m4 . $m5 .$m6 ." 0xffffffff at -12 match u16 0x". $m1 . $m2 ." 0xffff at -14 ", $tmp_arr) ." flowid ". $my_id);
                              break;
                           case BIDIRECTIONAL:
                              $this->addRule(str_replace("[HOST_DEFS]", "u32 match u16 0x0800 0xffff at -2 match u32 0x". $m3 . $m4 . $m5 .$m6 ." 0xffffffff at -12 match u16 0x". $m1 . $m2 ." 0xffff at -14 ", $tmp_arr) ." flowid ". $my_id);
                              $this->addRule(str_replace("[HOST_DEFS]", "u32 match u16 0x0800 0xffff at -2 match u16 0x". $m5 . $m6 ." 0xffff at -4 match u32 0x". $m1 . $m2 . $m3 . $m4 ." 0xffffffff at -8 ", $tmp_arr) ." flowid ". $my_id);
                              break;
                        }
                     }
                  }  
               }
            }
            elseif($pipe->pipe_src_target != 0 && $pipe->pipe_dst_target != 0) {

               $src_hosts = $this->getTargetHosts($pipe->pipe_src_target);

               foreach($src_hosts as $src_host) {
                  if(!$this->check_if_mac($src_host)) {
                     if($this->isGRE()) {
                        $hex_host = $this->convertIpToHex($src_host);
                        $tmp_str = "u32 match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at [DIR1] ";
                     }
                     else {
                        $tmp_str = "u32 match ip [DIR1] ". $src_host ." ";
                     }
                  }
                  else {
                     if(preg_match("/(.*):(.*):(.*):(.*):(.*):(.*)/", $src_host))
                        list($sm1, $sm2, $sm3, $sm4, $sm5, $sm6) = split(":", $src_host);
                     else
                        list($sm1, $sm2, $sm3, $sm4, $sm5, $sm6) = split("-", $src_host);
 
                     $tmp_str = "u32 [DIR1] [DIR2]";
                  }

                  $dst_hosts = $this->getTargetHosts($pipe->pipe_dst_target);
                  foreach($dst_hosts as $dst_host) {

                     if(!$this->check_if_mac($dst_host)) {

                        if($this->isGRE()) {
                           foreach($tmp_array as $tmp_arr) {
                              $hex_host = $this->convertIpToHex($dst_host);
                              switch($pipe->pipe_direction) {

                                 case UNIDIRECTIONAL:
                                    $string = str_replace("[HOST_DEFS]", $tmp_str . "match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at [DIR2] ", $tmp_arr);
                                    $string = str_replace("[DIR1]", "36", $string);
                                    $string = str_replace("[DIR2]", "40", $string);
                                    $this->addRule($string ." flowid ". $my_id);
                                    break;

                                 case BIDIRECTIONAL:
                                    $string = str_replace("[HOST_DEFS]", $tmp_str . "match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at [DIR2] ", $tmp_arr);
                                    $string = str_replace("[DIR1]", "36", $string);
                                    $string = str_replace("[DIR2]", "40", $string);
                                    $this->addRule($string ." flowid ". $my_id);
                                    $string = str_replace("[HOST_DEFS]", $tmp_str . "match u32 0x". $hex_host['ip'] ." ". $hex_host['netmask'] ." at [DIR2] ", $tmp_arr);
                                    $string = str_replace("[DIR1]", "40", $string);
                                    $string = str_replace("[DIR2]", "36", $string);
                                    $this->addRule($string ." flowid ". $my_id);
                                    break;
                              }
                           }
                        }
                        else {

                           foreach($tmp_array as $tmp_arr) {

                              switch($pipe->pipe_direction) {

                                 case UNIDIRECTIONAL:
                                    $string = str_replace("[HOST_DEFS]", $tmp_str . "match ip [DIR2] ". $dst_host, $tmp_arr);
                                    $string = str_replace("[DIR1]", "src", $string);
                                    $string = str_replace("[DIR2]", "dst", $string);
                                    $this->addRule($string ." flowid ". $my_id);
                                    break;

                                 case BIDIRECTIONAL:
                                    $string = str_replace("[HOST_DEFS]", $tmp_str . "match ip [DIR2] ". $dst_host, $tmp_arr);
                                    $string = str_replace("[DIR1]", "src", $string);
                                    $string = str_replace("[DIR2]", "dst", $string);
                                    $this->addRule($string ." flowid ". $my_id);
                                    $string = str_replace("[HOST_DEFS]", $tmp_str . "match ip [DIR2] ". $dst_host, $tmp_arr);
                                    $string = str_replace("[DIR1]", "dst", $string);
                                    $string = str_replace("[DIR2]", "src", $string);
                                    $this->addRule($string ." flowid ". $my_id);
                                    break;
                              }
                           }
                        }
                     }
                     else {

                        if(preg_match("/(.*):(.*):(.*):(.*):(.*):(.*)/", $dst_host))
                           list($dm1, $dm2, $dm3, $dm4, $dm5, $dm6) = split(":", $dst_host);
                        else
                           list($dm1, $dm2, $dm3, $dm4, $dm5, $dm6) = split("-", $dst_host);

                        foreach($tmp_array as $tmp_arr) {

                           switch($pipe->pipe_direction) {

                              case UNIDIRECTIONAL:
                                 $string = str_replace("[HOST_DEFS]", $tmp_str . "match ip [DIR2] ". $dst_host, $tmp_arr);
                                 $string = str_replace("[DIR1]", "src", $string);
                                 $string = str_replace("[DIR2]", "dst", $string);
                                 $this->addRule($string ." flowid ". $my_id);
                                 break;

                              case BIDIRECTIONAL:
                                 $string = str_replace("[HOST_DEFS]", $tmp_str, $tmp_arr);
                                 $string = str_replace("[DIR1]", "match u16 0x0800 0xffff at -2 match u16 0x". $sm5 . $sm6 ." 0xffff at -4 match u32 0x". $sm1 . $sm2 . $sm3 . $sm4 ." 0xffffffff at -8", $string);
                                 $string = str_replace("[DIR2]", "match u16 0x0800 0xffff at -2 match u32 0x". $dm3 . $dm4 . $dm5 .$dm6 ." 0xffffffff at -12 match u16 0x". $dm1 . $dm2 ." 0xffff at -14", $string);
                                 $this->addRule($string ." flowid ". $my_id);
                                 $string = str_replace("[HOST_DEFS]", $tmp_str, $tmp_arr);
                                 $string = str_replace("[DIR1]", "match u16 0x0800 0xffff at -2 match u32 0x". $sm3 . $sm4 . $sm5 .$sm6 ." 0xffffffff at -12 match u16 0x". $sm1 . $sm2 ." 0xffff at -14", $string);
                                 $string = str_replace("[DIR2]", "match u16 0x0800 0xffff at -2 match u16 0x". $dm5 . $dm6 ." 0xffff at -4 match u32 0x". $dm1 . $dm2 . $dm3 . $dm4 ." 0xffffffff at -8", $string);
                                 $this->addRule($string ." flowid ". $my_id);
                                 break;

                           }
                        }
                     }
                  }
               }
            }
            else {

               foreach($tmp_array as $tmp_arr)
                  $this->addRule(str_replace("[HOST_DEFS]", "u32", $tmp_arr) ." flowid ". $my_id);

            }
	    
            break;

         case 'ipt':

            $match_str = "";
            $cnt= 0;
            $str_p2p   = "";
            $match_ary = Array();
            $proto_ary = Array();

	         // Construct a string with all used ipt matches
 
            /* If this filter should match on ftp data connections add the rules here */
            if($filter->filter_match_ftp_data == "Y") {
               $this->addRule(IPT_BIN ." -t mangle -A ms-chain-". $this->getName() ."-". $parent ." --match conntrack --ctproto tcp --ctstate RELATED,ESTABLISHED --match helper --helper ftp -j CLASSIFY --set-class ". $my_id);
               $this->addRule(IPT_BIN ." -t mangle -A ms-chain-". $this->getName() ."-". $parent ." --match conntrack --ctproto tcp --ctstate RELATED,ESTABLISHED --match helper --helper ftp -j RETURN");
            }

            /* If this filter should match on SIP data streans (RTP / RTCP) add the rules here */
            if($filter->filter_match_sip == "Y") {
               $this->addRule(IPT_BIN ." -t mangle -A ms-chain-". $this->getName() ."-". $parent ." --match conntrack --ctproto udp --ctstate RELATED,ESTABLISHED --match helper --helper sip -j CLASSIFY --set-class ". $my_id);
               $this->addRule(IPT_BIN ." -t mangle -A ms-chain-". $this->getName() ."-". $parent ." --match conntrack --ctproto udp --ctstate RELATED,ESTABLISHED --match helper --helper sip -j RETURN");

            }

            // filter matches on protocols 
            if($filter->filter_protocol_id >= 0) {

               switch($ms->getProtocolNumberById($filter->filter_protocol_id)) {
		  
                  /* IP */
                  case 4:
                     array_push($proto_ary, " -p 6");
                     array_push($proto_ary, " -p 17");
                     break;
                  default:
                     array_push($proto_ary, " -p ". $ms->getProtocolNumberById($filter->filter_protocol_id));
                     break;
               }

               // Select for TCP flags (only valid for TCP protocol)
               if($ms->getProtocolNumberById($filter->filter_protocol_id) == 6) {

                  $str_tcpflags = "";

                  if($filter->filter_tcpflag_syn == "Y")
                     $str_tcpflags.= "SYN,";
                  if($filter->filter_tcpflag_ack == "Y")
                     $str_tcpflags.= "ACK,";
                  if($filter->filter_tcpflag_fin == "Y")
                     $str_tcpflags.= "FIN,";
                  if($filter->filter_tcpflag_rst == "Y")
                     $str_tcpflags.= "RST,";
                  if($filter->filter_tcpflag_urg == "Y")
                     $str_tcpflags.= "URG,";
                  if($filter->filter_tcpflag_psh == "Y")
                     $str_tcpflags.= "PSH,";

                  if($str_tcpflags != "")
                     $match_str.= " --tcp-flags ". substr($str_tcpflags, 0, strlen($str_tcpflags)-1) ." ". substr($str_tcpflags, 0, strlen($str_tcpflags)-1);

               }

               // Get all the used ports for IP, TCP or UDP 
               switch($ms->getProtocolNumberById($filter->filter_protocol_id)) {

                  case 4:  // IP
                  case 6:  // TCP
                  case 17: // UDP
                     $all_ports = array();
                     $cnt_ports = 0;

                     // Which ports are selected for this filter 
                     $ports = $ms->getPorts($filter->filter_idx);

                     if($ports) {
                        while($port = $ports->fetchRow()) {
                           // If this port is definied as range or list get all the single ports 
                           $dst_ports = $ms->extractPorts($port->port_number);
                           if($dst_ports != 0) {
                              foreach($dst_ports as $dst_port) {
                                 array_push($all_ports, $dst_port);
                                 $cnt_ports++;
                              }
                           }
                        }
                     }
                     break;
               }
            }
            else
               array_push($proto_ary, "");

            // Layer7 protocol matching 
            if($l7protocols = $ms->getL7Protocols($filter->filter_idx)) {
		  
               $l7_cnt = 0;
               $l7_protos = array();

               while($l7proto = $l7protocols->fetchRow()) {
                  array_push($l7_protos, $l7proto->l7proto_name);
                  $l7_cnt++;
               }
            }

            // TOS flags matching 
            if($filter->filter_tos > 0)
               $match_str.= " -m tos --tos ". $filter->filter_tos;

            // packet length matching 
            if($filter->filter_packet_length > 0)
               $match_str.= " -m length --length ". $filter->filter_packet_length;

            // time range matching 
            if($filter->filter_time_use_range == "Y") {
               $start = strftime("%Y:%m:%d:%H:%M:00", $filter->filter_time_start);
               $stop = strftime("%Y:%m:%d:%H:%M:00", $filter->filter_time_stop);
               $match_str.= " -m time --datestart ". $start ." --datestop ". $stop;
            }
            else {
               $str_days = "";
               if($filter->filter_time_day_mon == "Y")
                  $str_days.= "Mon,";
               if($filter->filter_time_day_tue == "Y")
                  $str_days.= "Tue,";
               if($filter->filter_time_day_wed == "Y")
                  $str_days.= "Wed,";
               if($filter->filter_time_day_thu == "Y")
                  $str_days.= "Thu,";
               if($filter->filter_time_day_fri == "Y")
                  $str_days.= "Fri,";
               if($filter->filter_time_day_sat == "Y")
                  $str_days.= "Sat,";
               if($filter->filter_time_day_sun == "Y")
                  $str_days.= "Sun,";

               if($str_days != "")
                  $match_str.= " -m time --days ". substr($str_days, 0, strlen($str_days)-1);
            }

            // IPP2P matching 
            if($filter->filter_p2p_edk == "Y")
               $str_p2p.= "--edk ";
            if($filter->filter_p2p_kazaa == "Y")
               $str_p2p.= "--kazaa ";
            if($filter->filter_p2p_dc == "Y")
               $str_p2p.= "--dc ";
            if($filter->filter_p2p_gnu == "Y")
               $str_p2p.= "--gnu ";
            if($filter->filter_p2p_bit == "Y")
               $str_p2p.= "--bit ";
            if($filter->filter_p2p_apple == "Y")
               $str_p2p.= "--apple ";
            if($filter->filter_p2p_soul == "Y")
               $str_p2p.= "--soul ";
            if($filter->filter_p2p_winmx == "Y")
               $str_p2p.= "--winmx ";
            if($filter->filter_p2p_ares == "Y")
               $str_p2p.= "--ares ";

            if($str_p2p != "")
               $match_str.= " -m ipp2p ". substr($str_p2p, 0, strlen($str_p2p)-1);

            // End of match string
	 
            /* All port matches will be matched with the iptables multiport */
            /* (advantage is that src&dst matches can be done with a simple */
            /* --port */

            switch($ms->getProtocolNumberById($filter->filter_protocol_id)) {

               /* TCP, UDP or IP */
               case 4:
               case 6:
               case 17:
		  
                  if($cnt_ports > 0) {
                     switch($pipe->pipe_direction) {
                        /* 1 = incoming, 3 = both */
                        case UNIDIRECTIONAL:
                           $match_str.= " -m multiport --dport ";
                           break;
                        case BIDIRECTIONAL:
                           $match_str.= " -m multiport --port ";
                           break;
                     }

                     $j = 0;
                     for($i = 0; $i <= $cnt_ports; $i++) {
                        if($j == 0)
                           $tmp_ports = "";

                        if(isset($all_ports[$i]))
                           $tmp_ports.= $all_ports[$i] .",";

                        // with one multiport match iptables can max. match 14 single ports 
                        if($j == 14 || $i == $cnt_ports-1) {
                           $tmp_str = $match_str . substr($tmp_ports, 0, strlen($tmp_ports)-1); 
                           array_push($match_ary, $tmp_str);
                           $j = 0;
                        }
                        else 
                           $j++;
                     }
                  }
                  break;

               default:

                  // is there any l7 filter protocol we have to attach to the filter? 
                  if(isset($l7_cnt) && $l7_cnt > 0) {
                     foreach($l7_protos as $l7_proto) {
                        array_push($match_ary, $match_str ." -m layer7 --l7proto ". $l7_proto);
                     }
                  }
                  else 
                     array_push($match_ary, $match_str); 
                  break;
            }

            foreach($match_ary as $match_str) {

               $ipt_tmpl = IPT_BIN ." -t mangle -A ms-chain-". $this->getName() ."-". $parent;

               if($pipe->pipe_src_target != 0 && $pipe->pipe_dst_target == 0) {
                  $src_hosts = $this->getTargetHosts($pipe->pipe_src_target);
                  foreach($src_hosts as $src_host) {
                     foreach($proto_ary as $proto_str) {
                        if(strstr("-", $src_host) === false) {
                           $this->addRule($ipt_tmpl ." -s ". $src_host ." ". $proto_str ." ". $match_str ." -j CLASSIFY --set-class ". $my_id);
                           $this->addRule($ipt_tmpl ." -s ". $src_host ." ". $proto_str ." ". $match_str ." -j RETURN");
                        }
                        else {
                           $this->addRule($ipt_tmpl ." -m iprange --src-range ". $src_host ." ". $proto_str ." ". $match_str ." -j CLASSIFY --set-class ". $my_id);
                           $this->addRule($ipt_tmpl ." -m iprange --src-range ". $src_host ." ". $proto_str ." ". $match_str ." -j RETURN");
                        }
                     }
                  }
               }
               elseif($pipe->pipe_src_target == 0 && $pipe->pipe_dst_target != 0) {
                  $dst_hosts = $this->getTargetHosts($pipe->pipe_dst_target);
                  foreach($dst_hosts as $dst_host) {
                     foreach($proto_ary as $proto_str) {
                        if(strstr("-", $dst_host) === false) {
                           $this->addRule($ipt_tmpl ." -d ". $dst_host ." ". $proto_str ." ". $match_str ." -j CLASSIFY --set-class ". $my_id);
                           $this->addRule($ipt_tmpl ." -d ". $dst_host ." ". $proto_str ." ". $match_str ." -j RETURN");
                        }
                        else {
                           $this->addRule($ipt_tmpl ." -m iprange --dst-range ". $dst_host ." ". $proto_str ." ". $match_str ." -j CLASSIFY --set-class ". $my_id);
                           $this->addRule($ipt_tmpl ." -m iprange --dst-range ". $dst_host ." ". $proto_str ." ". $match_str ." -j RETURN");
                        }
                     }
                  }
               }
               elseif($pipe->pipe_src_target != 0 && $pipe->pipe_dst_target != 0) {
                  $src_hosts = $this->getTargetHosts($pipe->pipe_src_target);
                  $dst_hosts = $this->getTargetHosts($pipe->pipe_dst_target);
                  foreach($src_hosts as $src_host) {
                     foreach($dst_hosts as $dst_host) {
                        foreach($proto_ary as $proto_str) {
                           if(strstr("-", $dst_host) === false) {
                              $this->addRule($ipt_tmpl ." -s ". $src_host ." -d ". $dst_host ." ". $proto_str ." ". $match_str ." -j CLASSIFY --set-class ". $my_id);
                              $this->addRule($ipt_tmpl ." -s ". $src_host ." -d ". $dst_host ." ". $proto_str ." ". $match_str ." -j RETURN");
                           }
                           else {
                              $this->addRule($ipt_tmpl ." -m iprange --src-range ". $src_host ." --dst-range ". $dst_host ." ". $proto_str ." ". $match_str ." -j CLASSIFY --set-class ". $my_id);
                              $this->addRule($ipt_tmpl ." -m iprange --src-range ". $src_host ." --dst-range ". $dst_host ." ". $proto_str ." ". $match_str ." -j RETURN");

                           }
                        }
                     }
                  }
               }
               elseif($pipe->pipe_src_target == 0 && $pipe->pipe_dst_target == 0) {
                  foreach($proto_ary as $proto_str) {
                     $this->addRule($ipt_tmpl ." ". $proto_str ." ". $match_str ." -j CLASSIFY --set-class ". $my_id);
                     $this->addRule($ipt_tmpl ." ". $proto_str ." ". $match_str ." -j RETURN");
                  }
               }
            }
            break;

      }

   } // addPipeFilter()

   private function addFallbackFilter($parent, $filter = "")
   {
      global $ms;

      switch($ms->getOption("filter")) {

	 default:
	 case 'tc':
	    $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 5 u32 match u32 0 0 flowid ". $filter);
	    break;
	 case 'ipt':
	    $this->addRule(IPT_BIN ." -t mangle -A ms-chain-". $this->getName() ."-". $parent ." -j CLASSIFY --set-class ". $filter);
	    $this->addRule(IPT_BIN ." -t mangle -A ms-chain-". $this->getName() ."-". $parent ." -j RETURN");
	    break;
      }

   } // addFallbackFilter()

   private function addMatchallFilter($parent, $filter = "")
   {
      global $ms;

      switch($ms->getOption("filter")) {
         case 'tc':
            $this->addRule(TC_BIN ." filter add dev ". $this->getName() ." parent ". $parent ." protocol all prio 2 u32 match u32 0 0 classid ". $filter);
            break;

         case 'ipt':
            if($ms->getOption("msmode") == "router") {
               //$this->addRule(IPT_BIN ." -t mangle -A ms-forward -o ". $this->getName() ." -j ms-chain-". $this->getName() ."-". $filter);
               $this->addRule(IPT_BIN ." -t mangle -A ms-forward -o ". $this->getName() ." -j MARK --set-mark ". $ms->getConnmarkId($this->getId(), $filter));
               $this->addRule(IPT_BIN ." -t mangle -A ms-forward -o ". $this->getName() ." -j RETURN");
            }
            elseif($ms->getOption("msmode") == "bridge") {
               $this->addRule(IPT_BIN ." -t mangle -A ms-forward -m physdev --physdev-in ". $this->getName() ." -j MARK --set-mark ". $ms->getConnmarkId($this->getId(), $filter));
               $this->addRule(IPT_BIN ." -t mangle -A ms-forward -m physdev --physdev-in ". $this->getName() ." -j RETURN");
            }
            break;

      }

   } // addMatchallFilter()

   /**
    * build chain-ruleset
    *
    * this function will build up the chain-ruleset necessary
    * for the provided network path and direction.
    */
   public function buildChains($netpath_idx, $direction)
   {
      global $ms;

      $this->addRuleComment("Rules for interface ". $this->getName());
      $chains = $this->getChains($netpath_idx);

      while($chain = $chains->fetchRow()) {
         $this->addRuleComment("chain ". $chain->chain_name ."");
         /* chain doesn't ignore QoS? */
         if($chain->chain_sl_idx != 0)
            $this->addClass("1:1", "1:". $this->current_chain . $this->current_class, $ms->get_service_level($chain->chain_sl_idx), $direction);

         /* remember the assigned chain id */
         $this->setChainID($chain->chain_idx, "1:". $this->current_chain . $this->current_class, "dst", "src");

         if($ms->getOption("filter") == "ipt") {
            $this->addRule(IPT_BIN ." -t mangle -N ms-chain-". $this->getName() ."-1:". $this->current_chain . $this->current_filter);
            $this->addRule(IPT_BIN ." -t mangle -A ms-postrouting -m connmark --mark ". $ms->getConnmarkId($this->getId(), "1:". $this->current_chain . $this->current_filter) ." -j ms-chain-". $this->getName() ."-1:". $this->current_chain . $this->current_filter);
         }
		   
         /* setup the filter definition to match traffic which should go into this chain */
         if($chain->chain_src_target != 0 || $chain->chain_dst_target != 0) {
            $this->addHostFilter("1:1", "host", $chain, "1:". $this->current_chain . $this->current_filter, $direction);
         } else {
            $this->addMatchallFilter("1:1", "1:". $this->current_chain . $this->current_filter);
         }

         /* chain doesn't ignore QoS? */
         if($chain->chain_sl_idx != 0) {

            /* chain uses fallback service level? */
            if($chain->chain_fallback_idx != 0) {

               $this->addRuleComment("generating pipes for ". $chain->chain_name ."");
               $this->buildPipes($chain->chain_idx, "1:". $this->current_chain . $this->current_class, $direction);

               // Fallback
               $this->addClass("1:". $this->current_chain . $this->current_class, "1:". $this->current_chain ."99", $ms->get_service_level($chain->chain_fallback_idx), $direction, $ms->get_service_level($chain->chain_sl_idx));
               $this->addSubQdisc($this->current_chain ."99:", "1:". $this->current_chain ."99", $ms->get_service_level($chain->chain_fallback_idx));
               $this->addFallbackFilter("1:". $this->current_chain . $this->current_class, "1:". $this->current_chain ."99");
               $this->setPipeID(-1, $chain->chain_idx, "1:". $this->current_chain ."99");
            }
            else {
               $this->addRuleComment("chain without service level");
               $this->addSubQdisc($this->current_chain . $this->current_class .":", "1:". $this->current_chain . $this->current_class, $ms->get_service_level($chain->chain_sl_idx));
            }
         }

//	 $this->current_class  = 1;
//	 $this->current_filter = 1;
	 //$this->current_chain  = dechex(hexdec($this->current_chain) + 1);
         $this->current_chain+=1;

      }

   } // buildChains()

   private function getChains($netpath_idx)
   {
      global $db;

      return $db->db_query("SELECT * FROM ". MYSQL_PREFIX ."chains WHERE chain_active='Y' AND "
         ."chain_netpath_idx='". $netpath_idx ."' ORDER BY chain_position ASC");

   } // getChains()

   /* build ruleset for incoming pipes */
   private function buildPipes($chain_idx, $my_parent, $chain_direction)
   {
      global $ms, $db;

      /* get all active pipes for this chain */
      $pipes = $db->db_query("
         SELECT
            p.*
         FROM
            ". MYSQL_PREFIX ."pipes p
         INNER JOIN
            ". MYSQL_PREFIX ."assign_pipes_to_chains apc
         ON
            p.pipe_idx=apc.apc_pipe_idx
         WHERE
            p.pipe_active='Y'
         AND
            apc.apc_chain_idx='". $chain_idx ."'
         ORDER BY
            p.pipe_position ASC"
      );

      while($pipe = $pipes->fetchRow()) {

         $this->current_pipe+=1;
         $my_id = "1:". $this->current_chain . $this->current_pipe;
         $this->addRuleComment("pipe ". $pipe->pipe_name ."");

         $sl = $ms->get_service_level($pipe->pipe_sl_idx);

         /* add a new class for this pipe */
         $this->addClass($my_parent, $my_id, $sl, $chain_direction);
         $this->addSubQdisc($this->current_chain . $this->current_pipe .":", $my_id, $sl);
         $this->setPipeID($pipe->pipe_idx, $chain_idx, "1:". $this->current_chain . $this->current_pipe);

         /* get the nescassary parameters */
         $filters = $ms->getFilters($pipe->pipe_idx);

         if($filters->numRows() > 0) {
            while($filter = $filters->fetchRow()) {
               $detail = new Filter($filter->apf_filter_idx);
               $this->addPipeFilter($my_parent, "pipe_filter", $detail, $my_id, $pipe);
            }
         }
         else {
            $this->addPipeFilter($my_parent, "pipe_filter", $detail, $my_id, $pipe);
         }
      }

   } // buildPipes()

   private function iptInitRulesIf() 
   {
      global $ms;

      if($ms->getOption("msmode") == "router") {
         $this->addRule(IPT_BIN ." -t mangle -A FORWARD -o ". $this->getName() ." -j ms-forward");
         $this->addRule(IPT_BIN ." -t mangle -A OUTPUT -o ". $this->getName() ." -j ms-forward");
         $this->addRule(IPT_BIN ." -t mangle -A POSTROUTING -o ". $this->getName() ." -j ms-postrouting");
      }
      else {
         $this->addRule(IPT_BIN ." -t mangle -A POSTROUTING -m physdev --physdev-out ". $this->getName() ." -j ms-postrouting");
      }

   } // iptInitRulesIf()

   /**
    * initialize the current interface
    *
    * this function which initialize the current interface, which means
    * to prepare all the necessary tc-rules and add them to the buffer
    * to be executed later when loading the rules.
    */
   public function Initialize($direction)
   {
      global $ms;

      $ack_sl = $ms->getOption("ack_sl");

      $this->addRuleComment("Initialize Interface ". $this->getName());

      $this->addRootQdisc("1:");


      /* Initial iptables rules */
      if($ms->getOption("filter") == "ipt") 
         $this->iptInitRulesIf();

      $this->addInitClass("1:", "1:1");
      $this->addInitFilter("1:0");

      /* ACK options */
      if($ack_sl != 0) {

         $this->addRuleComment("boost ACK packets");
         $this->addClass("1:1", "1:2", $ms->get_service_level($ack_sl), $direction);
         $this->addSubQdisc("2:", "1:2", $ms->get_service_level($ack_sl));
         $this->addAckFilter("1:1", "ack", "1:2", "1");

      }

      $this->setStatus(true);

   } // Initialize()

   /**
    * check if MAC address
    *
    * check if specified host consists a MAC address.
    * @return true, false
    */
   private function check_if_mac($host)
   {
      if(preg_match("/(.*):(.*):(.*):(.*):(.*):(.*)/", $host) ||
         preg_match("/(.*)-(.*)-(.*)-(.*)-(.*)-(.*)/", $host))
         return true;

      return false;

   } // check_if_mac()

   /**
    * convert an IP address into a hex value
    *
    * @param string $IP
    * @return string
    */
   private function convertIpToHex($host)
   {
      $ipv4 = new Net_IPv4;
      $parsed = $ipv4->parseAddress($host);
      if(!$ipv4->validateIP($parsed->ip))
         return _("Incorrect IP address! Can not convert it to hex!");
      if(!$ipv4->validateNetmask($parsed->netmask))
         return _("Incorrect Netmask! Can not convert it to hex!");

      if(($hex_host = $ipv4->atoh($parsed->ip)) == false)
         die(_("Failed to convert ". $parsed->ip ." to hex!"));

      if(($hex_subnet = $ipv4->atoh($parsed->netmask)) == false)
         die(_("Failed to convert ". $parsed->netmask ." to hex!"));

      return array('ip' => $hex_host, 'netmask' => $hex_subnet);

   } // convertIpToHex

} // class Ruleset_Interface

?>