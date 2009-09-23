<?php

class arr extends kohana_arr {

	/**
	 * Gets a value from an array using a dot separated path.
	 *
	 *     // Get the value of $array['foo']['bar']
	 *     $value = Arr::path($array, 'foo.bar');
	 *
	 * @param   array   array to search
	 * @param   string  key path, dot separated
	 * @param   mixed   default value if the path is not set
	 * @return  mixed
	 */
	public static function path($array, $path, $default = NULL, $delimiter = '.')
	{
		// Split the keys by delimiter
		$keys = is_array($path) ? $path : explode($delimiter, trim($path, $delimiter));

		// Split the keys by slashes
		//$keys = explode('.', $path);

		do
		{
			$key = array_shift($keys);

			if (ctype_digit($key))
			{
				// Make the key an integer
				$key = (int) $key;
			}

			if (isset($array[$key]))
			{
				if ($keys)
				{
					if (is_array($array[$key]))
					{
						// Dig down into the next part of the path
						$array = $array[$key];
					}
					else
					{
						// Unable to dig deeper
						break;
					}
				}
				else
				{
					// Found the path requested
					return $array[$key];
				}
			}
			else
			{
				// Unable to dig deeper
				break;
			}
		}
		while ($keys);

		// Unable to find the value requested
		return $default;
	}

	/**
	 * Sets a value in an array using a path.
	 *
	 *     // Set the value of $array['foo']['bar'] to TRUE
	 *     $array = Arr::path_set('foo.bar',TRUE);
	 *
	 *     $array = Arr::path_set( array('foo','bar'),  TRUE);
	 *
	 *     Arr::path_set('foo.bar',TRUE,$array);
	 *
	 * @param   mixed   key path
	 * @param   mixed   value
   * @param   array   array to modify (optional)
	 * @return  array
	 */
	public static function path_set($path,$value, & $array = array(),$delimiter = '.')
	{
		if ( ! is_array($path))
		{
			// Split the keys by dots
			$path = explode($delimiter, trim($path, $delimiter));
		}

		$ref_copy =& $array;

		do
		{
			$key = array_shift($path);

			if(count($path))
			{
				if(!isset($ref_copy[$key]) || (! is_array($ref_copy[$key]) && ! $ref_copy[$key] instanceof ArrayObject))
				{
					$ref_copy[$key] = array();
				}
			}
			else
			{
				$ref_copy[$key] = $value;
			}
			$ref_copy =& $ref_copy[$key];
		}
		while(!empty($path));

		return $array;
	}

	// Recursively removes all NULL values from an array
	public static function filter(array $array)
	{
		foreach($array as $key => $value)
		{
			if($value === NULL)
			{
				unset($array[$key]);
			}
			elseif (is_array($value))
			{
				$array[$key] = self::filter($value);
			}
		}
		return $array;
	}
}