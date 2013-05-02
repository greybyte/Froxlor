<?php

/**
 * Froxlor API Groups-Module
 *
 * PHP version 5
 *
 * This file is part of the Froxlor project.
 * Copyright (c) 2010- the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @category   Modules
 * @package    API
 * @since      0.99.0
 */

/**
 * Class Groups
 *
 * @copyright  (c) the authors
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @category   Modules
 * @package    API
 * @since      0.99.0
 */
class Groups extends FroxlorModule implements iGroups {

	/**
	 * @see iGroups::listGroups()
	 *
	 * @return array the groups-bean-data as array
	 */
	public static function listGroups() {
		// get all groups
		$groups = Database::findAll('groups');
		// create array from beans
		$grp_array = Database::exportAll($groups, false);
		// clean sharedUsers so we don't output password/apikey
		$grp_array_clean = array();
		foreach ($grp_array as $group) {
			if (isset($group['sharedUsers'])) {
				$gusers = $group['sharedUsers'];
				$gusers_clean = array();
				foreach ($gusers as $user) {
					unset($user['apikey']);
					unset($user['password']);
					$gusers_clean[] = $user;
				}
				$group['sharedUsers'] = $gusers_clean;
			}
			$grp_array_clean[] = $group;
		}
		// return all the groups as array (api)
		return ApiResponse::createResponse(
				200,
				null,
				$grp_array_clean
		);
	}

	/**
	 * @see iGroups::statusGroup();
	 *
	 * @param string $name name of the group
	 *
	 * @throws GroupsException if the group does not exist
	 * @return array groups-bean array of the group
	 */
	public static function statusGroup() {

		$name = self::_correctGroup(self::getParam('name'));

		$group = Database::findOne('groups', 'groupname = :grp', array(':grp' => $name));

		// if null, no group was found
		if ($group === null) {
			throw new GroupsException(404, 'Group "'.$name.'" not found');
		}

		// return it as array
		return ApiResponse::createResponse(200, null, Database::exportAll($group));
	}

	/**
	 * @see iGroups::addGroup()
	 *
	 * @param string $name name of the group
	 *
	 * @throws GroupsException if the group already exists
	 * @return array groups-bean array of the new group
	 */
	public static function addGroup() {

		$name = self::_correctGroup(self::getParam('name'));

		// check if it already exists
		$grp_check = Froxlor::getApi()->apiCall('Groups.statusGroup', array('name' => $name));
		if ($grp_check->getResponseCode() == 200) {
			throw new GroupsException(406, 'The group "'.$name.'" does already exist');
		}

		// create new bean
		$grp = Database::dispense('groups');
		$grp->groupname = $name;
		$grpid = Database::store($grp);

		$grp = Database::load('groups', $grpid);
		// return the bean as array
		return ApiResponse::createResponse(200, null, Database::exportAll($grp));
	}

	/**
	 * @see iGroups::copyGroup()
	 *
	 * @param string $name name of the group
	 * @param string $copyfrom name of the group which is copied
	 *
	 * @throws GroupsException if the new group already exists or the other one doesn't
	 * @return array groups-bean array of the new group
	 */
	public static function copyGroup() {

		$name = self::_correctGroup(self::getParam('name'));
		$copyfrom = self::_correctGroup(self::getParam('copyfrom'));

		// check if the group which is to copy exists
		$grp_check = Froxlor::getApi()->apiCall('Groups.statusGroup', array('name' => $copyfrom));
		if ($grp_check->getResponseCode() != 200) {
			throw new GroupsException(404, 'The group you want to copy from ("'.$copyfrom.'") does not exist');
		}

		// add the new group (if possible
		$newgrp_check = Froxlor::getApi()->apiCall('Groups.addGroup', array('name' => $name));
		if ($newgrp_check->getResponseCode() != 200) {
			// that did not work out as expected
			return $newgrp_check->getResponse();
		}

		// get new group
		$newgrp = Database::load('groups', $newgrp_check->getData()[0]['id']);
		// get group to copy from
		$cpygrp = Database::load('groups', $grp_check->getData()[0]['id']);

		$newgrp->sharedPermissions = $cpygrp->sharedPermissions;
		$newgrp->sharedGroups = $cpygrp->sharedGroups;
		Database::store($newgrp);

		$grp = Database::load('groups', $newgrp->id);
		// return the bean as array
		return ApiResponse::createResponse(200, null, Database::exportAll($grp));
	}

	/**
	 * @see iGroups::nestGroup()
	 *
	 * @param string $name name of the group to add
	 * @param string $with_group name of the group to add to
	 *
	 * @throws GroupsException if the group already is subgroup of the given group
	 *                         or either of the groups does not exist
	 * @return array groups-bean array of the group given by name
	 */
	public static function nestGroup() {

		$name = self::_correctGroup(self::getParam('name'));
		$with_group = self::_correctGroup(self::getParam('with_group'));

		// check if the group which to nest into exists
		$grp_check = Froxlor::getApi()->apiCall('Groups.statusGroup', array('name' => $name));
		if ($grp_check->getResponseCode() != 200) {
			throw new GroupsException(404, 'The group you want to nest ("'.$name.'") does not exist');
		}

		// check if the group which is to be nested exists
		$wgrp_check = Froxlor::getApi()->apiCall('Groups.statusGroup', array('name' => $with_group));
		if ($wgrp_check->getResponseCode() != 200) {
			throw new GroupsException(404, 'The group you want to nest with ("'.$with_group.'") does not exist');
		}

		// get group to nest into
		$withgrp = Database::load('groups', $wgrp_check->getData()[0]['id']);
		// get group to be nested
		$grp = Database::load('groups', $grp_check->getData()[0]['id']);

		// check if already nested
		$groups = $withgrp->sharedGroups;
		foreach ($groups as $group) {
			if ($group->id == $grp->id) {
				throw new GroupsException(406, 'The group "'.$name.'" is already nested with group "'.$with_group.'"');
			}
		}

		// add to with_group's sharedGroups array
		$withgrp->sharedGroups[] = $grp;
		// save
		Database::store($withgrp);

		// load stored data
		$grp = Database::load('groups', $grp->id);
		// return the bean as array
		return ApiResponse::createResponse(200, null, Database::exportAll($grp));
	}

	/**
	 * @see iGroups::modifyGroup()
	 *
	 * @param int $id id of the group
	 * @param sting $name new group name
	 *
	 * @throws GroupsException if group does not exists
	 * @return array exported groups-bean of the updated group-entry
	 */
	public static function modifyGroup() {

	}

	/**
	 * @see iGroups::deleteGroup()
	 *
	 * @param string $name e.g. @customer
	 *
	 * @throws GroupsException if still in use or not found
	 * @return bool success = true
	 */
	public static function deleteGroup() {

		$name = self::_correctGroup(self::getParam('name'));

		// get group
		$grp_check = Froxlor::getApi()->apiCall('Groups.statusGroup', array('name' => $name));
		// check responsecode
		if ($grp_check->getResponseCode() != 200) {
			// return non-success message
			return $grp_check->getResponse();
		}
		// get id from response
		$grpid = $grp_check->getData()[0]['id'];
		// load bean
		$grp = Database::load('groups', $grpid);
		// check if in use (users)
		$users = $grp->sharedUsers;
		if (is_array($users) && count($users) > 0) {
			throw new GroupsException(403, 'The group "'.$name.'" cannot be deleted as it is in use');
		}
		// delete it
		Database::trash($grp);
		// return bean as array
		return ApiResponse::createResponse(200, null, array('success' => true));

	}

	/**
	 * checks for the prefixed @-sign and adds it if neccessary
	 *
	 * @param string $group group name to correct
	 *
	 * @return string corrected groupname
	 */
	private static function _correctGroup($group = null) {
		if (substr($group, 0, 1) != '@') {
			$group = '@'.$group;
		}
		return $group;
	}

	/**
	 * (non-PHPdoc)
	 * @see FroxlorModule::Core_moduleSetup()
	 */
	public function Core_moduleSetup() {
	}
}