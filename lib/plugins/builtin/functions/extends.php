<?php

/**
 * TOCOM
 *
 * This software is provided 'as-is', without any express or implied warranty.
 * In no event will the authors be held liable for any damages arising from the use of this software.
 *
 * This file is released under the LGPL
 * "GNU Lesser General Public License"
 * More information can be found here:
 * {@link http://www.gnu.org/copyleft/lesser.html}
 *
 * @author     Jordi Boggiano <j.boggiano@seld.be>
 * @copyright  Copyright (c) 2008, Jordi Boggiano
 * @license    http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 * @link       http://dwoo.org/
 * @version    0.3.4
 * @date       2008-04-09
 * @package    Dwoo
 */
class Dwoo_Plugin_extends extends Dwoo_Plugin implements Dwoo_ICompilable
{
	protected static $childSource;
	protected static $l;
	protected static $r;
	protected static $lastReplacement;

	public static function compile(Dwoo_Compiler $compiler, $file)
	{
		list($l, $r) = $compiler->getDelimiters();
		self::$l = preg_quote($l);
		self::$r = preg_quote($r);

		if($compiler->getLooseOpeningHandling())
		{
			self::$l .= '\s*';
			self::$r = '\s*'.self::$r;
		}
		$inheritanceTree = array(array('source'=>$compiler->getTemplateSource()));

		while(!empty($file))
		{
			if($file === '""' || $file === "''" || (substr($file, 0, 1) !== '"' && substr($file, 0, 1) !== '"'))
			{
				$compiler->triggerError('Extends : The file name must be a non-empty string', E_USER_ERROR);
				return;
			}

			if(preg_match('#^["\']([a-z]{2,}):(.*?)["\']$#i', $file, $m))
			{
				$resource = $m[1];
				$identifier = $m[2];
			}
			else
			{
				$resource = 'file';
				$identifier = substr($file, 1, -1);
			}

			if($resource === 'file' && $policy = $compiler->getSecurityPolicy())
			{
				while(true)
				{
					if(preg_match('{^([a-z]+?)://}i', $identifier))
						return $compiler->triggerError('The security policy prevents you to read files from external sources.', E_USER_ERROR);

					$identifier = realpath($identifier);
					$dirs = $policy->getAllowedDirectories();
					foreach($dirs as $dir=>$dummy)
					{
						if(strpos($identifier, $dir) === 0)
							break 2;
					}
					return $compiler->triggerError('The security policy prevents you to read <em>'.$identifier.'</em>', E_USER_ERROR);
				}
			}

			try {
				$parent = $compiler->getDwoo()->templateFactory($resource, $identifier);
			} catch (Dwoo_Exception $e) {
				$compiler->triggerError('Extends : Resource <em>'.$resource.'</em> was not added to Dwoo, can not include <em>'.$identifier.'</em>', E_USER_ERROR);
			}

			if($parent === null)
				$compiler->triggerError('Extends : Resource "'.$resource.':'.$identifier.'" was not found.', E_USER_ERROR);
			elseif($parent === false)
				$compiler->triggerError('Extends : Extending "'.$resource.':'.$identifier.'" was not allowed for an unknown reason.', E_USER_ERROR);

			$newParent = array('source'=>$parent->getSource(), 'resource'=>$resource, 'identifier'=>$identifier, 'uid'=>$parent->getUid());
			if(array_search($newParent, $inheritanceTree, true) !== false)
			{
				$compiler->triggerError('Extends : Recursive template inheritance detected', E_USER_ERROR);
			}

			$inheritanceTree[] = $newParent;

			if(preg_match('/^'.self::$l.'extends\s+(?:file=)?\s*(\S+?)'.self::$r.'/i', $parent->getSource(), $match))
				$file = (substr($match[1], 0, 1) !== '"' && substr($match[1], 0, 1) !== '"') ? '"'.str_replace('"', '\\"', $match[1]).'"' : $match[1];
			else
				$file = false;
		}

		while(true)
		{
			$parent = array_pop($inheritanceTree);
			$child = end($inheritanceTree);
			self::$childSource = $child['source'];
			self::$lastReplacement = count($inheritanceTree) === 1;
			if(!isset($newSource))
				$newSource = $parent['source'];
			$newSource = preg_replace_callback('/'.self::$l.'block (["\']?)(.+?)\1'.self::$r.'(?:\r?\n?)(.*?)(?:\r?\n?)'.self::$l.'\/block'.self::$r.'/is', array('Dwoo_Plugin_extends', 'replaceBlock'), $newSource);

			$newSource = $l.'do extendsCheck("'.$parent['resource'].':'.$parent['identifier'].'" "'.str_replace('"', '\\"', $parent['uid']).'")'.$r.$newSource;

			if(self::$lastReplacement)
				break;
		}

		$compiler->setTemplateSource($newSource);
		$compiler->setPointer(0);
	}

	protected static function replaceBlock(array $matches)
	{
		if(preg_match('/'.self::$l.'block (["\']?)'.preg_quote($matches[2]).'\1'.self::$r.'(?:\r?\n?)(.*?)(?:\r?\n?)'.self::$l.'\/block'.self::$r.'/is', self::$childSource, $override))
		{
			$l = stripslashes(self::$l);
			$r = stripslashes(self::$r);

			if(self::$lastReplacement)
				return preg_replace('/'.self::$l.'\$dwoo\.parent'.self::$r.'/is', $matches[3], $override[2]);
			else
				return $l.'block '.$matches[1].$matches[2].$matches[1].$r.preg_replace('/'.self::$l.'\$dwoo\.parent'.self::$r.'/is', $matches[3], $override[2]).$l.'/block'.$r;
		}
		else
		{
			if(self::$lastReplacement)
				return $matches[3];
			else
				return $matches[0];
		}
	}
}