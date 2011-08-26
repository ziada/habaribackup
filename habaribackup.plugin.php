<?php
class HabariBackup extends Plugin
{
	private $rows_per_segment= 100;
	private $filename = '';
	private $backup_path= '';
	private $backup_errors = array();
	private $db_type= '';
	private $db_host= '';
	private $db_name= '';
	private $db_prefix= '';

	/**
	 * function action_plugin_activation
	**/
	public function action_plugin_activation( $file )
	{
		if ( realpath( $file ) == __FILE__ ) {
			// first, set the weekly backup job
			CronTab::add_daily_cron( 'habaribackup', 'habaribackup', 'Backup the database and email it to someone' );
			// and let's set up a log module, too
			EventLog::register_type( 'default', 'backup' );
			// and finally, create an ACL token
			ACL::create_token( 'HabariBackup', 'Backup the Habari database', 'habaribackup' );
		}
	}

	/**
	 * function action_plugin_deactivation
	**/
	public function action_plugin_deactivation( $file )
	{
		if ( Plugins::id_from_file($file) == Plugins::id_from_file(__FILE__) ) {
			// remove Cron, Dashboard module, and ACL token
			CronTab::delete_cronjob( 'habaribackup' );
			Modules::remove_by_name( 'Database Backup' );
			ACL::destroy_token( 'HabariBackup' );
		}
	}

	/**
	 * function filter_dash_modules
	**/
	public function filter_dash_modules( $modules )
	{
		if ( User::identify()->can( 'HabariBackup' ) ) {
			array_push( $modules, 'Database Backup' );
		}
		return $modules;
	}

	/**
	 * function filter_dash_module_database_backup
	**/
	public function filter_dash_module_database_backup( $module, $module_id, $theme )
	{
		if ( User::identify()->cannot( 'HabariBackup' ) ) {
			// this user doesn't have access to backups
			return $module;
		}
		$params= array(
			'type_id' => LogEntry::type( 'backup', 'default' ),
			'limit' => 5,
			);
		$logs= EventLog::get( $params );
		$content= '<ul class="items">';
		if ( count( $logs ) ) {
			foreach ( $logs as $log ) {
				$content.= '<li class="item clear"><span class="date pct15 minor">';
				$content.= $log->timestamp->format( 'M j' );
				$content.= '</span><span class="message pct85 minor">';
				if ( $log->severity == LogEntry::severity( 'err' ) ) {
					$content.= '<strong>';
				} else {
				}
				$content.= $log->message;
				if ( $log->severity == LogEntry::severity( 'err' ) ) {
					$content.= '<br>' . $log->data;
				}
				$content.= '</span></li>';
			}
		} else {
			$content.= '<li class="item clear"><span class="date pct15 minor">&nbsp; </span><span class="message pct85 minor">' . _t( "No backups performed yet." ) . '</span></li>';
		}
		$content.= '</ul>';
		$module['title']= 'Database Backup';
		$module['content']= $content;
		return $module;
	}

	/**
	 * function filter_plugin_config
	**/
	public function filter_plugin_config( $actions, $plugin_id )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			$actions[]= _t('Configure');
			if ( User::identify()->can( 'HabariBackup' ) ) {
				// only users with the proper permission
				// should be able to execute a backup
				$actions[]= _t( 'Execute' );
			}
		}
		return $actions;
	}

	/**
	 * function action_plugin_ui
	**/
	public function action_plugin_ui( $plugin_id, $action )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			switch ( $action ) {
				case _t( 'Configure' ) :
					$ui = new FormUI( strtolower( get_class( $this ) ) );
					$path= $ui->append( 'text', 'path', 'habaribackup__path', _t( 'Path to backup directory: ' ) . $this->backup_path );
					$recipient= $ui->append( 'text', 'user', 'habaribackup__recipient',  _t( 'Email address of backup recipient: ' ) );
					$ui->append( 'submit', 'save', _t( 'Save' ) );
					$ui->out();
					break;
				case _t( 'Execute' ) :
					$this->filter_habaribackup();
					Utils::redirect( URL::get( 'admin', 'page=plugins' ) );
					break;
			}
		}
	}

	/**
	 * function update_config
	**/
	public function update_config( $ui )
	{
		return true;
	}

	/**
	 * function gzip
	**/
	private function gzip() {
		return function_exists('gzopen');
	}

	/**
	 * function mail
	**/
	private function mail()
	{
		if ('' == $this->filename) { return FALSE; }
		$diskfile= $this->backup_path . $this->filename;
		if (! file_exists($diskfile)) return false;

		$randomish = md5(time());
		$boundary = "==BACKUP-BY-SKIPPY-$randomish";
		$fp = fopen($diskfile,"rb");
		$file = fread($fp,filesize($diskfile));
		$this->close($fp);
		$data = chunk_split(base64_encode($file));
		$headers = "MIME-Version: 1.0\n";
		$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\n";
		$headers .= 'From: ' . Options::get( 'title' ) . ' Backup <noreply@' . Site::get_url( 'hostname' ) . '>' . "\n";

		// Define a multipart boundary
		$message = "This is a multi-part message in MIME format.\n\n" .
			"--{$boundary}\n" .
			"Content-Type: text/plain; charset=\"iso-8859-1\"\n" .
			"Content-Transfer-Encoding: 7bit\n\n";
		$message.= sprintf(_t("Attached to this email is\n   %1s\n   Size:%2s kilobytes\n"), $this->filename, round(filesize($diskfile)/1024));
		$message.= "\n\n";

		// Add file attachment to the message
		 $message .= "--{$boundary}\n" .
		 "Content-Type: application/octet-stream;\n" .
		 	" name=\"{$this->filename}\"\n" .
			"Content-Disposition: attachment;\n" .
			" filename=\"{$this->filename}\"\n" .
			"Content-Transfer-Encoding: base64\n\n" .
			$data . "\n\n" .
			"--{$boundary}--\n";

		mail ( Options::get( 'habaribackup__recipient' ), Options::get( 'title' ) . ' ' . _t('Database Backup'), $message, $headers);

		return;
	}

	/**
	 * function filter_habaribackup
	**/
	public function filter_habaribackup()
	{
		$this->backup_path= Options::get( 'habaribackup__path' );
		// make sure we end in a trailing slash
		if ( '/' != substr( $this->backup_path, -1, 1 ) ) {
			$this->backup_path.= '/';
		}

		// this bit is only until we get a better method
		// to access the config variables
		list( $type, $string )= explode( ':', Config::get( 'db_connection' )->connection_string );
		if ( 'sqlite' == $type ) {
			if( basename( $string ) == $string && !file_exists( './' . $string ) ) {
				$string = Site::get_path( 'user', TRUE ) . $string;
			}
			$this->db_name= $string;
		} else {
			list( $host, $dbname )= explode( ';', $string );
			$this->db_host= substr( $host, 5 );
			$this->db_name= substr( $dbname, 7 );
		}
		$this->db_type= $type;
		$this->db_prefix= Config::get( 'db_connection' )->prefix;

		switch( $type ) {
			case 'sqlite':
				$this->backup_sqlite();
				break;
			case 'mysql':
				$this->backup_mysql();
				break;
		}

		if ( ! count( $this->backup_errors ) ) {
			$this->mail();
			EventLog::log( 'Emailed database backup to ' . Options::get( 'habaribackup__recipient' ), 'info', 'default', 'backup' );
		} else {
			EventLog::log( count( $this->backup_errors ) . ' errors backing up database.', 'err', 'default', 'backup', implode( '<br>', $this->backup_errors ) );
		}

		// delete the file
		unlink ( $this->backup_path . $this->filename );
	}

	/**
	 * funciton backup_sqlite
	**/
	function backup_sqlite()
	{
		$this->filename= basename( $this->db_name . time() . '.gz' );

		if ( ! is_writable( $this->backup_path ) ) {
			$this->backup_error( _t( 'The backup directory is not writeable!.' ) );
			return false;
		}

		// let's optimize the DB file before backing it up
		DB::query( 'VACUUM' );

		if ( $of= gzopen( $this->backup_path . $this->filename, 'wb9' ) ) {
			if ( $if= fopen( $this->db_name, 'rb' ) ) {
				while ( ! feof( $if ) ) {
					gzwrite( $of, fread( $if, 1024*512 ) );
				}
				fclose( $if );
			} else {
				$this->backup_error( _t( 'Error writing SQLite data to backup file.' ) );
			}
			gzclose( $of );
		} else {
			$this->backup_error( _t( 'Error opening backup file.' ) );
		}
	}

	/**
	 * function sql_addslashes($a_string = '', $is_like = FALSE)
	**/
	private function sql_addslashes($a_string = '', $is_like = FALSE)
	{
	        /*
	                Better addslashes for SQL queries.
	                Taken from phpMyAdmin.
	        */
	    if ($is_like) {
	        $a_string = str_replace('\\', '\\\\\\\\', $a_string);
	    } else {
	        $a_string = str_replace('\\', '\\\\', $a_string);
	    }
	    $a_string = str_replace('\'', '\\\'', $a_string);

	    return $a_string;
	}

	/**
	 * function backquote
	**/
	private function backquote($a_name)
	{
	        /*
	         Add backqouotes to tables and db-names in
	        SQL queries. Taken from phpMyAdmin.
	        */
	    if (!empty($a_name) && $a_name != '*') {
	        if (is_array($a_name)) {
	             $result = array();
	             reset($a_name);
	             while(list($key, $val) = each($a_name)) {
	                 $result[$key] = '`' . $val . '`';
	             }
	             return $result;
	        } else {
	            return '`' . $a_name . '`';
	        }
	    } else {
	        return $a_name;
	    }
	}

	/**
	 * function open
	**/
	private function open($filename = '', $mode = 'w') {
		if ('' == $filename) return false;
		if ($this->gzip()) {
			$fp = @gzopen($filename, $mode);
		} else {
			$fp = @fopen($filename, $mode);
		}
		return $fp;
	}

	/**
	 * function close
	**/
	private function close($fp) {
		if ($this->gzip()) {
			gzclose($fp);
		} else {
			fclose($fp);
		}
	}


	/**
	 * function stow
	**/
	private function stow($query_line) {
		if ($this->gzip()) {
			if(@gzwrite($this->fp, $query_line) === FALSE) {
				$this->backup_error(_t('There was an error writing a line to the backup script:'));
				backup_error('&nbsp;&nbsp;' . $query_line);
			}
		} else {
			if(@fwrite($this->fp, $query_line) === FALSE) {
				$this->backup_error(_t('There was an error writing a line to the backup script:'));
				$this->backup_error('&nbsp;&nbsp;' . $query_line);
			}
		}
	}

	/**
	 * function backup_error
	**/
	private function backup_error($err) {
		if(count($this->backup_errors) < 20) {
			$this->backup_errors[] = $err;
		} elseif(count($this->backup_errors) == 20) {
			$this->backup_errors[] = _t('Subsequent errors have been omitted from this log.');
		}
	}

	/**
	 * function backup_mysql_table
	**/
	private function backup_mysql_table($table, $segment = 'none') {
		$table_structure = DB::get_results( "DESCRIBE {$table}" );
		if (! $table_structure) {
			$this->backup_error(_t('Error getting table details') . ": $table");
			return FALSE;
		}

		if(($segment == 'none') || ($segment == 0)) {
			//
			// Add SQL statement to drop existing table
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# Delete any existing table " . $this->backquote($table) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			$this->stow("DROP TABLE IF EXISTS " . $this->backquote($table) . ";\n");

			//
			//Table structure
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# Table structure of table " . $this->backquote($table) . "\n");
			$this->stow("#\n");
			$this->stow("\n");

			$create_table = DB::get_results( "SHOW CREATE TABLE {$table}" );
			$schema= $create_table[0];
			$index= "Create Table";
			if (FALSE === $create_table) {
				$this->backup_error(sprintf(_t(" Error with SHOW CREATE TABLE for %s." ), $table));
				$this->stow("#\n# Error with SHOW CREATE TABLE for $table!\n#\n");
			}
			$this->stow($schema->$index . ' ;');

			if (FALSE === $table_structure) {
				$this->backup_error(sprintf(_t("Error getting table structure of %s"), $table));
				$this->stow("#\n# Error getting table structure of $table!\n#\n");
			}

			//
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow('# Data contents of table ' . $this->backquote($table) . "\n");
			$this->stow("#\n");
		}



		if(($segment == 'none') || ($segment >= 0)) {
			$ints = array();
			foreach ($table_structure as $struct) {
				if ( (0 === strpos($struct->Type, 'tinyint')) ||
					(0 === strpos(strtolower($struct->Type), 'smallint')) ||
					(0 === strpos(strtolower($struct->Type), 'mediumint')) ||
					(0 === strpos(strtolower($struct->Type), 'int')) ||
					(0 === strpos(strtolower($struct->Type), 'bigint')) ||
					(0 === strpos(strtolower($struct->Type), 'timestamp')) ) {
						$ints[strtolower($struct->Field)] = "1";
				}
			}

			// this bit is designed to load the schema files so we
			// can parse them to get the class properties for each
			// object, since the object's field names are protected
			/* Grab the queries from the RDBMS schema file */
			$file_path= HABARI_PATH . "/system/schema/{$this->db_type}/schema.sql";
			$schema_sql= trim(file_get_contents($file_path), "\r\n ");
			$schema_sql= str_replace('{$schema}',$this->db_type, $schema_sql);
			$schema_sql= str_replace('{$prefix}',$this->db_prefix, $schema_sql);
			$schema_sql= str_replace( array( "\r\n", "\r", ), array( "\n", "\n" ), $schema_sql );
			$schema_sql= preg_replace("/;\n([^\n])/", ";\n\n$1", $schema_sql);$schema_sql= preg_replace("/;\n([^\n])/", ";\n\n$1", $schema_sql);
			$schema_sql= preg_replace("/\n{3,}/","\n\n", $schema_sql);
			$queries= preg_split('/(\\r\\n|\\r|\\n)\\1/', $schema_sql);
			$tables= array();
			foreach ($queries as $query) {
				$t= trim(substr( $query, 13, ( strpos( $query, '(' ) -13 )) );
				$q= explode( "\n", $query );
				// get rid of the opening and closing lines
				unset( $q[0] );
				unset( $q[count($q)] );
				$temp= array();
				foreach( $q as $line ) {
					$line= trim( $line );
					// don't worry about DB keys
					if ( false === strpos( $line, 'KEY' ) ) {
						$temp[]= substr( $line, 0, strpos( $line, ' ' ) );
					}
				}
				$tables[$t]= $temp;
			}

			// Batch by $row_inc

			if($segment == 'none') {
				$row_start = 0;
				$row_inc = $this->rows_per_segment;
			} else {
				$row_start = $segment * $this->rows_per_segment;
				$row_inc = $this->rows_per_segment;
			}

			do {
				if ( !ini_get('safe_mode')) @set_time_limit(15*60);
				$table_data = DB::get_results("SELECT * FROM {$table} LIMIT {$row_start}, {$row_inc}" );

				$entries = 'INSERT INTO ' . $this->backquote($table) . ' VALUES (';
				//    \x08\\x09, not required`
				$search = array("\x00", "\x0a", "\x0d", "\x1a");
				$replace = array('\0', '\n', '\r', '\Z');
				if($table_data) {
					foreach ($table_data as $row) {
						$values = array();
						$t= $tables[$table];
						foreach ( $t as $key ) {
							if (isset( $ints[strtolower($row->$key)] ) ) {
								$values[] = $row->$key;
							} else {
								$values[] = "'" . str_replace($search, $replace, $this->sql_addslashes($row->$key)) . "'";
							}
						}
						$this->stow(" \n" . $entries . implode(', ', $values) . ') ;');
					}
					$row_start += $row_inc;
				}
			} while((count($table_data) > 0) and ($segment=='none'));
		}


		if(($segment == 'none') || ($segment < 0)) {
			// Create footer/closing comment in SQL-file
			$this->stow("\n");
			$this->stow("#\n");
			$this->stow("# End of data contents of table " . $this->backquote($table) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->stow("\n");
		}
	}

	/**
	 * function return_bytes
	**/
	private function return_bytes($val) {
	   $val = trim($val);
	   $last = strtolower($val{strlen($val)-1});
	   switch($last) {
	       // The 'G' modifier is available since PHP 5.1.0
	       case 'g':
	           $val *= 1024;
	       case 'm':
	           $val *= 1024;
	       case 'k':
	           $val *= 1024;
	   }

	   return $val;
	}

	/**
	 * function backup_mysql
	**/
	private function backup_mysql() {
		$this->filename = $this->db_name . '_' . $this->db_prefix . '_' . time() . ".sql";
			if ($this->gzip()) {
				$this->filename .= '.gz';
			}

		if (is_writable( $this->backup_path ) ) {
			$this->fp = $this->open( $this->backup_path . $this->filename);
			if(!$this->fp) {
				$this->backup_error(_t('Could not open the backup file for writing!' ));
				return false;
			}
		} else {
			$this->backup_error(_t('The backup directory is not writeable!' ) );
			return false;
		}

		//Begin new backup of MySql
		$this->stow("# Habari SQL database backup\n");
		$this->stow("#\n");
		$this->stow("# Generated: " . date("l j. F Y H:i T") . "\n");
		$this->stow("# Hostname: " . $this->db_host . "\n");
		$this->stow("# Database: " . $this->backquote( $this->db_name ) . "\n");
		$this->stow("# --------------------------------------------------------\n");

		foreach ( DB::list_tables() as $table ) {
			// Increase script execution time-limit to 15 min for every table.
			if ( !ini_get('safe_mode')) @set_time_limit(15*60);
			// Create the SQL statements
			$this->stow("# --------------------------------------------------------\n");
			$this->stow("# Table: " . $this->backquote($table) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->backup_mysql_table($table);
		}

		$this->close($this->fp);
	}
}
?>
