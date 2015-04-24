<?php
/**
 * All plugin hooks are bundled here
 */

/**
 * Check if requested page is a static page
 *
 * @param string $hook         name of the hook
 * @param string $type         type of the hook
 * @param array  $return_value return value
 * @param array  $params       hook parameters
 *
 * @return array
 */
function static_route_hook_handler($hook, $type, $return_value, $params) {
	/**
	 * $return_value contains:
	 * $return_value['handler'] => requested handler
	 * $return_value['segments'] => url parts ($page)
	 */

	$identifier = $return_value['handler'];
	if (empty($identifier)) {
		return;
	}
	
	$handlers = elgg_get_config("pagehandler");
	if (!elgg_extract($identifier, $handlers)) {
		$options = array(
			"type" => "object",
			"subtype" => "static",
			"limit" => 1,
			"metadata_name_value_pairs" => array("friendly_title" => $identifier),
			"metadata_case_sensitive" => false
		);
		
		$ia = elgg_set_ignore_access(true);
		$entities = elgg_get_entities_from_metadata($options);
		elgg_set_ignore_access($ia);
		
		if (empty($entities)) {
			return;
		}
		
		$entity = $entities[0];
		if (has_access_to_entity($entity) || $entity->canEdit()) {
			
			$entity_guid = $entities[0]->getGUID();

			$return_value['segments'] = array("view", $entity_guid);
			$return_value['handler'] = "static";

			return $return_value;
		}
	}
}

/**
 * Returns a url for a static thumbnail page
 *
 * @param string $hook         name of the hook
 * @param string $type         type of the hook
 * @param array  $return_value return value
 * @param array  $params       hook parameters
 *
 * @return string
 */
function static_entity_icon_url_hook_handler($hook, $type, $return_value, $params) {
	
	if (empty($params) || !is_array($params)) {
		return $return_value;
	}
	
	$entity = elgg_extract("entity", $params);
	if (elgg_instanceof($entity, "object", "static")) {
		$size = elgg_extract("size", $params);
		
		if ($entity->icontime) {
			$return_value = "static/thumbnail/{$entity->getGUID()}/{$size}/{$entity->icontime}/{$size}.jpg";
		}
	}
	
	return $return_value;
}

/**
 * Allow moderators to edit static pages and their children
 *
 * @param string $hook         'permissions_check'
 * @param string $type         'object'
 * @param bool   $return_value can the user edit this entity
 * @param array  $params       supplied params
 *
 * @return bool
 */
function static_permissions_check_hook_handler($hook, $type, $return_value, $params) {
	
	if ($return_value) {
		// already have access, no need to add
		return $return_value;
	}
	
	if (empty($params) || !is_array($params)) {
		return $return_value;
	}
	
	$entity = elgg_extract("entity", $params);
	$user = elgg_extract("user", $params);
	
	if (empty($entity) || !elgg_instanceof($entity, "object", "static")) {
		return $return_value;
	}
	
	if (empty($user) || !elgg_instanceof($user, "user")) {
		return $return_value;
	}
	
	// check if the owner is a group
	$owner = $entity->getOwnerEntity();
	if (!empty($owner) && elgg_instanceof($owner, "group")) {
		// if you can edit the group, you can edit the static page
		if ($owner->canEdit($user->getGUID())) {
			return true;
		}
	}
	
	// check if the user is a moderator of this static page
	$ia = elgg_set_ignore_access(true);
	$moderators = $entity->moderators;
	
	if (!empty($moderators)) {
		if (!is_array($moderators)) {
			$moderators = array($moderators);
		}
		
		if (in_array($user->getGUID(), $moderators)) {
			elgg_set_ignore_access($ia);
			
			return true;
		}
	}
	
	elgg_set_ignore_access($ia);
	
	// if not moderator, check higher pages (if any)
	if ($entity->getContainerGUID() != $entity->site_guid) {
		$moderators = static_get_parent_moderators($entity, true);
		
		if (!empty($moderators)) {
			if (in_array($user->getGUID(), $moderators)) {
				return true;
			}
		}
	}
	
	return $return_value;
}

/**
 * Allow moderators to write static pages
 *
 * @param string $hook         'container_permissions_check'
 * @param string $type         'object'
 * @param bool   $return_value can the user write to this container
 * @param array  $params       supplied params
 *
 * @return bool
 */
function static_container_permissions_check_hook_handler($hook, $type, $return_value, $params) {
	
	if (!empty($type) && $type == "object") {
		
		if (!empty($params) && is_array($params)) {
			$container = elgg_extract("container", $params);
			$subtype = elgg_extract("subtype", $params);
			
			if ($subtype == "static" && elgg_instanceof($container, "site")) {
				$return_value = true;
			} elseif ($subtype == "static" && elgg_instanceof($container, "group") && !$container->canEdit()) {
				$return_value = false;
			}
		}
	}
	
	return $return_value;
}

/**
 * Orders the items in the static page menu
 *
 * @param string         $hook         'prepare'
 * @param string         $type         'menu:page'
 * @param ElggMenuItem[] $return_value the menu items
 * @param array          $params       supplied params
 *
 * @return ElggMenuItem[]
 */
function static_prepare_page_menu_hook_handler($hook, $type, $return_value, $params) {
	$static = elgg_extract("static", $return_value);
	
	if (is_array($static)) {
		$ordered_menu = static_order_menu($static);
		$has_children = false;
		
		foreach ($ordered_menu as $menu_item) {
			if ($menu_item->getChildren()) {
				$has_children = true;
				break;
			}
		}
		
		if ($has_children) {
			$return_value["static"] = $ordered_menu;
		} else {
			unset($return_value["static"]);
		}
	}
	
	return $return_value;
}

/**
 * Add menu items to the owner block menu
 *
 * @param string         $hook         'register'
 * @param string         $type         'menu:owner_block'
 * @param ElggMenuItem[] $return_value the menu items
 * @param array          $params       supplied params
 *
 * @return ElggMenuItem[]
 */
function static_register_owner_block_menu_hook_handler($hook, $type, $return_value, $params) {
	
	if (empty($params) || !is_array($params)) {
		return $return_value;
	}
	
	$owner = elgg_extract("entity", $params);
	if (empty($owner) || !elgg_instanceof($owner, "group")) {
		return $return_value;
	}
	
	if (static_group_enabled($owner)) {
		$return_value[] = ElggMenuItem::factory(array(
			"name" => "static",
			"text" => elgg_echo("static:groups:owner_block"),
			"href" => "static/group/" . $owner->getGUID()
		));
	}
	
	return $return_value;
}

/**
 *
 * @param string $hook         'entity_types'
 * @param string $type         'content_subscriptions'
 * @param array  $return_value the current supported entity types
 * @param array  $params       supplied params
 *
 * @return array
 */
function static_content_subscriptions_entity_types_handler($hook, $type, $return_value, $params) {
	
	if (!is_array($return_value)) {
		$return_value = array();
	}
	
	if (!isset($return_value["object"])) {
		$return_value["object"] = array();
	}
	
	$return_value["object"][] = "static";
	
	return $return_value;
}

/**
 * Add menu items to the filter menu
 *
 * @param string         $hook         'register'
 * @param string         $type         'menu:filter'
 * @param ElggMenuItem[] $return_value the menu items
 * @param array          $params       supplied params
 *
 * @return ElggMenuItem[]
 */
function static_register_filter_menu_hook_handler($hook, $type, $return_value, $params) {
	
	if (!static_out_of_date_enabled()) {
		return $return_value;
	}
	
	if (!elgg_in_context("static")) {
		return $return_value;
	}
	
	$page_owner = elgg_get_page_owner_entity();
	if (elgg_instanceof($page_owner, "group")) {
		$return_value[] = ElggMenuItem::factory(array(
			"name" => "all",
			"text" => elgg_echo("all"),
			"href" => "static/group/" . $page_owner->getGUID(),
			"is_trusted" => true,
			"priority" => 100
		));
		
		if ($page_owner->canEdit()) {
			$return_value[] = ElggMenuItem::factory(array(
				"name" => "out_of_date_group",
				"text" => elgg_echo("static:menu:filter:out_of_date:group"),
				"href" => "static/group/" . $page_owner->getGUID() . "/out_of_date",
				"is_trusted" => true,
				"priority" => 250
			));
		}
	} else {
		$return_value[] = ElggMenuItem::factory(array(
			"name" => "all",
			"text" => elgg_echo("all"),
			"href" => "static/all",
			"is_trusted" => true,
			"priority" => 100
		));
	}
	
	if (elgg_is_admin_logged_in()) {
		$return_value[] = ElggMenuItem::factory(array(
			"name" => "out_of_date",
			"text" => elgg_echo("static:menu:filter:out_of_date"),
			"href" => "static/out_of_date",
			"is_trusted" => true,
			"priority" => 200
		));
	}
	
	$user = elgg_get_logged_in_user_entity();
	if (!empty($user)) {
		$return_value[] = ElggMenuItem::factory(array(
			"name" => "out_of_date_mine",
			"text" => elgg_echo("static:menu:filter:out_of_date:mine"),
			"href" => "static/out_of_date/" . $user->username,
			"is_trusted" => true,
			"priority" => 300
		));
	}
	
	return $return_value;
}

/**
 * Add menu items to the filter menu
 *
 * @param string $hook         'cron'
 * @param string $type         'daily'
 * @param string $return_value optional output
 * @param array  $params       supplied params
 *
 * @return void
 */
function static_daily_cron_handler($hook, $type, $return_value, $params) {
	
	if (empty($params) || !is_array($params)) {
		return;
	}
	
	if (!static_out_of_date_enabled()) {
		return;
	}
	
	$time = elgg_extract("time", $params, time());
	$days = (int) elgg_get_plugin_setting("out_of_date_days", "static");
	$site = elgg_get_site_entity();
	
	$options = array(
		"type" => "object",
		"subtype" => "static",
		"limit" => false,
		"modified_time_upper" => $time - ($days * 24 * 60 * 60),
		"modified_time_lower" => $time - (($days + 1) * 24 * 60 * 60),
		"order_by" => "e.time_updated DESC"
	);
	
	// ignore access
	$ia = elgg_set_ignore_access(true);
	
	$batch = new ElggBatch("elgg_get_entities", $options);
	$users = array();
	foreach ($batch as $entity) {
		$last_editors = $entity->getAnnotations(array(
			"annotation_name" => "static_revision",
			"limit" => 1,
			"order_by" => "n_table.time_created DESC"
		));
		
		if (empty($last_editors)) {
			continue;
		}
		
		$users[$last_editors[0]->getOwnerGUID()] = $last_editors[0]->getOwnerEntity();
	}

	// restore access
	elgg_set_ignore_access($ia);
	
	if (empty($users)) {
		return;
	}
	
	foreach ($users as $user) {
		$subject = elgg_echo("static:out_of_date:notification:subject");
		$message = elgg_echo("static:out_of_date:notification:message", array(
			$user->name,
			elgg_normalize_url("static/out_of_date/" . $user->username)
		));
		
		notify_user($user->getGUID(), $site->getGUID(), $subject, $message, array(), "email");
	}
}

/**
 * Add some menu items
 *
 * @param string         $hook         the name of the hook
 * @param string         $type         the type of the hook
 * @param ElggMenuItem[] $return_value current menu items
 * @param array          $params       supplied params
 *
 * @return ElggMenuItem[]
 */
function static_register_entity_menu_hook_handler($hook, $type, $return_value, $params) {
	
	if (empty($params) || !is_array($params)) {
		return $return_value;
	}
	
	$entity = elgg_extract("entity", $params);
	if (empty($entity) || !elgg_instanceof($entity, "object", "static")) {
		return $return_value;
	}
	
	// remove menu items
	$remove_menu_items = array(
		'edit'
	);
	foreach ($return_value as $index => $menu_item) {
		if (in_array($menu_item->getName(), $remove_menu_items)) {
			unset($return_value[$index]);
		}
	}
	
	// add comment link
	if (!$entity->canComment()) {
		return $return_value;
	}
	
	$return_value[] = ElggMenuItem::factory(array(
		"name" => "comments",
		"text" => elgg_view_icon("speech-bubble"),
		"href" => $entity->getURL() . "#static-comments-" . $entity->getGUID(),
		"title" => elgg_echo("comment:this"),
		"priority" => 300
	));
	
	return $return_value;
}

/**
 * check if commenting is allowed in the page
 *
 * @param string $hook         the name of the hook
 * @param string $type         the type of the hook
 * @param bool   $return_value current menu items
 * @param array  $params       supplied params
 *
 * @return bool
 */
function static_permissions_comment_hook_handler($hook, $type, $return_value, $params) {
	
	if (empty($params) || !is_array($params)) {
		return $return_value;
	}
	
	$entity = elgg_extract("entity", $params);
	if (empty($entity) || !elgg_instanceof($entity, "object", "static")) {
		return $return_value;
	}
	
	$return_value = false;
	if ($entity->enable_comments == "yes") {
		$return_value = true;
	}
	
	return $return_value;
}

/**
 * make a menu structure
 *
 * @param string         $hook
 * @param string         $type
 * @param ElggMenuItem[] $return_value
 * @param array          $params
 *
 * @return ElggMenuItem[]
 */
function static_register_static_group_widget_hook_handler($hook, $type, $return_value, $params) {
	
	if (empty($params) || !is_array($params)) {
		return $return_value;
	}
	
	$entity = elgg_extract('entity', $params);
	if (empty($entity) || !elgg_instanceof($entity)) {
		return $return_value;
	}
	
	$children = static_get_ordered_children($entity);
	if (empty($children)) {
		return $return_value;
	}
	
	$show_children = (bool) elgg_extract('show_children', $params, false);
	$depth = (int) elgg_extract('depth', $params, 0);
	foreach ($children as $order => $child) {
		
		$item = ElggMenuItem::factory(array(
			'name' => $child->getGUID(),
			'text' => "<span>{$child->title}</span>",
			'href' => $child->getURL(),
			'priority' => $order,
		));
		
		if (!empty($depth)) {
			$item->setParentName($entity->getGUID());
		}
		
		$return_value[] = $item;
		
		if ($show_children) {
			$new_params = $params;
			$new_params['entity'] = $child;
			$new_params['depth'] = $depth + 1;
			
			$items = static_register_static_group_widget_hook_handler($hook, $type, array(), $new_params);
			if (!empty($items)) {
				$return_value = array_merge($return_value, $items);
			}
		}
	}
	
	return $return_value;
}
