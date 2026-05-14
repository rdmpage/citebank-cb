<?php

// $Id: //

/**
 * @file config.php
 *
 * Global configuration variables (may be added to by other modules).
 *
 */

global $config;

// Date timezone
date_default_timezone_set('UTC');

$config['platform'] = 'local';
//$config['platform'] = 'cloud';

$config['site']		= 'local';
$config['site']		= 'web';

switch ($config['site'])
{
	case 'web':
		$config['web_server']	= '?'; 
		$config['web_root']		= '/';
		$config['site_name'] 	= 'CiteBank';
		break;	

	case 'local':
	default:
		$config['web_server']	= 'http://localhost'; 
		$config['web_root']		= '/citebank-cb/'; // trailing "/" is important!
		$config['site_name'] 	= 'CiteBank';
		break;
}

// Environment----------------------------------------------------------------------------
// In development this is a PHP file that is in .gitignore, when deployed these parameters
// will be set on the server
if (file_exists(dirname(__FILE__) . '/env.php'))
{
	include 'env.php';
}

// CouchDB--------------------------------------------------------------------------------	
if ($config['platform'] == 'local')
{
	$config['couchdb_options'] = array(
		'database' 	=> 'citebank',
		'host' 		=> getenv('COUCHDB_USERNAME') . ':' . getenv('COUCHDB_PASSWORD') . '@' . getenv('COUCHDB_HOST'),
		'port' 		=> getenv('COUCHDB_PORT'),
		'prefix' 	=> getenv('COUCHDB_PROTOCOL'),		
		);	
}

if ($config['platform'] == 'cloud')
{
	$config['couchdb_options'] = array(
		'database' 	=> 'citebank',
		'host' 		=> getenv('COUCHDB_HOST'),
		'port' 		=> getenv('COUCHDB_PORT'),
		'prefix' 	=> getenv('COUCHDB_PROTOCOL')
		);	
}

$config['stale'] = false;
	
?>
