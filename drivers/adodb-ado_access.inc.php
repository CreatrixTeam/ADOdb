<?php
/*
@version   v5.21.0-dev  ??-???-2016
@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
Released under both BSD license and Lesser GPL library license.
Whenever there is any discrepancy between the two licenses,
the BSD license will take precedence. See License.txt.
Set tabs to 4 for best viewing.

  Latest version is available at http://adodb.sourceforge.net

	Microsoft Access ADO data driver. Requires ADO and ODBC. Works only on MS Windows.
*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

if (!defined('_ADODB_ADO_LAYER')) {
	include(ADODB_DIR."/drivers/adodb-ado5.inc.php");
	
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

	/*function BeginTrans() { return false;}

	public function CommitTrans() { return false;}

	public function RollbackTrans() { return false;}*/

}


class  ADORecordSet_ado_access extends ADORecordSet_ado {

	public  $databaseType = "ado_access";

}
