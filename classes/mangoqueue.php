<?php

class MangoQueue {

	// Get next message object from queue $queue
	// $delete: delete message from queue upon getting
	public static function get($queue = 0, $delete = TRUE)
	{
		$db = MangoDB::instance();
		$db->connect();

		if(!validate::alpha_dash($queue))
		{
			// invalid queue name
			return NULL;
		}

		$code = new MongoCode('function() {'.
				'var c = del ? { q : queue_name } : { q : queue_name, p : 0 }; '.
				'var o = db.queue.find(c,{ m : 1 }).sort({t:1}).limit(1); '.
				'if(o.count() > 0)'.
				'{ '.
					'o = o.next(); '.
					'if(del) '.
						'db.queue.remove({_id:o._id}); '.
					'else '.
						'db.queue.update({_id:o._id},{$set : {p:1}}); '.
					'return o; '.
				'} '.
			'}',array('queue_name'=>$queue,'del'=>(boolean)$delete));
			
		// Note: if multithreaded - I can make the item find a function within function and
		// run that function until (var e = db.$cmd.findOne({getlasterror:1}); e.n === 1)
		// (and add {_id:o._id,p:0} to update method)

		$msg = $db->execute($code);

		$item = $msg['retval'];
		
		if(! $item)
		{
			return NULL;
		}
		else
		{
			$item['m'] = unserialize(gzinflate($item['m']->bin));
			return $delete ? $item['m'] : (object) $item;
		}
	}

	// Delete item from queue - use this if you don't delete items on get()
	public static function delete(stdClass $item)
	{
		MangoDB::instance()->remove('queue',array('_id'=> $item->_id));
	}

	// Add message $msg to queue $queue
	public static function set($msg,$queue = 0)
	{
		// compose message
		$msg = array(
			't'  => microtime(TRUE), // time
			'm'   => new MongoBinData(gzdeflate(serialize($msg))), // msg
			'q' => $queue,           // queue name
			'p' => 0                 // processing
		);

		// store
		MangoDB::instance()->save('queue',$msg);
	}
}
?>