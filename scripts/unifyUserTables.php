<?php
$host = 'hostname';
$user = 'your-username';
$pass = 'your-password';

$primeWiki = 'wiki_meta';
$wikiDBnames = array(
	'wiki_eva',
	"wiki_mod",
	"wiki_bme",
	"wiki_flight",
	"wiki_rio",
	"wiki_dd_ms",
	"wiki_odb",
	"wiki_oso",
	"wiki_robo",
	"wiki_spartan",
	"wiki_topo",
	"wiki_vido",
	"wiki_meta"
);

$initialOffset = 10000; // make sure this is larger than your largest user ID

###############################################
#
# No changes required below here
#
###############################################




class DB {

	public $mysqli;

	public function __construct ( $wikiDB ) {
		global $host, $user, $pass;

		$this->mysqli = new mysqli( $host, $user, $pass, $wikiDB );

		if( $this->mysqli->connect_errno > 0 ){
		    die( 'Unable to connect to database [' . $this->mysqli->connect_error . ']' );
		}
	}

	public function query ( $sql ) {

		$result = $this->mysqli->query( $sql );

		if( ! $result ) {
		    die( 'There was an error running the query [' . $this->mysqli->error . ']' );
		}

		echo "\n$sql";

		if ( $result instanceof mysqli_result ) {

			$return = array();
			while( $row = $result->fetch_assoc() ){
				//print_r($row);
			    $return[] = $row;
			}

			$result->free();
			return $return;
		}
		else {
			echo "\nQuery complete, no rows returned.\n";
			return $result;
		}
	}

}






// $idAndNameTables = array(
// 	"archive"       => array( "idField" => "ar_user",  "userNameField" => "ar_user_text" ),
// 	"filearchive"   => array( "idField" => "fa_user",  "userNameField" => "fa_user_text" ),
// 	"image"         => array( "idField" => "img_user", "userNameField" => "img_user_text" ),
// 	"logging"       => array( "idField" => "log_user", "userNameField" => "log_user_text" ),
// 	"oldimage"      => array( "idField" => "oi_user",  "userNameField" => "oi_user_text" ),
// 	"recentchanges" => array( "idField" => "rc_user",  "userNameField" => "rc_user_text" ),
// 	"revision"      => array( "idField" => "rev_user", "userNameField" => "rev_user_text" ),
// );


$userTable = array( "idField" => "user_id", "userNameField" => "user_name" );

$userColumnInfo = array(
	'user_name'         => 'b',
	'user_editcount'    => 'i',
	'user_touched'      => 'b',
	'user_registration' => 'b',
	'user_email'        => 'b',
	'user_real_name'    => 'b',
);


$tablesToModify = array(
	"page_restrictions"  => array( "idField" => "pr_user" ),
	"protected_titles"   => array( "idField" => "pt_user" ),
	"uploadstash"        => array( "idField" => "us_user" ),
	"user_former_groups" => array( "idField" => "ufg_user" ),
	"user_groups"        => array( "idField" => "ug_user" ),
	"user_newtalk"       => array( "idField" => "user_id" ),
	"watchlist"          => array( "idField" => "wl_user" ),

	// these have IDs and usernames, but usernames should not need to be modified or used
	"archive"       => array( "idField" => "ar_user",  "userNameField" => "ar_user_text" ),
	"filearchive"   => array( "idField" => "fa_user",  "userNameField" => "fa_user_text" ),
	"image"         => array( "idField" => "img_user", "userNameField" => "img_user_text" ),
	"logging"       => array( "idField" => "log_user", "userNameField" => "log_user_text" ),
	"oldimage"      => array( "idField" => "oi_user",  "userNameField" => "oi_user_text" ),
	"recentchanges" => array( "idField" => "rc_user",  "userNameField" => "rc_user_text" ),
	"revision"      => array( "idField" => "rev_user", "userNameField" => "rev_user_text" ),

	// extension tables
	'watch_tracking_user' => array( "idField" => 'user_id' ),
	// 'wiretap'             => array( "idField" => NONE, username only )

);


$wikiDBs = array();
$originalUserIDs = array();
foreach( $wikiDBnames as $wiki ) {
	echo "\nConnecting to database $wiki";
	$wikiDBs[$wiki] = new DB( $wiki );

	$db = $wikiDBs[$wiki];

	$thisWikiUserTable = $db->query( "SELECT user_id, user_name FROM user" );

	$originalUserIDs[$wiki] = array();
	foreach( $thisWikiUserTable as $row ) {
		$userName  = $row['user_name'];
		$oldUserId = $row['user_id'];

		$originalUserIDs[$wiki][$userName] = $oldUserId;
	}

}






/**
 *  For each database, add $initialOffset to all user IDs in all tables
 *
 *  This just makes it so user IDs are always unique
 *
 *  Also remove unneeded table
 *
 **/
foreach( $wikiDBs as $wiki => $db ) {

	echo "\nAdding initial offset to user IDs in $wiki";

	foreach ( $tablesToModify + array( "user" => $userTable ) as $tableName => $tableInfo ) {
		$idField = $tableInfo['idField'];
		$db->query( "UPDATE $tableName SET $idField = $idField + $initialOffset" );
	}

	$db->query( "UPDATE ipblocks SET ipb_user = ipb_user + $initialOffset, ipb_by = ipb_by + $initialOffset");

	// DROP external_user table. See https://www.mediawiki.org/wiki/Manual:External_user_table
	$db->query( "DROP TABLE IF EXISTS external_user" );


}




/**
 *  Create $userArray by reading table `user` from all databases
 *
 *
 *
 **/
$userArray = array();
$newUserProps = array();
$userColumnsIssetChecks = array(
	'user_email',
	'user_real_name',
);

echo "\nCreating userArray from all user tables";

// Read user table for all wikis, add to $userArray giving each username a new unique ID
foreach( $wikiDBs as $wiki => $db ) {

	echo "\nAdding $wiki to userArray";

	// SELECT entire user table
	$userColumns = implode( ',', array_keys( $userColumnInfo ) );
	$result = $db->query(
		"SELECT $userColumns FROM user"
	);

	foreach( $result as $row ) {

		$userName = $row['user_name'];

		if ( ! isset( $userArray[$userName] ) ) {

			$userArray[$userName] = $row;

			// give new ID
			$newId = count( $userArray );

			$userArray[$userName]["user_id"] = $newId;

		} else {

			// sum edit counts
			$userArray[$userName]["user_editcount"] += $row['user_editcount'];

			// If this wiki ($row) has an older user_registration, use this wiki's value
			if ( $userArray[$userName]["user_registration"] > $row['user_registration'] ) {
				$userArray[$userName]["user_registration"] = $row['user_registration'];
			}

			// If this wiki ($row) has been touched more recently, use this wiki's value
			if ( $userArray[$userName]["user_touched"] < $row['user_touched'] ) {
				$userArray[$userName]["user_touched"] = $row['user_touched'];
			}

			foreach ( $userColumnsIssetChecks as $col ) {
				if ( ! $userArray[$userName][$col] ) {
					$userArray[$userName][$col] = $row[$col];
				}
			}

		}
	}

}





/**
 *  For all wikis, make changes to tables with usernames and user IDs
 *
 *  Loop through the ~17 tables with usernames and user IDs (except the user
 *  and user_properties tables) and update them accordingly
 *
 *  In the end, only one user and user_properties table will exist across all
 *  wikis.
 *
 **/
echo "\nStarting major table modifications";
foreach ( $wikiDBs as $wiki => $db ) {

	echo "\nStarting major modifications to $wiki";

	// // For tables with username and id columns: replace the id with the id from $userArray
	// foreach( $userArray as $userName => $newUserId ) {
	// 	foreach( $tablesWithUsernameAndId as $tableName => $tableInfo ) {
	// 		$idField = $tableInfo['idField'];
	// 		$userNameField = $tableInfo['userNameField'];

	// 		$stmt = $db->mysqli->prepare( "UPDATE $tableName SET $idField=? WHERE $userNameField=?" );
	// 		$stmt->bind_param( 'is', $newUserId, $userName );
	// 		$stmt->execute();
	// 	}
	// }

	// Lookup the ID in the user table, use username to get new ID from $UserArray, update ID
	// $originalUserIDs[$wiki][$userName] = old user id
	// $thisWikiUserTable = $db->query( "SELECT user_id, user_name FROM user" );
	// print_r( $thisWikiUserTable );

	$usernameToOldId = array();
	$newIdToOld = array(); // array like $newIdToOld[ newId ] = oldId
	$oldIdToNew = array(); // opposite of above...

	// foreach( $thisWikiUserTable as $row ) {
	foreach( $originalUserIDs[$wiki] as $userName => $oldUserId ) {

		$info = $userArray[$userName];
		$newUserId = $info['user_id'];

		// quick convert-from-this-to-that arrays
		$usernameToOldId[$userName] = $oldUserId;
		// $newIdToOld[$newUserId] = $oldUserId;
		$oldIdToNew[$oldUserId] = $newUserId;


		foreach( $tablesToModify as $tableName => $tableInfo ) {
			$idField = $tableInfo['idField'];
			$stmt = $db->mysqli->prepare( "UPDATE $tableName SET $idField=? WHERE $idField=?" );
			$stmt->bind_param( 'ii', $newUserId, $oldUserId );
			$stmt->execute();
		}

		// fix ipblocks table
		$stmt = $db->mysqli->prepare( "UPDATE ipblocks SET ipb_user=? WHERE ipb_user=?");
		$stmt->bind_param( 'ii', $newUserId, $oldUserId );
		$stmt->execute();
		$stmt = $db->mysqli->prepare( "UPDATE ipblocks SET ipb_by=? WHERE ipb_by=?");
		$stmt->bind_param( 'ii', $newUserId, $oldUserId );
		$stmt->execute();

	}


	// Get contents of user_properties, prep for insert into common
	// user_properties table
	$oldUserProps = $db->query( "SELECT * FROM user_properties" );
	// echo "\n\nOLDUSERPROPS:\n";
	// print_r( $oldUserProps );
	// echo "\n\oldIdToNew:\n";
	// print_r( $oldIdToNew );

	foreach( $oldUserProps as $row ) {
		if ( isset( $oldIdToNew[ $row['up_user'] ] ) ) {
			$newPropUserId = $oldIdToNew[ $row['up_user'] ];

			$row['up_user'] = $newPropUserId; // could be dupes across wikis...need to upsert at end
			$newUserProps[] = $row;
		} else {
			$oldId = $row['up_user'];
			echo "\nUser ID #$oldId not found in oldIdToNew array for $wiki.";
		}
	}

	// Empty the user table for this wiki, since it will just use the common
	// one created at the end. Same for user_properties
	//$db->query( "DELETE FROM user" );
	//$db->query( "DELETE FROM user_properties" );

}




/**
 *  Create new user table on the one wiki with the shared user table
 *
 *
 *
 **/
$db = $wikiDBs[$primeWiki];
$db->query( 'DELETE FROM user' );
$stmt = $db->mysqli->prepare(
	"INSERT INTO user
		(user_id,user_name,user_editcount,user_touched,user_registration,user_email,user_real_name)
	VALUES
		(?,?,?,?,?,?,?)"
);
foreach( $userArray as $username => $info ) {

	// all blobs (binary) except user id and edit count
	$colTypes = 'ibibbbb';

	// wish this wasn't hard-coded, but whatever
	$stmt->bind_param(
		$colTypes,
		$info['user_id'],
		$info['user_name'],
		$info['user_editcount'],
		$info['user_touched'],
		$info['user_registration'],
		$info['user_email'],
		$info['user_real_name']
	);
	$stmt->execute();

}
$autoInc = count( $userArray ) + 1;
$db->query( "ALTER TABLE user AUTO_INCREMENT = $autoInc;" );




/**
 *  Create new user_properties table on the one wiki with the shared user table
 *
 *
 *
 **/
$db->query( 'DELETE FROM user_properties' );
$stmt = $db->mysqli->prepare(
	"INSERT IGNORE INTO user_properties (up_user, up_property, up_value) VALUES (?,?,?)"
);
foreach( $newUserProps as $row ) {
	$stmt->bind_param( 'isb', $row['up_user'], $row['up_property'], $row['up_value'] );
	$stmt->execute();
}

echo "\n"; //end of script
