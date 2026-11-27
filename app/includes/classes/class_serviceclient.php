<?php
/**
 * Remote-site links are no longer supported.
 *
 * PhpGedView could link a record to a record on another site and fetch it over SOAP. The SOAP service and its
 * client were removed from YooperGEDView. This class remains only so that the places that look up a remote
 * site keep working: they all treat "no such site" as "not a remote record".
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * @package PhpGedView
 */

if (!defined('PGV_PHPGEDVIEW')) {
	header('HTTP/1.0 403 Forbidden');
	exit;
}

define('PGV_CLASS_SERVICECLIENT_PHP', '');

// The import functions used to be loaded on every page through this file; other code relies on that.
require_once PGV_ROOT.'includes/functions/functions_import.php';

class ServiceClient {
	static function getInstance($id) {
		return null;
	}
}
