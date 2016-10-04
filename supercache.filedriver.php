<?php

/**
 * Super Cache module: File cache driver
 * 
 * Copyright (c) 2016 Kijin Sung <kijin@kijinsung.com>
 * All rights reserved.
 * 
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License
 * for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
class SuperCacheFileDriver
{
	/**
	 * The cache directory.
	 */
	protected $_dir;
	
	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->_dir = _XE_PATH_ . 'files/supercache';
		if (!file_exists($this->_dir))
		{
			FileHandler::makeDir($this->_dir);
		}
	}
	
	/**
	 * Get the value of a key.
	 * 
	 * This method returns null if the key was not found.
	 * 
	 * @param string $key
	 * @param int $max_age
	 * @return mixed
	 */
	public function get($key, $max_age = 0)
	{
		// Get filename.
		$filename = $this->getFilename($key);
		if (!file_exists($filename))
		{
			return false;
		}
		
		// Get data from file.
		$data = (include $filename);
		
		// Don't accept expired or invalid data.
		if (!is_array($data) || count($data) < 2 || ($data[0] > 0 && $data[0] < time()))
		{
			@unlink($filename);
			return false;
		}
		else
		{
			return $data[1];
		}
	}
	
	/**
	 * Set the value to a key.
	 * 
	 * This method returns true on success and false on failure.
	 * $ttl is measured in seconds. If it is zero, the key should not expire.
	 * 
	 * @param string $key
	 * @param mixed $value
	 * @param int $ttl
	 * @return bool
	 */
	public function put($key, $value, $ttl = 0)
	{
		// Get filename.
		$filename = $this->getFilename($key);
		$filedir = dirname($filename);
		if (!file_exists($filedir))
		{
			FileHandler::makeDir($filedir);
		}
		
		// Encode the data.
		$data = array($ttl ? (time() + $ttl) : 0, $value);
		$data = '<' . '?php /* ' . $key . ' */' . PHP_EOL . 'return unserialize(' . var_export(serialize($data), true) . ');' . PHP_EOL;
		
		// Write to a temp file and atomically rename it over the target.
		$tmpfilename = $filename . '.tmp.' . microtime(true);
		$result = @file_put_contents($tmpfilename, $data, LOCK_EX);
		if (!$result)
		{
			return false;
		}
		$result = @rename($tmpfilename, $filename);
		if (!$result)
		{
			@unlink($filename);
			$result = @rename($tmpfilename, $filename);
		}
		
		// Invalidate opcache for the cache file.
		if (function_exists('opcache_invalidate'))
		{
			@opcache_invalidate($filename, true);
		}
		return $result ? true : false;
	}
	
	/**
	 * Delete a key.
	 * 
	 * This method returns true on success and false on failure.
	 * If the key does not exist, it should return false.
	 * 
	 * @param string $key
	 * @return bool
	 */
	public function delete($key)
	{
		return @unlink($this->getFilename($key));
	}
	
	/**
	 * Check if a key exists.
	 * 
	 * This method returns true on success and false on failure.
	 * 
	 * @param string $key
	 * @return bool
	 */
	public function isValid($key)
	{
		return $this->get($key) !== null;
	}
	
	/**
	 * Increase the value of a key by $amount.
	 * 
	 * If the key does not exist, this method assumes that the current value is zero.
	 * This method returns the new value.
	 * 
	 * @param string $key
	 * @param int $amount
	 * @return int
	 */
	public function incr($key, $amount)
	{
		$value = intval($this->get($key));
		$success = $this->put($key, $value + $amount, 0);
		return $success ? ($value + $amount) : false;
	}
	
	/**
	 * Decrease the value of a key by $amount.
	 * 
	 * If the key does not exist, this method assumes that the current value is zero.
	 * This method returns the new value.
	 * 
	 * @param string $key
	 * @param int $amount
	 * @return int
	 */
	public function decr($key, $amount)
	{
		return $this->incr($key, 0 - $amount);
	}
	
	/**
	 * Clear all keys from the cache.
	 * 
	 * This method returns true on success and false on failure.
	 * 
	 * @return bool
	 */
	public function truncate()
	{
		// Try to rename the cache directory first.
		$tempdirname = $this->_dir . '_' . time();
		$renamed = @rename($this->_dir, $tempdirname);
		if (!$renamed)
		{
			return false;
		}
		
		// Delete the old cache directory.
		return $this->deleteDirectory($tempdirname);
	}
	
	/**
	 * Get the filename to store a key.
	 * 
	 * @param string $key
	 * @return string
	 */
	public function getFilename($key)
	{
		$key = strtr($key, ':', '/');
		return $this->_dir . '/' . $key . '.php';
	}
	
	/**
	 * Get a group key.
	 * 
	 * This method simply returns the key.
	 * 
	 * @param string $group_key
	 * @param string $key
	 * @return string
	 */
	public function getGroupKey($group_key, $key)
	{
		return $key;
	}
	
	/**
	 * Invalidate a group key.
	 * 
	 * This method clears the cache.
	 * 
	 * @param string $group_key
	 * @return bool
	 */
	public function invalidateGroupKey($group_key)
	{
		return $this->truncate();
	}
	
	/**
	 * Invalidate a subgroup key.
	 * 
	 * This method deletes the directory associated with the subgroup key.
	 * 
	 * @param string $subgroup_key
	 * @param int $index
	 * @return bool
	 */
	public function invalidateSubgroupKey($subgroup_key, $index)
	{
		return $this->deleteDirectory($this->_dir . '/' . strtr($subgroup_key, ':', '/') . '/' . $index, false);
	}
	
	/**
	 * Delete a directory.
	 * 
	 * This method tries to use system commands to delete a directory quickly,
	 * but falls back to XE functions if this doesn't work.
	 * 
	 * @param string $dir
	 * @param bool $fallback
	 * @return bool
	 */
	public function deleteDirectory($dir, $fallback = true)
	{
		// Try to delete the renamed directory using system commands.
		if (function_exists('exec') && !preg_match('/(?<!_)exec/', ini_get('disable_functions')))
		{
			if (strncasecmp(\PHP_OS, 'win', 3) == 0)
			{
				@exec('rmdir /S /Q ' . escapeshellarg($dir));
			}
			else
			{
				@exec('rm -rf ' . escapeshellarg($dir));
			}
		}
		
		// Try to delete the renamed directory using XE functions.
		if (file_exists($dir))
		{
			if ($fallback)
			{
				FileHandler::removeDir($dir);
				clearstatcache($dir);
				return file_exists($dir);
			}
			else
			{
				return false;
			}
		}
		else
		{
			return true;
		}
	}
}
