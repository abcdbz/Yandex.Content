<?php
/**
 * Simple queue class
 *
 * USAGE:
 * $jobData = array(
 *		'content' => $text_for_yandex,
 *		'site_name' => 'www.yoursite.ru'
 * );
 * $qs = new SimpleQueue($datasource, 'QueueTableName');
 * $qs->putInQueue($jobData);
 */
class SimpleQueue {
	private $datasource;
	private $queue_table;
	/**
	 *
	 * @param object $datasource MySQLi object or other, implementing "escape" and "query" methods
	 * @param type $queue_table Table name for save queue to
	 */
	public function __construct($datasource, $queue_table) {
		$this->datasource = $datasource;
		$this->queue_table = $queue_table;
	}
	/**
	 *
	 * @param string[] $jobData Data array 'tbl_column' => 'value'
	 * @return integer Inserted row id
	 */
	public function putInQueue($jobData) {
		$insertsql = array(
			'`created` = ' . date("'YmdHis'", time())
		);
		foreach ($jobData as $column => $value) {
			$insertsql[] = '`' . $column . "` = '" . $this->datasource->escape($value) . "'";
		}
		$sql = 'INSERT INTO `' . $this->queue_table . '` set ' . implode(', ', $insertsql);
		if (($result = $this->datasource->query($sql)) == false) {
			return false;
		}
		return $this->datasource->insert_id;
	}
	/**
	 *
	 * @return string[][] Array of active jobs 'jobid' => jobdata[]
	 */
	public function getActiveJobs() {
		$sql = 'SELECT * FROM `' . $this->queue_table . '` where `status` = "new"';
		if (!($result = $this->datasource->query($sql))) {
			return false;
		}
		$list = array();
		while ($row = $result->fetch_assoc()) {
			$list[$row['id']] = $row;
		}
		return $list;
	}
	/**
	 * Mark job as done by id
	 *
	 * @param integer $jobId
	 * @return boolean
	 */
	public function markJobAsDone($jobId) {
		$sql = 'UPDATE `' . $this->queue_table . '` set `status` = "done" where id = ' . (int) $jobId;
		return $this->datasource->query($sql);
	}
}