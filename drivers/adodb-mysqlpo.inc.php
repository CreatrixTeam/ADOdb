<?php

/*
@version   v5.22.0-dev  Unreleased
@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.
  Set tabs to 8.

  MySQL code that supports transactions. For MySQL 3.23 or later.
  Code from James Poon <jpoon88@yahoo.com>

  This driver extends the deprecated mysql driver, and was originally designed to be a
  portable driver in the same manner as oci8po and mssqlpo. Its functionality
  is exactly duplicated in the mysqlt driver, which is itself deprecated.
  This driver will be removed in ADOdb version 6.0.0.

  Requires mysql client. Works on Windows and Unix.
*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

include_once(ADODB_DIR."/drivers/adodb-mysql.inc.php");


class ADODB_mysqlt extends ADODB_mysql {
	public  $databaseType = 'mysqlt';
	public  $ansiOuter = true; // for Version 3.23.17 or later
	public  $hasTransactions = true;
	public  $autoRollback = true; // apparently mysql does not autorollback properly

	public function __construct()
	{
	global $ADODB_EXTENSION; if ($ADODB_EXTENSION) $this->rsPrefix .= 'ext_';
	}

	public function BeginTrans()
	{
		if ($this->transOff) return true;
		$this->transCnt += 1;
		$this->Execute('SET AUTOCOMMIT=0');
		$this->Execute('BEGIN');
		return true;
	}

	public function CommitTrans($ok=true)
	{
		if ($this->transOff) return true;
		if (!$ok) return $this->RollbackTrans();

		if ($this->transCnt) $this->transCnt -= 1;
		$this->Execute('COMMIT');
		$this->Execute('SET AUTOCOMMIT=1');
		return true;
	}

	public function RollbackTrans()
	{
		if ($this->transOff) return true;
		if ($this->transCnt) $this->transCnt -= 1;
		$this->Execute('ROLLBACK');
		$this->Execute('SET AUTOCOMMIT=1');
		return true;
	}

}

class ADORecordSet_mysqlt extends ADORecordSet_mysql{
	public  $databaseType = "mysqlt";

	protected function _MoveNext()
	{
		$this->bind = false;
		if (@$this->fields = mysql_fetch_array($this->_queryID,$this->mysql_getDriverFetchMode())) {
			$this->_currentRow += 1;
			return true;
		}
		if (!$this->EOF) {
			$this->_currentRow += 1;
			$this->EOF = true;
		}
		return false;
	}
}

class ADORecordSet_ext_mysqlt extends ADORecordSet_mysqlt {

	protected function _MoveNext()
	{
		return adodb_movenext($this);
	}
}
