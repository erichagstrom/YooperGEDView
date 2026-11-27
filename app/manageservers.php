<?php
/**
*  Manage Servers Page
*
*  Allow a user the ability to manage servers i.e. allowing, banning, deleting
*
* phpGedView: Genealogy Viewer
* Copyright (C) 2002 to 2012  PGV Development Team.  All rights reserved.
*
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program; if not, write to the Free Software
* Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*
* @package PhpGedView
* @subpackage Admin
* @version $Id: manageservers.php 7086 2012-12-27 15:27:48Z canajun2eh $
* @author rbennett
*/

define('PGV_SCRIPT_NAME', 'manageservers.php');
require './config.php';
require_once PGV_ROOT.'includes/functions/functions.php';
require_once PGV_ROOT.'includes/functions/functions_edit.php';
require_once PGV_ROOT.'includes/functions/functions_import.php';

print_header($pgv_lang["title_manage_servers"]);
//-- only allow gedcom admins here
if (!PGV_USER_GEDCOM_ADMIN) {
	print $pgv_lang["access_denied"];
	//-- display messages as to why the editing access was denied
	if (!PGV_USER_GEDCOM_ADMIN) print "<br />".$pgv_lang["user_cannot_edit"];
	print "<br /><br /><div class=\"center\"><a href=\"javascript: ".$pgv_lang["close_window"]."\" onclick=\"window.close();\">".$pgv_lang["close_window"]."</a></div>\n";
	print_footer();
	exit;
}

$banned = array();
if (file_exists($INDEX_DIRECTORY.'banned.php')) {
	require($INDEX_DIRECTORY.'banned.php');
}
$search_engines = array();
if (file_exists($INDEX_DIRECTORY."search_engines.php")) {
	require($INDEX_DIRECTORY.'search_engines.php');
}

$action = safe_GET('action');
if (empty($action)) $action = safe_POST('action');
$address = safe_GET('address');
if (empty($address)) $address = safe_POST('address');
$comment = safe_GET('comment');
if (empty($comment)) $comment = safe_POST('comment');
$comment = str_replace(array("\\", "\$", "\""), array("\\\\", "\\\$", "\\\""), $comment);

$deleteBanned = safe_POST('deleteBanned');
if (!empty($deleteBanned)) { // A "remove banned IP" button was pushed
	$action = 'deleteBanned';
	$address = $deleteBanned;
}

$deleteTimedBan = safe_POST('deleteTimedBan');
if (!empty($deleteTimedBan)) { // A "remove timed banned IP" button was pushed
	$action = 'deleteTimedBan';
	$address = $deleteTimedBan;
}

$deleteSearch = safe_POST('deleteSearch');
if (!empty($deleteSearch)) { // A "remove search engine IP" button was pushed
	$action = 'deleteSearch';
	$address = $deleteSearch;
}

$deleteServer = safe_POST('deleteServer');
if (!empty($deleteServer)) { // A "remove remote server" button was pushed
	$action = 'deleteServer';
	$address = $deleteServer;
}

if (empty($action)) $action = 'showForm';

/*
* Validate input string to be an IP address
*/
function validIP($address) {
	if (!preg_match('/^\d{1,3}\.(\d{1,3}|\*)\.(\d{1,3}|\*)\.(\d{1,3}|\*)$/', $address)) return false;
	$pieces = explode('.', $address);
	foreach ($pieces as $number) {
		if ($number!="*" && $number>255) return false;
	}
	return true;
}

if ($action=='addBanned' || $action=='addTimedBan' || $action=='addSearch' || $action=='deleteBanned' || $action=='deleteTimedBan' || $action=='deleteSearch') {
	if (validIP($address)) {
		// Even if we are adding a new record, we must delete the existing one first.
		PGV_DB::prepare(
			"DELETE FROM {$TBLPREFIX}ip_address WHERE ip_address=?"
		)->execute(array($address));
		if ($action=='addBanned') {
			PGV_DB::prepare(
				"INSERT INTO {$TBLPREFIX}ip_address (ip_address, category, comment) VALUES (?, ?, ?)"
			)->execute(array($address, 'banned', $comment));
		}
		if ($action=='addTimedBan') {
			PGV_DB::prepare(
				"INSERT INTO {$TBLPREFIX}ip_address (ip_address, category, comment) VALUES (?, ?, ?)"
			)->execute(array($address, 'timedban', $comment));
		}
		if ($action=='addSearch') {
			PGV_DB::prepare(
				"INSERT INTO {$TBLPREFIX}ip_address (ip_address, category, comment) VALUES (?, ?, ?)"
			)->execute(array($address, 'search-engine', $comment));
		}
	} else {
		if ($action=='addBanned') {
			$errorBanned=$pgv_lang['error_ban_server'];
		}
		if ($action=='addTimedBan') {
			$errorBanned=$pgv_lang['error_ban_server'];
		}
		if ($action=='addSearch') {
			$errorBanned=$pgv_lang['error_ban_server'];
		}
	}
	$action='showForm';
}


?>

<script language="JavaScript" type="text/javascript">
<!--
function showSite(siteID) {
	buttonShow = document.getElementById("buttonShow_"+siteID);
	siteDetails = document.getElementById("siteDetails_"+siteID);
	if (siteDetails.style.display=='none') {
		buttonShow.innerHTML='<?php echo $pgv_lang["hide_details"];?>';
		siteDetails.style.display='block';
	} else {
		buttonShow.innerHTML='<?php echo $pgv_lang["show_details"];?>';
		siteDetails.style.display='none';
	}
}
//-->
</script>


<!-- Search Engine IP address table -->
<table class="width66" align="center">
<tr>
	<td colspan="2" class="title" align="center">
	<?php echo $pgv_lang["title_manage_servers"];?>
	</td>
</tr>
<tr>
	<td>
	<form name="searchengineform" action="manageservers.php" method="post">
	<table class="width100" align="center">
		<tr>
		<td class="facts_label">
			<?php print_help_link("help_manual_search_engines", "qm"); ?>
			<b><?php echo $pgv_lang["label_manual_search_engines"];?></b>
		</td>
		</tr>
		<tr>
		<td class="facts_value">
			<table align="center">
<?php
	$sql="SELECT ip_address, comment FROM {$TBLPREFIX}ip_address WHERE category='search-engine' ORDER BY comment";
	$index=0;
	$search_engines=PGV_DB::prepare($sql)->fetchAssoc();
	foreach ($search_engines as $ip_address=>$ip_comment) {
		echo '<tr><td>';
			echo '<button name="deleteSearch" value="', $ip_address, '" type="submit">';
			if (isset($PGV_IMAGES["remove"]["other"])) {
				echo '<img src="', $PGV_IMAGE_DIR, '/', $PGV_IMAGES["remove"]["other"], '" alt="', $pgv_lang['remove'], '">';
			} else {
				echo $pgv_lang["remove"];
			}
			echo '</button>';
		echo '</td><td><span dir="ltr"><input type="text" name="address', ++$index, '" size="16" value="', $ip_address, '" readonly /></span></td>';
		echo '<td><input type="text" name="comment', ++$index, '" size="60" value="', $ip_comment, '" readonly /></td></tr>';
	}
	echo '<tr><td valign="top"><input name="action" type="hidden" value="addSearch"/>';
	if (isset($PGV_IMAGES["add"]["other"])) {
		echo '<input type="image" src="', $PGV_IMAGE_DIR, '/', $PGV_IMAGES["add"]["other"], '" alt="', $pgv_lang['add'], '">';
	} else {
		echo '<input type="submit" value="', $pgv_lang['add'], '" />';
	}
	echo '</td><td valign="top"><span dir="ltr"><input type="text" id="txtAddIp" name="address" size="16"  value="', empty($errorSearch) ? '':$address, '" /></span></td>';
	echo '<td><input type="text" id="txtAddComment" name="comment" size="60"  value="" />';
	echo '<br />', $pgv_lang["enter_comment"], '</td></tr>';

	if (!empty($errorSearch)) {
		print '<tr><td colspan="2"><span class="warning">';
		print $errorSearch;
		print '</span></td></tr>';
		$errorSearch = '';
	}
	echo '</table></td></tr></table></form></td></tr></table>';
?>

<!-- Banned IP address table -->
<table class="width66" align="center">
<tr>
	<td>
	<form name="banIPform" action="manageservers.php" method="post">
	<table class="width100" align="center">
		<tr>
		<td class="facts_label">
			<?php print_help_link("help_banning", "qm"); ?>
			<b><?php echo $pgv_lang["label_banned_servers"];?></b>
		</td>
		</tr>
		<tr>
		<td class="facts_value">
			<table align="center">
<?php
	$sql="SELECT ip_address, comment FROM {$TBLPREFIX}ip_address WHERE category='banned' ORDER BY comment";
	$banned=PGV_DB::prepare($sql)->fetchAssoc();
	foreach ($banned as $ip_address=>$ip_comment) {
		echo '<tr><td>';
			echo '<button name="deleteBanned" value="', $ip_address, '" type="submit">';
			if (isset($PGV_IMAGES["remove"]["other"])) {
				echo '<img src="', $PGV_IMAGE_DIR, '/', $PGV_IMAGES["remove"]["other"], '" alt="', $pgv_lang['remove'], '">';
			} else {
				echo $pgv_lang["remove"];
			}
			echo '</button>';
		echo '</td><td><span dir="ltr"><input type="text" name="address', ++$index, '" size="16" value="', $ip_address, '" readonly /></span></td>';
		echo '<td><input type="text" name="comment', ++$index, '" size="60" value="', $ip_comment, '" readonly /></td></tr>';
	}
	echo '<tr><td valign="top"><input name="action" type="hidden" value="addBanned"/>';
	if (isset($PGV_IMAGES["add"]["other"])) {
		echo '<input type="image" src="', $PGV_IMAGE_DIR, '/', $PGV_IMAGES["add"]["other"], '" alt="', $pgv_lang['add'], '">';
	} else {
		echo '<input type="submit" value="', $pgv_lang['add'], '" />';
	}
	echo '</td><td valign="top"><span dir="ltr"><input type="text" id="txtAddIp" name="address" size="16"  value="', empty($errorBanned) ? '':$address, '" /></span></td>';
	echo '<td><input type="text" id="txtAddComment" name="comment" size="60"  value="" />';
	echo '<br />', $pgv_lang["enter_comment"], '</td></tr>';

	if (!empty($errorBanned)) {
		print '<tr><td colspan="2"><span class="warning">';
		print $errorBanned;
		print '</span></td></tr>';
		$errorBanned = '';
	}
	echo '</table></td></tr></table></form></td></tr></table>';
?>

<!-- Timed Ban IP address table -->
<table class="width66" align="center">
<tr>
	<td>
	<form name="timedbanIPform" action="manageservers.php" method="post">
	<table class="width100" align="center">
		<tr>
		<td class="facts_label">
			<?php print_help_link("help_timedban", "qm"); ?>
			<b><?php echo $pgv_lang["label_timedban_servers"];?></b>
		</td>
		</tr>
		<tr>
		<td class="facts_value">
			<table align="center">
<?php
	$sql="SELECT ip_address, comment FROM {$TBLPREFIX}ip_address WHERE category='timedban' ORDER BY comment";
	$timedban=PGV_DB::prepare($sql)->fetchAssoc();
	foreach ($timedban as $ip_address=>$ip_comment) {
		echo '<tr><td>';
			echo '<button name="deleteTimedBan" value="', $ip_address, '" type="submit">';
			if (isset($PGV_IMAGES["remove"]["other"])) {
				echo '<img src="', $PGV_IMAGE_DIR, '/', $PGV_IMAGES["remove"]["other"], '" alt="', $pgv_lang['remove'], '">';
			} else {
				echo $pgv_lang["remove"];
			}
			echo '</button>';
		echo '</td><td><span dir="ltr"><input type="text" name="address', ++$index, '" size="16" value="', $ip_address, '" readonly /></span></td>';
		echo '<td><input type="text" name="comment', ++$index, '" size="60" value="', $ip_comment, '" readonly /></td></tr>';
	}
	echo '<tr><td valign="top"><input name="action" type="hidden" value="addTimedBan"/>';
	if (isset($PGV_IMAGES["add"]["other"])) {
		echo '<input type="image" src="', $PGV_IMAGE_DIR, '/', $PGV_IMAGES["add"]["other"], '" alt="', $pgv_lang['add'], '">';
	} else {
		echo '<input type="submit" value="', $pgv_lang['add'], '" />';
	}
	echo '</td><td valign="top"><span dir="ltr"><input type="text" id="txtAddIp" name="address" size="16"  value="', empty($errorBanned) ? '':$address, '" /></span></td>';
	echo '<td><input type="text" id="txtAddComment" name="comment" size="60"  value="" />';
	echo '<br />', $pgv_lang["enter_banexpiry"], '</td></tr>';

	if (!empty($errorBanned)) {
		print '<tr><td colspan="2"><span class="warning">';
		print $errorBanned;
		print '</span></td></tr>';
		$errorBanned = '';
	}
	echo '</table></td></tr></table></form></td></tr></table>';
?>

<?php
	print_footer();
?>
