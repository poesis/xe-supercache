<?php

/**
 * Super Cache module: base class
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
class SuperCache extends ModuleObject
{
	/**
	 * Triggers to insert.
	 */
	protected static $_insert_triggers = array(
		array('moduleObject.proc', 'before', 'controller', 'triggerBeforeModuleObjectProc'),
		array('document.getDocumentList', 'before', 'controller', 'triggerBeforeGetDocumentList'),
		array('document.insertDocument', 'after', 'controller', 'triggerAfterInsertDocument'),
		array('document.updateDocument', 'after', 'controller', 'triggerAfterUpdateDocument'),
		array('document.deleteDocument', 'after', 'controller', 'triggerAfterDeleteDocument'),
		array('document.copyDocumentModule', 'after', 'controller', 'triggerAfterCopyDocumentModule'),
		array('document.moveDocumentModule', 'after', 'controller', 'triggerAfterMoveDocumentModule'),
		array('document.moveDocumentToTrash', 'after', 'controller', 'triggerAfterMoveDocumentToTrash'),
		array('document.restoreTrash', 'after', 'controller', 'triggerAfterRestoreDocumentFromTrash'),
	);
	
	/**
	 * Triggers to delete.
	 */
	protected static $_delete_triggers = array();
	
	/**
	 * Module configuration cache.
	 */
	protected static $_config_cache = null;
	
	/**
	 * CacheHandler cache.
	 */
	protected static $_cache_handler_cache = null;
	
	/**
	 * Get module configuration.
	 * 
	 * @return object
	 */
	public function getConfig()
	{
		if (self::$_config_cache === null)
		{
			$oModuleModel = getModel('module');
			self::$_config_cache = $oModuleModel->getModuleConfig($this->module) ?: new stdClass;
		}
		return self::$_config_cache;
	}
	
	/**
	 * Set module configuration.
	 * 
	 * @param object $config
	 * @return object
	 */
	public function setConfig($config)
	{
		$oModuleController = getController('module');
		$result = $oModuleController->insertModuleConfig($this->module, $config);
		if ($result->toBool())
		{
			self::$_config_cache = $config;
		}
		return $result;
	}
	
	/**
	 * Get information from the system cache.
	 * 
	 * @param string $key
	 * @param int $ttl
	 * @param string $group_key (optional)
	 * @return mixed
	 */
	public function getCache($key, $ttl = 86400, $group_key = null)
	{
		if (self::$_cache_handler_cache === null)
		{
			self::$_cache_handler_cache = CacheHandler::getInstance('object');
		}
		
		if (self::$_cache_handler_cache->isSupport())
		{
			$group_key = $group_key ?: $this->module;
			return self::$_cache_handler_cache->get(self::$_cache_handler_cache->getGroupKey($group_key, $key), $ttl);
		}
		else
		{
			return false;
		}
	}
	
	/**
	 * Save information in the system cache.
	 * 
	 * @param string $key
	 * @param mixed $value
	 * @param int $ttl
	 * @param string $group_key (optional)
	 * @return bool
	 */
	public function setCache($key, $value, $ttl = 86400, $group_key = null)
	{
		if (self::$_cache_handler_cache === null)
		{
			self::$_cache_handler_cache = CacheHandler::getInstance('object');
		}
		
		if (self::$_cache_handler_cache->isSupport())
		{
			$group_key = $group_key ?: $this->module;
			return self::$_cache_handler_cache->put(self::$_cache_handler_cache->getGroupKey($group_key, $key), $value, $ttl);
		}
		else
		{
			return false;
		}
	}
	
	/**
	 * Delete information from the system cache.
	 * 
	 * @param string $key
	 * @param string $group_key (optional)
	 * @return bool
	 */
	public function deleteCache($key, $group_key = null)
	{
		if (self::$_cache_handler_cache === null)
		{
			self::$_cache_handler_cache = CacheHandler::getInstance('object');
		}
		
		if (self::$_cache_handler_cache->isSupport())
		{
			$group_key = $group_key ?: $this->module;
			self::$_cache_handler_cache->delete(self::$_cache_handler_cache->getGroupKey($group_key, $key));
		}
		else
		{
			return false;
		}
	}
	
	/**
	 * Clear the system cache.
	 * 
	 * @param string $group_key (optional)
	 * @return bool
	 */
	public function clearCache($group_key = null)
	{
		if (self::$_cache_handler_cache === null)
		{
			self::$_cache_handler_cache = CacheHandler::getInstance('object');
		}
		
		if (self::$_cache_handler_cache->isSupport())
		{
			$group_key = $group_key ?: $this->module;
			return self::$_cache_handler_cache->invalidateGroupKey($group_key);
		}
		else
		{
			return false;
		}
	}
	
	/**
	 * Return an error message.
	 * 
	 * @param string $message
	 * @param $arg1, $arg2 ...
	 * @return object
	 */
	public function error($message /* $arg1, $arg2 ... */)
	{
		$args = func_get_args();
		if (count($args) > 1)
		{
			global $lang;
			array_shift($args);
			$message = vsprintf($lang->$message, $args);
		}
		return new Object(-1, $message);
	}
	
	/**
	 * Check triggers.
	 * 
	 * @return bool
	 */
	public function checkTriggers()
	{
		$oModuleModel = getModel('module');
		foreach (self::$_insert_triggers as $trigger)
		{
			if (!$oModuleModel->getTrigger($trigger[0], $this->module, $trigger[2], $trigger[3], $trigger[1]))
			{
				return true;
			}
		}
		foreach (self::$_delete_triggers as $trigger)
		{
			if ($oModuleModel->getTrigger($trigger[0], $this->module, $trigger[2], $trigger[3], $trigger[1]))
			{
				return true;
			}
		}
		return false;
	}
	
	/**
	 * Register triggers.
	 * 
	 * @return object
	 */
	public function registerTriggers()
	{
		$oModuleModel = getModel('module');
		$oModuleController = getController('module');
		foreach (self::$_insert_triggers as $trigger)
		{
			if (!$oModuleModel->getTrigger($trigger[0], $this->module, $trigger[2], $trigger[3], $trigger[1]))
			{
				$oModuleController->insertTrigger($trigger[0], $this->module, $trigger[2], $trigger[3], $trigger[1]);
			}
		}
		foreach (self::$_delete_triggers as $trigger)
		{
			if ($oModuleModel->getTrigger($trigger[0], $this->module, $trigger[2], $trigger[3], $trigger[1]))
			{
				$oModuleController->deleteTrigger($trigger[0], $this->module, $trigger[2], $trigger[3], $trigger[1]);
			}
		}
		return new Object(0, 'success_updated');
	}
	
	/**
	 * Install.
	 * 
	 * @return object
	 */
	public function moduleInstall()
	{
		return $this->registerTriggers();
	}
	
	/**
	 * Check update.
	 * 
	 * @return bool
	 */
	public function checkUpdate()
	{
		return $this->checkTriggers();
	}
	
	/**
	 * Update.
	 * 
	 * @return object
	 */
	public function moduleUpdate()
	{
		return $this->registerTriggers();
	}
	
	/**
	 * Recompile cache.
	 * 
	 * @return void
	 */
	public function recompileCache()
	{
		$this->clearCache();
	}
}
