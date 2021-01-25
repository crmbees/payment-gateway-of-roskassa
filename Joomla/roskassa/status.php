<?php
defined('_JEXEC') or die('Restricted access');

/*
	Copyright: © 2021 CRMBees.
  License: GNU General Public License v3.0
  License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

$_REQUEST['option']='com_virtuemart';
$_REQUEST['view']='pluginresponse';
$_REQUEST['task']='pluginnotification';
$_REQUEST['tmpl']='component';
$_REQUEST['format'] = 'raw';
$_REQUEST['pelement'] = 'roskassa';
include('../../../index.php');
