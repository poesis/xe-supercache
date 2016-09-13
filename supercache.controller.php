<?php

/**
 * Super Cache module: controller class
 * 
 * Copyright (c) 2016 Kijin Sung <kijin@kijinsung.com>
 * All rights reserved.
 */
class SuperCacheController extends SuperCache
{
	/**
	 * The maximum supported page.
	 */
	protected $_maxSupportedPage = 1;
	
	/**
	 * Trigger called at moduleObject.proc (before)
	 */
	public function triggerBeforeModuleObjectProc($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		if (!$config->paging_cache || !($document_srl = Context::get('document_srl')) || Context::get('page'))
		{
			return;
		}
		
		// If this is a document view request without a page number, fill in the page number to prevent the getDocumentListPage query.
		if (preg_match('/^(?:board|bodex|beluxe)\.disp(?:board|bodex|beluxe)content/i', $obj->module_info->module . '.' . $obj->act))
		{
			// Use the referer to figure out which page the visitor came from.
			if ($referer = $_SERVER['HTTP_REFERER'])
			{
				// Only guess the page number from the same module in the same site.
				if (strpos($referer, '//' . $_SERVER['HTTP_HOST'] . '/') === false)
				{
					return;
				}
				elseif (preg_match('/\/([a-zA-Z0-9_-]+)(?:\?|(?:\/\d+)?$)/', $referer, $matches) && $matches[1] === $obj->mid)
				{
					Context::set('page', 1);
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
	}
	
	/**
	 * Trigger called at document.getDocumentList (before)
	 */
	public function triggerBeforeGetDocumentList($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		if (!$config->paging_cache || (!$obj->mid && !$obj->module_srl))
		{
			return;
		}
		
		// Abort if this request is for any page greater than 1.
		if ($obj->page > $this->_maxSupportedPage)
		{
			return;
		}
		
		// Abort if there are any search terms other than module_srl and category_srl.
		if ($obj->search_target || $obj->search_keyword || $obj->exclude_module_srl || $obj->start_date || $obj->end_date || $obj->member_srl)
		{
			return;
		}
		
		// Abort if there are any other unusual search options.
		$oDocumentModel = getModel('document');
		$oDocumentModel->_setSearchOption($obj, $args, $query_id, $use_division);
		if ($query_id !== 'document.getDocumentList' || $use_division)
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
		
		// Get documents and replace the output.
		$obj->use_alternate_output = $oModel->getDocumentList($args, $document_count);
	}
	
	/**
	 * Trigger called at document.insertDocument (after)
	 */
	public function triggerAfterInsertDocument($obj)
	{
		$oModel = getModel('supercache');
		$oModel->updateDocumentCount($obj->module_srl, $obj->category_srl, 1);
	}
	
	/**
	 * Trigger called at document.updateDocument (after)
	 */
	public function triggerAfterUpdateDocument($obj)
	{
		$original = getModel('document')->getDocument($obj->document_srl);
		$original_module_srl = intval($original->get('module_srl'));
		$original_category_srl = intval($original->get('category_srl'));
		$new_module_srl = intval($obj->module_srl) ?: $original_module_srl;
		$new_category_srl = intval($obj->category_srl) ?: $original_category_srl;
		
		if ($original_module_srl !== $new_module_srl || $original_category_srl !== $new_category_srl)
		{
			$oModel = getModel('supercache');
			$oModel->updateDocumentCount($new_module_srl, $new_category_srl, 1);
			if ($original_module_srl)
			{
				$oModel->updateDocumentCount($original_module_srl, $original_category_srl, -1);
			}
		}
	}
	
	/**
	 * Trigger called at document.deleteDocument (after)
	 */
	public function triggerAfterDeleteDocument($obj)
	{
		$oModel = getModel('supercache');
		$oModel->updateDocumentCount($obj->module_srl, $obj->category_srl, -1);
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
}
