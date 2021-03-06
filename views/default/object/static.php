<?php

$entity = elgg_extract('entity', $vars);

if (elgg_extract('full_view', $vars)) {
	
	$metadata = elgg_view_menu('entity', array(
		'entity' => $vars['entity'],
		'handler' => 'static',
		'sort_by' => 'priority',
		'class' => 'elgg-menu-hz alliander-theme-menu-static',
	));
	
	$params = array(
		'entity' => $entity,
		'title' => false,
		'metadata' => $metadata,
		'tags' => false
	);
	$summary = elgg_view('object/elements/summary', $params);
	
	$body = "";
	if ($entity->icontime) {
		$body .= elgg_view_entity_icon($entity, "large", array(
			"href" => false,
			"class" => "float-alt"
		));
	}
	$body .= elgg_view("output/longtext", array("value" => $entity->description));
	
	echo elgg_view('object/elements/full', array(
		'summary' => $summary,
		'body' => $body,
	));

} elseif (elgg_in_context("search")) {
	// probably search

	$title = $entity->getVolatileData("search_matched_title");
	$description = $entity->getVolatileData("search_matched_description");
	
	$title = elgg_view("output/url", array(
		"text" => $title,
		"href" => $entity->getURL(),
		"is_trusted" => true
	));
	$body = $title . "<br />" . $description;

	echo elgg_view_image_block("", $body);
	
} elseif (elgg_in_context("widgets")) {
	$body = elgg_view("output/url", array(
		"text" => $entity->title,
		"href" => $entity->getURL()
	));
	echo elgg_view_image_block("", $body);
	
} else {

	$show_edit = elgg_extract("show_edit", $vars, true);
	
	$body = "<tr data-guid='" . $entity->getGUID() . "'>";
	$body .= "<td>" . elgg_view("output/url", array(
		"text" => $entity->title,
		"href" => $entity->getURL(),
		"is_trusted" => true
	)) . "</td>";
	if ($show_edit && $entity->canEdit()) {
		$edit_link = elgg_view("output/url", array(
			"href" => "static/edit/" . $entity->getGUID(),
			"text" => elgg_view_icon("settings-alt")
		));
		$delete_link = elgg_view("output/url", array(
			"href" => "action/static/delete?guid=" . $entity->getGUID(),
			"text" => elgg_view_icon("delete"),
			"confirm" => true
		));
	
		$body .= "<td width='1%' class='center'>" . $edit_link . "</td>";
		$body .= "<td width='1%' class='center'>" . $delete_link . "</td>";
	} else {
		// add blank cells if you can not edit
		$body .= "<td width='1%'>&nbsp;</td><td width='1%'>&nbsp;</td>";
	}
	$body .= "</tr>";

	echo $body;
}
