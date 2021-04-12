<?php
/*
@version   v5.21.0-beta.1  20-Dec-2020
@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
Released under both BSD license and Lesser GPL library license.
Whenever there is any discrepancy between the two licenses,
the BSD license will take precedence. See License.txt.
Set tabs to 4 for best viewing.

  Latest version is available at https://adodb.org/

	Microsoft Access ADO data driver. Requires ADO and ODBC. Works only on MS Windows.
*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

if (!defined('_ADODB_ADO_LAYER')) {
	include_once(ADODB_DIR . "/drivers/adodb-ado5.inc.php");
}

class  ADODB_ado_access extends ADODB_ado {
	public  $databaseType = 'ado_access';
	public  $hasTop = 'top';		// support mssql SELECT TOP 10 * FROM TABLE
	public  $fmtDate = "#Y-m-d#";
	public  $fmtTimeStamp = "#Y-m-d h:i:sA#";// note no comma
	public  $upperCase = 'ucase';
	public  $hasGenID = true;

	public function __construct()
	{
		parent::__construct();
	}

	protected function _connect($pHostName, $pUserName, $pPassword, $pDataBase, $p_ = '')
	{
		try
		{
			$tCOM = NULL;
			$tConnectionString = "";

			if(!empty($this->charPage))
				{$tCOM = new COM('ADODB.Connection',null,$this->charPage);}
			else
				{$tCOM = new COM('ADODB.Connection');}

			if(!$tCOM)
				{return false;}

			$tCOM->Provider = "Microsoft.Jet.OLEDB.4.0"; // Microsoft Jet Provider

			$tConnectionString .= "PROVIDER=".$tCOM->Provider.";DATA SOURCE=$pHostName";
			if(!empty($pUserName))
				{$tConnectionString .= ";User Id=$pUserName";}
			if(!empty($pPassword))
				{$tConnectionString .= ";Password=$pPassword";}

			$tCOM->Open((string)$pHostName);
			$this->_connectionID = $tCOM;
			$tCOM->CursorLocation = $this->_cursor_location;

			return  ($tCOM->State > 0);

		}
		catch(exception $tE)
		{
			if($this->debug)
				{echo "<pre>",$tConnectionString,"\n",$tE,"</pre>\n";}
		}

		return false;
	}

	/*function BeginTrans() { return false;}

	public function CommitTrans() { return false;}

	public function RollbackTrans() { return false;}*/

}


class  ADORecordSet_ado_access extends ADORecordSet_ado {

	public  $databaseType = "ado_access";

}
