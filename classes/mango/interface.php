<?php

interface Mango_Interface
{
	public function as_array( $__get = FALSE );
	
	public function get_changed($update, array $prefix = array());
	
	public function set_saved();
}