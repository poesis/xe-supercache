<?php

/**
 * Super Cache module: controller class
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
class SuperCacheController extends SuperCache
{
	/**
	 * Flag to cache the current request.
	 */
	protected $_defaultUrlChecked = false;
	protected $_cacheCurrentSearch = false;
	protected $_cacheCurrentRequest = null;
	protected $_cacheStartTimestamp = null;
	protected $_cacheHttpStatusCode = 200;
	
	/**
	 * Trigger called at moduleHandler.init (before)
	 */
	public function triggerBeforeModuleHandlerInit($obj)
	{
		// Get module configuration and request information.
		$config = $this->getConfig();
		$current_domain = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']);
		$request_method = Context::getRequestMethod();
		
		// Check the Accept: header for erroneous CSS and image requests.
		if ($request_method === 'GET' && isset($_SERVER['HTTP_REFERER']) && parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST) === $current_domain)
		{
			$accept_header = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
			if ($config->block_css_request && !strncmp($accept_header, 'text/css', 8))
			{
				return $this->terminateWithPlainText('/* block_css_request */');
			}
			if ($config->block_img_request && $accept_header && !strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE') && !strncmp($accept_header, 'image/', 6) && !preg_match('/\b(?:ht|x)ml\b/', $accept_header))
			{
				return $this->terminateWithPlainText('/* block_img_request */');
			}
		}
		
		// Check the default URL.
		if ($config->redirect_to_default_url && $request_method === 'GET')
		{
			$default_url = parse_url(Context::getDefaultUrl());
			if ($current_domain !== $default_url['host'])
			{
				$redirect_url = sprintf('%s://%s%s%s', $default_url['scheme'], $default_url['host'], $default_url['port'] ? (':' . $default_url['port']) : '', $_SERVER['REQUEST_URI']);
				return $this->terminateRedirectTo($redirect_url);
			}
			else
			{
				$this->_defaultUrlChecked = true;
			}
		}
		
		// Check the full page cache (if not delayed).
		if ($config->full_cache && !$config->full_cache_delay_trigger)
		{
			$this->checkFullPageCache($obj, $config);
		}
	}
	
	/**
	 * Trigger called at moduleHandler.init (after)
	 */
	public function triggerAfterModuleHandlerInit($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Check the full page cache (if delayed).
		if ($config->full_cache && $config->full_cache_delay_trigger)
		{
			$this->checkFullPageCache($obj, $config);
		}
		
		// Fill the page variable for paging cache.
		if ($config->paging_cache)
		{
			$this->fillPageVariable($obj, $config);
		}
		
		// Register autoloaders for documentItem and commentItem, because some versions of XE fail to autoload them.
		spl_autoload_register(function($class) {
			if (preg_match('/^(document|comment)item$/', strtolower($class), $matches))
			{
				include_once sprintf('%1$smodules/%2$s/%2$s.item.php', _XE_PATH_, $matches[1]);
			}
		});
	}
	
	/**
	 * Trigger called at document.getDocumentList (before)
	 */
	public function triggerBeforeGetDocumentList($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		$this->_cacheCurrentSearch = false;
		if (($config->paging_cache === false && !$config->search_cache) || ((!isset($obj->mid) || !$obj->mid) && (!isset($obj->module_srl) || !$obj->module_srl)))
		{
			return;
		}
		
		// If this is a POST search request (often caused by sketchbook skin), abort to prevent double searching.
		if ($config->disable_post_search && $_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest' && !$_POST['act'] && $obj->search_keyword)
		{
			return $this->terminateRequest('disable_post_search');
		}
		
		// Abort if an alternate list has already been set.
		if (isset($obj->use_alternate_output) && $obj->use_alternate_output)
		{
			return;
		}
		
		// Abort if there are search queries, but activate the search result cache.
		$search_target = isset($obj->search_target) ? $obj->search_target : null;
		$search_keyword = isset($obj->search_keyword) ? $obj->search_keyword : null;
		$exclude_module_srl = isset($obj->exclude_module_srl) ? $obj->exclude_module_srl : array();
		$start_date = isset($obj->start_date) ? $obj->start_date : null;
		$end_date = isset($obj->end_date) ? $obj->end_date : 0;
		$member_srl = isset($obj->member_srl) ? $obj->member_srl : null;
		if ($search_target || $search_keyword || $exclude_module_srl || $start_date || $end_date || $member_srl)
		{
			if ($config->search_cache && $search_target && Context::getRequestMethod() === 'GET' && Context::get('module') !== 'admin' && (!Context::get('act') || Context::get('act') === 'dispBoardContent'))
			{
				if (!$obj->module_srl || !isset($config->search_cache_exclude_modules[$obj->module_srl]))
				{
					$oTimelineModel = getModel('timeline');
					if ($oTimelineModel && $oTimelineModel->getTimelineInfo($obj->module_srl))
					{
						return;
					}
					
					$oModel = getModel('supercache');
					if ($cached_search_result = $oModel->getSearchResultCache($obj))
					{
						$obj->use_alternate_output = $cached_search_result;
					}
					else
					{
						$this->_cacheCurrentSearch = $obj;
					}
				}
			}
			return;
		}
		
		// Remove page number if a robot requests an old page.
		if ($obj->page > 100 && Context::get('oDocument') && isCrawler())
		{
			$obj->page = 1;
		}
		
		// Abort if this request is for any page greater than 1, unless offset queries are enabled.
		if ($obj->page > 1 && ($config->paging_cache_use_offset === false || !isset($config->paging_cache_use_offset) && version_compare(__XE_VERSION__, '1.8.42', '>=')))
		{
			return;
		}
		
		// Abort if there are any other unusual search options.
		$oDocumentModel = getModel('document');
		$oDocumentModel->_setSearchOption($obj, $args, $query_id, $use_division);
		if ($query_id !== 'document.getDocumentList' || $use_division || (is_array($args->module_srl) && count($args->module_srl) > 1))
		{
			return;
		}
		
		// Abort if the module is excluded by configuration.
		if (!$args->module_srl || isset($config->paging_cache_exclude_modules[$args->module_srl]))
		{
			return;
		}
		
		// Abort if the module/category has fewer documents than the threshold.
		$oModel = getModel('supercache');
		$document_count = $oModel->getDocumentCount($args->module_srl, $args->category_srl);
		if ($document_count < $config->paging_cache_threshold)
		{
			return;
		}
		
		// Add offset to simulate paging.
		if ($config->paging_cache_use_offset && $args->page > 1)
		{
			$args->list_offset = ($args->page - 1) * $args->list_count;
		}
		
		// Get documents and replace the output.
		$obj->use_alternate_output = $oModel->getDocumentList($args, $document_count);
	}
	
	/**
	 * Trigger called at document.getDocumentList (after)
	 */
	public function triggerAfterGetDocumentList($obj)
	{
		// Cache the current search.
		if ($this->_cacheCurrentSearch)
		{
			$oModel = getModel('supercache');
			$oModel->setSearchResultCache($this->_cacheCurrentSearch, $obj);
			$this->_cacheCurrentSearch = false;
		}
	}
	
	/**
	 * Trigger called at document.insertDocument (after)
	 */
	public function triggerAfterInsertDocument($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Update document count for pagination cache.
		$oModel = getModel('supercache');
		if ($config->paging_cache)
		{
			$oModel->updateDocumentCount($obj->module_srl, $obj->category_srl, 1);
		}
		
		// Refresh full page cache for the current module and/or index module.
		if ($config->full_cache && $config->full_cache_document_action)
		{
			if (isset($config->full_cache_document_action['refresh_module']) && $obj->module_srl)
			{
				$oModel->deleteFullPageCache($obj->module_srl, 0);
			}
			if (isset($config->full_cache_document_action['refresh_index']))
			{
				$index_module_srl = Context::get('site_module_info')->index_module_srl ?: 0;
				if ($index_module_srl != $obj->module_srl || !isset($config->full_cache_document_action['refresh_module']))
				{
					$oModel->deleteFullPageCache($index_module_srl, 0);
				}
			}
		}
		
		// Refresh search result cache for the current module.
		if ($config->search_cache && $config->search_cache_document_action)
		{
			if (isset($config->search_cache_document_action['refresh_module']) && $obj->module_srl)
			{
				$oModel->deleteSearchResultCache($obj->module_srl, false);
			}
		}
		
		// Refresh widgets referencing the current module.
		if ($config->widget_cache_autoinvalidate_document && $obj->module_srl)
		{
			$oModel->invalidateWidgetCache($obj->module_srl);
		}
	}
	
	/**
	 * Trigger called at document.updateDocument (after)
	 */
	public function triggerAfterUpdateDocument($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Get the old and new values of module_srl and category_srl.
		$original = getModel('document')->getDocument($obj->document_srl);
		$original_module_srl = intval($original->get('module_srl'));
		$original_category_srl = intval($original->get('category_srl'));
		$new_module_srl = intval($obj->module_srl) ?: $original_module_srl;
		$new_category_srl = intval($obj->category_srl) ?: $original_category_srl;
		
		// Update document count for pagination cache.
		$oModel = getModel('supercache');
		if ($config->paging_cache && ($original_module_srl !== $new_module_srl || $original_category_srl !== $new_category_srl))
		{
			$oModel->updateDocumentCount($new_module_srl, $new_category_srl, 1);
			if ($original_module_srl)
			{
				$oModel->updateDocumentCount($original_module_srl, $original_category_srl, -1);
			}
		}
		
		// Refresh full page cache for the current document, module, and/or index module.
		if ($config->full_cache && $config->full_cache_document_action)
		{
			if (isset($config->full_cache_document_action['refresh_document']))
			{
				$oModel->deleteFullPageCache(0, $obj->document_srl);
			}
			if (isset($config->full_cache_document_action['refresh_module']) && $obj->module_srl)
			{
				$oModel->deleteFullPageCache($original_module_srl, 0);
				if ($original_module_srl !== $new_module_srl)
				{
					$oModel->deleteFullPageCache($new_module_srl, 0);
				}
			}
			if (isset($config->full_cache_document_action['refresh_index']))
			{
				$index_module_srl = Context::get('site_module_info')->index_module_srl ?: 0;
				if (($index_module_srl != $original_module_srl && $index_module_srl != $new_module_srl) || !isset($config->full_cache_document_action['refresh_module']))
				{
					$oModel->deleteFullPageCache($index_module_srl, 0);
				}
			}
		}
		
		// Refresh search result cache for the current module.
		if ($config->search_cache && $config->search_cache_document_action)
		{
			if (isset($config->search_cache_document_action['refresh_module']))
			{
				if ($original_module_srl)
				{
					$oModel->deleteSearchResultCache($original_module_srl, false);
				}
				if ($new_module_srl && ($original_module_srl !== $new_module_srl))
				{
					$oModel->deleteSearchResultCache($new_module_srl, false);
				}
			}
		}
		
		// Refresh widgets referencing the current module.
		if ($config->widget_cache_autoinvalidate_document)
		{
			if ($original_module_srl)
			{
				$oModel->invalidateWidgetCache($original_module_srl);
			}
			if ($new_module_srl && ($original_module_srl !== $new_module_srl))
			{
				$oModel->invalidateWidgetCache($new_module_srl);
			}
		}
	}
	
	/**
	 * Trigger called at document.deleteDocument (after)
	 */
	public function triggerAfterDeleteDocument($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Update document count for pagination cache.
		$oModel = getModel('supercache');
		if ($config->paging_cache)
		{
			$oModel->updateDocumentCount($obj->module_srl, $obj->category_srl, -1);
		}
		
		// Refresh full page cache for the current document, module, and/or index module.
		if ($config->full_cache && $config->full_cache_document_action)
		{
			if (isset($config->full_cache_document_action['refresh_document']))
			{
				$oModel->deleteFullPageCache(0, $obj->document_srl);
			}
			if (isset($config->full_cache_document_action['refresh_module']) && $obj->module_srl)
			{
				$oModel->deleteFullPageCache($obj->module_srl, 0);
			}
			if (isset($config->full_cache_document_action['refresh_index']))
			{
				$index_module_srl = Context::get('site_module_info')->index_module_srl ?: 0;
				if ($index_module_srl != $obj->module_srl || !isset($config->full_cache_document_action['refresh_module']))
				{
					$oModel->deleteFullPageCache($index_module_srl, 0);
				}
			}
		}
		
		// Refresh search result cache for the current module.
		if ($config->search_cache && $config->search_cache_document_action)
		{
			if (isset($config->search_cache_document_action['refresh_module']) && $obj->module_srl)
			{
				$oModel->deleteSearchResultCache($obj->module_srl, false);
			}
		}
		
		// Refresh widgets referencing the current module.
		if ($config->widget_cache_autoinvalidate_document && $obj->module_srl)
		{
			$oModel->invalidateWidgetCache($obj->module_srl);
		}
	}
	
	/**
	 * Trigger called at document.copyDocumentModule (after)
	 */
	public function triggerAfterCopyDocumentModule($obj)
	{
		$this->triggerAfterUpdateDocument($obj);
	}
	
	/**
	 * Trigger called at document.moveDocumentModule (after)
	 */
	public function triggerAfterMoveDocumentModule($obj)
	{
		$this->triggerAfterUpdateDocument($obj);
	}
	
	/**
	 * Trigger called at document.moveDocumentToTrash (after)
	 */
	public function triggerAfterMoveDocumentToTrash($obj)
	{
		$this->triggerAfterDeleteDocument($obj);
	}
	
	/**
	 * Trigger called at document.restoreTrash (after)
	 */
	public function triggerAfterRestoreDocumentFromTrash($obj)
	{
		$this->triggerAfterUpdateDocument($obj);
	}
	
	/**
	 * Trigger called at comment.insertComment (after)
	 */
	public function triggerAfterInsertComment($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Refresh full page cache for the current document, module, and/or index module.
		if ($config->full_cache && $config->full_cache_comment_action)
		{
			$oModel = getModel('supercache');
			if (isset($config->full_cache_comment_action['refresh_document']) && $obj->document_srl)
			{
				$oModel->deleteFullPageCache(0, $obj->document_srl);
			}
			if (isset($config->full_cache_comment_action['refresh_module']) && $obj->module_srl)
			{
				$oModel->deleteFullPageCache($obj->module_srl, 0);
			}
			if (isset($config->full_cache_comment_action['refresh_index']))
			{
				$index_module_srl = Context::get('site_module_info')->index_module_srl ?: 0;
				if ($index_module_srl != $obj->module_srl || !isset($config->full_cache_comment_action['refresh_module']))
				{
					$oModel->deleteFullPageCache($index_module_srl, 0);
				}
			}
		}
		
		// Refresh search result cache for the current module.
		if ($config->search_cache && $config->search_cache_comment_action)
		{
			if (isset($config->search_cache_comment_action['refresh_module']) && $obj->module_srl)
			{
				$oModel = isset($oModel) ? $oModel : getModel('supercache');
				$oModel->deleteSearchResultCache($obj->module_srl, true);
			}
		}
		
		// Refresh widgets referencing the current module.
		if ($config->widget_cache_autoinvalidate_comment && $obj->module_srl)
		{
			$oModel = isset($oModel) ? $oModel : getModel('supercache');
			$oModel->invalidateWidgetCache($obj->module_srl);
		}
	}
	
	/**
	 * Trigger called at comment.updateComment (after)
	 */
	public function triggerAfterUpdateComment($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Refresh full page cache for the current document, module, and/or index module.
		if ($config->full_cache && $config->full_cache_comment_action)
		{
			$original = getModel('comment')->getComment($obj->comment_srl);
			$document_srl = $obj->document_srl ?: $original->document_srl;
			$module_srl = $obj->module_srl ?: $original->module_srl;
			
			$oModel = getModel('supercache');
			if (isset($config->full_cache_comment_action['refresh_document']) && $document_srl)
			{
				$oModel->deleteFullPageCache(0, $document_srl);
			}
			if (isset($config->full_cache_comment_action['refresh_module']) && $module_srl)
			{
				$oModel->deleteFullPageCache($module_srl, 0);
			}
			if (isset($config->full_cache_comment_action['refresh_index']))
			{
				$index_module_srl = Context::get('site_module_info')->index_module_srl ?: 0;
				if ($index_module_srl != $module_srl || !isset($config->full_cache_comment_action['refresh_module']))
				{
					$oModel->deleteFullPageCache($index_module_srl, 0);
				}
			}
		}
		
		// Refresh search result cache for the current module.
		if ($config->search_cache && $config->search_cache_comment_action)
		{
			if (isset($config->search_cache_comment_action['refresh_module']))
			{
				$module_srl = isset($module_srl) ? $module_srl : ($obj->module_srl ?: getModel('comment')->getComment($obj->comment_srl)->module_srl);
				if ($module_srl)
				{
					$oModel = isset($oModel) ? $oModel : getModel('supercache');
					$oModel->deleteSearchResultCache($module_srl, true);
				}
			}
		}
		
		// Refresh widgets referencing the current module.
		if ($config->widget_cache_autoinvalidate_comment)
		{
			$module_srl = isset($module_srl) ? $module_srl : ($obj->module_srl ?: getModel('comment')->getComment($obj->comment_srl)->module_srl);
			if ($module_srl)
			{
				$oModel = isset($oModel) ? $oModel : getModel('supercache');
				$oModel->invalidateWidgetCache($module_srl);
			}
		}
	}
	
	/**
	 * Trigger called at comment.deleteComment (after)
	 */
	public function triggerAfterDeleteComment($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		$module_srl = $obj->module_srl ?: (method_exists($obj, 'get') ? $obj->get('module_srl'): 0);
		
		// Refresh full page cache for the current document, module, and/or index module.
		if ($config->full_cache && $config->full_cache_comment_action)
		{
			$oModel = getModel('supercache');
			if (isset($config->full_cache_comment_action['refresh_document']) && $obj->document_srl)
			{
				$oModel->deleteFullPageCache(0, $obj->document_srl);
			}
			if (isset($config->full_cache_comment_action['refresh_module']) && $module_srl)
			{
				$oModel->deleteFullPageCache($module_srl, 0);
			}
			if (isset($config->full_cache_comment_action['refresh_index']))
			{
				$index_module_srl = Context::get('site_module_info')->index_module_srl ?: 0;
				if ($index_module_srl != $module_srl || !isset($config->full_cache_comment_action['refresh_module']))
				{
					$oModel->deleteFullPageCache($index_module_srl, 0);
				}
			}
		}
		
		// Refresh search result cache for the current module.
		if ($config->search_cache && $config->search_cache_comment_action)
		{
			if (isset($config->search_cache_comment_action['refresh_module']) && $module_srl)
			{
				$oModel = isset($oModel) ? $oModel : getModel('supercache');
				$oModel->deleteSearchResultCache($module_srl, true);
			}
		}
		
		// Refresh widgets referencing the current module.
		if ($config->widget_cache_autoinvalidate_comment && $module_srl)
		{
			$oModel = isset($oModel) ? $oModel : getModel('supercache');
			$oModel->invalidateWidgetCache($module_srl);
		}
	}
	
	/**
	 * Trigger called at moduleHandler.proc (after)
	 */
	public function triggerAfterModuleHandlerProc($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Store the output of the current request in the full-page cache.
		if ($this->_cacheCurrentRequest)
		{
			// Capture the HTTP status code of the current request.
			if (!is_object($obj) || !method_exists($obj, 'getHttpStatusCode'))
			{
				$this->_cacheHttpStatusCode = 404;
			}
			elseif (($status_code = $obj->getHttpStatusCode()) > 200)
			{
				$this->_cacheHttpStatusCode = intval($status_code);
			}
			
			// Do not store redirects.
			if ($this->_cacheHttpStatusCode >= 300 && $this->_cacheHttpStatusCode <= 399)
			{
				$this->_cacheCurrentRequest = false;
			}
			
			// Do not store error codes unless include_404 is true.
			if ($this->_cacheHttpStatusCode !== 200 && !$config->full_cache_include_404)
			{
				$this->_cacheCurrentRequest = false;
			}
			
			// Do not store page if XE_VALIDATOR_MESSAGE exists.
			if ($_SESSION['XE_VALIDATOR_MESSAGE'] || Context::get('XE_VALIDATOR_MESSAGE'))
			{
				$this->_cacheCurrentRequest = false;
			}
		}
		
		// Change gzip setting.
		if (is_object($obj) && $gzip = $config->use_gzip)
		{
			if ($gzip !== 'none' && $gzip !== 'default')
			{
				if (defined('RX_VERSION') && function_exists('config'))
				{
					config('view.use_gzip', true);
				}
				elseif (!defined('__OB_GZHANDLER_ENABLE__'))
				{
					define('__OB_GZHANDLER_ENABLE__', 1);
				}
			}
			
			switch ($gzip)
			{
				case 'except_robots':
					$obj->gzhandler_enable = isCrawler() ? false : true;
					break;
				case 'except_naver':
					$obj->gzhandler_enable = preg_match('/(yeti|naver)/i', $_SERVER['HTTP_USER_AGENT']) ? false : true;
					break;
				case 'none':
					$obj->gzhandler_enable = false;
					break;
				case 'always':
				default:
					break;
			}
		}
		
		// Remove Android Push App trigger that causes issue #9 when using the full-page cache.
		if ($this->_cacheCurrentRequest && $config->full_cache['pushapp'])
		{
			$GLOBALS['__triggers__']['display']['before'] = array_filter($GLOBALS['__triggers__']['display']['before'], function($entry) {
				return $entry->module !== 'androidpushapp';
			});
		}
		
		// Reorder itemshop trigger that causes the widget cache to not work at all.
		if ($config->widget_cache)
		{
			$pop_triggers = array();
			$GLOBALS['__triggers__']['display']['before'] = array_filter($GLOBALS['__triggers__']['display']['before'], function($entry) use(&$pop_triggers) {
				if ($entry->module === 'itemshop')
				{
					$pop_triggers[] = $entry;
					return false;
				}
				else
				{
					return true;
				}
			});
			foreach ($pop_triggers as $entry)
			{
				$GLOBALS['__triggers__']['display']['before'][] = $entry;
			}
		}
	}
	
	/**
	 * Trigger called at display (before)
	 */
	public function triggerBeforeDisplay(&$content)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Return if widgets should not be cached for this request.
		if (!$config->widget_cache || !$config->widget_cache_duration)
		{
			return;
		}
		if (Context::getResponseMethod() !== 'HTML' || preg_match('/^disp(?:Layout|Page)[A-Z]/', Context::get('act')))
		{
			return;
		}
		
		$module_info = Context::get('module_info');
		$module_srl = (isset($module_info) && isset($module_info->module_srl)) ? $module_info->module_srl : 0;
		if (isset($config->widget_cache_exclude_modules[$module_srl]))
		{
			return;
		}
		
		// Return if widget compilation is in "Javascript Mode" for any reason.
		$oWidgetController = getController('widget');
		if ($oWidgetController->javascript_mode || $oWidgetController->layout_javascript_mode)
		{
			return;
		}
		
		// Return if SimpleXML is not available.
		if (!function_exists('simplexml_load_string'))
		{
			return;
		}
		
		// Convert widgets into HTML output using Super Cache's own widget cache.
		$oWidgetController = getController('widget');
		$content = preg_replace_callback('/<div\b([^>]*?)\bwidget=([^>]*?)><div><div>((<img\b[^>]*?>)*)/i', array($oWidgetController, 'transWidgetBox'), $content);
		$content = preg_replace_callback('/<img\b(?:[^>]*?)\bwidget="(?:[^>]*?)>/i', array($this, 'procWidgetCache'), $content);
	}
	
	/**
	 * Trigger called at display (after)
	 */
	public function triggerAfterDisplay($content)
	{
		// Store the output of the current request in the full-page cache.
		if ($this->_cacheCurrentRequest)
		{
			// Collect extra data.
			if ($this->_cacheCurrentRequest[1] && ($oDocument = Context::get('oDocument')) && ($oDocument->document_srl == $this->_cacheCurrentRequest[1]))
			{
				$extra_data = array(
					'member_srl' => abs(intval($oDocument->get('member_srl'))),
					'view_count' => intval($oDocument->get('readed_count')),
				);
			}
			else
			{
				$extra_data = array();
			}
			
			// Call a trigger to pre-process the content.
			$trigger_output = ModuleHandler::triggerCall('supercache.storeFullPageCache', 'before', $content);
			if (is_object($trigger_output) && method_exists($trigger_output, 'toBool') && !$trigger_output->toBool())
			{
				return $trigger_output;
			}
			
			// Set the full-page cache.
			getModel('supercache')->setFullPageCache(
				$this->_cacheCurrentRequest[0],
				$this->_cacheCurrentRequest[1],
				$this->_cacheCurrentRequest[2],
				$this->_cacheCurrentRequest[3],
				$content,
				$extra_data,
				$this->_cacheHttpStatusCode,
				microtime(true) - $this->_cacheStartTimestamp
			);
		}
	}
	
	/**
	 * Check the full page cache for the current request,
	 * and terminate the request with a cached response if available.
	 * 
	 * @param object $obj
	 * @param object $config
	 * @return void
	 */
	public function checkFullPageCache($obj, $config)
	{
		// Abort if not an HTML GET request.
		if (Context::getRequestMethod() !== 'GET' || PHP_SAPI === 'cli')
		{
			return;
		}
		
		// Abort if logged in.
		$logged_info = Context::get('logged_info');
		if ($logged_info && $logged_info->member_srl)
		{
			return;
		}
		
		// Abort if XE_VALIDATOR_MESSAGE exists.
		if ($_SESSION['XE_VALIDATOR_MESSAGE'] || Context::get('XE_VALIDATOR_MESSAGE'))
		{
			return;
		}
		
		// Abort if the visitor has an excluded cookie.
		if ($config->full_cache_exclude_cookies)
		{
			foreach ($config->full_cache_exclude_cookies as $key => $value)
			{
				if (isset($_COOKIE[$key]) && strlen($_COOKIE[$key]))
				{
					return;
				}
			}
		}
		
		// Abort if the current user agent type is excluded.
		if (isCrawler() && !isset($config->full_cache['robot']))
		{
			return;
			
		}
		$device_type = $this->getDeviceType();
		if ($device_type === 'pc' && !isset($config->full_cache['pc']))
		{
			return;
		}
		if ($device_type !== 'pc' && !isset($config->full_cache['mobile']))
		{
			return;
		}
		if (strpos($device_type, 'push') !== false && !isset($config->full_cache['pushapp']))
		{
			return;
		}
		
		// Abort if the current domain does not match the default domain.
		$site_module_info = Context::get('site_module_info');
		if (!$this->_defaultUrlChecked)
		{
			$current_domain = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']);
			$default_domain = parse_url(Context::getDefaultUrl(), PHP_URL_HOST);
			if ($current_domain !== $default_domain && $current_domain !== parse_url($site_module_info->domain, PHP_URL_HOST))
			{
				return;
			}
		}
		
		// Collect more information about the current request.
		$mid = $obj->mid ?: Context::get('mid');
		$act = Context::get('act');
		$document_srl = Context::get('document_srl');
		$is_secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && $_SERVER['HTTPS'] !== 'off');
		
		// Abort if the current act is excluded.
		if (isset($config->full_cache_exclude_acts[$act]))
		{
			return;
		}
		
		// Abort if the current module is excluded.
		if (!$obj->mid && !$obj->module && !$obj->module_srl)
		{
			$module_srl = $site_module_info->module_srl;
		}
		elseif ($obj->module_srl)
		{
			$module_srl = $obj->module_srl;
		}
		elseif ($mid)
		{
			$module_info = getModel('module')->getModuleInfoByMid($mid, intval($site_module_info->site_srl) ?: 0);
			$module_srl = $module_info ? $module_info->module_srl : 0;
		}
		else
		{
			$module_srl = 0;
		}
		
		$module_srl = intval($module_srl);
		if (isset($config->full_cache_exclude_modules[$module_srl]))
		{
			return;
		}
		
		// Determine the page type.
		if ($act && $act !== 'dispBoardContent')
		{
			$page_type = 'other';
		}
		elseif ($document_srl)
		{
			$page_type = 'document';
		}
		elseif ($module_srl)
		{
			$page_type = 'module';
		}
		else
		{
			$page_type = 'url';
		}
		
		// Abort if the current page type is not selected for caching.
		if (!isset($config->full_cache_type[$page_type]) && !($page_type === 'url' && $config->full_cache_include_404))
		{
			return;
		}
		
		// Compile the user agent type.
		$user_agent_type = $device_type . ($is_secure ? '_secure' : '') . '_' . Context::getLangType();
		
		// Remove unnecessary request variables.
		$request_vars = Context::getRequestVars();
		if (is_object($request_vars))
		{
			$request_vars = get_object_vars($request_vars);
		}
		unset($request_vars['mid'], $request_vars['module'], $request_vars['module_srl'], $request_vars['document_srl'], $request_vars['m']);
		
		// Add separate cookies to request variables.
		if ($config->full_cache_separate_cookies)
		{
			foreach ($config->full_cache_separate_cookies as $key => $value)
			{
				if (isset($_COOKIE[$key]) && strlen($_COOKIE[$key]))
				{
					$request_vars['_COOKIE'][$key] = strval($_COOKIE[$key]);
				}
			}
		}
		
		// Add URL to request variables.
		if ($page_type === 'url')
		{
			$request_vars['_REQUEST_URI'] = $_SERVER['REQUEST_URI'];
			$page_type = 'other';
		}
		
		// Check the cache.
		$oModel = getModel('supercache');
		switch ($page_type)
		{
			case 'module':
				$this->_cacheCurrentRequest = array($module_srl, 0, $user_agent_type, $request_vars);
				$cache = $oModel->getFullPageCache($module_srl, 0, $user_agent_type, $request_vars);
				break;
			case 'document':
				$this->_cacheCurrentRequest = array($module_srl, $document_srl, $user_agent_type, $request_vars);
				$cache = $oModel->getFullPageCache($module_srl, $document_srl, $user_agent_type, $request_vars);
				break;
			case 'other':
				$this->_cacheCurrentRequest = array(0, 0, $user_agent_type, $request_vars);
				$cache = $oModel->getFullPageCache(0, 0, $user_agent_type, $request_vars);
				break;
		}
		
		// Replace the CSRF token.
		if ($cache && class_exists('Rhymix\\Framework\\Session', false) && method_exists('Rhymix\\Framework\\Session', 'getGenericToken'))
		{
			$cache['content'] = preg_replace_callback('#(<meta name="csrf-token" content=")[^"]*(" />)#', function($match) {
				return $match[1] . \Rhymix\Framework\Session::getGenericToken() . $match[2];
			}, $cache['content']);
		}
		
		// Call a trigger to post-process the content.
		if ($cache)
		{
			$trigger_output = ModuleHandler::triggerCall('supercache.fetchFullPageCache', 'after', $cache['content']);
			if (is_object($trigger_output) && method_exists($trigger_output, 'toBool') && !$trigger_output->toBool())
			{
				$cache = false;
			}
		}
		
		// If cached content is available, display it and exit.
		if ($cache)
		{
			// Find out how much time is left until this content expires.
			$expires = max(0, $cache['expires'] - time());
			
			// Print X-SuperCache and Cache-Control headers.
			header('X-SuperCache: HIT, dev=' . $device_type . ', type=' . $page_type . ', expires=' . $expires);
			if ($this->useCacheControlHeaders($config))
			{
				$this->printCacheControlHeaders($expires, $config->full_cache_stampede_protection ? 10 : 0);
			}
			
			// Increment the view count if required.
			if ($page_type === 'document' && $config->full_cache_incr_view_count && isset($cache['extra_data']['view_count']))
			{
				if (!isset($_SESSION['readed_document'][$document_srl]))
				{
					$oModel->updateDocumentViewCount($document_srl, $cache['extra_data']);
					$_SESSION['readed_document'][$document_srl] = true;
				}
			}
			
			// Print the content or a 304 response.
			if ($_SERVER['HTTP_IF_MODIFIED_SINCE'] && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $cache['cached'])
			{
				$this->printHttpStatusCodeHeader(304);
			}
			else
			{
				if ($cache['status'] && $cache['status'] !== 200)
				{
					$this->printHttpStatusCodeHeader($cache['status']);
				}
				header("Content-Type: text/html; charset=UTF-8");
				echo $cache['content'];
				echo "\n" . '<!--' . "\n";
				echo '    Serving ' . strlen($cache['content']) . ' bytes from full page cache' . "\n";
				echo '    Generated at ' . date('Y-m-d H:i:s P', $cache['cached']) . ' in ' . $cache['elapsed'] . "\n";
				echo '    Cache expires in ' . $expires . ' seconds' . "\n";
				echo '-->' . "\n";
			}
			Context::close();
			exit;
		}
		
		// Otherwise, prepare headers to cache the current request.
		header('X-SuperCache: MISS, dev=' . $device_type . ', type=' . $page_type . ', expires=' . $config->full_cache_duration);
		if ($this->useCacheControlHeaders($config))
		{
			$this->printCacheControlHeaders($config->full_cache_duration, $config->full_cache_stampede_protection ? 10 : 0);
		}
		$this->_cacheStartTimestamp = microtime(true);
	}
	
	/**
	 * Print HTTP status code header.
	 * 
	 * @param int $http_status_code
	 * @return void
	 */
	public function printHttpStatusCodeHeader($http_status_code)
	{
		switch ($http_status_code)
		{
			case 301: return header('HTTP/1.1 301 Moved Permanently');
			case 302: return header('HTTP/1.1 302 Found');
			case 304: return header('HTTP/1.1 304 Not Modified');
			case 400: return header('HTTP/1.1 400 Bad Request');
			case 403: return header('HTTP/1.1 403 Forbidden');
			case 404: return header('HTTP/1.1 404 Not Found');
			case 500: return header('HTTP/1.1 500 Internal Server Error');
			case 503: return header('HTTP/1.1 503 Service Unavailable');
			default: return function_exists('http_response_code') ? http_response_code($http_status_code) : header(sprintf('HTTP/1.1 %d Internal Server Error', $http_status_code));
		}
	}
	
	/**
	 * Print cache control headers.
	 * 
	 * @param int $expires
	 * @param int $scatter
	 * @return void
	 */
	public function printCacheControlHeaders($expires, $scatter)
	{
		$scatter = intval($expires * ($scatter / 100));
		$expires = intval($expires - mt_rand(0, $scatter));
		header('Cache-Control: max-age=' . $expires);
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT');
		header_remove('Pragma');
	}
	
	/**
	 * Check if cache control headers are enabled for the current request.
	 * 
	 * @param object $config
	 * @return bool
	 */
	public function useCacheControlHeaders($config)
	{
		if ($config->full_cache_use_headers)
		{
			return $config->full_cache_use_headers_proxy_too ? true : !(isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR']);
		}
		else
		{
			return false;
		}
	}
	
	/**
	 * If this is a document view request without a page number,
	 * fill in the page number to prevent the getDocumentListPage query.
	 * 
	 * @param object $obj
	 * @param object $config
	 * @return void
	 */
	public function fillPageVariable($obj, $config)
	{
		// Only work if there is a document_srl without a page variable and a suitable referer header.
		if ($obj->mid && Context::get('document_srl') && Context::get('act') && !Context::get('module') && !Context::get('page') && ($referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : false))
		{
			// Only guess the page number from the same module in the same site.
			if (strpos($referer, '//' . $_SERVER['HTTP_HOST'] . '/') === false)
			{
				return;
			}
			elseif (preg_match('/\/([a-zA-Z0-9_-]+)(?:\?|(?:\/\d+)?$)/', $referer, $matches) && $matches[1] === $obj->mid)
			{
				if (isCrawler())
				{
					Context::set('page', 1);
				}
				else
				{
					return;
				}
			}
			elseif (preg_match('/\bmid=([a-zA-Z0-9_-]+)\b/', $referer, $matches) && $matches[1] === $obj->mid)
			{
				if (preg_match('/\bpage=(\d+)\b/', $referer, $matches))
				{
					Context::set('page', $matches[1]);
				}
				else
				{
					Context::set('page', 1);
				}
			}
		}
	}
	
	/**
	 * Get the status of the full-page cache. This method returns true if the current page will be cached.
	 * 
	 * @return bool
	 */
	public function getFullPageCacheStatus()
	{
		return $this->_cacheCurrentRequest ? true : false;
	}
	
	/**
	 * Get the device type for full-page cache.
	 * 
	 * @return string
	 */
	public function getDeviceType()
	{
		// Prioritize XE official mobile device detection.
		if (method_exists('Mobile', 'isMobileEnabled'))
		{
			$is_mobile_enabled = Mobile::isMobileEnabled();
		}
		else
		{
			$is_mobile_enabled = (Context::getDBInfo()->use_mobile_view === 'Y');
		}
		
		// Check the session for cached data.
		if (!$is_mobile_enabled && isset($_SESSION['supercache_device_type']))
		{
			list($device_type, $checksum) = explode('|', $_SESSION['supercache_device_type']);
			if (strlen($checksum) && ($checksum === md5($_SERVER['HTTP_USER_AGENT'])))
			{
				return $device_type;
			}
		}
		
		// Detect mobile devices and Android Push App.
		$is_mobile1 = Mobile::isFromMobilePhone();
		$is_mobile2 = Mobile::isMobileCheckByAgent();
		$is_pushapp = (strpos($_SERVER['HTTP_USER_AGENT'], 'XEPUSH') !== false) ? true : false;
		$is_tablet = ($is_mobile1 || $is_mobile2 || $is_pushapp) ? Mobile::isMobilePadCheckByAgent() : false;
		
		// Compose the device type string: pc/mo/po/mc + push + tab
		$device_type = ($is_mobile1 ? 'm' : 'p') . ($is_mobile2 ? 'o' : 'c') . ($is_pushapp ? 'push' : '') . ($is_tablet ? 'tab' : '');
		
		// Save the device type in the session for future reference.
		if (!$is_mobile_enabled && (!method_exists('Context', 'getSessionStatus') || Context::getSessionStatus()))
		{
			$_SESSION['supercache_device_type'] = sprintf('%s|%s', $device_type, md5($_SERVER['HTTP_USER_AGENT']));
		}
		return $device_type;
	}
	
	/**
	 * Process widget cache.
	 * 
	 * @param string $match
	 * @return string
	 */
	public function procWidgetCache($match)
	{
		// Get widget attributes.
		$widget_attrs = new stdClass;
		$widget_xml = @simplexml_load_string($match[0]);
		if (!$widget_xml)
		{
			return $match[0];
		}
		foreach ($widget_xml->attributes() as $key => $value)
		{
			if (isset(self::$_skipWidgetAttrs[$key]))
			{
				$widget_attrs->{$key} = strval($value);
			}
			else
			{
				$widget_attrs->{$key} = preg_replace_callback('/%u([0-9a-f]+)/i', function($m) {
					return html_entity_decode('&#x' . $m[1] . ';');
				}, rawurldecode(strval($value)));
			}
		}
		
		// If this widget should not be cached, return.
		if (!$widget_attrs->widget || isset(self::$_skipWidgetNames[$widget_attrs->widget]))
		{
			return $match[0];
		}
		
		// Get module configuration.
		$config = $this->getConfig();
		if (!isset($config->widget_config[$widget_attrs->widget]) || !$config->widget_config[$widget_attrs->widget]['enabled'])
		{
			return $match[0];
		}
		
		// Get the list of target modules for this widget.
		$target_modules = array();
		if ($config->widget_cache_autoinvalidate_document || $config->widget_cache_autoinvalidate_comment)
		{
			if ($widget_attrs->module_srl)
			{
				$target_modules = array_map('intval', explode(',', $widget_attrs->module_srl));
			}
			if ($widget_attrs->module_srls)
			{
				$target_modules = array_unique($target_modules + array_map('intval', explode(',', $widget_attrs->module_srls)));
			}
		}
		
		// Generate the cache key and duration.
		$oModel = getModel('supercache');
		$cache_key = $oModel->getWidgetCacheKey($widget_attrs, $config->widget_config[$widget_attrs->widget]['group'] ? Context::get('logged_info') : false);
		$cache_duration = $config->widget_config[$widget_attrs->widget]['duration'] ?: $config->widget_cache_duration;
		if (isset($widget_attrs->widget_cache) && $widget_attrs->widget_cache && !($config->widget_config[$widget_attrs->widget]['force'] ?? false))
		{
			if (preg_match('/^([0-9\.]+)([smhd])$/i', $widget_attrs->widget_cache, $matches) && $matches[1] > 0)
			{
				$cache_duration = intval(floatval($matches[1]) * intval(strtr(strtolower($matches[2]), array('s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400))));
			}
			else
			{
				$cache_duration = intval(floatval($widget_attrs->widget_cache) * 60) ?: $cache_duration;
			}
		}
		
		// Randomize the cache duration for stampede protection.
		if ($config->widget_cache_stampede_protection !== false)
		{
			$cache_duration = intval(($cache_duration * 0.8) + ($cache_duration * (crc32($cache_key) % 256) / 1024));
		}
		
		// Check the cache for previously rendered widget content.
		$widget_content = $oModel->getWidgetCache($cache_key, $cache_duration);
		
		// If not found in cache, execute the widget.
		if ($widget_content === false)
		{
			$oWidgetController = getController('widget');
			$oWidget = $oWidgetController->getWidgetObject($widget_attrs->widget);
			if ($oWidget && method_exists($oWidget, 'proc'))
			{
				$widget_content = $oWidget->proc($widget_attrs);
				getController('module')->replaceDefinedLangCode($widget_content);
				$widget_content = trim($widget_content);
				if ($widget_content !== '')
				{
					$widget_content = str_replace('<!--#Meta:', '<!--Meta:', $widget_content);
					$oModel->setWidgetCache($cache_key, $cache_duration, $widget_content, $target_modules);
				}
			}
			else
			{
				return '';
			}
		}
		
		// Generate the widget HTML.
		$inner_styles = sprintf('padding: %dpx %dpx %dpx %dpx !important;', $widget_attrs->widget_padding_top ?? 0, $widget_attrs->widget_padding_right ?? 0, $widget_attrs->widget_padding_bottom ?? 0, $widget_attrs->widget_padding_left ?? 0);
		$widget_content = sprintf('<div style="*zoom:1;%s">%s</div>', $inner_styles, $widget_content);
		if (isset($widget_attrs->widgetstyle) && $widget_attrs->widgetstyle)
		{
			$oWidgetController = isset($oWidgetController) ? $oWidgetController : getController('widget');
			$widget_content = $oWidgetController->compileWidgetStyle($widget_attrs->widgetstyle, $widget_attrs->widget, $widget_content, $widget_attrs, false);
		}
		$outer_styles = preg_replace('/url\((.+)(\/?)none\)/is', '', $widget_attrs->style);
		$output = sprintf('<div class="xe-widget-wrapper %s" %sstyle="%s">%s</div>', $widget_attrs->css_class ?? '', $widget_attrs->id ?? '', $outer_styles, $widget_content);
		
		// Return the result.
		return $output;
	}
	
	/**
	 * Terminate the current request.
	 * 
	 * @param string $reason
	 * @param array $data (optional)
	 * @return exit
	 */
	public function terminateRequest($reason = '', $data = array())
	{
		$output = class_exists('BaseObject') ? new BaseObject : new Object;
		$output->add('supercache_terminated', $reason);
		foreach ($data as $key => $value)
		{
			$output->add($key, $value);
		}
		$oDisplayHandler = new DisplayHandler;
		$oDisplayHandler->printContent($output);
		Context::close();
		exit;
	}
	
	/**
	 * Terminate the current request by printing a plain text message.
	 * 
	 * @param string $message
	 * @return exit
	 */
	public function terminateWithPlainText($message = '')
	{
		header('Content-Type: text/plain; charset=UTF-8');
		echo $message;
		Context::close();
		exit;
	}
	
	/**
	 * Terminate the current request by redirecting to another URL.
	 * 
	 * @param string $url
	 * @param int $status (optional)
	 * @return exit
	 */
	public function terminateRedirectTo($url, $status = 301)
	{
		$this->printHttpStatusCodeHeader($status);
		header('Location: ' . $url);
		header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
		header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
		header_remove('Pragma');
		Context::close();
		exit;
	}
}
