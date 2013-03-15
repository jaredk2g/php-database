<?php

/**
 * Abstraction layer between the database and application. Uses PHP's PDO extension.
 * @author Jared King <j@jaredtking.com>
 * @link http://jaredtking.com
 * @version 1.0
 * @copyright 2012 Groupr
 * @license MIT
	Permission is hereby granted, free of charge, to any person obtaining a copy of this software and
	associated documentation files (the "Software"), to deal in the Software without restriction,
	including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
	and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so,
	subject to the following conditions:
	
	The above copyright notice and this permission notice shall be included in all copies or
	substantial portions of the Software.
	
	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT
	LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
	IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
	WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
	SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
 
class Database
{
	/////////////////////////////
	// Private class variables
	/////////////////////////////
	
	private static $DBH;
	private static $numrows;
	private static $queryCount;
	private static $batch = false;
	private static $batchQueue;
	
	/**
	* Initializes the connection with the database. Only needs to be called once.
	*
	* @return boolean true if successful
	*/
	static function initialize()
	{
		try
		{
			// Initialize database
			if( self::$DBH == null )
				self::$DBH = new PDO(DB_TYPE . ':host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
		}
		catch(PDOException $e)
		{
			include_once 'ErrorStack.php';
			ErrorStack::add( $e->getMessage(), __CLASS__, __FUNCTION__ );
			return false;
		}
		
		// Set error level
		if(ERROR_LEVEL == 1)
			self::$DBH->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );
		else
			self::$DBH->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		
		// Set counters
		self::$queryCount = array(
			'select' => 0,
			'sql' => 0,
			'insert' => 0,
			'update' => 0,
			'delete' => 0
		);
		
		return true;
	}
	
	/**
	* Generates and executes a select query.
	*
	* Parameters:
	* <ul>
	* <li>where: Array of where parameters. Key => value translates into key = value. If no key is supplied then the value is treated as its own parameter.
	* <code>'where' => array( 'first_name' => 'John', 'last_name' => 'Doe', 'created > 10405833' )</code></li>
	* <li>single: returns a single value</li>
	* <li>singleRow: returns a single row</li>
	* <li>fetchStyle: see PDO manual</li>
	* <li>join</li>
	* <li>orderBy</li>
	* <li>groupBy</li>
	* </ul>
	*
	* @param string $tableName table name
	* @param string $fields fields, comma-seperated
	* @param array $parameters parameters
	* @param boolean $showQuery echoes the generated query if true
	*
	* @return boolean success?
	*/
	static function select( $tableName, $fields, $parameters = array(), $showQuery = false )
	{
		if( isset( $parameters[ 'single' ] ) && $parameters[ 'single' ] )
		{
			$parameters[ 'singleRow' ] = true;
			$parameters[ 'fetchStyle' ] = 'singleColumn';
		}
		
		$where = null;
		$where_other = array(); // array of parameters which do not contain an equal sign or is too complex for our implode function
		
		// store the original where parameters
		$originalWhere = array();
		
		if( isset( $parameters[ 'where' ] ) )
		{
			$originalWhere = $parameters[ 'where' ];
			
			if( is_string( $parameters[ 'where' ] ) )
			{ // deprecating building where strings, use named parameters instead
				$where = ' WHERE ' . $parameters['where'];
				exit( "Deprecated: $where" );
			}
			else
			{ // use named parameters, its safer
				foreach( (array)$parameters['where'] as $key=>$value )
				{
					if( is_numeric( $key ) )
					{ // should not be parameterized
						if( $value != '' )
							$where_other[] = $value;
							
						unset( $parameters['where'][$key] );
					}
				}
				
				$where_arr = array();
				$where_other_implode = implode(' AND ', $where_other );
				if( $where_other_implode  != '' ) // add to where clause
					$where_arr[] = $where_other_implode;
				
				$where_parameterized = implode(' AND ', array_map(create_function('$key, $value', 'return $key.\' = :\'.str_replace(".","",$key);'), array_keys($parameters['where']), array_values($parameters['where'])) );
				foreach( (array)$parameters['where'] as $parameter=>$value )
				{ // strip periods from named parameters, MySQL does not like this
					unset($parameters['where'][$parameter]);
					$parameters['where'][str_replace('.','',$parameter)] = $value;
				}

				if( $where_parameterized != '' )
					$where_arr[] = $where_parameterized;
					
				if( count( $where_arr ) > 0 )
					$where = ' WHERE ' . implode(' AND ', $where_arr );
			}
		}
		else
			$parameters[ 'where' ] = null;

		if( isset( $parameters[ 'join' ] ) ) // joins cannot be included in where due to the use of named parameters
			$where .= (( strlen( $where) > 0 ) ? ' AND ' : '' ) . $parameters[ 'join' ];
			
		$orderBy = null;
		if( isset($parameters['orderBy']) )
			$orderBy = ' ORDER BY ' . $parameters['orderBy'];
			
		$groupBy = null;
		if( isset($parameters['groupBy']) )
			$groupBy = ' GROUP BY ' . $parameters['groupBy'];
			
		$limit = null;
		if( isset($parameters['limit']) )
			$limit = ' LIMIT ' . $parameters['limit'];
			
		$fetchStyle = PDO::FETCH_ASSOC;
		if( isset($parameters['fetchStyle']) )
		{
			switch( $parameters['fetchStyle'] )
			{
				case 'assoc':			$fetchStyle = PDO::FETCH_ASSOC; 	break;
				case 'num':				$fetchStyle = PDO::FETCH_NUM; 		break;
				case 'singleColumn':	$fetchStyle = PDO::FETCH_COLUMN; 	break;
				default:				$fetchStyle = PDO::FETCH_ASSOC; 	break;
			}
		}
		
		try
		{
			$query = 'SELECT ' . implode(',', (array)$fields) . ' FROM ' . $tableName . $where . $groupBy . $orderBy . $limit;
			
			if( $showQuery || false )
			{
				global $selectQueries;
				$selectQueries .= $query . "\n";
				echo $query . "\n";
			}

        	// execute query
			$STH = self::$DBH->prepare( $query );
			$STH->execute( $parameters[ 'where' ] );

			$result = null;
			if( isset($parameters['singleRow']) && $parameters['singleRow'] )
				$result = $STH->fetch( $fetchStyle );
			else
				$result = $STH->fetchAll( $fetchStyle );
			
	        // store the number of rows
			self::$numrows = $STH->rowCount();

			// increment the select count
			self::$queryCount['select']++;

	        return $result;
		}
		catch(PDOException $e)
		{
			include_once 'ErrorStack.php';		
			ErrorStack::add( $e->getMessage(), __CLASS__, __FUNCTION__ );
			return false;
		}
	}
	
	/**
	* Executes a SQL query on the database
	*
	* WARNING: this could be dangerous so use with caution, no checking is performed
	*
	* @param string $query query
	*
	* @return mixed result
	*/
	static function sql($query)
	{
		// increment the sql counter
		self::$queryCount['sql']++;
		
		return self::$DBH->query($query);
	}
	
	/**
	* Gets the number of rows affected by the last query
	*
	* @return int number of rows affected by last query
	*/
	static function numrows()
	{
		return (int)self::$numrows;
	}

	/**
	* Gets the ID of the last inserted row
	*
	* @return int last inserted ID
	*/
	static function lastInsertId()
	{
		try
		{
			return self::$DBH->lastInsertId();
		}
		catch(PDOException $e)
		{
			include_once 'ErrorStack.php';		
			ErrorStack::add( $e->getMessage(), __CLASS__, __FUNCTION__ );
			return null;
		}
	}
	
	/**
	* Gets a listing of the tables in the database
	*
	* @return array tables
	*/
	static function listTables()
	{
		$result = self::$DBH->query("show tables");
		
		return $result->fetchAll( PDO::FETCH_COLUMN );
	}
	
	/**
	* Gets a listing of the columns in a table
	*
	* @return array columns
	*/
	static function listColumns( $table )
	{
		$result = self::$DBH->query("SHOW COLUMNS FROM `$table`");
		
		return $result->fetchAll( PDO::FETCH_NUM );
	}
		
	/**
	* Gets the number of a type of statements exectued
	*
	* @param string $key type of query counter to load (all,select,insert,delete,update,sql)
	*
	* @return int count
	*/
	static function queryCounter( $key = 'all' )
	{
		if( $key == 'all' || !isset( self::$queryCount[ $key ] ) )
			return self::$queryCount;
		else
			return self::$queryCount[ $key ];
	}
		
	////////////////////////////////
	// SETTERS	
	////////////////////////////////
	
	/**
	 * Notifies the class to start batching insert, update, delete queries
	 *
	 * @return boolean success
	 */
	static function startBatch()
	{
		return self::$DBH->beginTransaction();
	}
	
	/**
	 * Executes all of the queries in the batch queue
	 *
	 * @return boolean success
	 */
	static function executeBatch()
	{
		return self::$DBH->commit();
	}
	
	/**
	* Inserts a row into the database
	*
	* @param string $tableName table name
	* @param array $data data to be inserted
	*
	* @return boolean true if successful
	*/
	static function insert( $tableName, $data )
	{
		try
		{
			$STH = self::$DBH->prepare('INSERT INTO ' . $tableName . ' (' . self::implode_key( ',', (array)$data ) . ') VALUES (:' . self::implode_key( ',:', (array)$data ) . ')');
			$STH->execute($data);
			
			// update the insert counter
			self::$queryCount[ 'insert' ]++;
		}
		catch(PDOException $e)
		{
			include_once 'ErrorStack.php';		
			ErrorStack::add( $e->getMessage(), __CLASS__, __FUNCTION__ );
			return false;
		}
		
		return true;
	}
	
	/**
	 * Inserts multiple rows at a time
	 *
	 * NOTE: The input data array must be a multi-dimensional array of rows with each entry in the row corresponding to the same entry in the fields
	 *
	 * @param string $tableName table name
	 * @param array $fields field names
	 * @param array $data data to be inserted
	 *
	 * @return boolean succeess
	 */
	static function insertBatch( $tableName, $fields, $data )
	{
		if( count( $data ) == 0 )
			return true;
	
		try
		{
			// start the transaction
			self::$DBH->beginTransaction();
			
			// prepare the values to be inserted
			$insert_values = array();
			$question_marks = array();
			foreach( $data as $d )
			{
				// build the question marks
			    $result = array();
		        for($x=0; $x < count($d); $x++)
		            $result[] = '?';
				$question_marks[] = '(' . implode(',', $result) . ')';
				
				// get the insert values
				$insert_values = array_merge( $insert_values, array_values($d) );
			}
			
			// generate the SQL
			$sql = "INSERT INTO $tableName (" . implode( ",", $fields ) . ") VALUES " . implode( ',', $question_marks );
			
			// prepare the statement
			$stmt = self::$DBH->prepare( $sql );
			
			// execute!
			$stmt->execute( $insert_values );
			
			// commit the transaction
			self::$DBH->commit();	
			
			// increment the insert counter
			self::$queryCount[ 'insert' ]++;		
		}
		catch(PDOException $e)
		{
			include_once 'ErrorStack.php';		
			ErrorStack::add( $e->getMessage(), __CLASS__, __FUNCTION__ );
			return false;
		}
		
		return true;			
	}
	
	/**
	* Builds and executes an update query
	*
	* @param string $tableName table name
	* @param array $data data to be updated
	* @param array $where array of keys in $data which will be used to match the rows to be updated
	* @param bool $showQuery echoes the query if true
	*
	* @return boolean true if successful
	*/
	static function update( $tableName, $data, $where = null, $showQuery = false )
	{
		try
		{
			$sql = 'UPDATE ' . $tableName . ' SET ';
			foreach( (array)$data as $key=>$value )
			 	$sql .= $key . ' = :' . $key . ',';
			$sql = substr_replace($sql,'',-1);
			if( $where == null )
				$sql .= ' WHERE id = :id';
			else
				$sql .= ' WHERE ' . implode(' AND ', array_map(create_function('$key, $value', 'return $value.\' = :\'.$value;'), array_keys($where), array_values($where)) );

			if( $showQuery ) {
				echo $sql;
			}
				
			$STH = self::$DBH->prepare($sql);
			$STH->execute($data);
			
			self::$queryCount[ 'update' ]++;
		}
		catch(PDOException $e)
		{
			include_once 'ErrorStack.php';		
			ErrorStack::add( $e->getMessage(), __CLASS__, __FUNCTION__ );
			return false;
		}
		
		return true;
	}
	
	/**
	* Builds and executes a delete query
	*
	* @param string $tableName table name
	* @param array $where values used to match rows to be deleted
	*
	* @return boolean true if successful
	*/
	static function delete( $tableName, $where, $showQuery = false )
	{
		try
		{
			$where_other = array(); // array of parameters which do not contain an equal sign or is too complex for our implode function
			$where_arr = array(); // array that will be used to concatenate all where clauses together
			
			foreach( $where as $key=>$value )
			{
				if( is_numeric( $key ) )
				{ // should not be parameterized
					$where_other[] = $value;
					unset( $where[$key] );
				}
				else
					$where[$key] = self::$DBH->quote($value);
			}
			
			$where_other_implode = implode(' AND ', $where_other );
			if( $where_other_implode  != '' ) // add to where clause
				$where_arr[] = $where_other_implode;
				
			$where_parameterized = implode(' AND ', array_map(create_function('$key, $value', 'return $key.\'=\'.$value;'), array_keys($where), array_values($where) ) );
			if( $where_parameterized != '' )
				$where_arr[] = $where_parameterized;
				
			$query = 'DELETE FROM ' . $tableName . ' WHERE ' . implode(' AND ', $where_arr );

			if( $showQuery )
				echo $query;

			self::$DBH->exec( $query );
						
			self::$queryCount[ 'delete' ]++;
		}
		catch(PDOException $e)
		{
			include_once 'ErrorStack.php';		
			ErrorStack::add( $e->getMessage(), __CLASS__, __FUNCTION__ );
			return false;
		}
		
		return true;
	}
	
	////////////////////////////
	// Private Class Functions
	////////////////////////////
	
	private static function implode_key($glue = '', $pieces = array())
	{
	    $arrK = array_keys($pieces);
	    return implode($glue, $arrK);
	}
	
	private static function multi_implode($array = array(), $glue = '') {
	    $ret = '';
	
	    foreach ($array as $item) {
	        if (is_array($item)) {
	            $ret .= self::multi_implode($item, $glue) . $glue;
	        } else {
	            $ret .= $item . $glue;
	        }
	    }
	
	    $ret = substr($ret, 0, 0-strlen($glue));
	
	    return $ret;
	}
}