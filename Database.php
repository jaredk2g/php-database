<?php
/**
 * php-database is an abstraction layer between the database and application. Uses PHP's PDO extension with memcache capabilities.
 * @author Jared King <jared@nfuseweb.com>
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
 
/* Configuration */

// Set the database access information as constants.
DEFINE ('DB_TYPE', 'mysql'); // Database Type
DEFINE ('DB_USER', ''); // Database Username.
DEFINE ('DB_PASSWORD', ''); // Database Password.
DEFINE ('DB_HOST', ''); // Database Host.
DEFINE ('DB_NAME', ''); // Database Name.
DEFINE ('ERROR_LEVEL', '0'); // Set at 0 for development and 1 for production.
// Memcache
DEFINE ('USE_MEMCACHE', true );
DEFINE ('MEMCACHE_HOST', '127.0.0.1' );
DEFINE ('MEMCACHE_PORT', '11211' );
 
class Database
{
	/////////////////////////////
	// Private class variables
	/////////////////////////////
	
	private static $DBH;
	private static $memcache;
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
			if( Database::$DBH == null )
				Database::$DBH = new PDO(DB_TYPE . ':host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
		}
		catch(PDOException $e)
		{
			ErrorStack::add( $e->getMessage(), __CLASS__, __FUNCTION__ );
			return false;
		} // try/catch

		// Initialize memcache (only if enabled)
		if( class_exists('Memcache') && defined( 'USE_MEMCACHE' ) && USE_MEMCACHE && !self::$memcache )
		{
			// attempt to connect to memcache
			try
			{
				self::$memcache = new Memcache;
				@self::$memcache->connect( MEMCACHE_HOST, MEMCACHE_PORT) or (self::$memcache = false);
			}
			catch(Exception $e)
			{
				self::$memcache = false;
			}
		} // if
		
		// Set error level
		if(ERROR_LEVEL == 1)
			Database::$DBH->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );
		else
			Database::$DBH->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		
		// Set counters
		self::$queryCount = array(
			'select' => 0,
			'sql' => 0,
			'insert' => 0,
			'update' => 0,
			'delete' => 0,
			'cache' => 0
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
	* @param int $cacheTimeout if 0: no caching will be used, if > 0: caching will be used
	* @param boolean $showQuery echoes the generated query if true
	*
	* @return boolean success?
	*/
	static function select( $tableName, $fields, $parameters = array(), $cache = 0, $showQuery = false )
	{
		if( isset( $parameters[ 'single' ] ) && $parameters[ 'single' ] )
		{
			$parameters[ 'singleRow' ] = true;
			$parameters[ 'fetchStyle' ] = 'singleColumn';
		} // if
		
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
					} // if
				} // foreach
				
				$where_arr = array();
				$where_other_implode = implode(' AND ', $where_other );
				if( $where_other_implode  != '' ) // add to where clause
					$where_arr[] = $where_other_implode;
				
				$where_parameterized = implode(' AND ', array_map(create_function('$key, $value', 'return $key.\' = :\'.str_replace(".","",$key);'), array_keys($parameters['where']), array_values($parameters['where'])) );
				foreach( (array)$parameters['where'] as $parameter=>$value )
				{ // strip periods from named parameters, MySQL does not like this
					unset($parameters['where'][$parameter]);
					$parameters['where'][str_replace('.','',$parameter)] = $value;
				} // foreach

				if( $where_parameterized != '' )
					$where_arr[] = $where_parameterized;
					
				if( count( $where_arr ) > 0 )
					$where = ' WHERE ' . implode(' AND ', $where_arr );
			} // if
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
			} // switch
		} // if
		
		try
		{
			$query = 'SELECT ' . implode(',', (array)$fields) . ' FROM ' . $tableName . $where . $groupBy . $orderBy . $limit;
			
			if( $showQuery )
				echo $query . "\n";

			// caching key
			$key = ( $cache > 0 ) ? md5( "mysql_query_" . $query . self::multi_implode( $parameters ) . implode( '', $originalWhere ) ) : null;

			$cachedValue = array( 'numrows' => 0, 'result' => null );
			
			// check if the query is in the cache
			$hitDB = true;
	        if( $cache > 0 )
	        {
	        	$cachedValue = self::getCache( $key );
	        	
	        	if( $cachedValue !== false )
	        	{
		        	// increment the cache count
		        	self::$queryCount['cache']++;

	        		$hitDB = false;
	        	} // if
	        } // if	        
	        
	        if( $hitDB )
	        {
	        	// not in cache, execute query
				$STH = Database::$DBH->prepare( $query );
				$STH->execute( $parameters[ 'where' ] );

				if( isset($parameters['singleRow']) && $parameters['singleRow'] )
					$cachedValue[ 'result' ] = $STH->fetch( $fetchStyle );
				else
					$cachedValue[ 'result' ] = $STH->fetchAll( $fetchStyle );
					
				$cachedValue[ 'numrows' ] = $STH->rowCount();

				// increment the select count
				self::$queryCount['select']++;

				// add the result to the cache
                if( !self::setCache( $key, $cachedValue, $cache ) )
                {
                    // If we get here, there isn't a memcache daemon running or responding
                }
	        }
	        
	        Database::$numrows = $cachedValue[ 'numrows' ];

	        return $cachedValue[ 'result' ];
		}
		catch(PDOException $e)
		{
			ErrorStack::add( $e->getMessage(), __CLASS__, __FUNCTION__ );
			return false;
		} // try/catch
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
		
		return Database::$DBH->query($query);
	}
	
	/**
	* Gets the number of rows affected by the last query
	*
	* @return int number of rows affected by last query
	*/
	static function numrows()
	{
		return (int)Database::$numrows;
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
			return Database::$DBH->lastInsertId();
		}
		catch(PDOException $e)
		{
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
		$result = Database::$DBH->query("show tables");
		
		return $result->fetchAll( PDO::FETCH_COLUMN );
	}
	
	/**
	* Gets a listing of the columns in a table
	*
	* @return array columns
	*/
	static function listColumns( $table )
	{
		$result = Database::$DBH->query("SHOW COLUMNS FROM `$table`");
		
		return $result->fetchAll( PDO::FETCH_NUM );
	}
		
	/**
	* Gets the number of a type of statements exectued
	*
	* @param string $key type of query counter to load (all,select,insert,delete,update,sql,cache)
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
	function startBatch()
	{
		Database::$DBH->beginTransaction();
	}
	
	/**
	 * Executes all of the queries in the batch queue
	 *
	 * @return boolean success
	 */
	function executeBatch()
	{
		Database::$DBH->commit();
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
			$STH = Database::$DBH->prepare('INSERT INTO ' . $tableName . ' (' . Database::implode_key( ',', (array)$data ) . ') VALUES (:' . Database::implode_key( ',:', (array)$data ) . ')');
			$STH->execute($data);
			
			// update the insert counter
			self::$queryCount[ 'insert' ]++;
		}
		catch(PDOException $e)
		{
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
		try
		{
			// start the transaction
			Database::$DBH->beginTransaction();
			
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
			$stmt = Database::$DBH->prepare( $sql );
			
			// execute!
			$stmt->execute( $insert_values );
			
			// commit the transaction
			Database::$DBH->commit();	
			
			// increment the insert counter
			self::$queryCount[ 'insert' ]++;		
		}
		catch(PDOException $e)
		{
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
				
			$STH = Database::$DBH->prepare($sql);
			$STH->execute($data);
			
			self::$queryCount[ 'update' ]++;
		}
		catch(PDOException $e)
		{  
			ErrorStack::add( $e->getMessage(), __CLASS__, __FUNCTION__ );
			return false;
		} // catch
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
	static function delete( $tableName, $where )
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
					$where[$key] = Database::$DBH->quote($value);
			} // foreach
			
			$where_other_implode = implode(' AND ', $where_other );
			if( $where_other_implode  != '' ) // add to where clause
				$where_arr[] = $where_other_implode;
				
			$where_parameterized = implode(' AND ', array_map(create_function('$key, $value', 'return $key.\'=\'.$value;'), array_keys($where), array_values($where) ) );
			if( $where_parameterized != '' )
				$where_arr[] = $where_parameterized;
				
			$query = 'DELETE FROM ' . $tableName . ' WHERE ' . implode(' AND ', $where_arr );

			Database::$DBH->exec( $query );
			
			self::$queryCount[ 'delete' ]++;
		}
		catch(PDOException $e)
		{
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
	
	
    private static function getCache( $key )
    {
        return (self::$memcache) ? self::$memcache->get($key) : false;
    }

    private static function setCache( $key, $object, $timeout = 60 )
    {
        return (self::$memcache && $timeout > 0) ? self::$memcache->set( $key, $object, MEMCACHE_COMPRESSED, $timeout ) : false;
    }	
}

/**
 * Handles the creation and storing of non-fatal errors. This class may be useful for logging errors or displaying them to users.
 */
class ErrorStack
{
	/////////////////////////////
	// Private Class Variables
	/////////////////////////////
	
	private static $stack = array();
	private static $context = '';
	
	////////////////////////////
	// GETTERS
	////////////////////////////
	
	/**
	* Gets error(s) in the stack based on the desired parameters
	*
	* This method is useful for pulling errors off the stack that occured within a class, function, context, by error code or any combination of
	* these parameters.
	* @param string $class class (optional)
	* @param string $function function (optional)
	* @param string $context context (optional)
	* @param string|int $errorCode error code (optional)
	* @return array errors
	*/
	public static function stack( $class = null, $function = null, $context = null,  $errorCode = null )
	{
		$errors = self::$stack;
		if( $class )
		{
			$errors = array();
			foreach( self::$stack as $error )
			{
				if( $error[ 'class'] == $class )
					$errors[] = $error;
			}
		}
		
		$errors2 = $errors;
		if( $function )
		{
			$errors2 = array();
			foreach( $errors as $error )
			{
				if( $error[ 'function' ] == $function )
					$errors2[] = $error;
			}
		}
		
		$errors3 = $errors2;
		if( $context )
		{
			$errors3 = array();
			foreach( $errors2 as $error )
			{
				if( $error[ 'context' ] == $context )
					$errors3[] = $error;
			}
		}
		
		$errors4 = $errors3;
		if( $errorCode )
		{
			$errors4 = array();
			foreach( $errors3 as $error )
			{
				if( $error[ 'code' ] == $errorCode )
					$errors4[] = $error;
			}
		}
		
		return $errors4;
	}
	
	/**
	* Checks if an error exists based on the given parameters.
	* @param string $class class
	* @param string $function function
	* @param string $context context
	* @param string|int $errorCode error code
	* @return boolean true if at least one error exists
	*/
	public static function hasError( $class = null, $function = null, $context = null, $errorCode = null )
	{
		return count( self::stack( $class, $function, $context, $errorCode ) ) > 0;
	}
	
	/**
	* Gets a single (first) message based on the given parameters.
	*
	* If multiple errors are matched then only the first one will be returned. If more than one error is possible
	* it is best to user the stack() method
	* @param string $class class
	* @param string $function function
	* @param string $context context
	* @param string|int $errorCode error code
	* @return string message
	*/
	public static function getMessage( $class, $function, $context, $errorCode )
	{
		$errors = self::stack( $class, $function, $context, $errorCode );
		return (count( $errors ) > 0 ) ? $errors[ 0 ][ 'message' ] : false;	
	}
	
	/////////////////////////////////////
	// SETTERS
	/////////////////////////////////////
	
	/**
	* Adds an error message to the stack
	* @param string $message message
	* @param string $class class
	* @param string $function function
	* @param string $context context
	* @param string|int $code error code
	* @return boolean true if successful
	*/
	public static function add( $message, $class = null, $function = null, $variables = array(), $context = null, $code = 0 )
	{
		if( $class == null && $function == null )
		{
			// try to look up the call history using debug_backtrace()
			$trace = debug_backtrace();
			if( isset( $trace[ 1 ] ) )
			{
				// $trace[0] is ourself
				// $trace[1] is our caller
				// and so on…
				$class = $trace[1]['class'];
				$function = $trace[1]['function'];
			} // if
		} // if
		
		self::$stack[] = array(
			'class' => $class,
			'function' => $function,
			'message' => Messages::generateMessage( $message, $variables ),
			'code' => $code,
			'context' => ($context) ? $context : self::$context
		);
		
		return true;
	}

	/**
	* Sets the context for all errors created.
	*
	* Unless explicitly overridden all errors will be created with the current context. Don't forget to clear
	* the context when finished with it.
	* @param string context
	* @return null
	*/
	public static function setContext( $context )
	{
		self::$context = $context;
	}
	
	/**
	* Clears the error context
	* @return null
	*/
	public static function clearContext( )
	{
		self::$context = '';
	}
	
	/**
	 * Prints out the error stack (for debugging)
	 */
	public static function dump()
	{
		print_r(ErrorStack::stack());
	}
}