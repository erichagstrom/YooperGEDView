<?php
/**
  * Update the database schema from version 10 to version 11
 *
 * Version 0: empty database
 * Version 1: create the pgv_site_setting table
 * Version 2: create the user tables, as per PhpGedView 4.2.1
 * Version 3: create the genealogy tables, as per PhpGedView 4.2.1
 *   From Version 4 onwards, we make incremental changes, rather than
 *   trying to introspect/update.  It's more efficient, flexible,
 *   and allows us to update column types, indexes, etc.
 * Version 4: Performance tuning: update column definitions for pgv_dates
 * Version 5: Performance tuning: update column definitions for pgv_individuals
 * Version 6: Performance tuning: update column definitions for pgv_families
 * Version 7: Performance tuning: update column definitions for pgv_sources
 * Version 8: Performance tuning: update column definitions for pgv_media/media_mapping
 * Version 9: Performance tuning: update column definitions for pgv_favorites/nextid/other/placelinks/places/remotelinks
 * Version 10: Move the $DEFAULT_GEDCOM setting from gedcoms.php to pgv_site_setting
 * Version 11: Add the modules and module_privacy tables for module administration
 * Version 12: Move $GEDCOMS from gedcoms.php to pgv_gedcom and pgv_gedcom_setting, split pgv_users into pgv_user/pgv_user_setting/pgv_user_gedcom_setting
 * Version 13: Move the hit-counters to a database table
 * Version 14: Move the banned/search-engine IP addresses to a database table
 * Version 15: Record sign-in attempts in a database table
 *
 * The script should assume that it can be interrupted at
 * any point, and be able to continue by re-running the script.
 * Fatal errors, however, should be allowed to throw exceptions,
 * which will be caught by the framework.
 * It shouldn't do anything that might take more than a few
 * seconds, for systems with low timeout values.
 *
 * YooperGEDView: a fork of phpGedView
 * Copyright (C) 2026  YooperGEDView contributors.
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
 */

if (!defined('PGV_PHPGEDVIEW')) {
	header('HTTP/1.0 403 Forbidden');
	exit;
}

define('PGV_SCHEMA_14_15', '');

// Every sign-in attempt: the audit log of sign-ins, and what the limit on failed attempts counts.
// Read and written by YooperGEDView\Security\SignInAttemptRepository.
if (!self::table_exists("{$TBLPREFIX}sign_in_attempt")) {
	self::exec(
		"CREATE TABLE {$TBLPREFIX}sign_in_attempt (".
		" attempt_id   ".self::$AUTO_ID_TYPE."      NOT NULL,".
		" user_name    ".self::$VARCHAR_TYPE."(64)  NOT NULL,". // as typed, lower case; not only names of real users
		" ip_address   ".self::$VARCHAR_TYPE."(45)  NOT NULL,". // long enough for IPv6
		" attempt_time ".self::$INT4_TYPE."         NOT NULL,". // seconds since 1970
		" success      ".self::$INT1_TYPE."         NOT NULL".  // 1 or 0
		") ".self::$UTF8_TABLE." ".self::$DB_ENGINE
	);
	self::exec("CREATE INDEX {$TBLPREFIX}sign_in_attempt_ix1 ON {$TBLPREFIX}sign_in_attempt (user_name, attempt_time)");
	self::exec("CREATE INDEX {$TBLPREFIX}sign_in_attempt_ix2 ON {$TBLPREFIX}sign_in_attempt (ip_address, attempt_time)");
}

// Update the version to indicate sucess
set_site_setting($schema_name, $next_version);
