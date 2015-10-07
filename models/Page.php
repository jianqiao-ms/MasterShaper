<?php

/**
 * @package MasterShaper
 * @subpackage Page
 */

class Page {

   static private $instance;
   private $sth_page;

   static function instance($page = null)
   {
      if(!self::$instance) {
         self::$instance = new Page($page);
      }
      return self::$instance;
   }

   private function __construct($page = null)
   {
      global $db;

      if(isset($page))
         $this->parse($page);

      if(isset($_SERVER['REQUEST_URI']))
         $this->uri = $_SERVER['REQUEST_URI'];

      if(isset($_SERVER['SCRIPT_URI']))
         $this->self = $_SERVER['SCRIPT_URI'];

   } // __construct()

   public function parse($page)
   {
      global $ms, $db, $rewriter;

      if(!isset($this->sth_page)) {

         $this->sth_page = $db->db_prepare("
            SELECT
               *
            FROM
               ". MYSQL_PREFIX ."pages
            WHERE
               ? REGEXP page_uri_pattern
         ");

      }

      $db->db_execute($this->sth_page, array(
         $page,
      ));

      if($this->sth_page->rowCount() <= 0) {
         $db->db_sth_free($this->sth_page);
         return false;
      }

      if(($row = $this->sth_page->fetch()) === false) {
         $db->db_sth_free($this->sth_page);
         return false;
      }

      if(!isset($row->page_includefile)) {
         $db->db_sth_free($this->sth_page);
         return false;
      }

      $db->db_sth_free($this->sth_page);

      $this->name = $row->page_name;
      $this->uri  = $row->page_uri;
      $this->uri_pattern = $row->page_uri_pattern;
      $this->includefile = $row->page_includefile;

      if($this->name == 'RPC Call') {
         if(!isset($_POST['type']) || !isset($_POST['action']))
            return false;
         if(!is_string($_POST['type']) || !isset($_POST['action']))
            return false;
         if($_POST['type'] != "rpc")
            return false;
         $this->call_type = "rpc";
         $this->action = $_POST['action'];
         return true;
      }

      /* chains-123.html, pipes-12.html, ... */
      if(preg_match('/(.*)-([0-9]+)/', $rewriter->request)) {
         preg_match('/.*\/(.*)-([0-9]+)/', $rewriter->request, $parts);

         if(!$this->is_valid_action($parts[1]))
            $ms->throwError('Invalid action: '. $parts[1]);
         if(!$this->is_valid_id($parts[2]))
            $ms->throwError('Invalid id: '. $parts[2]);

         $this->action = $parts[1];
         $this->id = $parts[2];
      }
      /* overview.html, rules.html, ... */
      elseif(preg_match('/.*\/.*\.html$/', $rewriter->request)) {
         preg_match('/.*\/(.*)\.html$/', $rewriter->request, $parts);
         if(!$this->is_valid_action($parts[1]))
            $ms->throwError('Invalid action: '. $parts[1]);

         $this->action = $parts[1];
      }
      /* register further _GET parameters */
      if(isset($_GET) && is_array($_GET) && !empty($_GET)) {
         foreach($_GET as $key => $value)
            $this->$key = htmlentities($value, ENT_QUOTES);
      }

      $this->call_type = "common";
      return true;

   } // parse()

   /**
    * set a different page
    *
    * @param string $new_page
    */
   public function set_page($new_page)
   {
      $this->parse($new_page);      

   } // set_page()

   /**
    * checks if the requested action is valid
    *
    * @param string $action
    * @return bool
    */
   private function is_valid_action($action)
   {
      $valid_actions = array(
         'overview',
         'login',
         'logout',
         'show',
         'list',
         'new',
         'edit',
         'manage',
         'settings',
         'options',
         'rules',
         'others',
         'load',
         'load-debug',
         'unload',
         'about',
         'mode',
         'chains',
         'pipes',
         'bandwidth',
         'rpc',
         'tasklist',
         'update-iana',
         'update-l7',
      );

      if(in_array($action, $valid_actions))
         return true;

      return false;

   } // is_valid_action()

   /**
    * checks if the submitted id is valid
    *
    * @param int $id
    * @return bool
    */
   private function is_valid_id($id)
   {
      $id = (int) $id;

      if(is_numeric($id))
         return true;

      return false;

   } // is_valid_id()

   /**
    * return true if current request is a RPC call
    *
    * @return bool
    */
   public function is_rpc_call()
   {
      if($this->call_type == "rpc")
         return true;

      return false;

   } // is_rpc_call

}