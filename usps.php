<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  User.joomla
 *
 * @copyright   Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;
jimport('usps.includes.routines');
use Joomla\Registry\Registry;
/**
 * Joomla User plugin
 *
 * @since  1.5
 */
class PlgUserUsps extends JPlugin
{
	/**
	 * Application object
	 *
	 * @var    JApplicationCms
	 * @since  3.2
	 */
	protected $app;
	/**
	 * Database object
	 *
	 * @var    JDatabaseDriver
	 * @since  3.2
	 */
	protected $db;
	/**
	 * This method should handle any login logic and report back to the subject
	 *
	 * @param   array  $user     Holds the user data
	 * @param   array  $options  Array holding options (remember, autoregister, group)
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.5
	 */
	public function onUserLogin($user, $options = array())
	{
		$params = $this->params;
		$debug = $params->get("debug");
		if ($debug) log_it("Entering ".__FILE__, __LINE__);
		$instance = $this->_getUser($user, $options);
		// If _getUser returned an error, then pass it back.
		if ($instance instanceof Exception)
		{
			return false;
		}
		// If the user is blocked, redirect with an error
		if ($instance->get('block') == 1)
		{
			$this->app->enqueueMessage(JText::_('JERROR_NOLOGIN_BLOCKED'), 'warning');
			return false;
		}
		// Authorise the user based on the group information
		if (!isset($options['group']))
		{
			$options['group'] = 'USERS';
		}
		// Check the user can login.
		$result = $instance->authorise($options['action']);
		if (!$result)
		{
			$this->app->enqueueMessage(JText::_('JERROR_LOGIN_DENIED'), 'warning');
			return false;
		}
		// Mark the user as logged in
		$instance->set('guest', 0);
		// Register the needed session variables
		$session = JFactory::getSession();
		$session->set('user', $instance);
		// Check to see the the session already exists.
		$this->app->checkSession();
		// Update the user related fields for the Joomla sessions table.
		$query = $this->db->getQuery(true)
			->update($this->db->quoteName('#__session'))
			->set($this->db->quoteName('guest') . ' = ' . $this->db->quote($instance->guest))
			->set($this->db->quoteName('username') . ' = ' . $this->db->quote($instance->username))
			->set($this->db->quoteName('userid') . ' = ' . (int) $instance->id)
			->where($this->db->quoteName('session_id') . ' = ' . $this->db->quote($session->getId()));
		try
		{
			$this->db->setQuery($query)->execute();
		}
		catch (RuntimeException $e)
		{
			return false;
		}
		// Hit the user last visit field
		$instance->setLastVisit();
		return true;
	}

	/**
	 * This method will return a user object
	 *
	 * If options['autoregister'] is true, if the user doesn't exist yet he will be created
	 *
	 * @param   array  $user     Holds the user data.
	 * @param   array  $options  Array holding options (remember, autoregister, group).
	 *
	 * @return  object  A JUser object
	 *
	 * @since   1.5
	 */
	protected function _getUser($user, $options = array())
	{
		$debug = $this->params->get("debug");
		$instance = JUser::getInstance();
		$username = trim($user['username']);
		$id = (int) JUserHelper::getUserId($username);
		if ($id == 0) $debug = true;
		if ($debug) log_it("The id field for $username is $id",__LINE__);
		$usps = ($user['type'] == 'usps');
		if ($id and ! $usps)
		{
			// Exit here for standard Joomla logins 
			$instance->load($id);
			return $instance;
		}
		if ($debug) write_log_array($user,"The user array in _getUser",__LINE__);
		if ($debug) write_log_array($user['groups'],"The groups array for $username",__LINE__);
		//  Only USPS Logins complete this User Setup
		if ($id)
		{
			$instance->load($id);
		}
		else
		{
			$instance->set('id', 0);
		}	
		// TODO : move this out of the plugin
		$config = JComponentHelper::getParams('com_users');
		// Hard coded default to match the default value from com_users.
		$defaultUserGroup = $config->get('new_usertype', 2);
		
		$instance->set('name', $user['fullname']);
		$instance->set('password_clear', $user['password_clear']);

		// Result should contain an email (check).
		
		$instance->set('email', $user['email']);
		$ary = array($defaultUserGroup);
		//USPS Authenticator has stored member's Assigned Group Names in array. 
		foreach($user['groups'] as $group){
		// Convert Group Name to index 
			$ary[] = $this->getGroupId($group);	
		}
		if ($debug) write_log_array($ary,"UserGroup Numbers for $username", __LINE__);
		$instance->set('groups', $ary);
		//$instance->set('groups', array($defaultUserGroup));
		// If autoregister is set let's register the user
		$autoregister = isset($options['autoregister']) ? $options['autoregister'] : $this->params->get('autoregister', 1);

		if ($autoregister)
		{
			if (!$instance->save())
			{
				log_it("Did not autoregister ".$username,__LINE__);
				JLog::add('Error in autoregistration for user ' . $user['username'] . '.', JLog::WARNING, 'error');
			}
		}
		else
		{
			// No existing user and autoregister off, this is a temporary user.
			$instance->set('tmp_user', true);
		}
		return $instance;
	}
	public function getGroupId($groupName)
	{
	   $db = JFactory::getDbo();
	   $select = "select id from #__usergroups where title='".$groupName."'";
	   $db->setQuery($select);
	   $db->query();
	   $data = $db->loadObject();

	   $groupId = $data->id;

	   if(empty($groupId))
	   		$groupId = 2;

	   return $groupId;
	}
}
