<?php

define("ACCESS_LOGGED_OUT", -5);
define("MULTI_DASHBOARD_MAX_TABS", 7);

require_once(dirname(__FILE__) . "/lib/functions.php");
require_once(dirname(__FILE__) . "/lib/events.php");
require_once(dirname(__FILE__) . "/lib/hooks.php");
require_once(dirname(__FILE__) . "/lib/page_handlers.php");
require_once(dirname(__FILE__) . "/lib/widgets.php");

/**
 * special actions before init system
 *
 * @return void
 */
function widget_manager_plugins_boot() {
	elgg_register_viewtype_fallback("internal_dashboard");
}

/**
 * Function that runs on system init. Used to perform initialization of the widget manager features.
 *
 * @return void
 */
function widget_manager_init() {
	// check valid WidgetManagerWidget class
	if (get_subtype_class("object", "widget") == "ElggWidget") {
		update_subtype("object", "widget", "WidgetManagerWidget");
	}
	
	// loads the widgets
	widget_manager_widgets_init();
	
	if (elgg_is_active_plugin("groups") && (elgg_get_plugin_setting("group_enable", "widget_manager") == "yes")) {
		// add the widget manager tool option
		
		$group_option_enabled = false;
		if (elgg_get_plugin_setting("group_option_default_enabled", "widget_manager") == "yes") {
			$group_option_enabled = true;
		}
		
		if (elgg_get_plugin_setting("group_option_admin_only", "widget_manager") != "yes") {
			// add the tool option for group admins
			add_group_tool_option('widget_manager',elgg_echo('widget_manager:groups:enable_widget_manager'), $group_option_enabled);
		} elseif (elgg_is_admin_logged_in()) {
			add_group_tool_option('widget_manager',elgg_echo('widget_manager:groups:enable_widget_manager'), $group_option_enabled);
		} elseif ($group_option_enabled) {
			// register event to make sure newly created groups have the group option enabled
			elgg_register_event_handler("create", "group", "widget_manager_create_group_event_handler");
		}
		
		// make default widget management available
		elgg_register_plugin_hook_handler('get_list', 'default_widgets', 'widget_manager_group_widgets_default_list');
	}
	
	// extend CSS
	elgg_extend_view("css/elgg", "css/widget_manager/site");
	elgg_extend_view("css/elgg", "css/widget_manager/global");
	
	elgg_extend_view("css/admin", "css/widget_manager/admin");
	elgg_extend_view("css/admin", "css/widget_manager/global");
	
	elgg_extend_view("js/elgg", "js/widget_manager/site");
	elgg_extend_view("js/admin", "js/widget_manager/admin");
	
	// register a widget title url handler
	// core widgets
	elgg_register_plugin_hook_handler("entity:url", "object", "widget_manager_widgets_url");
	// widget manager widgets
	elgg_register_plugin_hook_handler("entity:url", "object", "widget_manager_widgets_url_hook_handler");
	
	// add extra widget pages
	$extra_contexts = elgg_get_plugin_setting("extra_contexts", "widget_manager");
	if ($extra_contexts) {
		$contexts = string_to_tag_array($extra_contexts);
		if ($contexts) {
			foreach ($contexts as $context) {
				elgg_register_page_handler($context, "widget_manager_extra_contexts_page_handler");
			}
		}
	}
	
	elgg_register_plugin_hook_handler("action", "plugins/settings/save", "widget_manager_plugins_settings_save_hook_handler");
	elgg_register_plugin_hook_handler("action", "widgets/add", "widget_manager_widgets_action_hook_handler");
	elgg_register_plugin_hook_handler("action", "widgets/move", "widget_manager_widgets_action_hook_handler");
	
	elgg_register_plugin_hook_handler("permissions_check", "object", "widget_manager_permissions_check_object_hook_handler");
	
	// multi dashboard support
	add_subtype("object", MultiDashboard::SUBTYPE, "MultiDashboard");
	
	if (elgg_is_logged_in() && widget_manager_multi_dashboard_enabled()) {
		elgg_register_page_handler("multi_dashboard", "widget_manager_multi_dashboard_page_handler");
		
		elgg_register_plugin_hook_handler('register', 'menu:extras', 'widget_manager_register_extras_menu');
		// add js initialisation view
		elgg_extend_view("page/elements/sidebar", "widget_manager/multi_dashboard/sidebar", 400);
		
		elgg_register_event_handler("create", "object", "widget_manager_create_object_handler");
		
		elgg_register_plugin_hook_handler("route", "dashboard", "widget_manager_dashboard_route_handler");
		elgg_register_plugin_hook_handler("action", "widgets/add", "widget_manager_widgets_add_action_handler");
		
		elgg_register_action("multi_dashboard/edit", dirname(__FILE__) . "/actions/multi_dashboard/edit.php");
		elgg_register_action("multi_dashboard/delete", dirname(__FILE__) . "/actions/multi_dashboard/delete.php");
		elgg_register_action("multi_dashboard/drop", dirname(__FILE__) . "/actions/multi_dashboard/drop.php");
		elgg_register_action("multi_dashboard/reorder", dirname(__FILE__) . "/actions/multi_dashboard/reorder.php");
	}
}

/**
 * Function that runs on system pagesetup.
 *
 * @return void
 */
function widget_manager_pagesetup() {
	$context = elgg_get_context();
	
	if (elgg_is_admin_logged_in() && $context == "admin") {
		// move defaultwidgets menu item
		elgg_unregister_menu_item("page", "appearance:default_widgets");
		elgg_register_menu_item('page', array(
				'name' => "appearance:default_widgets",
				'href' => "admin/appearance/default_widgets",
				'text' => elgg_echo("admin:appearance:default_widgets"),
				'context' => 'admin',
				'parent_name' => "widgets",
				'section' => "configure"
		));
		
		// add own menu items
		elgg_register_admin_menu_item('configure', 'manage', 'widgets');
		
		if (elgg_get_plugin_setting("custom_index", "widget_manager") == "1|0") {
			// a special link to manage homepages that are only available if logged out
			elgg_register_menu_item('page', array(
				'name' => "admin:widgets:manage:index",
				'href' => elgg_get_site_url() . "?override=true",
				'text' => elgg_echo("admin:widgets:manage:index"),
				'context' => 'admin',
				'parent_name' => "widgets",
				'section' => "configure"
			));
		}
	}
	
	// update fixed widgets if needed
	if (in_array($context, array("profile", "dashboard")) && ($page_owner_guid = elgg_get_page_owner_guid())) {
		// only check things if you are viewing a profile or dashboard page
		$fixed_ts = elgg_get_plugin_setting($context . "_fixed_ts", "widget_manager");
		if (empty($fixed_ts)) {
			// there should always be a fixed ts, so fix it now. This situation only occurs after activating widget_manager the first time.
			$fixed_ts = time();
			elgg_set_plugin_setting($context . "_fixed_ts", $fixed_ts, "widget_manager");
		}
		
		// get the ts of the profile/dashboard you are viewing
		$user_fixed_ts = elgg_get_plugin_user_setting($context . "_fixed_ts", $page_owner_guid, "widget_manager");
		if ($user_fixed_ts < $fixed_ts) {
			widget_manager_update_fixed_widgets($context, $page_owner_guid);
		}
	}
}

/**
 *  Enables widget that are not specifically registered for groups or index widget, but do work
 *
 *   @return void
 */
function widget_manager_reset_widget_context() {
	global $CONFIG;
	
	$allowed_group_widgets = array("bookmarks", "pages", "blog", "twitter");
	$allowed_index_widgets = array("twitter");
	
	if (is_array($CONFIG->widgets->handlers)) {
		foreach ($allowed_group_widgets as $handler) {
			if (array_key_exists($handler, $CONFIG->widgets->handlers)) {
				$CONFIG->widgets->handlers[$handler]->context[] = "groups";
			}
		}
		foreach ($allowed_index_widgets as $handler) {
			if (array_key_exists($handler, $CONFIG->widgets->handlers)) {
				$CONFIG->widgets->handlers[$handler]->context[] = "index";
			}
		}
	}
}

// register default Elgg events
elgg_register_event_handler("plugins_boot", "system", "widget_manager_plugins_boot");
elgg_register_event_handler("init", "system", "widget_manager_init");
elgg_register_event_handler("init", "system", "widget_manager_reset_widget_context",9999); // needs to be last
elgg_register_event_handler("pagesetup", "system", "widget_manager_pagesetup");
elgg_register_event_handler("all", "object", "widget_manager_update_widget", 1000); // is only a fallback
	
// register plugin hooks
elgg_register_plugin_hook_handler("access:collections:write", "user", "widget_manager_write_access_hook");
elgg_register_plugin_hook_handler("access:collections:read", "user", "widget_manager_read_access_hook");
elgg_register_plugin_hook_handler("action", "widgets/save", "widget_manager_widgets_save_hook");
elgg_register_plugin_hook_handler('index', 'system', 'widget_manager_custom_index', 50); // must be very early

elgg_register_plugin_hook_handler('register', 'menu:widget', 'widget_manager_register_widget_menu');
elgg_register_plugin_hook_handler('prepare', 'menu:widget', 'widget_manager_prepare_widget_menu');

elgg_register_plugin_hook_handler('advanced_context', 'widget_manager', 'widget_manager_advanced_context');
elgg_register_plugin_hook_handler('available_widgets_context', 'widget_manager', 'widget_manager_available_widgets_context');

elgg_register_plugin_hook_handler('permissions_check', 'widget_layout', 'widget_manager_widget_layout_permissions_check');

// register actions
elgg_register_action("widget_manager/manage", dirname(__FILE__) . "/actions/manage.php", "admin");
elgg_register_action("widget_manager/widgets/toggle_fix", dirname(__FILE__) . "/actions/widgets/toggle_fix.php", "admin");
