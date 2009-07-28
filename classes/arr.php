<?php

class arr extends kohana_arr {

	/**
	 * Sets a value in an array using a path.
	 *
	 *     // Set the value of $array['foo']['bar'] to TRUE
	 *     $array = Arr::path_set('foo/bar',TRUE);
	 *
	 *     $array = Arr::path_set( array('foo','bar'),  TRUE);
	 *
	 *     Arr::path_set('foo/bar',TRUE,$array);
	 *
	 * @param   mixed   key path
	 * @param   mixed   value
   * @param   array   array to modify (optional)
	 * @return  array
	 */
	public static function path_set($path,$value, & $array = array())
	{
		if(is_string($path))
		{
			// Split the keys by slashes
			$path = explode('/', trim($path, '/'));
		}

		$ref_copy =& $array;

		while(count($path))
		{
			$key = array_shift($path);

			if (ctype_digit($key))
			{
				// Make the key an integer
				$key = (int) $key;
			}

			if (! isset($ref_copy[$key]) || ! is_array($ref_copy[$key]))
			{
				$ref_copy[$key] = array();
			}

			$ref_copy =& $ref_copy[$key];
		}

		// set value
		$ref_copy = $value;

		return $array;
	}
}