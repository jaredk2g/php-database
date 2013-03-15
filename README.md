php-database
============

## PHP Database abstraction layer implementing PDO

### Getting Started

```php
Database::initialize();
```

### Selecting Data

The ability to fetch data from a database into a nicely formatted associative array is one of the main features of this library.

Fetch all of the rows from a database:
```php
Database::select('Users', '*');
```

Fetch some rows from a database:
```php
Database::select(
	'Users',
	'*',
	array(
		'limit' => '0,5',
		'orderBy' => 'last_name ASC,first_name ASC' ) );
```


Getting the number of rows from the previous result
```php
Database::numrows();
```

Fetch a single row:
```php
Database::select(
	'Users',
	'first_name,last_name,user_email',
	array(
		'where' => array(
			'uid' => 110 ),
		'singleRow' => 'true' ) );
```

Fetch a single column:
```php
Database::select(
	'Users',
	'user_email',
	array(
		'orderBy' => 'last_name ASC,first_name ASC',
		'fetchStyle' => 'singleColumn' ) );
```

Fetch a single value:
```php
Database::select(
	'Users',
	'first_name',
	array(
		'where' => array(
			'uid' => 396 ),
		'single' => true ) );
```
	
### Inserting data

```php
Database::insert(
	'Users',
	array(
		'first_name' => 'Abe',
		'last_name' => 'Lincoln' ) );
```

Get the last inserted id:
```php
Database::lastInsertId();
```
	
### Updating data

```php
Database::update(
	'Users',
	array(
		'uid' => $lastInsertId,
		'first_name' => 'George',
		'last_name' => 'Washington' ),
	array(
		'uid' ) );
```

### Deleting data

```php
Database::delete(
	'Users',
	array(
		'uid' => $lastInsertId ) );
```

### Executing a SQL statement
Any SQL statement can be executed. In the following example we will optimize all of the tables in the database.

```php
$tables = Database::listTables();

foreach( $tables as $table )
	Database::sql( "OPTIMIZE TABLE `$table`;" );
```