<?php

/**
 * Sierra access object
 *
 * @author David Walker <dwalker@calstate.edu>
 * 
 * @author Stacy Rickel <srickel1@unl.edu>
 * for modified content
 */

class Sierra
{
	/**
	 * @var string
	 */
	private $host;
	
	/**
	 * @var string
	 */
	private $username;
	
	/**
	 * @var string
	 */
	private $password;
	
	/**
	 * @var string
	 */
	private $port;
	
	/**
	 * @var string
	 */
	private $dbname;	
	
	/**
	 * @var PDO
	 */
	
	private $pdo;
	
	/**
	 * @var int
	 */
	
	private $total;
	
	/**
	 * Create new Sierra access object
	 * 
	 * @param string $host      hostname, sierra-db.example.edu
	 * @param string $username  db username
	 * @param string $password  db user's password
	 * @param string $port      [optional] default '1032'
	 * @param string $dbname    [optional] default 'iii'
	 */
	
	public function __construct($host, $username, $password, $port = '1032', $dbname = 'iii')
	{
		$this->host = $host;
		$this->username = $username;
		$this->password = $password;
		$this->port = $port;
		$this->dbname = $dbname;
	}
	
	/**
	 * Export bibliographic records (and attached item records) modified AFTER the supplied date
	 * 
	 * @param int $timestamp    unix timestamp
	 * @param string $location  path to file to create
	 * @param string $record_type string indicating type of record retrieving (added element)
	 * @param string $output_format format of output - marc or xml 
	 */
	
	public function exportRecordsModifiedAfter($timestamp, $location, $record_type='bib',$output_format='marc')
	{
		$date = gmdate("Y-m-d H:i:s", $timestamp);
		
		// record id's for those records modified since our supplied date
		
		$results = $this->getModifiedRecordData($date,$record_type);
		
		// make 'em
		if ($output_format=='xml') $this->createXMLRecords($location, $record_type.'_modified'.gmdate("Y-m-d",$timestamp), $results, true);
		else $this->createRecords($location, $record_type.'_modified'.gmdate("Y-m-d",$timestamp),$record_type, $results,true);
	}

	/**
	 * Export bibliographic records deleted AFTER the supplied date
	 *
	 * @param int $timestamp    unix timestamp
	 * @param string $location  path to file to create
	 * @param string $record_type type of record to retrieve information for
	 * @param string $output_format format of output - marc or xml 
	 */
	
	public function exportRecordsDeletedAfter($timestamp, $location,$record_type='bib',$output_format='marc')
	{
		$date = gmdate("Y-m-d H:i:s", $timestamp);
	
		// record id's for those records modified since our supplied date
	
		$results = $this->getDeletedRecordData($date,$record_type);
	
		// make 'em
	
		if ($output_format =='xml') $this->createXMLRecords($location, $record_type.'_deleted'.gmdate("Y-m-d",$timestamp), $results, true);
		else $this->createRecords($location, $record_type.'_deleted'.gmdate("Y-m-d",$timestamp), $record_type, $results, true);
	}	
	
	/**
	 * Export all records of a particular type out of the Innovative system
	 * 
	 * @param string $location  path to file to create
	 * @param string $record_type type of record to retrieve
	 * @param string $output_format format of output - marc or xml 
	 * 
	 */
	
	public function exportRecords($location,$record_type='bib',$output_format='marc')
	{
		// get all record id's
		echo "Retrieving all record data for $record_type\n";
		$results = $this->getAllRecordData($record_type);
		
		// make 'em
		echo "Retrieved ".count($results)." records\n";
		echo "Creating records...\n";
		if ($output_format =='xml') $this->createXMLRecords($location, 'full_'.$record_type, $record_type,$results,true);
		else $this->createRecords($location, 'full_'.$record_type, $record_type,$results,true);
		
	}
	
	/**
	 * Fetch an individual record
	 * 
	 * @param string $id
	 * @return File_MARC_Record|null
	 */
	
	public function getBibRecord($id)
	{
		// bib record query
		/* found leader information in the control_field:
		control_field.p40,p41,p42 seem to be the o5 (record status code) 06 (record_type/format code) and 07 (bib_level_code)
		*/		
		$sql = trim("
			SELECT
				bib_view.id,
				bib_view.bcode1,
				bib_view.bcode2,
				bib_view.bcode3,
				bib_view.cataloging_date_gmt,
				varfield_view.marc_tag,
				varfield_view.marc_ind1,
				varfield_view.marc_ind2,
				varfield_view.field_content,
				varfield_view.varfield_type_code,
				control_field.p40,
				control_field.p41,
				control_field.p42,
				leader_field.*
			FROM
				sierra_view.bib_view
			INNER JOIN 
				sierra_view.varfield_view ON bib_view.id = varfield_view.record_id
			LEFT JOIN
				sierra_view.leader_field ON bib_view.id = leader_field.record_id
			LEFT JOIN
				sierra_view.control_field ON bib_view.id = control_field.record_id and control_field.control_num=8
			WHERE
				bib_view.record_num = '$id' 
			ORDER BY 
				marc_tag
		");
		
		$results = $this->getResults($sql);
		
		if ( count($results) == 0 )
		{
			return null;
		}
		
	
		
		$result = $results[0];
		
		$internal_id = $result[0]; // internal postgres id
		
		//check for erm using the internal id
		
		$erm_sql = trim("
				SELECT 
					holding_record_erm_holding.* 
				FROM 
					sierra_view.bib_record_holding_record_link 
				LEFT JOIN 
					sierra_view.holding_record_erm_holding ON holding_record_erm_holding.holding_record_id=bib_record_holding_record_link.holding_record_id
				WHERE 
					bib_record_holding_record_link.bib_record_id=$internal_id and marc_tag='856'
			");
		$erm_results = $this->getResults($erm_sql);
		if (count($erm_results) > 0) $results = array_merge($results,$erm_results);
		// let's parse a few things, shall we
		
		if ($result['bcode3'] == 'n'){
			//suppressed item - let's delete it from the discovery tool data.
			$record = $this->createDeletedRecord($id);
			return $record;
		} 
		
		//check for records to ignore we couldn't filter out before
		// call numbers that start with x are reserve items
		foreach ($results as $row){
			if (!empty($row['varfield_type_code']) && ($row['varfield_type_code'] == 'c' && preg_match('/^x.*/',$row['field_content']))) return null;
		}
		
		//start the marc record
		$record = new File_MARC_Record();
			
		// leader
		
		// 0000's here get converted to correct lengths by File_MARC
		
		$leader = '00000'; // 00-04 - Record length
		
		/* we have to determine what to do in the cases that we get no leader information back from the database */
		
		if ($this->getLeaderValue($result,'record_status_code') == ' ') $leader .= $this->getLeaderValue($result,'p40'); // 05 - Record status
		else $leader .= $this->getLeaderValue($result,'record_status_code');
		//we can get the following field from the bcode1 field
		if ($this->getLeaderValue($result,'record_type_code') == ' ') $leader .= $this->getLeaderValue($result,'bcode1');
		else $leader .= $this->getLeaderValue($result,'record_type_code'); // 06 - Type of record
		
		//we can get the following field from the bcode2 field
		if ($this->getLeaderValue($result,'bib_level_code')==' ') $leader .= $this->getLeaderValue($result,'bcode2');
		else $leader .= $this->getLeaderValue($result,'bib_level_code'); // 07 - Bibliographic level
		
		$leader .= $this->getLeaderValue($result,'control_type_code'); // 08 - Type of control
		$leader .= $this->getLeaderValue($result,'char_encoding_scheme_code'); // 09 - Character coding scheme
		$leader .= '2'; // 10 - Indicator count 
		$leader .= '2'; // 11 - Subfield code count
		$leader .= '00000'; // 12-16 - Base address of data
		
		//found the next one in p43 of control_field
		$leader .= $this->getLeaderValue($result,'encoding_level_code'); // 17 - Encoding level
		$leader .= $this->getLeaderValue($result,'descriptive_cat_form_code'); // 18 - Descriptive cataloging form
		$leader .= $this->getLeaderValue($result,'multipart_level_code'); // 19 - Multipart resource record level
		$leader .= '4'; // 20 - Length of the length-of-field portion
		$leader .= '5'; // 21 - Length of the starting-character-position portion
		$leader .= '0'; // 22 - Length of the implementation-defined portion
		$leader .= '0'; // 23 - Undefined
		
		$record->setLeader($leader);
		
		// innovative bib record fields
		// Was set to 907 in the shrew git setup, but since our innovative export sends this to the 035 field, seems easiest to match that.
		$bib_field = new File_MARC_Data_Field('035');
		$record->appendField($bib_field);
		$bib_field->appendSubfield(new File_MARC_Subfield('a', $this->getFullRecordId($id)));
		
		// cataloging info fields -woo-hoo - nice to have
		
		$bib_field = new File_MARC_Data_Field('998');
		$record->appendField($bib_field);
		
		$bib_field->appendSubfield(new File_MARC_Subfield('c', trim($result['cataloging_date_gmt'])));
		$bib_field->appendSubfield(new File_MARC_Subfield('d', trim($result['bcode1'])));
		$bib_field->appendSubfield(new File_MARC_Subfield('e', trim($result['bcode2'])));
		$bib_field->appendSubfield(new File_MARC_Subfield('f', trim($result['bcode3'])));
		//$bib_field->appendSubfield(new File_MARC_Subfield('l', trim($result['location'])));
		
		// marc fields
		
		foreach ( $results as $result )
		{
			try
			{
				// skip missing tags and 'old' 9xx tags that mess with the above
				
				if ( $result['marc_tag'] == null || $result['marc_tag'] == '907' || $result['marc_tag'] == '998')
				{
					continue;
				}
				
				// control field
				
				if ( (int) $result['marc_tag'] < 10 )
				{
					$control_field = new File_MARC_Control_Field($result['marc_tag'], $result['field_content']);
					$record->appendField($control_field);
				}
				
				// data field
				
				else 
				{
					$data_field = new File_MARC_Data_Field($result['marc_tag']);
					$data_field->setIndicator(1, $result['marc_ind1']);
					$data_field->setIndicator(2, $result['marc_ind2']);
					
					$content = $result['field_content'];
					
					$content_array  = explode('|', $content);
					
					foreach ( $content_array as $subfield )
					{
						$code = substr($subfield, 0, 1);
						$data = substr($subfield, 1);
						
						if ( $code == '')
						{
							continue;
						}
						
						$subfield = new File_MARC_Subfield($code, trim($data));
						$data_field->appendSubfield($subfield);
					}
					
					$record->appendField($data_field);
				}
			}
			catch ( File_MARC_Exception $e )
			{
				trigger_error( $e->getMessage(), E_USER_WARNING );
			}
		}
		
		// location codes - leaving that in to use 
		
		$sql = trim("
			SELECT location_code, location_name.name as location_name
			FROM
				sierra_view.bib_record_location
			LEFT JOIN 
				sierra_view.location ON bib_record_location.location_code=location.code
			LEFT JOIN
				sierra_view.location_name ON location.id=location_name.location_id
			WHERE
				bib_record_id = '$internal_id'
		");		
		
		$results = $this->getResults($sql);
		
		if ( count($results) > 0 )
		{
			$location_record = new File_MARC_Data_Field('907');
			
			foreach ( $results as $result )
			{
				$location_record->appendSubfield(new File_MARC_Subfield('b', trim((string)$result['location_code'])));
				if (trim($result['location_name']) != '') $location_record->appendSubfield(new File_MARC_Subfield('l', trim((string)$result['location_name'])));
			}
			
			$record->appendField($location_record);
		}
		
		// item records
		
// 		$sql = trim("
// 			SELECT item_view.*
// 			FROM 
// 				sierra_view.bib_view,
// 				sierra_view.item_view,
// 				sierra_view.bib_record_item_record_link
// 			WHERE 
// 				bib_view.record_num = '$id' AND
// 				bib_view.id = bib_record_item_record_link.bib_record_id AND
// 				item_view.id = bib_record_item_record_link.item_record_id				
// 		");
		
// 		$results = $this->getResults($sql);
		
// 		foreach ( $results as $result )
// 		{
// 			$item_record = new File_MARC_Data_Field('945');
// 			$item_record->appendSubfield(new File_MARC_Subfield('l', trim($result['location_code'])));
			
// 			$record->appendField($item_record);
// 		}
		
		return $record;
	}
	
	/**
	 * Create MARC records from a set of record id's
	 *
	 * @param string $location  path to file to create
	 * @param string $name      name of file to create
	 * @param string $record_type	type of records: bib, authority
	 * @param array $results    id query
	 * @param bool $split       [optional] whether split the file into 50,000-record smaller files (default false)
	 * 
	 */
	
	public function createRecords($location, $name, $record_type='bib',$results, $split = false)
	{
		if (! is_dir($location) )
		{
			throw new Exception("location must be a valid directory, you supplied '$location'");
		}
		
		$this->total = count($results);
		
		// split them into chunks of 100k - nope larger - 200k I think
		
		$chunks = array_chunk($results, 100000);
		$x = 1; // file number
		$y = 1; // number of records's processed
		
		// file to write to
		
		if ( $split === false )
		{
			$marc21_file = fopen("$location/$name.mrc", "wb");
		}
		
		foreach ( $chunks as $chunk )
		{
			// file to write to (if broken into chunks)
			
			if ( $split === true )
			{
				$marc21_file = fopen("$location/$name-$x.mrc", "wb");
			}
			
			// create each marc record based on the id
			
			foreach ( $chunk as $result )
			{
				$marc_record = null;
			
				$id = $result['record_num'];
				
				// deleted record
			
				if ( !empty($result['deletion_date_gmt']))
				{
					$marc_record = $this->createDeletedRecord($id,$record_type);
				}
				else // active record
				{
					if ($record_type=='bib') $marc_record = $this->getBibRecord($id);
					elseif ($record_type=='authority') $marc_record = $this->getAuthorityRecord($id);					
											
				}
				
				if ( $marc_record != null )
				{
					fwrite($marc21_file, $marc_record->toRaw());
				}
				
			
				$this->log("Fetched $record_type record '$id' (" . number_format($y) . " of " . number_format($this->total) . ")\n");
				$y++;
			}
			
			if ( $split === true )
			{
				fclose($marc21_file);
			}

			$x++;
			
			// blank this so PDO will create a new connection
			// otherwise after about ~70,000 queries the server 
			// will drop the connection with an error
			
			$this->pdo = null; 
		}
		
		if ( $split === false )
		{
			fclose($marc21_file);
		}
	}
	
	/**
	 * Create XML records from a set of record id's
	 * Added to facilitate create of other catalog records that do not have standard MARC
	 * 
	 * @param string $location  path to file to create
	 * @param string $name      name of file to create
	 * @param string $record_type	type of records: bib, authority
	 * @param array $results    id query
	 * @param bool $split       [optional] whether split the file into 50,000-record smaller files (default false)
	 *
	 */
	
	public function createXMLRecords($location, $name, $record_type='eresource',$results, $split = false)
	{
		if (! is_dir($location) )
		{
			throw new Exception("location must be a valid directory, you supplied '$location'");
		}
	
		$this->total = count($results);
	
		// split them into chunks of 100k - nope larger - 200k I think
	
		$chunks = array_chunk($results, 100000);
		$x = 1; // file number
		$y = 1; // number of records's processed
	
		// file to write to
	
		if ( $split === false )
		{
			//start the XML file as that's what we are doing here
			$xml_doc = new DomDocument('1.0', 'UTF-8');
			$xml_doc->formatOutput=true;
			$xml_file = "$location/$name.xml";
			$records = $xml_doc->createElement("records");
			$xml_doc->appendChild($records);
		}
	
		foreach ( $chunks as $chunk )
		{
			// file to write to (if broken into chunks)
				
			if ( $split === true )
			{
			
				$xml_file = "$location/$name-$x.xml";
				$xml_doc = new DomDocument('1.0','UTF-8');
				$xml_doc->formatOutput = true;
				$xml_doc->createElement('records');
				$records = $xml_doc->createElement("records");
				$xml_doc->appendChild($records);
		
			}
				
			// create each marc record based on the id
				
			foreach ( $chunk as $result )
			{
				$marc_record = null;
					
				$id = $result['record_num'];
	
				// deleted record
					
				if ( !empty($result['deletion_date_gmt']))
				{
					$marc_record = $this->createDeletedRecord($id,$record_type);
				}
				else // active record
				{
					if ($record_type=='eresource') 	$xml_doc = $this->getEresourceRecord($xml_doc,$records,$id);
					else return null;
						
				}
					
				$this->log("Fetched $record_type record '$id' (" . number_format($y) . " of " . number_format($this->total) . ")\n");
				$y++;
			}
				
			if ( $split === true )
			{
				$xml_doc->save($xml_file);				
			}
	
			$x++;
				
			// blank this so PDO will create a new connection
			// otherwise after about ~70,000 queries the server
			// will drop the connection with an error
				
			$this->pdo = null;
		}
	
		if ( $split === false )
		{
			$xml_doc->save($xml_field);			
		}
	}
	
	/**
	 * Create a deleted record
	 * 
	 * This is essentially a placeholder record so we have something that represents a
	 * record completely expunged from the system
	 * 
	 * @param int $id
	 * @param string $record_type type of record 
	 */
	
	public function createDeletedRecord($id,$record_type='bib')
	{
		$record = new File_MARC_Record();
		
		$control_field = new File_MARC_Control_Field('001', "$id");
		$record->appendField($control_field);
		
		$record_field = new File_MARC_Data_Field('035');
		$record->appendField($record_field);
		$record_field->appendSubfield(new File_MARC_Subfield('a', $this->getFullRecordId($id)));
		
		// find field to use to mark for deletion
		if ($record_type=='bib'){								
			$new_field = new File_MARC_Data_Field('998');
		}
		elseif ($record_type='authority'){			
			$new_field = new File_MARC_Data_Field('010');
		}
		
		$record->appendField($new_field);
		$new_field->appendSubfield(new File_MARC_Subfield('f', 'd'));

		return $record;
	}
	
	/**
	 * Return record id (and date information) for records of type $record_type modified since the supplied date
	 * 
	 * @return array
	 */

	protected function getModifiedRecordData( $date,$record_type='bib' )
	{
		
		$sql = trim("
			SELECT
				record_num, record_last_updated_gmt, deletion_date_gmt
			FROM
				sierra_view.record_metadata 
			WHERE
				record_type_code = :record_type_code AND
				campus_code = '' AND 
				( record_last_updated_gmt > :modified_date OR deletion_date_gmt > :modified_date) 
			ORDER BY
				record_last_updated_gmt DESC NULLS LAST 
		");

		$results = $this->getResults($sql, array(':modified_date' => $date,':record_type_code'=>substr($record_type,0,1)));
		
		return $results;
	}
	
	/**
	 * Return record id (and date information) for records of type $record_type  modified since the supplied date
	 *
	 * @return array
	 */
	
	protected function getDeletedRecordData( $date, $record_type = "bib")
	{
		$sql = trim("
			SELECT
				record_num, record_last_updated_gmt, deletion_date_gmt
			FROM
				sierra_view.record_metadata
			WHERE
				record_type_code = :record_type_code AND
				campus_code = '' AND
				deletion_date_gmt > :modified_date
			ORDER BY
				record_last_updated_gmt DESC NULLS LAST
		");
	
		return $this->getResults($sql, array(':modified_date' => $date,':record_type_code'=>substr($record_type,0,1)));
	}	
	
	/**
	 * Return record id (and date information) for all records of a particular type in the system
	 * @param $include_options - determine what records to include - deleted and/or suppressed 
	 * 	default is array('deleted'=>false, 'suppressed'=>false) to not include them.
	 * @return array
	 */
	
	protected function getAllRecordData( $record_type = 'bib',$limit = null,  $offset = 0, $include_options = array() )
	{
		// types of records we can get 
		if ($record_type=='bib') $join_table = "bib_record";
		elseif ($record_type=='authority') $join_table = "authority_record";
		elseif ($record_type=='item') $join_table = "item_record";
		elseif ($record_type=='eresource') $join_table = "resource_record";
		
		$record_type_code = substr($record_type,0,1);
		
		if (!empty($include_options['suppressed'])) {
			$sql = trim("
			SELECT
				record_metadata.record_num, record_metadata.record_last_updated_gmt, record_metadata.deletion_date_gmt
			FROM
				sierra_view.record_metadata,
				sierra_view.{$join_table}
			WHERE
				record_metadata.record_type_code = '{$record_type_code}' AND
				record_metadata.campus_code = '' AND
				record_metadata.deletion_date_gmt IS NULL AND
				sierra_view.record_metadata.id = sierra_view.{$join_table}.id
			ORDER BY
				record_last_updated_gmt DESC NULLS LAST
		");
		}
		else {
		$sql = trim("
			SELECT
				record_metadata.record_num, record_metadata.record_last_updated_gmt, record_metadata.deletion_date_gmt
			FROM
				sierra_view.record_metadata,
				sierra_view.{$join_table}
			WHERE
				record_metadata.record_type_code = '{$record_type_code}' AND
				record_metadata.campus_code = '' AND
				record_metadata.deletion_date_gmt IS NULL AND
				sierra_view.record_metadata.id = sierra_view.{$join_table}.id AND
				NOT sierra_view.{$join_table}.is_suppressed
			ORDER BY
				record_last_updated_gmt DESC NULLS LAST
		");
		} 

		if ( $limit != null && $offset != 0 )
		{
			$sql .= " LIMIT $limit, $offset";
		}		
		echo "Running sql query $sql\n";
		return $this->getResults($sql);
	}	

	/**
	 * Fetch results from the database
	 *
	 * @param string $sql    query
	 * @param array $params  [optional] query input paramaters
	 *
	 * @throws Exception
	 * @return array
	 */
	
	protected function getResults($sql, array $params = null)
	{
		$statement = $this->pdo()->prepare($sql);
	
		if ( ! $statement->execute($params) )
		{
			throw new Exception('Could not execute query');
		}
	
		return $statement->fetchAll();
	}
	
	/**
	 * The full record id, including starting period and check digit
	 * 
	 * @param string $id
	 * @param string $record_type_prefix - character of record type.  Valid choices: 'a','b','i','e'
	 * @return string
	 */
	
	protected function getFullRecordId($id,$record_type_prefix='b')
	{
		return ".$record_type_prefix$id" . $this->getCheckDigit($id);
	}
	
	/**
	 * Calculate Innovative Record number check digit
	 * 
	 * Thanks to mark matienzo (anarchivist) https://github.com/anarchivist/drupal-shrew/
	 * 
	 * @param string $recnum
	 * @return string
	 */
	
	protected function getCheckDigit($recnum) 
	{
		$seq = array_reverse(str_split($recnum));
		$sum = 0;
		$multiplier = 2;
		
		foreach ($seq as $digit)
		{
			$digit *= $multiplier;
			$sum += $digit;
			$multiplier++;
		}
		$check = $sum % 11;
		
		if ($check == 10)
		{
		    return 'x';
		}
		else
		{
    		return strval($check);
    	}
	}
	
	/**
	 * Error logging
	 * 
	 * @param string $message
	 */
	
	protected function log($message)
	{
		echo $message;
	}
	
	/**
	 * Lazy load PDO
	 */
	
	protected function pdo()
	{
		if ( ! $this->pdo instanceof PDO )
		{
			$dsn = 'pgsql:host=' . $this->host . ';' .
				'port=' . $this->port . ';' .
				'dbname=' . $this->dbname . ';' .
				'user=' . $this->username . ';' .
				'password=' . $this->password . ';' . 
				'sslmode=require';

			$this->pdo = new PDO($dsn);
			$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		
		return $this->pdo;
	}
	
	/**
	 * Get the value out of the array or return a blank space
	 * @param array $array
	 * @param string $key
	 */
	
	private function getLeaderValue(array $array, $key)
	{
		$value = $array[$key];
		
		if ( $value == "")
		{
			return " ";
		}
		else
		{
			return $value;
		}
	}
	
	/** Additional functions added by srickel1 to facitilate export of Authority records **/
	public function getAuthorityRecord($id)
	{
		// authority record query	
		$sql = trim("
				SELECT
				authority_view.id,
				authority_view.record_creation_date_gmt,
				authority_view.suppress_code,
				varfield_view.marc_tag,
				varfield_view.marc_ind1,
				varfield_view.marc_ind2,
				TRIM(varfield_view.field_content),
				varfield_view.varfield_type_code,
				control_field.p40,
				control_field.p41,
				control_field.p42,
				leader_field.*
				FROM
				sierra_view.authority_view
				INNER JOIN
				sierra_view.varfield_view ON authority_view.id = varfield_view.record_id
				LEFT JOIN
				sierra_view.leader_field ON authority_view.id = leader_field.record_id
				LEFT JOIN
				sierra_view.control_field ON authority_view.id = control_field.record_id
				WHERE
				authority_view.record_num = '$id' and control_field.control_num=8
				ORDER BY
				marc_tag
				");
	
		$results = $this->getResults($sql);
	
		if ( count($results) == 0 )
		{
		return null;
		}
	
		// let's parse a few things, shall we
	
		$result = $results[0];
	
		$internal_id = $result[0]; // internal postgres id
	
		if ($result['suppress_code'] == 'n'){
			//suppressed item - let's delete it from the discovery tool data.
				$record = $this->createDeletedRecord($id);
				return $record;
		}
	
		//start the marc record
		$record = new File_MARC_Record();
			
		// leader
	
		// 0000's here get converted to correct lengths by File_MARC
	
		$leader = '00000'; // 00-04 - Record length
	
		/* we have to determine what to do in the cases that we get no leader information back from the database */
	
		if ($this->getLeaderValue($result,'record_status_code') == ' ') $leader .= $this->getLeaderValue($result,'p40'); // 05 - Record status
		else $leader .= $this->getLeaderValue($result,'record_status_code');
		//we can get the following field from the bcode1 field
		if ($this->getLeaderValue($result,'record_type_code') == ' ') $leader .= $this->getLeaderValue($result,'p41');
		else $leader .= $this->getLeaderValue($result,'record_type_code'); // 06 - Type of record
	
		//we can get the following field from the ? field
		$leader .= $this->getLeaderValue($result,'p42');	
	
		$leader .= $this->getLeaderValue($result,'control_type_code'); // 08 - Type of control
		$leader .= $this->getLeaderValue($result,'char_encoding_scheme_code'); // 09 - Character coding scheme
		$leader .= '2'; // 10 - Indicator count
		$leader .= '2'; // 11 - Subfield code count
		$leader .= '00000'; // 12-16 - Base address of data
	
		//found the next one in p43 of control_field
		$leader .= $this->getLeaderValue($result,'encoding_level_code'); // 17 - Encoding level
		$leader .= $this->getLeaderValue($result,'descriptive_cat_form_code'); // 18 - Descriptive cataloging form
		$leader .= $this->getLeaderValue($result,'multipart_level_code'); // 19 - Multipart resource record level
		$leader .= '4'; // 20 - Length of the length-of-field portion
			$leader .= '5'; // 21 - Length of the starting-character-position portion
		$leader .= '0'; // 22 - Length of the implementation-defined portion
		$leader .= '0'; // 23 - Undefined
	
		$record->setLeader($leader);
	
			
		// marc fields
		$record_id_field = new File_MARC_Data_Field('035');
		$record->appendField($record_id_field);
		$record_id_field->appendSubfield(new File_MARC_Subfield('a', $this->getFullRecordId($id,'a')));
		
		foreach ( $results as $result )
		{
		try
		{
		// skip missing tags and 'old' 9xx tags that mess with the above
	
		if ( $result['marc_tag'] == null || $result['marc_tag'] == '907' || $result['marc_tag'] == '998')
		{
		continue;
		}
	
		// control field
	
		if ( (int) $result['marc_tag'] < 10 )
		{
				$control_field = new File_MARC_Control_Field($result['marc_tag'], $result['field_content']);
						$record->appendField($control_field);
						}
	
						// data field
	
						else
						{
						$data_field = new File_MARC_Data_Field($result['marc_tag']);
						$data_field->setIndicator(1, $result['marc_ind1']);
						$data_field->setIndicator(2, $result['marc_ind2']);
			
								$content = $result['field_content'];
									
										$content_array  = explode('|', $content);
			
												foreach ( $content_array as $subfield )
												{
												$code = substr($subfield, 0, 1);
												$data = substr($subfield, 1);
	
												if ( $code == '')
												{
												continue;
												}
	
												$subfield = new File_MARC_Subfield($code, trim($data));
												$data_field->appendSubfield($subfield);
												}
													
												$record->appendField($data_field);
												}
		}
		catch ( File_MARC_Exception $e )
		{
			trigger_error( $e->getMessage(), E_USER_WARNING );
		}
		}
	
		return $record;
	}
	
	/**
	 * Fetch an individual record
	 * @param xml object
	 * @param string $id
	 * @return XML object |null
	 */
	
	public function getEresourceRecord($xml_obj,$xml_root,$id)
	{
		$id = substr($id,0,7);  //just in case we get some full record numbers..
		// eresource record query		
		$sql = trim("
				SELECT
				r.id,
				r.record_num,				
				r.resource_status_code,				
				r.access_provider_code,
				r.publisher_code,
				varfield.varfield_type_code,
				trim(varfield.field_content) as field_content,
				varfield.occ_num FROM 
				sierra_view.resource_view as r 
				LEFT JOIN sierra_view.varfield 
				ON varfield.record_id=r.id 
				WHERE r.record_num='$id' 
				ORDER BY varfield.varfield_type_code,occ_num				
				");
		//example record_num=1000333
		
		$results = $this->getResults($sql);
	
		if ( count($results) == 0 )
		{
			return null;
		}	
		//append to the xml document
		$record = $xml_obj->createElement('eresource');
		$xml_root->appendChild($record);
		
		$result = $results[0];
	
		$internal_id = $result[0]; // internal postgres id
		
		//TODO: figure out how to write deleted XML record
// 		if ($result['suppress_code'] == 'n'){
// 			//suppressed item - let's delete it from the discovery tool data.
// 			$record = $this->createDeletedRecord($id);
// 			return $record;
// 		}
		//check for license information for authorized users using the internal id in checkin record holdings
		$license_sql = trim("SELECT field_content FROM
		sierra_view.varfield_view LEFT JOIN sierra_view.license_view 
		ON (license_view.record_num=varfield_view.record_num and varfield_view.record_type_code='l') 
		LEFT JOIN sierra_view.resource_record_license_record_link as rl 
		ON rl.license_record_id=license_view.id
		WHERE rl.resource_record_id='$internal_id' and varfield_type_code='u'");
		$l_result = $this->getResults($license_sql);
		if (count($l_result)==1)
		{ //access information to add
			$access_level = $l_result[0][0];
		}
		else $access_level = null;
		//write the information to the xml file
		//add all the child nodes
		
		$record_id = $xml_obj->createElement('record_id'); 
		$record_id=$record->appendChild($record_id);		
		$record_id->appendChild($xml_obj->createTextNode($id));
		
		foreach ($results as $result){
			$item = null;
			switch ($result['varfield_type_code']) {
				case 'f': //public note
						$item = $xml_obj->createElement('note');
						break;
				case 'j': //description
						$item = $xml_obj->createElement('description');
						break;
				case 'p': //resource_id
						$item = $xml_obj->createElement('resource_id');
						break;
				case 'q': //subject
						$item = $xml_obj->createElement('subject');
						break;
				case 't': //title
						$item = $xml_obj->createElement('title');
						break;
				case 'x': //alternate name/title
						$item = $xml_obj->createElement('alternate_name');
						break;
				case 'y': //resource url
						$item = $xml_obj->createElement('resource_url');
						break;				
			}			
			if (isset($item)) {
				$record->appendChild($item);
				$item->appendChild($xml_obj->createTextNode($result['field_content']));
			}
		}
		//add the license information
		if (isset($access_level)){
			$access = $xml_obj->createElement('access_level');
			$record->appendChild($access);
			$access->appendChild($xml_obj->createTextNode($access_level));
		}
		return $xml_obj;
	}
}
