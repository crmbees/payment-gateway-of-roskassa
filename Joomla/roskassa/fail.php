<?php 
defined('_JEXEC') or die('Restricted access');

/*
	Copyright: © 2021 CRMBees.
  License: GNU General Public License v3.0
  License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
?>
<form action="../../../index.php" method="post" name="fname">
	<input type="hidden" name="option" value="com_virtuemart">
	<input type="hidden" name="view" value="pluginresponse">
	<input type="hidden" name="task" value="pluginUserPaymentCancel">
</form>
<script>
document.forms.fname.submit();
</script>