<?php

/* Configuration */

DEFINE ('DB_TYPE', 'mysql');
DEFINE ('DB_USER', '');
DEFINE ('DB_PASSWORD', '');
DEFINE ('DB_HOST', '');
DEFINE ('DB_NAME', '');
DEFINE ('ERROR_LEVEL', 0); // Set at 0 for development and 1 for production.

require 'Database.php';

// initialize the database connection, only needs to be called once
Database::initialize();

/* Getting Data */

// fetch all of the rows from a database
$users = Database::select('Users', '*');
print_r($users);

// fetch some rows from a database
$users = Database::select(
	'Users',
	'*',
	array(
		'limit' => '0,5',
		'orderBy' => 'last_name ASC,first_name ASC' ) );
print_r($users);

$numrows = Database::numrows();
echo "There are $numrows users\n";

// fetch a single row
$user = Database::select(
	'Users',
	'first_name,last_name,user_email',
	array(
		'where' => array(
			'uid' => 110 ),
		'singleRow' => 'true' ) );
print_r($user);

// fetch a single column
$emails = Database::select(
	'Users',
	'user_email',
	array(
		'orderBy' => 'last_name ASC,first_name ASC',
		'fetchStyle' => 'singleColumn' ) );
print_r($emails);

// fetch a single value
$firstName = Database::select(
	'Users',
	'first_name',
	array(
		'where' => array(
			'uid' => 396 ),
		'single' => true ) );
print_r($firstName);
	
// inserting data
Database::insert(
	'Users',
	array(
		'first_name' => 'Abe',
		'last_name' => 'Lincoln' ) );

// get the last inserted id
$lastInsertId = Database::lastInsertId();
	
// updating data
Database::update(
	'Users',
	array(
		'uid' => $lastInsertId,
		'first_name' => 'George',
		'last_name' => 'Washington' ),
	array(
		'uid' ) );

// deleting data
Database::delete(
	'Users',
	array(
		'uid' => $lastInsertId ) );

// executing a SQL statement
$tables = Database::listTables();

foreach( $tables as $table )
	Database::sql( "OPTIMIZE TABLE `$table`;" );

