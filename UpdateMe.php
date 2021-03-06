<?php

/**
 * Copyright (c) 2013 Yakub Kristianto
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
 * OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
 * SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 *
 * @package UpdateMe
 * @version 0.0.3
 * @author Yakub Kristianto
 * @copyright Yakub Kristianto 2013
 */

class UpdateMe
{
	const version = '0.0.3';
	private $PATCH_URL = '';
	private $LOCAL_BASE_DIR = '';
	private $LOCAL_BACKUP_DIR = '';
	private $default_directory_mode = '0755';
	private $version_filename = 'version.txt';
	private $remove_filename = 'remove-list.txt';

	/**
	 * @param string $config Check config.php.sample for more information about configuration
	 */
	public function __construct($config)
	{
		if (substr($config['PATCH_URL'], -1) != '/') {
			$config['PATCH_URL'] .= '/';
		}

		$this->PATCH_URL = $config['PATCH_URL'];
		$this->LOCAL_BASE_DIR = $config['LOCAL_BASE_DIR'];
		$this->LOCAL_BACKUP_DIR = $config['LOCAL_BACKUP_DIR'];
	}

	/**
	 * Check whether server has new version
	 * @return mixed Will return server version number if server version is bigger, or FALSE otherwise
	 */
	public function check_update()
	{

		$local_ver = $this->check_local_version();
		$server_ver = $this->check_server_version();
		if (version_compare($server_ver, $local_ver))
			return $server_ver;
		else
			return FALSE;
	}

	/**
	 * Get the latest version number of server files.
	 * @return string Version number of latest patch in server
	 */
	public function check_server_version()
	{
		// Get Patch version
		$ch = curl_init($this->PATCH_URL.$this->version_filename);

		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

		$str = curl_exec($ch);
		curl_close($ch);

		$server_vesion = $this->str_to_version_info($str);
		return $server_vesion;
	}

	/**
	 * Get the current version of local files
	 * @return string Version number of current files
	 */
	public function check_local_version()
	{
		// Get local version
		if (file_exists($path = $this->LOCAL_BACKUP_DIR.$this->version_filename)) {
			$str = file_get_contents($path);
			$local_version = $this->str_to_version_info($str);
		}
		else
			$local_version = FALSE;

		return $local_version;
	}

	/**
	 * Download patch file from server with given version number
	 * @param type $version the version number of file to download
	 */
	public function get_patch_file($version)
	{
		$ch = curl_init($this->PATCH_URL.$version.'.zip');
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_NOBODY, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		curl_close($ch);
		if ($status != 200 && $type != 'application/zip') {
			return FALSE;
		}

		// If server has the file, download it
		$ch = curl_init($this->PATCH_URL.$version.'.zip');
		$fp = fopen($this->LOCAL_BACKUP_DIR.$version.'.zip', "w");

		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_HEADER, 0);

		curl_exec($ch);
		curl_close($ch);
		fclose($fp);
		return TRUE;
	}

	/**
	 * Update local files using patch file with specific version number
	 * @param type $version
	 * @throws Exception Throw error if patch file with given version not found
	 */
	public function update($version = FALSE)
	{
		$files = $this->get_backed_up_files();

		if ($version === FALSE) {
			// Update to latest version
			$version = key($files);
		}

		if (!isset($files[$version])) {
			throw new Exception('Version '.$version.' not found!');
		}

		if (!file_exists($this->LOCAL_BACKUP_DIR.$files[$version])) {
			throw new Exception('File '.$files[$version].' not found!');
		}

		// Get list of file in zip & pre-check routine
		$zip = new ZipArchive();
		$zip->open($this->LOCAL_BACKUP_DIR.$files[$version]);
		$file_list = array();
		$remove_filename = FALSE;
		for( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = $stat['name'];
			$path = $this->LOCAL_BASE_DIR.$name;

			// TODO: Precheck here ----
			// It's directory
			if (substr($name, -1) == '/') {
				// Directory not exist? Create it
				if (!file_exists($path)) {
					mkdir($path, $this->default_directory_mode, TRUE);
				}
			}
			// It's file
			else {
				$file_list[] = $name;
				if (strtolower($name) == $this->remove_filename)
					$remove_filename = $name;
			}
		}

		$remove_list = FALSE;
		if ($remove_filename) {
			$remove_list = $zip->getFromName($remove_filename);
		}

		$this->create_rollback($version, $this->check_local_version(), $file_list, $remove_list);

		$this->remove_files($remove_list);

		// Extract the zip file
		$zip->extractTo($this->LOCAL_BASE_DIR);
		$zip->close();

		if ($remove_filename) unlink($this->LOCAL_BASE_DIR.$remove_filename);

		// Update local version info
		file_put_contents($this->LOCAL_BACKUP_DIR.$this->version_filename, $version);
	}

	/**
	 * Get list of files in local backup directory.
	 * @return array List of version and filename. Eg: array('1.0.0' => '1.0.0.Zip', '1.0.1' => '1.0.1.zip')
	 */
	public function get_backed_up_files()
	{
		$files = scandir($this->LOCAL_BACKUP_DIR);
		$list = array();
		foreach ($files as $file) {
			if (preg_match('/^(\d+\.\d+\.\d+)\.zip$/i', $file, $match) && is_file($this->LOCAL_BACKUP_DIR.$file)) {
				$list[$match[1]] = $file;
			}
		}
		arsort($list);
		return $list;
	}

	/**
	 * Generate zipped files that is going to be replaced/removed by Update command.
	 *
	 * @param type $version1
	 * @param type $version2
	 * @param type $file_list
	 */
	private function create_rollback($version1, $version2, $file_list, $remove_list)
	{
		if (!$version2) $version2 = '0.0.0';
		$i = 1;
		$zipname = "{$version1}.{$i}.{$version2}.zip";
		while (file_exists($this->LOCAL_BACKUP_DIR.$zipname)) {
			$i++;
			$zipname = "{$version1}.{$i}.{$version2}.zip";
		}

		$remove_files = array();
		if ($remove_list !== FALSE)
			$remove_files = explode("\n", $remove_list);

		$zip = new ZipArchive();
		$res = $zip->open($this->LOCAL_BACKUP_DIR.$zipname, ZipArchive::CREATE);
		if ($res === TRUE) {
			// Backup files that are going to be removed
			foreach ($remove_files as $k=>$file) {
				if (in_array($file, $file_list)) continue;
				if (file_exists($file) && is_file($file)) {
					$zip->addFile($this->LOCAL_BASE_DIR.$file, $file);
				}
			}

			// Backup files that are going to be updated
			foreach ($file_list as $file) {
				if (file_exists($file) && is_file($file)) {
					$zip->addFile($this->LOCAL_BASE_DIR.$file, $file);
				}
			}

			// Add all files that are going to be updated to remove list
			$zip->addFromString($this->remove_filename, implode("\n", $file_list));
			$zip->close();
		}
	}

	/**
	 * Rollback the latest update
	 */
	public function rollback()
	{
		// Find a rollback file.
		$current_version = $this->check_local_version();
		$files = scandir($this->LOCAL_BACKUP_DIR);
		$list = array();
		$regex = '/^('.str_replace('.', '\.', $current_version).'\.(\d+)\.(\d+\.\d+\.\d+))\.zip$/i';
		foreach ($files as $file) {
			if (preg_match($regex, $file, $match) && is_file($this->LOCAL_BACKUP_DIR.$file)) {
				$list[$match[2]] = array('file'=>$file, 'ver'=>$match[3]);
			}
		}
		krsort($list);
		$rollback_version = key($list);
		if (!$rollback_version) return FALSE;
		$zipfile = $list[$rollback_version]['file'];
		$previous_version = $list[$rollback_version]['ver'];

		$zip = new ZipArchive();
		$zip->open($this->LOCAL_BACKUP_DIR.$zipfile);
		$remove_list = $zip->getFromName($this->remove_filename);
		$this->remove_files($remove_list);

		// Extract the zip file
		$zip->extractTo($this->LOCAL_BASE_DIR);
		$zip->close();

		if ($remove_list !== FALSE) unlink($this->LOCAL_BASE_DIR.$this->remove_filename);

		// Update local version info
		file_put_contents($this->LOCAL_BACKUP_DIR.$this->version_filename, $previous_version);

		// Remove rollback file
		unlink($this->LOCAL_BACKUP_DIR.$zipfile);
	}

	public function check_dependencies($return_report = FALSE)
	{
		$complete = TRUE;
		$report = array(
			'extension' => array(
				'curl' => TRUE,
			),
			'class' => array(
				'ZipArchive' => TRUE,
			),
		);

		// check if extension loaded
		foreach ($report['extension'] as $ext => &$loaded) {
			if (!$loaded = extension_loaded($ext)) $complete = FALSE;
		}

		// check if class exist
		foreach ($report['class'] as $class => &$exist) {
			if (!$exist = class_exists($class)) $complete = FALSE;
		}

		return ($return_report) ? $report : $complete;
	}

	private function str_to_version_info($string)
	{
		$latest_version = FALSE;
		if (preg_match('/^\s*?(\d+\.\d+\.\d+)/i', $string, $match)) {
			$latest_version = $match[1];
		}
		return $latest_version;
	}

	private function remove_files($remove_list)
	{
		if ($remove_list === FALSE) return FALSE;

		$remove_files = explode("\n", $remove_list);
		foreach ($remove_files as $remove_file) {
			if (strpos($remove_file, '..') !== FALSE) continue;
			if (file_exists($this->LOCAL_BASE_DIR.$remove_file))
				unlink($this->LOCAL_BASE_DIR.$remove_file);
		}
	}

}