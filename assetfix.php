<?php
/**
 * @package    Joomla.Cli
 *
 * @copyright  Copyright (C) 2013 AtomTech, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// We are a valid entry point.
const _JEXEC = 1;

// Load system defines
if (file_exists(dirname(__DIR__) . '/defines.php'))
{
	require_once dirname(__DIR__) . '/defines.php';
}

if (!defined('_JDEFINES'))
{
	define('JPATH_BASE', dirname(__DIR__));
	require_once JPATH_BASE . '/includes/defines.php';
}

// Get the framework.
require_once JPATH_LIBRARIES . '/import.legacy.php';

// Bootstrap the CMS libraries.
require_once JPATH_LIBRARIES . '/cms.php';

// Configure error reporting to maximum for CLI output.
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * This class checks some common situations that occur when the asset table is corrupted.
 *
 * @package  Joomla.Cli
 * @since    3.1
 */
class AssetFixCli extends JApplicationCli
{
	/**
	 * Overrides the parent doExecute method to run the web application.
	 *
	 * This method should include your custom code that runs the application.
	 *
	 * @since   3.1
	 */
	public function __construct()
	{
		// Import the dependencies.
		jimport('joomla.table.asset');

		// Call the parent __construct method so it bootstraps the application class.
		parent::__construct();

		// Add the logger.
		JLog::addLogger(array('text_file' => 'assetfix.php'));
	}

	/**
	 * Entry point for CLI script.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	protected function doExecute()
	{
		// Backup the tables to modify.
		$this->out('Creating Backup...');
		$tables = array('#__assets', '#__categories', '#__content');
		$this->doBackup($tables);

		// Cleanup the asset table.
		$this->out('Populate database with default assets table...');
		$this->populateDatabase('./sql/assets.sql');

		// Fixing the extensions assets.
		$this->out('Creating extensions assets...');
		$this->fixExtensionsAssets();

		// Fixing the categories assets.
		$this->out('Creating category assets...');
		$this->fixCategoryAssets();

		// Fixing the content assets.
		$this->out('Creating content assets...');
		$this->fixContentAssets();

		$this->out();

		// End message.
		$this->out('Finished assets updates!');
	}

	/**
	 * Backup tables.
	 *
	 * @param   array  $tables  Array with the name of tables to backup.
	 *
	 * @return  boolean
	 *
	 * @since   3.1
	 * @throws  Exception
	 */
	protected function doBackup($tables)
	{
		$count = count($tables);

		for ($i = 0; $i < $count; $i++)
		{
			// Rename the tables.
			$table  = $tables[$i];
			$rename = $tables[$i] . "_backup";

			$exists = $this->_existsTable($rename);

			if ($exists == 0)
			{
				$this->_copyTable($table, $rename);
			}
		}
	}

	/**
	 * Copy table to old site to new site.
	 *
	 * @param   string  $from  The old table structure.
	 * @param   string  $to    The new table structure.
	 *
	 * @return  boolean
	 *
	 * @since   3.1
	 * @throws  Exception
	 */
	protected function _copyTable($from, $to = null)
	{
		// Initialiase variables.
		$db = JFactory::getDbo();

		if (!$to)
		{
			$to = $from;
		}

		if ($this->_cloneTable($from, $to))
		{
			// Set the query.
			$db->setQuery('INSERT INTO ' . $to . ' SELECT * FROM ' . $from);

			try
			{
				$db->execute();
			}
			catch (Exception $e)
			{
				// Display the error.
				$this->out($e->getMessage(), true);

				// Close the app.
				$this->close($e->getCode());
			}
		}

		return true;
	}

	/**
	 * Clone old table structure from.
	 *
	 * @param   string  $from  The old table structure.
	 * @param   string  $to    The new table structure.
	 * @param   string  $drop  Drop the table.
	 *
	 * @return  boolean
	 *
	 * @since   3.1
	 * @throws  Exception
	 */
	protected function _cloneTable($from, $to = null, $drop = true)
	{
		// Initialiase variables.
		$db = JFactory::getDbo();

		if (!$to)
		{
			$to = $from;
		}

		if ($this->_existsTable($from) == 0)
		{
			return false;
		}
		else
		{
			// Set the query.
			$db->setQuery('CREATE TABLE ' . $to . ' LIKE ' . $from);

			try
			{
				$db->execute();
			}
			catch (Exception $e)
			{
				// Display the error.
				$this->out($e->getMessage(), true);

				// Close the app.
				$this->close($e->getCode());
			}
		}

		return true;
	}

	/**
	 * Verify if exists table.
	 *
	 * @param   string  $table  The table name.
	 *
	 * @return  boolean
	 *
	 * @since   3.1
	 * @throws  Exception
	 */
	function _existsTable($table)
	{
		// System configuration.
		$config   = JFactory::getConfig();
		$database = $config->get('db');

		// Initialiase variables.
		$db       = JFactory::getDbo();
		$query    = $db->getQuery(true);

		$table    = preg_replace('/#__/', $db->getPrefix(), $table);

		// Prepare query.
		$query->select('COUNT(*) AS count');
		$query->from('information_schema.tables');
		$query->where('table_schema = "' . $database . '"');
		$query->where('table_name = "' . $table . '"');

		// Inject the query and load the result.
		$db->setQuery($query);
		$result = $db->loadResult();

		// Check for a database error.
		if ($db->getErrorNum())
		{
			JError::raiseWarning(500, $db->getErrorMsg());
			return null;
		}

		return $result;
	}

	/**
	 * Populate database.
	 *
	 * @param   string  $sqlfile  The sql file.
	 *
	 * @return  boolean
	 *
	 * @since   3.1
	 * @throws  Exception
	 */
	function populateDatabase($sqlfile)
	{
		// Initialiase variables.
		$db     = JFactory::getDbo();
		$buffer = file_get_contents($sqlfile);

		if (!$buffer)
		{
			return -1;
		}

		$queries = $db->splitSql($buffer);

		foreach ($queries as $query)
		{
			$query = trim($query);

			if ($query != '' && $query {0} != '#')
			{
				// Set the query.
				$db->setQuery($query);

				try
				{
					$db->execute();
				}
				catch (Exception $e)
				{
					// Display the error.
					$this->out($e->getMessage(), true);

					// Close the app.
					$this->close($e->getCode());
				}
			}
		}

		return true;
	}

	/**
	 * Fix the assets of extensions table.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	protected function fixExtensionsAssets()
	{
		// Initialiase variables.
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		// Prepare query.
		$query->select('name, element');
		$query->from('#__extensions');
		$query->where('type = "component"');
		$query->where('protected = 0');
		$query->group('element');

		// Inject the query and load the extensions.
		$db->setQuery($query);
		$extensions = $db->loadObjectList();

		// Get an instance of the asset table.
		$asset = JTable::getInstance('Asset');

		foreach ($extensions as $extension)
		{
			$asset->id = 0;

			// Reset class properties.
			$asset->reset();

			// Load an asset by name.
			$asset->loadByName($extension->element);

			if ($asset->id == 0)
			{
				// Setting the name and title.
				$asset->name  = $extension->element;
				$asset->title = $extension->name;

				// Getting the original rules.
				$query = $db->getQuery(true);

				// Prepare query.
				$query->select('rules');
				$query->from('#__assets_backup');
				$query->where('name = "' . $extension->element . '"');

				// Inject the query and load the result.
				$db->setQuery($query);
				$rules = $db->loadResult();

				// Add the rules.
				$asset->rules = $rules !== null ? $rules : '{"core.admin":{"7":1},"core.manage":{"6":1},"core.create":[],"core.delete":[],"core.edit":[],"core.edit.state":[],"core.edit.own":[]}';

				// Setting the location of the new extension.
				$asset->setLocation(1, 'last-child');

				// Store the row.
				$asset->store();
			}
		}
	}

	/**
	 * Fix the assets of category table.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	protected function fixCategoryAssets()
	{
		// Initialiase variables.
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		// Prepare query.
		$query->select('*');
		$query->from('#__categories');
		$query->where('id != 1');

		// Inject the query and load the categories.
		$db->setQuery($query);
		$categories = $db->loadObjectList();

		foreach ($categories as $category)
		{
			// Fixing name of the extension.
			$category->extension = $category->extension == 'com_contact_details' ? 'com_contact' : $category->extension;

			// Get an instance of the asset table.
			$asset = JTable::getInstance('Asset');

			// Load an asset by name.
			$name  = $category->extension . '.category.' . (int) $category->id;
			$asset->loadByName($name);

			$asset->title = $category->title;
			$asset->name  = $name;

			// Getting the original rules.
			$query = $db->getQuery(true);

			// Prepare query.
			$query->select('rules');
			$query->from('#__assets_backup');
			$query->where('name = "' . $asset->name . '"');

			// Inject the query and load the result.
			$db->setQuery($query);
			$rules = $db->loadResult();

			if ($category->parent_id !== false)
			{
				// Setting the parent.
				$parent = 0;

				if ($category->parent_id == 1)
				{
					// Get an instance of the asset table.
					$parentAsset = JTable::getInstance('Asset');
					$parentAsset->loadByName($category->extension);

					$parent = $parentAsset->id;
				}
				elseif ($category->parent_id > 1)
				{
					// Getting the correct parent.
					$query = $db->getQuery(true);

					// Prepare query.
					$query->select('a.id');
					$query->from('#__categories AS c');
					$query->join('LEFT', '#__assets AS a ON a.title = c.title');
					$query->where('c.id = ' . (int) $category->parent_id);

					// Inject the query and load the result.
					$db->setQuery($query);
					$parent = $db->loadResult();
				}

				// Setting the location of the new category.
				$asset->setLocation($parent, 'last-child');
			}

			// Add the rules.
			$asset->rules = $rules !== null ? $rules : '{"core.admin":{"7":1},"core.manage":{"6":1},"core.create":[],"core.delete":[],"core.edit":[],"core.edit.state":[]}';

			// Store the row.
			$asset->store();

			// Fixing the category asset_id.
			$query = $db->getQuery(true);

			// Prepare query.
			$query->update($db->quoteName('#__categories'));
			$query->set($db->quoteName('asset_id') . ' = ' . (int) $asset->id);
			$query->where('id = ' . (int) $category->id);

			// Inject the query and load the result.
			$db->setQuery($query);
			$db->query();
		}
	}

	/**
	 * Fix the assets of content table.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	protected function fixContentAssets()
	{
		// Initialiase variables.
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		// Prepare query.
		$query->select('*');
		$query->from('#__content');

		// Inject the query and load the result.
		$db->setQuery($query);
		$contents = $db->loadObjectList();

		foreach ($contents as $article)
		{
			// Get an instance of the asset table.
			$table = JTable::getInstance('Asset');

			$table->title = $article->title;
			$table->name  = 'com_content.article.' . $article->id;

			// Getting the original rules.
			$query = $db->getQuery(true);

			// Prepare query.
			$query->select('rules');
			$query->from('#__assets_backup');
			$query->where('name = "' . $table->name . '"');

			// Inject the query and load the result.
			$db->setQuery($query);
			$rules = $db->loadResult();

			// Setting the parent.
			$parent = 0;

			if ($article->catid !== false)
			{
				if ($article->catid == 1)
				{
					// Get an instance of the asset table.
					$parentAsset = JTable::getInstance('Asset');
					$parentAsset->loadByName('com_content');

					$parent = $parentAsset->id;
				}
				elseif ($article->catid > 1)
				{
					// Getting the correct parent.
					$query = $db->getQuery(true);

					// Prepare query.
					$query->select('a.id');
					$query->from('#__categories AS c');
					$query->join('LEFT', '#__assets AS a ON a.title = c.title');
					$query->where('c.id = ' . (int) $article->catid);

					// Inject the query and load the result.
					$db->setQuery($query);
					$parent = $db->loadResult();
				}

				// Setting the location of the new category.
				$table->setLocation($parent, 'last-child');
			}

			// Add the rules.
			$table->rules = $rules !== null ? $rules : '{"core.delete":{"6":1},"core.edit":{"6":1,"4":1},"core.edit.state":{"6":1,"5":1}}';

			// Store the row.
			$table->store();

			// Fixing the category asset_id.
			$query = $db->getQuery(true);

			// Prepare query.
			$query->update($db->quoteName('#__content'));
			$query->set($db->quoteName('asset_id') . ' = ' . (int) $table->id);
			$query->where('id = ' . (int) $article->id);

			// Inject the query and load the result.
			$db->setQuery($query);
			$db->query();
		}
	}
}

// Instantiate the application object, passing the class name to JCli::getInstance
// and use chaining to execute the application.
JApplicationCli::getInstance('AssetFixCli')->execute();
