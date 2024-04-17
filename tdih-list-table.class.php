<?php

if(!class_exists('WP_List_Table')){ require_once(ABSPATH.'wp-admin/includes/class-wp-list-table.php'); }

class TDIH_List_Table extends WP_List_Table {

	private $date_description;

	private $date_format;

	private $era_mark;

	private $per_page;

	public function __construct(){

		$options = get_option('tdih_options');

		$this->date_description = $options['date_format'];

		$this->date_format = $this->date_mask();

		$this->era_mark = $options['era_mark'] == 1 ? __(' BC', 'this-day-in-history') : __(' BCE', 'this-day-in-history');

		$this->per_page = $this->get_items_per_page('edit_tdih_event_per_page');

		parent::__construct(array('singular' => 'event', 'plural' => 'events', 'ajax' => true));
	}

	public function column_default($item, $column_name){

		switch($column_name){
			case 'event_date':
				return $item->event_date;

			case 'event_name':
				return $item->event_name;

			case 'event_type':
				return $item->event_type === NULL ? '--' : '<a href="admin.php?page=this-day-in-history&type='.$item->event_slug.'">'.$item->event_type.'</a>';

			case 'event_modified':
				return $item->event_modified;

			default:
				return print_r($item, true);
		}
	}

	public function column_event_date($item){

    $page = isset($_REQUEST['page']) ? esc_attr($_REQUEST['page']) : ''; // Sanitize the 'page' parameter
    $slug = sanitize_title($item->slug); // Sanitize the 'slug' from $item

    $actions = array(
        'edit'   => sprintf('<a href="?page=%s&action=%s&id=%s">Edit</a>', $page, 'edit', $item->ID),
        'delete' => sprintf('<a href="?page=%s&action=%s&id=%s&noheader=true">Delete</a>', $page, 'delete', $item->ID),
        'view'   => sprintf('<a href="%s/this-day-in-history/%s">View</a>', get_home_url(), $slug),
    );

    return sprintf('%1$s %2$s', $this->date_add_era($item->event_date), $this->row_actions($actions));
}


	public function column_cb($item){

		return sprintf('<input type="checkbox" name="%1$s[]" value="%2$s" />', $this->_args['singular'], $item->ID);
	}

	public function get_columns(){

		$columns = array(
			'cb'             => '<input type="checkbox" />',
			'event_date'     => __('Event Date', 'this-day-in-history'),
			'event_name'     => __('Event Name', 'this-day-in-history'),
			'event_type'     => __('Event Type', 'this-day-in-history'),
			'event_modified' => __('Event Modified', 'this-day-in-history')
		);

		return $columns;
	}

	public function get_hidden_columns(){

		$columns = (array) get_user_option('manage_tdih_event-menucolumnshidden');

		return $columns;
	}

	public function get_sortable_columns() {

		$sortable_columns = array(
			'event_date'     => array('event_date', true),
			'event_name'     => array('event_name', false),
			'event_type'     => array('event_type', false),
			'event_modified' => array('event_modified', false)
		);

		return $sortable_columns;
	}

	public function get_bulk_actions() {

		$actions = array('bulk_delete' => 'Delete');

		return $actions;
	}

 	public function no_items() {

		_e('No historic events have been found.', 'this-day-in-history');
	}
	
	public function prepare_items() {
		// Get the current page number
		$current_page = $this->get_pagenum();

		// Set up query arguments
		$args = array(
			'post_type'      => 'tdih_event', 
			'posts_per_page' => $this->per_page,
			'paged'          => $current_page,
			'orderby'        => isset($_REQUEST['orderby']) ? $_REQUEST['orderby'] : '',
			'order'          => isset($_REQUEST['order']) ? $_REQUEST['order'] : 'ASC',
			'tax_query'      => array(),
		);

		// Add taxonomy filter if 'type' is set
		if (!empty($_REQUEST['type'])) {
			$args['tax_query'][] = array(
				'taxonomy' => 'category', 
				'field'    => 'slug',
				'terms'    => $_REQUEST['type'],
				'fields'   => 'ids', 

			);
		}

		// Add search query
		if (!empty($_REQUEST['s'])) {
			$args['s'] = sanitize_text_field($_REQUEST['s']);
		}

		// Custom ordering based on different orderby values
		switch ($args['orderby']) {
			case 'event_name':
				$args['orderby'] = 'title';
				break;
			case 'event_date':
				$args['orderby']  = 'post_title';
				break;
			case 'event_type':
				$args['orderby']  = 'name';
				$args['tax_query'] = array(); // Remove tax query to avoid conflict
				break;
			case 'event_modified':
				$args['orderby'] = 'modified';
				break;
			default:
				$args['orderby'] = 'title';
		}

		// Get posts
	   
		$events = get_posts($args);
				
			
		// Total number of items
		$total_items = wp_count_posts('tdih_event')->publish; 

		// Set the items for the current page
		// Modify each post object to include category information
		foreach ($events as &$event) {
			$category = wp_get_post_terms($event->ID, 'event_type',array('fields' => 'names')); 
			$event->event_type = !empty($category) ? $category[0] : '';
			$event->slug = $event->post_name;
			$event->event_name = $event->post_content;
			$event->event_modified = $event->post_modified;
			$event->event_date = $event->post_title;

		}
		//print_r($events);
		$this->items = $events;

		// Set up pagination
		$this->set_pagination_args(array(
			'total_items' => $total_items,
			'per_page'    => $this->per_page,
			'total_pages' => ceil($total_items / $this->per_page),
		));
	}




	public function process_action() {
		global $wpdb;

		switch($this->current_action()){

			case 'new':
				$this->item_add_edit();
				break;

			case 'add':
				$this->item_add();
				break;

			case 'edit':
				$this->item_add_edit();
				break;

			case 'update':
				$this->item_update();
				break;

			case 'delete':
				$this->item_delete();
				break;

			case 'bulk_delete':
				$this->bulk_delete();
				break;

			default:
				$this->prepare_items();
				$this->item_list();
		}
	}

	private function bulk_delete() {
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-events' ) ) {
			wp_die( 'Security check failed', 'Error' );
		}
		global $wpdb;

		check_admin_referer('bulk-events');

		$ids = (array) $_REQUEST['event'];

		foreach ($ids as $i => $value) {

			$result = wp_delete_post($ids[$i], true);
		}

		$url = add_query_arg(array('message' => 4), admin_url('admin.php?page=this-day-in-history'));

		wp_redirect($url);
	}

	private function date_add_era($date) {

		$d = substr($date, 0, 1) == '-' ? new DateTime(substr($date, 1)) : new DateTime($date);

		$date = substr($date, 0, 1) == '-' ? $d->format($this->date_format).$this->era_mark : $d->format($this->date_format);

		return $date;
	}

	private function date_check($date) {

		if (preg_match("/^-?(\d{4})-(\d{2})-(\d{2})$/", $date, $matches)) {

			$year = $matches[1] == '0000' ? '2000' : $matches[1];

			if (checkdate($matches[2], $matches[3], $year)) { return true; }
		}

		return false;
	}

	private function date_mask() {

		switch ($this->date_description) {

			case 'MM-DD-YYYY':
				$format = 'm-d-Y';
				break;

			case 'DD-MM-YYYY':
				$format = 'd-m-Y';
				break;

			default:
				$format = 'Y-m-d';
		}

		return $format;
	}

	private function date_reorder($date) {

		switch ($this->date_description) {

			case 'MM-DD-YYYY':
				if (preg_match("/^(\d{2})-(\d{2})-(\d{1,4})(".$this->era_mark.")?$/i", $date, $matches)) { $date = sprintf('%04d', $matches[3]).'-'.$matches[1].'-'.$matches[2]; }
				break;

			case 'DD-MM-YYYY':
				if (preg_match("/^(\d{2})-(\d{2})-(\d{1,4})(".$this->era_mark.")?$/i", $date, $matches)) { $date = sprintf('%04d', $matches[3]).'-'.$matches[2].'-'.$matches[1]; }
				break;

			default:
				if (preg_match("/^(\d{1,4})-(\d{2})-(\d{2})(".$this->era_mark.")?$/i", $date, $matches)) { $date = sprintf('%04d', $matches[1]).'-'.$matches[2].'-'.$matches[3]; }
		}

		if (isset($matches[4])) { $date = '-'.$date; }

		return $date;
	}

	private function event_types($id) {

		$terms = get_the_terms($id, 'event_type');

		$term_list = '';

		if ($terms != '') {
			foreach ($terms as $term) {
				$term_list .= $term->name.', ';
			}
		}
		$term_list = trim($term_list, ', ');

		return $term_list;
	}

	private function item_add() {
		
		check_admin_referer('this_day_in_history_add_edit');

		$event_date = $this->date_reorder($_POST['event_date_v']);
		$event_slug = sanitize_title_with_dashes($_POST['event_date_v']);
		$event_name = wp_kses_post($_POST['event_name_v']);
		$event_type = $_POST['event_type_v'];

		$error = $this->validate_event($event_date, $event_name);

		if ($error) {

			wp_die ($error, 'Error', array("back_link" => true));

		} else {

			$post = array(
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_status'    => 'publish',
				'post_title'     => $event_date,
				'post_name'      => $event_slug,
				'post_content'   => $event_name,
				'post_type'      => 'tdih_event',
				'tax_input'      => $event_type == -1 ? '' : array('event_type' => $event_type)
			);

			$result = wp_insert_post($post);
		}

		$url = add_query_arg(array('message' => 1), admin_url('?page=this-day-in-history'));

		wp_redirect($url);
	}

	private function item_add_edit() {
		
		global $wpdb;

		if ($this->current_action() == 'edit') {

			$id = (int) $_GET['id'];

			$event = $wpdb->get_row("SELECT ID, post_title AS event_date, post_content AS event_name, post_name as event_slug FROM ".$wpdb->prefix."posts WHERE ID=".$id);

			$event->event_date = $this->date_add_era($event->event_date);

			$event_type = $this->event_types($id);

		} else {

			$event = (object) array('event_date' => '', 'event_name' => '');

			$event_type = 0;
		}

		?>
			<div id="tdih" class="wrap">
				<h2>
					<?php _e('This Day In History', 'this-day-in-history'); ?>
					<?php if ($this->current_action() == 'edit') { echo '<a href="'.admin_url('?page=this-day-in-history&action=new').'" class="add-new-h2">'._x('Add New', 'post').'</a>'; } ?>
				</h2>
				<div id="ajax-response"></div>
				<div class="form-wrap">
					<h3><?php $this->current_action() == 'edit' ? _e('Edit Historic Event', 'this-day-in-history') : _e('New Historic Event', 'this-day-in-history'); ?></h3>
					<form id="add_edit_event" method="post" class="add_edit_event validate" action="<?php echo esc_attr(add_query_arg('noheader', 'true')); ?>">
						<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
						<input type="hidden" name="action" value="<?php echo $this->current_action() == 'edit' ? 'update' : 'add'; ?>" />
						<?php if ($this->current_action() == 'edit') { echo '<input type="hidden" name="id" value="'.$id.'" />'; } ?>
						<?php wp_nonce_field('this_day_in_history_add_edit'); ?>
						<div class="form-field form-required">
							<label for="event_date_v"><?php _e('Date', 'this-day-in-history'); ?></label>
							<input type="text" name="event_date_v" id="event_date_v" value="<?php echo $event->event_date; ?>" required="required" />
							<p><?php printf(__('The date the event occured (enter date in %s format).', 'this-day-in-history'), $this->date_description); ?></p>
						</div>
						<div class="form-field form-required">
							<label for="event_name_v"><?php _e('Name', 'this-day-in-history'); ?></label>
							<?php wp_editor($event->event_name, 'event_name_v'); ?>
							<p><?php _e('The name of the event.', 'this-day-in-history'); ?></p>
						</div>
						<div class="form-field">
							<label for="event_type_v"><?php _e('Type', 'this-day-in-history'); ?></label>
							<?php wp_dropdown_categories(array('hide_empty' => 0, 'name' => 'event_type_v', 'taxonomy' => 'event_type', 'selected' => $event_type, 'hierarchical' => 0, 'value_field' => 'name', 'orderby' => 'name', 'show_option_none' => __('none', 'this-day-in-history'))); ?>
							<p><?php _e('The type of event.', 'this-day-in-history'); ?></p>
						</div>
						<p class="submit">
							<input type="submit" name="submit" class="button" value="<?php echo $this->current_action() == 'edit' ? __('Save Changes', 'this-day-in-history') : __('Add Event', 'this-day-in-history'); ?>" />
						</p>
					</form>
				</div>
			</div>
		<?php

	}

	private function item_delete() {
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'this_day_in_history_add_edit' ) ) {
			wp_die( 'Security check failed', 'Error' );
		}
		$id = (int) $_GET['id'];

		$result = wp_delete_post($id, true);

		$url = add_query_arg(array('message' => 3), admin_url('?page=this-day-in-history'));

		wp_redirect($url);
	}
	
	private function item_list() {
    // Check if WordPress is loaded and the current user has the necessary permissions
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        // If not, display an error message or redirect to a safe location
        echo 'You do not have permission to access this page.';
        return;
    }

    // Proceed with displaying the item list
    ?>
    <div class="wrap">
        <h2>
            <?php _e('This Day In History', 'this-day-in-history'); ?>
            <a href="<?php echo admin_url('?page=this-day-in-history&action=new'); ?>" class="add-new-h2"><?php _ex('Add New','post'); ?></a>
        </h2>
        <form id="search-event-list" method="get">
            <input type="hidden" name="page" value="this-day-in-history" />
            <?php $this->search_box(__('Search Historic Events', 'this-day-in-history'), 'tdih'); ?>
        </form>
        <form id="event-list" method="post">
            <input type="hidden" name="noheader" value="true" />
            <?php $this->display() ?>
        </form>
    </div>
    <?php
}
	/*
	private function item_list() {

		?>
			<div class="wrap">
				<h2>
					<?php _e('This Day In History', 'this-day-in-history'); ?>
					<a href="<?php echo admin_url('?page=this-day-in-history&action=new'); ?>" class="add-new-h2"><?php _ex('Add New','post'); ?></a>
				</h2>
				<form id="search-event-list" method="get">
					<input type="hidden" name="page" value="this-day-in-history" />
					<?php $this->search_box(__('Search Historic Events', 'this-day-in-history'), 'tdih'); ?>
				</form>
				<form id="event-list" method="post">
					<input type="hidden" name="noheader" value="true" />
					<?php $this->display() ?>
				</form>
			</div>
		<?php

	}
	*/

	private function item_update() {
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'this_day_in_history_add_edit' ) ) {
			wp_die( 'Security check failed', 'Error' );
		}
		check_admin_referer('this_day_in_history_add_edit');

		$id = (int) $_POST['id'];
		$event_date = $this->date_reorder($_POST['event_date_v']);
		$event_slug = sanitize_title_with_dashes($_POST['event_date_v']);
		$event_name = wp_kses_post($_POST['event_name_v']);
		$event_type = $_POST['event_type_v'];

		$error = $this->validate_event($event_date, $event_name);

		if ($error) {

			wp_die ($error, 'Error', array("back_link" => true));

		} else {

			$post = array(
				'ID'           => $id,
				'post_title'   => $event_date,
				'post_name'    => $event_slug,
				'post_content' => $event_name,
				'tax_input'    => $event_type = array('event_type' => $event_type == '-1' ? '' : $event_type)
			);

			$result = wp_update_post($post);
		}

		$url = add_query_arg(array('message' => 2), admin_url('?page=this-day-in-history'));

		wp_redirect($url);
	}

	private function validate_event($event_date, $event_name) {

		$error = false;

		if (empty($event_date)) {

			$error = '<h3>'. __('Missing Event Date', 'this-day-in-history') .'</h3><p>'.  __('You must enter a date for the event.', 'this-day-in-history') .'</p>';

		} else if (empty($event_name)) {

			$error = '<h3>'. __('Missing Event Name', 'this-day-in-history') .'</h3><p>'. __('You must enter a name for the event.', 'this-day-in-history') .'</p>';

		} else if (!$this->date_check($event_date)) {

			$error = '<h3>'. __('Invalid Event Date', 'this-day-in-history') .'</h3><p>'.sprintf(__('Please enter dates in the format %s.', 'this-day-in-history'), $this->date_description) .'</p>';
		}

		return $error;
	}
}

?>