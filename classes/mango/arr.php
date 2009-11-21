<?php

class Mango_Arr extends Kohana_Arr {

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
}