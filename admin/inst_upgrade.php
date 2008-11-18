<?php

$page_security = 20;
$path_to_root="..";
include_once($path_to_root . "/includes/session.inc");

page(_("Software Upgrade"));

include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/admin/db/company_db.inc");
include_once($path_to_root . "/admin/db/maintenance_db.inc");
include_once($path_to_root . "/includes/ui.inc");

//
//	Checks $field existence in $table with given field $properties
//	$table - table name without prefix
//  $field -  optional field name
//  $properties - optional properties of field defined by MySQL:
//		'Type', 'Null', 'Key', 'Default', 'Extra'
//
function check_table($pref, $table, $field=null, $properties=null)
{
	$fields = db_query("SHOW COLUMNS FROM ".$pref.$table);
	if (!$fields)
		return 1;		// no such table or error

	if (!isset($field)) 
		return 0;		// table exists

	while( $row = db_fetch_assoc($fields)) 
	{
		if ($row['Field'] == $field) 
		{
			if (!isset($properties)) 
				return 0;
			foreach($properties as $property => $value) 
			{
				if ($row[$property] != $value) 
					return 3;	// failed type/length check
			}
			return 0; // property check ok.
		}
	}
	return 2; // field not found
}
//
//	Creates table of installer objects sorted by version.
//
function get_installers()
{
	global $path_to_root;

	$patchdir = $path_to_root."/sql/";
	$upgrades = array();	
	$datadir = @opendir($patchdir);

	if ($datadir)
	{
		while(false !== ($fname = readdir($datadir)))
		{ // check all php files but index.php
			if (!is_dir($patchdir . $fname) && ($fname != 'index.php')
				&& stristr($fname, '.php') != false)
			{
				unset($install);
				include_once($patchdir . $fname);
				if (isset($install)) // add installer if found
					$upgrades[$install->version] =  $install;
			}
		}
		ksort($upgrades); // sort by file name
		$upgrades = array_values($upgrades);
	}
	return $upgrades;
}
//
//	Apply one differential data set.
//
function upgrade_step($index, $conn) 
{
	global $path_to_root, $installers;

	$inst = $installers[$index];
	$sql = $inst->sql;
	$pref = $conn['tbpref'];
	$ret = true;

	$force = get_post('force_'.$index);
	if ($force || get_post('install_'.$index)) 
	{
		if (!$inst->installed($pref) || $force) 
		{
	//		if(!$inst->pre_check($pref)) return false;

			if ($sql != '')
				$ret &= db_import($path_to_root.'/sql/'.$sql, $conn, $force);

			$ret &= $inst->install($pref, $force);
		}
	}
	return $ret;
}

function db_open($conn)
{
	$db = mysql_connect($conn["host"] ,$conn["dbuser"], $conn["dbpassword"]);
	if (!$db)
		return false;
	if (!mysql_select_db($conn["dbname"], $db))
		return false;
	return $db;
}

$installers = get_installers();

if (get_post('Upgrade')) 
{

	$ret = true;
	foreach ($db_connections as $conn) 
	{
	// connect to database
		if (!($db = db_open($conn))) 
		{
			display_error(_("Cannot connect to database for company")
				." '".$conn['name']."'");
			continue;
		}
	// create security backup		
	 	if ($conn['tbpref'] != "")
			$filename = $conn['dbname'] . "_" . $conn['tbpref'] . date("Ymd_Hi") . ".sql";
		else
			$filename = $conn['dbname'] . "_" . date("Ymd_Hi") . ".sql";

		db_export($conn, $filename, 'no', 'Security backup before upgrade', $conn['tbpref']);
	// apply all upgrade data
		foreach ($installers as $i => $inst) 
		{
			$ret = upgrade_step($i, $conn);
			if (!$ret)
				display_error(
				sprintf(_("Database upgrade to version %s failed for company '%s'."),
					$inst->version, $conn['name'])
					.'<br>'
					._('You should restore company database from latest backup file'));
		}
// 		db_close($conn); ?
		if (!$ret) break;
	}
	if($ret)
		display_notification(_('All companies data has been successfully updated'));
	$Ajax->activate('_page_body');
}

start_form();
start_table($table_style);
$th = array(_("Version"), _("Description"), _("Sql file"), _("Install"),
	_("Force upgrade"));
table_header($th);

$k = 0; //row colour counter
foreach($installers as $i => $inst)
{
	alt_table_row_color($k);
	start_row();
	label_cell($inst->version);
	label_cell($inst->description);
	label_cell($inst->sql ? $inst->sql : '<i>'._('None').'</i>', 'align=center');
// this is checked only for first (site admin) company, 
// but in fact we should always upgrade all data sets after
// source upgrade.
	if ($inst->installed(TB_PREF))
		label_cell(_("Installed"));
	else
		check_cells(null,'install_'.$i, 0);
	check_cells(null,'force_'.$i, 0);
	end_row();
}
end_table(1);
submit_center('Upgrade', _('Upgrade system'), true, _('Save database and perform upgrade'), 'process');
end_form();

end_page();

?>