<?php

/**
 * Handles the creation and storing of non-fatal errors. This class may be useful for logging errors or displaying them to users.
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
	*
	* @param string $class class (optional)
	* @param string $function function (optional)
	* @param string $context context (optional)
	* @param string|int $errorCode error code (optional)
	*
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
	*
	* @param string $class class
	* @param string $function function
	* @param string $context context
	* @param string|int $errorCode error code
	*
	* @return boolean true if at least one error exists
	*/
	public static function hasError( $class = null, $function = null, $context = null, $errorCode = null )
	{
		return count( self::stack( $class, $function, $context, $errorCode ) ) > 0;
	}
	
	public static function hasErrorWithCode( $code )
	{
		return count( self::stack( null, null, null, $code ) ) > 0;
	}
	
	/** 
	 * Finds errors based on the given parameters
	 *
	 * @param string $context error context
	 *
	 * @return array
	 */
	public static function errorsWithContext( $context )
	{
		return self::stack( null, null, $context, null );
	}
		
	/**
	* Gets a single (first) message based on the given parameters.
	*
	* If multiple errors are matched then only the first one will be returned. If more than one error is possible
	* it is best to user the stack() method
	*
	* @param string $class class
	* @param string $function function
	* @param string $context context
	* @param string|int $errorCode error code
	*
	* @return string message
	*/
	public static function getMessage( $class, $function, $context, $errorCode )
	{
		$errors = self::stack( $class, $function, $context, $errorCode );
		return (count( $errors ) > 0 ) ? $errors[ 0 ][ 'message' ] : false;	
	}
	
	/**
	 * Gets the first message from the error stack for a given context
	 *
	 * @param string $context context
	 *
	 * @return string message
	 */
	public static function getMessageWithContext( $context )
	{
		return self::getMessage( '', '', $context, '' );
	}	
	
	/////////////////////////////////////
	// SETTERS
	/////////////////////////////////////
	
	/**
	* Adds an error message to the stack
	*
	* @param string $message message
	* @param string $class class
	* @param string $function function
	* @param string $context context
	* @param string $code error code
	*
	* @return boolean true if successful
	*/
	public static function add( $message, $class = null, $function = null, $variables = array(), $context = null, $code = 0 )
	{
		$code = $message;
		if( $class == null && $function == null )
		{
			// try to look up the call history using debug_backtrace()
			$trace = debug_backtrace();
			if( isset( $trace[ 1 ] ) )
			{
				// $trace[0] is ourself
				// $trace[1] is our caller
				// and so on...
				$class = $trace[1]['class'];
				$function = $trace[1]['function'];
			} // if
		} // if
		
		self::$stack[] = array(
			'class' => $class,
			'function' => $function,
			'message' => self::generateMessage( $message, $variables ),
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
	*
	* @param string context
	*
	* @return null
	*/
	public static function setContext( $context )
	{
		self::$context = $context;
	}
	
	/**
	* Clears the error context
	*
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
	
	static function generateMessage( $message, $variables = array() )
	{
		$i = 1;
		foreach( (array)$variables as $variable )
		{
			$message = str_replace( '{{' . $i . '}}', $variable, $message );
			$i++;
		}

		return $message;
	}	
}