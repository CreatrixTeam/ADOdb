<?php
/**
 * ADOdb PDO Firebird driver
 *
 * @version   v5.21.0-beta.1  20-Dec-2020
 * @copyright (c) 2019      Damien Regad, Mark Newnham and the ADOdb community
 *
 * Released under both BSD license and Lesser GPL library license.
 * Whenever there is any discrepancy between the two licenses,
 * the BSD license will take precedence. See License.txt.
 *
 * Set tabs to 4 for best viewing.
 *
 * Latest version is available at https://adodb.org/
 *
 * This version has only been tested on Firebird 3.0 and PHP 7
 */

/**
 * Class ADODB_pdo_firebird
 */
class ADODB_pdo_firebird extends ADODB_pdo
{
	public  $databaseType = "pdo_firebird";
	protected  $dsnType = 'firebird';
	public $dialect = 3;
	public $metaTablesSQL = "select lower(rdb\$relation_name) from rdb\$relations where rdb\$relation_name not like 'RDB\$%'";
	public $metaColumnsSQL = "select lower(a.rdb\$field_name), a.rdb\$null_flag, a.rdb\$default_source, b.rdb\$field_length, b.rdb\$field_scale, b.rdb\$field_sub_type, b.rdb\$field_precision, b.rdb\$field_type from rdb\$relation_fields a, rdb\$fields b where a.rdb\$field_source = b.rdb\$field_name and a.rdb\$relation_name = '%s' order by a.rdb\$field_position asc";

	public $arrayClass = 'ADORecordSet_array_pdo_firebird';

	/**
	 * Gets the version iformation from the server
	 *
	 * @return string[]
	 */
	//ALMOST VERBATIM FROM adodb-firebird.inc.php. DIFFERENCES ARE ONLY IN CODE STYLE.
	public function ServerInfo()
	{
		$arr['dialect'] = $this->dialect;
		switch ($arr['dialect']) {
			case '':
			case '1':
				$s = 'Firebird Dialect 1';
				break;
			case '2':
				$s = 'Firebird Dialect 2';
				break;
			default:
			case '3':
				$s = 'Firebird Dialect 3';
				break;
		}
		$arr['version'] = ADOConnection::_findvers($s);
		$arr['description'] = $s;
		return $arr;
	}

	//ALMOST VERBATIM FROM adodb-firebird.inc.php. DIFFERENCES ARE ONLY IN CODE STYLE APART FROM ONE
	//		DIFFERENCE THAT IS THE USE OF $false IN THE ORIGINAL.
	protected function _MetaColumns($pParsedTableName)
	{
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$table = $pParsedTableName['table']['name'];

		$rs = $this->Execute(sprintf($this->metaColumnsSQL, strtoupper($table)));

		$this->SetFetchMode2($savem);

		if ($rs === false) {
			return false;
		}

		$retarr = array();
		//OPN STUFF start
		$dialect3 = $this->dialect == 3;
		//OPN STUFF end
		while (!$rs->EOF) { //print_r($rs->fields);
			$fld = new ADOFieldObject();
			$fld->name = trim($rs->fields[0]);
			//OPN STUFF start
			$this->_ConvertFieldType($fld, $rs->fields[7], $rs->fields[3], $rs->fields[4], $rs->fields[5],
				$rs->fields[6], $dialect3);
			if (isset($rs->fields[1]) && $rs->fields[1]) {
				$fld->not_null = true;
			}
			if (isset($rs->fields[2])) {

				$fld->has_default = true;
				$d = substr($rs->fields[2], strlen('default '));
				switch ($fld->type) {
					case 'smallint':
					case 'integer':
						$fld->default_value = (int)$d;
						break;
					case 'char':
					case 'blob':
					case 'text':
					case 'varchar':
						$fld->default_value = (string)substr($d, 1, strlen($d) - 2);
						break;
					case 'double':
					case 'float':
						$fld->default_value = (float)$d;
						break;
					default:
						$fld->default_value = $d;
						break;
				}
			}
			if ((isset($rs->fields[5])) && ($fld->type == 'blob')) {
				$fld->sub_type = $rs->fields[5];
			} else {
				$fld->sub_type = null;
			}
			if ($this->GetFetchMode() == ADODB_FETCH_NUM) {
				$retarr[] = $fld;
			} else {
				$retarr[strtoupper($fld->name)] = $fld;
			}

			$rs->MoveNext();
		}
		$rs->Close();
		if (empty($retarr)) {
			return false;
		} else {
			return $retarr;
		}
	}

	//ALMOST VERBATIM FROM adodb-firebird.inc.php. DIFFERENCES ARE ONLY IN CODE STYLE APART FROM ONE
	//		DIFFERENCE THAT IS THE USE OF $false IN THE ORIGINAL.
	protected function _MetaIndexes ($pParsedTableName, $primary = FALSE, $owner=false)
	{
		$table = $pParsedTableName['table']['name'];
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

		$table = strtoupper($table);
		$sql = "SELECT * FROM RDB\$INDICES WHERE RDB\$RELATION_NAME = '" . $table . "'";
		if (!$primary) {
			$sql .= " AND RDB\$INDEX_NAME NOT LIKE 'RDB\$%'";
		} else {
			$sql .= " AND RDB\$INDEX_NAME NOT LIKE 'RDB\$FOREIGN%'";
		}

		// get index details
		$rs = $this->Execute($sql);
		if (!is_object($rs)) {
			// restore fetchmode
			$this->SetFetchMode2($savem);

			return false;
		}

		$indexes = array();
		while ($row = $rs->FetchRow()) {
			$index = $row[0];
			if (!isset($indexes[$index])) {
				if (is_null($row[3])) {
					$row[3] = 0;
				}
				$indexes[$index] = array(
					'unique' => ($row[3] == 1),
					'columns' => array()
				);
			}
			$sql = "SELECT * FROM RDB\$INDEX_SEGMENTS WHERE RDB\$INDEX_NAME = '" . $index . "' ORDER BY RDB\$FIELD_POSITION ASC";
			$rs1 = $this->Execute($sql);
			while ($row1 = $rs1->FetchRow()) {
				$indexes[$index]['columns'][$row1[2]] = $row1[1];
			}
		}
		// restore fetchmode
		$this->SetFetchMode2($savem);

		return $indexes;
	}

	//ALMOST VERBATIM FROM adodb-firebird.inc.php. DIFFERENCES ARE ONLY IN CODE STYLE.
	protected function _MetaPrimaryKeys($pParsedTableName,$owner_notused=false,$internalKey=false)
	{
		if ($internalKey) {
			return array('RDB$DB_KEY');
		}
		$table = $pParsedTableName['table']['name'];
		$table = strtoupper($table);

		$sql = 'SELECT S.RDB$FIELD_NAME AFIELDNAME
	FROM RDB$INDICES I JOIN RDB$INDEX_SEGMENTS S ON I.RDB$INDEX_NAME=S.RDB$INDEX_NAME
	WHERE UPPER(I.RDB$RELATION_NAME)=\'' . $table . '\' and I.RDB$INDEX_NAME like \'RDB$PRIMARY%\'
	ORDER BY I.RDB$INDEX_NAME,S.RDB$FIELD_POSITION';

		$a = $this->GetCol($sql, false, true);
		if ($a && sizeof($a) > 0) {
			return $a;
		}
		return false;
	}

	//ALMOST VERBATIM FROM adodb-firebird.inc.php. DIFFERENCES ARE ONLY IN CODE STYLE.
	public function SelectLimit($sql, $nrows = -1, $offset = -1, $inputarr = false, $secs = 0)
	{
		$nrows = (integer)$nrows;
		$offset = (integer)$offset;
		$str = 'SELECT ';
		if ($nrows >= 0) {
			$str .= "FIRST $nrows ";
		}
		$str .= ($offset >= 0) ? "SKIP $offset " : '';

		$sql = preg_replace('/^[ \t]*select/i', $str, $sql);
		if ($secs) {
			$rs = $this->cacheExecute($secs, $sql, $inputarr);
		} else {
			$rs = $this->execute($sql, $inputarr);
		}

		return $rs;
	}

	/**
	 * Sets the appropriate type into the $fld variable
	 *
	 * @param ADOFieldObject $fld By reference
	 * @param int            $ftype
	 * @param int            $flen
	 * @param int            $fscale
	 * @param int            $fsubtype
	 * @param int            $fprecision
	 * @param bool           $dialect3
	 */
	//OPN STUFF start
	//ALMOST VERBATIM FROM adodb-firebird.inc.php. DIFFERENCES ARE ONLY IN CODE STYLE.
	final private function _convertFieldType(&$fld, $ftype, $flen, $fscale, $fsubtype, $fprecision, $dialect3)
	{
		$fscale = abs($fscale);
		$fld->max_length = $flen;
		$fld->scale = null;
		switch ($ftype) {
			case 7:
			case 8:
				if ($dialect3) {
					switch ($fsubtype) {
						case 0:
							$fld->type = ($ftype == 7 ? 'smallint' : 'integer');
							break;
						case 1:
							$fld->type = 'numeric';
							$fld->max_length = $fprecision;
							$fld->scale = $fscale;
							break;
						case 2:
							$fld->type = 'decimal';
							$fld->max_length = $fprecision;
							$fld->scale = $fscale;
							break;
					} // switch
				} else {
					if ($fscale != 0) {
						$fld->type = 'decimal';
						$fld->scale = $fscale;
						$fld->max_length = ($ftype == 7 ? 4 : 9);
					} else {
						$fld->type = ($ftype == 7 ? 'smallint' : 'integer');
					}
				}
				break;
			case 16:
				if ($dialect3) {
					switch ($fsubtype) {
						case 0:
							$fld->type = 'decimal';
							$fld->max_length = 18;
							$fld->scale = 0;
							break;
						case 1:
							$fld->type = 'numeric';
							$fld->max_length = $fprecision;
							$fld->scale = $fscale;
							break;
						case 2:
							$fld->type = 'decimal';
							$fld->max_length = $fprecision;
							$fld->scale = $fscale;
							break;
					} // switch
				}
				break;
			case 10:
				$fld->type = 'float';
				break;
			case 14:
				$fld->type = 'char';
				break;
			case 27:
				if ($fscale != 0) {
					$fld->type = 'decimal';
					$fld->max_length = 15;
					$fld->scale = 5;
				} else {
					$fld->type = 'double';
				}
				break;
			case 35:
				if ($dialect3) {
					$fld->type = 'timestamp';
				} else {
					$fld->type = 'date';
				}
				break;
			case 12:
				$fld->type = 'date';
				break;
			case 13:
				$fld->type = 'time';
				break;
			case 37:
				$fld->type = 'varchar';
				break;
			case 40:
				$fld->type = 'cstring';
				break;
			case 261:
				$fld->type = 'blob';
				$fld->max_length = -1;
				break;
		} // switch
	}
	//OPN STUFF end
}

/**
 * Class ADORecordSet_pdo_firebird
 */
class ADORecordSet_pdo_firebird extends ADORecordSet_pdo
{

	public $databaseType = "pdo_firebird";
}

