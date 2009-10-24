<?php

/*
 * Mango implementation of a Mongo Javascript Array.
 */

class Mango_Set extends Mango_ArrayObject {

	/*
	 * Remembers if we are pushing or pulling ($push(All) and $pull(All) cannot happen at the same time)
	 */
	protected $_push;

	/*
	 * Set status to saved
	 */
	public function saved()
	{
		$this->_push = NULL;

		parent::saved();
	}

	/*
	 * Return array of changes
	 */
	public function changed($update, array $prefix = array())
	{
		if( ! empty($this->_changed) )
		{
			if($this->_push)
			{
				$changed = array();
				
				foreach($this->_changed as $index)
				{
					$changed[] = $this->offsetGet($index);
				}
			}
			else
			{
				$changed = $this->_changed;
			}

			foreach($changed as &$value)
			{
				if($value instanceof Mango_Interface)
				{
					$value = $value->as_array();
				}
			}

			if($update === FALSE)
			{
				return arr::path_set($prefix,$changed);
			}
			elseif (count($this->_changed) > 1)
			{
				return array( $this->_push ? '$pushAll' : '$pullAll' => array(implode('.',$prefix) => $changed) );
			}
			else
			{
				return array( $this->_push ? '$push' : '$pull' => array(implode('.',$prefix) => $changed[0]) );
			}
		}
		else
		{
			$changed = array();
			
			// if nothing is pushed or pulled, we support $set
			foreach($this as $key => $value)
			{
				if($value instanceof Mango_Interface)
				{
					$changed = arr::merge($changed, $value->changed($update, array_merge($prefix,array($key))));
				}
			}

			return $changed;
		}
	}

	/*
	 * Updated offsetSet
	 *
	 * Does not accept string keys (only numbers)
	 * Does not accept setting (pushing) when already pulling
	 */
	public function offsetSet($index,$newval)
	{
		if($this->_push === FALSE)
		{
			// we're already pulling
			return FALSE;
		}

		// sets don't have associative keys
		if(! is_int($index) && ! is_null($index))
		{
			return FALSE;
		}

		// Check if value is already added
		// We only allow unique items to be added
		if( $this->find($this->load_type($newval)) !== FALSE )
		{
			return TRUE;
		}

		// indicate push
		$this->_push = TRUE;

		$index = parent::offsetSet($index,$newval);

		if($index !== FALSE)
		{
			// new value - remember index (fetch actual value on mango_set::get_changed)
			$this->_changed[] = $index;
		}

		return TRUE;
	}

	/*
	 * Updated offsetUnset
	 *
	 * Does not accept string keys (only numbers)
	 * Does not allow unset (pulling) if already adding (pushing)
	 */
	public function offsetUnset($index)
	{
		if($this->_push === TRUE)
		{
			// we're already pushing
			return FALSE;
		}

		// sets don't have associative keys
		if(! ctype_digit((string)$index) && ! is_null($index))
		{
			return FALSE;
		}

		// Only one $push/$pull action allowed
		if( ! empty($this->_changed))
		{
			return FALSE;
		}

		// indicate pull
		$this->_push = FALSE;

		// when pulling, we store value itself, only way to have access to it
		$this->_changed[] = $this->offsetGet($index);

		parent::offsetUnset($index);
	}
}