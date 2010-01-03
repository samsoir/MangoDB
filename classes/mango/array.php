<?php

/*
 * Mango implementation of a Mongo Javascript Object.
 */

class Mango_Array extends Mango_ArrayObject {

	/*
	 * Return array of changes
	 */
	public function changed($update, array $prefix = array())
	{
		$changed = array();

		// Get a list of all relevant keys (current + unset keys)

		$keys = array();
		foreach($this as $key => $value)
		{
			$keys[] = $key;
		}
		// don't use array_unique(array_merge(array_keys($this->_changed),$keys)) - array_unique has issues with 0 values (eg array_unique( array('a',0,0,'a','c'));
		$keys = array_merge( $keys, array_diff( array_keys($this->_changed) ,$keys) );

		// Walk through array
		
		foreach($keys as $key)
		{
			$value = $this->offsetExists($key) 
				? $this->offsetGet($key) 
				: NULL;

			if ( isset($this->_changed[$key]))
			{
				// value has been changed
				if($value instanceof Mango_Interface)
				{
					$value = $value->as_array();
				}

				$path = array_merge($prefix,array($key));

				if ( $this->_changed[$key] === TRUE)
				{
					// __set
					$changed = $update
						? arr::merge($changed, array( '$set' => array( implode('.',$path) => $value)))
						: arr::merge($changed, arr::path_set($path,$value) );
				}
				else
				{
					// __unset
					if ( $update)
					{
						$changed = arr::merge($changed, array( '$unset' => array( implode('.',$path) => TRUE)));
					}
				}
			}
			elseif ($value instanceof Mango_Interface)
			{
				$changed = arr::merge($changed, $value->changed($update, array_merge($prefix,array($key))));
			}
		}

		return $changed;
	}

	/*
	 * Set a key to value
	 *
	 * @param   string   key
	 * @param   mixed    value
	 * @return  void
	 */
	public function offsetSet($key, $newval)
	{
		if(($key = parent::offsetSet($key,$newval)) !== FALSE)
		{
			// new value - remember change
			$this->_changed[$key] = TRUE;
		}
	}

	/*
	 * Unset a key
	 *
	 * @param   string   key
	 * @return  void
	 */
	public function offsetUnset($key)
	{
		if ( $this->offsetExists($key))
		{
			parent::offsetUnset($key);

			$this->_changed[$key] = FALSE;
		}
	}

	/*
	 * Updated as_array method
	 *
	 * (empty Mango_Arrays (equivalent of JS objects) are converted to object to ensure they're saved correctly)
	 */
	public function as_array( $clean = TRUE )
	{
		$array = parent::as_array( $clean );

		return $clean && ! count($array) 
			? (object) array() 
			: $array;
	}
}