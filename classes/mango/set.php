<?php

/*
 * Mango implementation of a Mongo Javascript Array.
 */

class Mango_Set extends Mango_ArrayObject {

	/*
	 * MongoDB does not support using different modifiers at the same time on a single set,
	 * therefore we remember the current mode
	 */
	protected $_mode;

	/*
	 * Set status to saved
	 */
	public function saved()
	{
		$this->_mode = NULL;

		parent::saved();
	}

	/*
	 * Return array of changes
	 *
	 * @param   boolean   Are we updating or creating
	 * @param   array     Location of set in parent element
	 * @return  array     Update data
	 * @throws  Mango_Exception   within a single set, if already $push/$pull, then no other mods are possible
	 */
	public function changed($update, array $prefix = array())
	{
		// fetch changed elements in this set
		$elements = array();

		switch ( $this->_mode)
		{
			case 'push':
			case 'set':
				foreach ( $this->_changed as $index)
				{
					$elements[] = $this->offsetGet($index);
				}
			break;
			case 'pull':
				// changed values were stored in _changed array
				$elements = $this->_changed;
			break;
		}

		// normalize changed elements 
		foreach ( $elements as &$element)
		{
			if ( $element instanceof Mango_Interface)
			{
				$element = $element->as_array();
			}
		}

		if ( $update === FALSE)
		{
			// no more changes possible after this
			return arr::path_set($prefix,$elements);
		}

		// First, get all changes made to the elements of this set directly
		$changes_local = array();

		switch ( $this->_mode)
		{
			case 'set':
				foreach ( $this->_changed as $index => $set_index)
				{
					$changes_local = arr::merge($changes_local, array('$set' => array( implode('.',$prefix) . '.' . $set_index => $elements[$index])));
				}
			break;
			case 'push':
			case 'pull':
				$mod = '$' . $this->_mode;

				if ( count($this->_changed) > 1)
				{
					$mod .= 'All';
				}
				else
				{
					$elements = $elements[0];
				}

				$changes_local = array($mod => array(implode('.',$prefix) => $elements));
			break;
		}

		// Second, get all changes made within children elements themselves
		$changes_children = array();

		// check elements that weren't modified directly for internal changes
		foreach ( $this as $index => $value)
		{
			if ( $this->_mode === 'pull' || $this->_mode === NULL || ! in_array($index, $this->_changed))
			{
				if( $value instanceof Mango_Interface)
				{
					$changes_children = arr::merge($changes_children, $value->changed($update, array_merge($prefix,array($index))));
				}
			}
		}

		// If we're pulling/pushing, any other modifier is disallowed (by MongoDB)
		if ( $this->_mode === 'push' || $this->_mode === 'pull')
		{
			if ( ! empty($changes_children))
			{
				throw new Mango_Exception('MongoDB does not support any other updates when already in :mode mode', array(
					':mode' => $this->_mode
				));
			}
		}

		// Return all changes
		return arr::merge( $changes_local, $changes_children);
	}

	/*
	 * Set value at index $index to $value
	 *
	 * @param   integer   index
	 * @param   mixed     value
	 * @return  void
	 * @throws  Mango_Exception   invalid key/action
	 */
	public function offsetSet($index,$newval)
	{
		// sets don't have associative keys
		if ( ! is_int($index) && ! is_null($index))
		{
			throw new Mango_Exception('Mango_Sets only supports numerical keys');
		}

		$mode = is_int($index) && $this->offsetExists($index)
			? 'set'
			: 'push';

		if ( isset($this->_mode) && $this->_mode !== $mode)
		{
			throw new Mango_Exception('MongoDB cannot :action when already in :mode mode', array(
				':action' => $mode,
				':mode'   => $this->_mode
			));
		}

		if ( $this->find($this->load_type($newval)) !== FALSE)
		{
			// value has been added to set already
			return TRUE;
		}

		// Set value
		$index = parent::offsetSet($index,$newval);

		// set mode & index of changed value
		$this->_mode = $mode;
		$this->_changed[] = $index;

		return TRUE;
	}

	/*
	 * Unset value at index $index
	 *
	 * @param   integer   index
	 * @return  void
	 * @throws  Mango_Exception   invalid key/action
	 */
	public function offsetUnset($index)
	{
		if ( ! ctype_digit((string)$index) && ! is_null($index))
		{
			throw new Mango_Exception('Mango_Sets only supports numerical keys');
		}

		if ( isset($this->_mode) && $this->_mode !== 'pull')
		{
			throw new Mango_Exception('MongoDB cannot pull when already in :mode mode', array(
				':mode'   => $this->_mode
			));
		}

		// set mode & pulled value
		$this->_mode = 'pull';
		$this->_changed[] = $this->offsetGet($index);

		parent::offsetUnset($index);
	}
}