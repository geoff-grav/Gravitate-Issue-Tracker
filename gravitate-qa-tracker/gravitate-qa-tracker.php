<?php

/*
Plugin Name: Gravitate QA Tracker
Plugin URI: http://www.gravitatedesign.com
Description: This is Plugin allows you and your users to Track Website issues.
Version: 1.0.0
*/

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
	echo 'Gravitate Issue Tracker.';
	exit;
}


add_action( 'wp_ajax_save_issues', array('GRAVITATE_QA_TRACKER', 'save_issues'));

register_activation_hook( __FILE__, array( 'GRAVITATE_QA_TRACKER', 'activate' ));
add_action('admin_menu', array( 'GRAVITATE_QA_TRACKER', 'admin_menu' ));
add_action('init', array( 'GRAVITATE_QA_TRACKER', 'init' ));
add_action('wp_enqueue_scripts', array( 'GRAVITATE_QA_TRACKER', 'enqueue_scripts' ));
add_action('admin_enqueue_scripts', array( 'GRAVITATE_QA_TRACKER', 'enqueue_scripts' ));
add_filter('plugin_action_links_'.plugin_basename(__FILE__), array( 'GRAVITATE_QA_TRACKER', 'plugin_settings_link' ));



class GRAVITATE_QA_TRACKER {

	private static $version = '1.0.0';
	private static $option_key = 'gravitate_qa_tracker_settings';

	private static $uri;
	private static $uri_root;

	private static $user = false;
	private static $access = false;

	private static $settings;

	static function activate()
	{
		if(!get_option(self::$option_key))
		{
			// Set Default Data
			$settings = array();
			$settings['hash_full_url'] = md5(wp_generate_password());
			$settings['hash_limited_url'] = md5(wp_generate_password());
			$settings['full_static_url'] = 'qatrackeradmin';
			$settings['limited_static_url'] = 'qatracker';
			$settings['status'] = "RESOLVED : lightgreen\nPending : orange\nAddressed : #77bbff\nDiscussion : #ff3333\nFuture Request : #ff88ff";
			$settings['priorities'] = "URGENT : #ff3333\nHigh : orange\nNormal : lightgrey\nLow : #77bbff\nFuture : #ff88ff";
			$settings['departments'] = "Developer\nDesign\nAccount Manager\nDigital Marketer\nClient : white";

			update_option(self::$option_key, $settings);
		}
	}

	static function set_settings()
	{
		self::$settings = get_option(self::$option_key);

		$selects = array('status', 'priorities', 'departments');

	    foreach ($selects as $select)
	    {
	    	if(!empty(self::$settings[$select]))
		    {
		    	$items = self::$settings[$select];
		    	self::$settings[$select] = array();
			    foreach (explode("\n", str_replace("\r", '', $items)) as $key => $value)
			    {
			    	$color = trim(strpos($value, ':') ? substr($value, (strpos($value, ':')+1)) : '');
			    	$value = trim(strpos($value, ':') ? substr($value, 0, strpos($value, ':')) : $value);
			    	self::$settings[$select][] = array('value' => $value, 'color' => $color);
			    }
			}
	    }
	}

	static function init()
	{
		self::set_settings();

		self::$uri = str_replace('?'.$_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI']);
		self::$uri.= (!empty($_GET['gravqatracker']) ? '?gravqatracker='.$_GET['gravqatracker'].'&' : '?');

		self::$uri_root = dirname(str_replace('?'.$_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI']));

		if(!empty($_COOKIE['grav_issues_user']))
		{
			self::$user = unserialize(base64_decode($_COOKIE['grav_issues_user']));
			setcookie("grav_issues_user", $_COOKIE['grav_issues_user'], time()+172800, "/");
		}

		if((!empty(self::$settings['full_static_url']) && strpos($_SERVER['REQUEST_URI'], self::$settings['full_static_url']) !== false) || (!empty(self::$settings['limited_static_url']) && strpos($_SERVER['REQUEST_URI'], self::$settings['limited_static_url']) !== false) || (!empty($_GET['gravqatracker'])))
		{
			$url_type = 'static';
			if(!empty($_GET['gravqatracker']))
			{
				if($_GET['gravqatracker'] == self::$settings['hash_full_url'])
				{
					self::$access = 'full';
				}
				else if($_GET['gravqatracker'] == self::$settings['hash_limited_url'])
				{
					self::$access = 'limited';
				}
				$url_type = 'hash';
			}
			else if(!empty(self::$settings['full_static_url']) && strpos($_SERVER['REQUEST_URI'], self::$settings['full_static_url']) !== false)
			{
				self::$access = 'full';
			}
			else if(!empty(self::$settings['limited_static_url']) && strpos($_SERVER['REQUEST_URI'], self::$settings['limited_static_url']) !== false)
			{
				self::$access = 'limited';
			}

			$is_ip_allowed = false;
			$ips = array('127.0.0.1');

			if(!empty(self::$settings['ips']))
			{
				$ips = array_merge($ips, array_map('trim', explode(',',self::$settings['ips'])));
			}

			if(!empty($ips))
			{
				foreach ($ips as $ip)
				{
					if(strpos(self::real_ip(), $ip) !== false)
					{
						$is_ip_allowed = true;
					}
				}
			}

			if($url_type == 'static' && !is_user_logged_in() && !$is_ip_allowed)
			{
				echo 'You do not have access';
				exit;
			}

			if(!empty($_POST['grav_issues_user_email']) && !empty($_POST['save_user_profile']))
			{
				$value = base64_encode(serialize(array('email' => $_POST['grav_issues_user_email'], 'name' => $_POST['grav_issues_user_name'], 'access' => self::$access)));
				if(setcookie("grav_issues_user", $value, time()+3600, "/"))
				{
					header("Location: ".$_SERVER['REQUEST_URI']);
					exit;
				}
			}

			if(!empty($_POST['update_data']))
			{
				if(!defined('DOING_AJAX'))
				{
					define('DOING_AJAX', true);
				}
				self::update_data($_POST['issue_id'], $_POST['issue_key'], $_POST['issue_value']);
				exit;
			}

			if(!empty($_POST['multi_select']))
			{
				if(!defined('DOING_AJAX'))
				{
					define('DOING_AJAX', true);
				}
				self::multi_select($_POST['ids'], $_POST['action']);
				exit;
			}

			if(!empty($_POST['get_current_page_issues']))
			{
				if(!defined('DOING_AJAX'))
				{
					define('DOING_AJAX', true);
				}
				self::get_current_page_issues($_POST['url']);
				exit;
			}

			if(!empty($_POST['save_issue']))
			{
				if(!defined('DOING_AJAX'))
				{
					define('DOING_AJAX', true);
				}
				self::save_issue();
				exit;
			}

			if(!empty($_POST['save_log']))
			{
				if(!defined('DOING_AJAX'))
				{
					define('DOING_AJAX', true);
				}
				self::save_log();
				exit;
			}

			if(!empty($_POST['delete_issue']))
			{
				if(!defined('DOING_AJAX'))
				{
					define('DOING_AJAX', true);
				}
				self::delete_issue();
				exit;
			}

			if(!empty($_GET['gissues_controls']))
			{
				if(empty(self::$user))
				{
					self::user_profile();
				}
				else
				{
					self::controls();
				}
				exit;
			}
			else if(!empty($_GET['view_issues']))
			{
				if(empty(self::$user))
				{
					self::user_profile();
				}
				else
				{
					self::view_issues();
				}
				exit;
			}
			else if(!empty($_GET['view_comments']))
			{
				if(!empty($_POST['save_comment']) && !empty($_POST['issue_id']))
				{
					self::save_comment();
				}
				self::view_comments();
				exit;
			}
			else
			{
				self::tracker();
				exit;
			}
		}
	}

	private static function get_current_page_issues($url)
	{
		$issues = get_posts(array('post_type' => 'gravitate_issue', 'post_status' => 'draft', 'posts_per_page' => -1));
		$items = array();
	    if($issues)
	    {
	        $num = 0;
	        foreach($issues as $issue)
	        {
	        	$data = get_post_meta( $issue->ID, 'gravitate_issue_data', 1);

	        	if($url == $data['url'] && $data['location'] == 'active')
	        	{
		            $description = str_replace('"','', $data['description']);
		            $description = strip_tags(str_replace(array("\n","\r","'"), "", $description));

		            $status = '';
		            $color = '';

		            if(!empty(self::$settings['status']))
		            {
		            	foreach(self::$settings['status'] as $k => $v)
		            	{
		          			if($data['status'] == sanitize_title($v['value']))
		          			{
		          				$status = $v['value'];
		          				$color = $v['color'];
		          			}
		          		}
		          	}

		            $item = array();
		            $item['id'] = $issue->ID;
		            $item['description'] = $description;
		            $item['status'] = $status;
		            $item['color'] = $color;
		            $item['created_by'] = ucwords($data['created_by']);

		            $items[] = $item;
		        }

	        }
	    }
	    echo json_encode($items);
	    exit;
	}

	private static function update_data($post_id, $key, $value)
	{
		$meta_value = get_post_meta( $post_id, 'gravitate_issue_data', true );

		if($meta_value[$key] == $value)
		{
			echo '--GRAVITATE_ISSUE_AJAX_SUCCESSFULLY--';
	    	exit;
		}

		// Update Issue
	    if((self::$access === 'full' || (self::$access === 'limited' && $key != 'department')) && !empty($meta_value) && isset($meta_value[$key]))
	    {
	    	$meta_value[$key] = $value;

	    	if(update_post_meta($post_id, 'gravitate_issue_data', $meta_value))
	    	{
	    		echo '--GRAVITATE_ISSUE_AJAX_SUCCESSFULLY--';

	    		self::save_log($post_id, self::$user['name'], 'Changed: '.ucwords($key).' to '.ucwords($value));

	    		exit;
	    	}
	    }

	    echo 'Error';
		exit;
	}

	private static function delete_issue()
	{
		if(self::$access === 'full')
		{
			// Delete Issue
		    if(!empty($_POST['delete_issue']))
		    {
		    	if($issue = get_post($_POST['delete_issue']))
		    	{
		    		if(delete_post_meta($issue->ID, 'gravitate_issue_data'))
		    		{
			    		if(wp_delete_post($issue->ID, true))
			    		{
			    			echo '--GRAVITATE_ISSUE_AJAX_SUCCESSFULLY--';
							exit;
			    		}
			    	}
		    	}
		    }
		}

	    echo 'Error';
		exit;
	}

	private static function multi_select($ids='', $action='')
	{
		$success = false;

		if(self::$access === 'full')
		{
			$ids = explode(',', $ids);
			// Delete Issue
		    if(!empty($ids))
		    {
		    	foreach ($ids as $id)
		    	{
		    		if($action == 'delete')
		    		{
		    			if($issue = get_post($id))
				    	{
				    		if(delete_post_meta($issue->ID, 'gravitate_issue_data'))
				    		{
					    		if(wp_delete_post($issue->ID, true))
					    		{
					    			$success = true;
					    		}
					    	}
				    	}
		    		}
		    		else if($action == 'active' || $action == 'archive' || $action == 'trash')
		    		{
		    			if($issue = get_post($id))
				    	{
				    		if($data = get_post_meta($issue->ID, 'gravitate_issue_data', true))
				    		{
				    			$data['location'] = $action;

					    		if(update_post_meta($issue->ID, 'gravitate_issue_data', $data))
	    						{
	    							$success = true;
	    						}
					    	}

					    	self::save_log($issue->ID, self::$user['name'], 'Moved Issue to: '.ucwords($action), true);
				    	}
				    }
		    	}
		    }
		}

		if($success)
		{
			echo '--GRAVITATE_ISSUE_AJAX_SUCCESSFULLY--';
			exit;
		}

	    echo 'Error';
		exit;
	}

	private static function save_comment()
	{
		// Save Data to Database
	    $args = array(
			'post_parent'			=> esc_sql($_POST['issue_id']),
			'post_status'           => 'draft',
			'post_type'             => 'gravitate_issue_com',
			'post_author'           => 1,
			'post_content'		  	=> esc_sql($_POST['comment']),
			'post_title'		  	=> self::$user['name'],
		);

		return wp_insert_post($args);
	}

	private static function save_log($issue_id, $user, $log, $silent=false)
	{
		// Save Data to Database
	    $args = array(
			'post_parent'			=> esc_sql($issue_id),
			'post_status'           => 'draft',
			'post_type'             => 'gravitate_issue_log',
			'post_author'           => 1,
			'post_content'		  	=> esc_sql($log),
			'post_title'		  	=> esc_sql($user)
		);

		if($post_id = wp_insert_post( $args ))
		{
			if(!$silent)
			{
				echo '--GRAVITATE_ISSUE_AJAX_SUCCESSFULLY--';
				exit;
			}
		}
	}

	private static function save_issue()
	{
		if(!empty(self::$user['name']))
		{
			// Create Image
		    if(!empty($_POST['screenshot_data']))
		    {
		    	$image_name = 'capture_'.rand(0,9).time().rand(0,9).'.png';
		    	$upload_dir = wp_upload_dir();

		    	if(!empty($upload_dir['path']))
		    	{
			        $data = base64_decode(substr($_POST['screenshot_data'], (strpos($_POST['screenshot_data'], ',')+1)));

			        $im = imagecreatefromstring($data);

			        if ($im !== false)
			        {
			            imagepng($im, $upload_dir['path'].'/'.$image_name);
			            imagedestroy($im);
			        }
			    }
		    }

		    // Save Data to Database
		    $args = array(
			  'post_status'           => 'draft',
			  'post_type'             => 'gravitate_issue',
			  'post_author'           => 1,
			);

			if($post_id = wp_insert_post( $args ))
			{
				$postdata = array();
				$postdata['location'] = 'active';
				$postdata['description'] = esc_sql($_POST['description']);
				$postdata['status'] = esc_sql((!empty($_POST['status']) ? $_POST['status'] : 2));
				$postdata['priority'] = esc_sql($_POST['priority']);
				$postdata['department'] = esc_sql($_POST['department']);
				$postdata['created_by'] = esc_sql(self::$user['name']);
				$postdata['screenshot'] = $upload_dir['url'].'/'.$image_name;
				$postdata['url'] = esc_sql($_POST['url']);
				$postdata['browser'] = esc_sql($_POST['browser']);
				$postdata['os'] = esc_sql($_POST['os']);
				$postdata['screen_width'] = esc_sql($_POST['screen_width']);
				$postdata['device_width'] = esc_sql($_POST['device_width']);
				$postdata['ip'] = esc_sql(self::real_ip());
				$postdata['link'] = esc_sql($_POST['link']);


				if(update_post_meta($post_id, 'gravitate_issue_data', $postdata))
				{
					echo '--GRAVITATE_ISSUE_AJAX_SUCCESSFULLY--';
					self::save_log($post_id, self::$user['name'], 'Created Issue');
					exit;
				}
			}
		}

		echo 'Error';
		exit;

	}

	static function admin_menu()
	{
		add_submenu_page( 'options-general.php', 'Gravitate QA Tracker', 'Gravitate QA Tracker', 'manage_options', 'gravitate_qa_tracker', array( __CLASS__, 'settings' ));
	}

	static function enqueue_scripts()
	{
		if(self::$user)
		{
	    	wp_enqueue_script( 'js_plugins', plugins_url( 'js/html2canvas_0.5.0.js', __FILE__ ));
	    }
	}

	static function settings()
	{
		if(!empty($_GET['page']) && $_GET['page'] == 'gravitate_qa_tracker')
		{
			$fields = array();
			$fields['full_static_url'] = array('type' => 'text', 'label' => 'Full Access URL', 'value' => 'qatrackeradmin', 'description' => 'Users must be logged in or coming from the Allowed IPs to access this.');
			$fields['limited_static_url'] = array('type' => 'text', 'label' => 'Limited Access URL', 'value' => 'qatracker', 'description' => 'Users must be logged in or coming from the Allowed IPs to access this.');
			$fields['ips'] = array('type' => 'text', 'label' => 'Allowed IPs', 'description' => 'IPs that are allowed access without Logging into WordPress.  Separate with commas. 127.0.0.1 is automatically added.');

			$fields['status'] = array('type' => 'textarea', 'label' => 'Status List', 'value' => "RESOLVED\nPending\nAddressed\nDiscussion\nFuture Request", 'description' => 'One Per Line.  Place them in order of what you want the Sort Order to be.<br> Separate colors with : &nbsp; &nbsp; Colors can be Hex code.');
			$fields['priorities'] = array('type' => 'textarea', 'label' => 'Priority List', 'value' => "URGENT\nHigh\nNormal\nLow\nFuture", 'description' => 'One Per Line.  Place them in order of what you want the Sort Order to be.<br> Separate colors with : &nbsp; &nbsp; Colors can be Hex code.');
			$fields['departments'] = array('type' => 'textarea', 'label' => 'Department List', 'value' => "Developer\nDesign\nAccount Manager\nDigital Marketer\nClient", 'description' => 'One Per Line.  Place them in order of what you want the Sort Order to be.<br> Separate colors with : &nbsp; &nbsp; Colors can be Hex code.');

			// $error = 'The Settings have been locked.  Please see your Web Developer.  This is most likely intensional as the don\'t want you to mess with the settings :)';

			if(!empty($error))
			{
				?>
					<div class="wrap">
					<h2>Gravitate QA Tracker</h2>
					<h4 style="margin: 6px 0;">Version <?php echo self::$version;?></h4>
					<?php if($error){?><div class="error"><p><?php echo $error; ?></p></div><?php } ?>
					</div>
				<?php
			}
			else
			{
				if(!empty($_POST['save_settings']) && !empty($_POST['settings']))
				{
					$_POST['settings']['updated_at'] = time();
					$settings = array_merge(get_option(self::$option_key), $_POST['settings']);

					if(update_option( self::$option_key, $settings ))
					{
						$success = 'Settings Saved Successfully';
					}

					self::set_settings();
				}

				if(!empty(self::$settings))
				{
					foreach (self::$settings as $key => $value)
					{
						if(isset($fields[$key]))
						{
							if(is_array($value))
							{
								$new_val = '';
								foreach ($value as $val)
								{
									if(isset($val['color']))
									{
										$new_val.= $val['value'].' : '. $val['color']."\n";
									}
								}
								$fields[$key]['value'] = $new_val;
							}
							else
							{
								$fields[$key]['value'] = $value;
							}
						}
					}
				}

				?>
					<div class="wrap">
						<h2>Gravitate QA Tracker</h2>
						<h4 style="margin: 6px 0;">Version <?php echo self::$version;?></h4>

						<?php if(!empty($success)){?><div class="updated"><p><?php echo $success; ?></p></div><?php } ?>
						<?php if(!empty($error)){?><div class="error"><p><?php echo $error; ?></p></div><?php } ?>
						<br>

						<form method="post">
							<input type="hidden" name="save_settings" value="1">
							<table class="form-table">
							<tr>
								<th><label>Full User Access</label></th>
								<td><a target="_blank" href="<?php echo site_url().'/?gravqatracker='.self::$settings['hash_full_url'];?>"><?php echo site_url().'/?gravqatracker='.self::$settings['hash_full_url'];?></a></td>
							</tr>
							<tr>
								<th><label>Limited User Access</label></th>
								<td><a target="_blank" href="<?php echo site_url().'/?gravqatracker='.self::$settings['hash_limited_url'];?>"><?php echo site_url().'/?gravqatracker='.self::$settings['hash_limited_url'];?></a></td>
							</tr>
							<?php
							foreach($fields as $meta_key => $field)
							{
								?>
								<tr>
									<th><label for="<?php echo $meta_key;?>"><?php echo $field['label'];?></label></th>
									<td>
									<?php

									if($field['type'] == 'text')
									{
										?><input type="text" name="settings[<?php echo $meta_key;?>]" id="<?php echo $meta_key;?>"<?php echo (isset($field['maxlength']) ? ' maxlength="'.$field['maxlength'].'"' : '');?> value="<?php echo esc_attr( (isset($field['value']) ? $field['value'] : '') );?>" class="regular-text" /><br /><?php
									}
									else if($field['type'] == 'textarea')
									{
										?><textarea rows="6" cols="38" name="settings[<?php echo $meta_key;?>]" id="<?php echo $meta_key;?>"><?php echo esc_attr( (isset($field['value']) ? $field['value'] : '') );?></textarea><br /><?php
									}
									else if($field['type'] == 'select')
									{
										?>
										<select name="settings[<?php echo $meta_key;?>]" id="<?php echo $meta_key;?>">
										<?php
										foreach($field['options'] as $option_value => $option_label){
											$real_value = ($option_value !== $option_label && !is_numeric($option_value) ? $option_value : $option_label);
											?>
											<option<?php echo ($real_value !== $option_label ? ' value="'.$real_value.'"' : '');?> <?php selected( ($real_value !== $option_label ? $real_value : $option_label), esc_attr( (isset($field['value']) ? $field['value'] : '') ));?>><?php echo $option_label;?></option>
											<?php
										} ?>
										</select>
										<?php
									}
									if(isset($field['description'])){ ?><span class="description"><?php echo $field['description'];?></span><?php } ?>
									</td>
								</tr>
								<?php
							}
							?>
							</table>
							<p><input type="submit" value="Save Settings" class="button button-primary" id="submit" name="submit"></p>
						</form>

				    </div>
				<?php
			}
		}
	}

	static function real_ip()
	{
	    if (!empty($_SERVER['HTTP_CLIENT_IP']))
	    {
	        $clientIP = $_SERVER['HTTP_CLIENT_IP'];
	    }
	    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
	    {
	        $clientIP = $_SERVER['HTTP_X_FORWARDED_FOR'];
	    }
	    elseif (!empty($_SERVER['HTTP_X_REAL_IP']))
	    {
	        $clientIP = $_SERVER['HTTP_X_REAL_IP'];
	    }
	    else
	    {
	        $clientIP = $_SERVER['REMOTE_ADDR'];
	    }
	    return $clientIP;
	}

	static function view_comments()
	{
		?>
			<!doctype html>
			<html lang="en-US">
			<head>
			<meta charset="utf-8">
			<title>Issues</title>
			<link rel="stylesheet" href="<?php echo plugins_url( 'css/gravitate-issue-tracker.css', __FILE__ );?>" type="text/css"/>
			<link href="<?php echo plugins_url( 'css/font-awesome.css', __FILE__ );?>" rel="stylesheet">
			<style>
			html, body { height: 100%; overflow: hidden; }
			</style>

			</head>
			<body class="view-comments">
			<div class="comments-container">
				<div class="comments-list">
				<?php

					$args = array();
					$args['post_parent'] = (!empty($_GET['issue_id']) ? $_GET['issue_id'] : '' );
					$args['post_type'] = array('gravitate_issue_log','gravitate_issue_com');
					$args['post_status'] = 'draft';
					$args['order'] = 'ASC';
					$args['posts_per_page'] = -1;


					$comments = new WP_Query($args);

					if($comments->have_posts())
					{
						while($comments->have_posts())
						{
							$comments->the_post();
							?>

							<div class="comment-title">
				        		<span><?php the_title();?></span> &nbsp; <?php the_time('F j, Y');?>
				        	</div>
				        	<div class="comment-body<?php if(get_post_type() == 'gravitate_issue_log'){?> comment-log<?php } ?>">
				        		<?php echo stripslashes(get_the_content());?>
				        	</div>

							<?php
						}
					}
					wp_reset_query();

				?>
				</div>
			</div>
			<form method="post">
				<input type="hidden" name="save_comment" value="1">
				<input type="hidden" name="issue_id" value="<?php echo (!empty($_GET['issue_id']) ? $_GET['issue_id'] : '' );?>">
				<label>Add Comment</label>
				<textarea name="comment"></textarea>
				<input type="submit" name="submit" value="Submit">
			</form>
			</body>
			</html>
		<?php
	}

	static function view_issues()
	{
		?>
		<!doctype html>
		<html lang="en-US">
		<head>
		<meta charset="utf-8">
		<title>Issues</title>
		<link rel="stylesheet" href="<?php echo plugins_url( 'css/gravitate-issue-tracker.css', __FILE__ );?>" type="text/css"/>
		<link rel="stylesheet" href="<?php echo plugins_url( 'slickgrid/slick.grid.css', __FILE__ );?>" type="text/css"/>
		<link href="<?php echo plugins_url( 'css/font-awesome.css', __FILE__ );?>" rel="stylesheet">

		<!-- Slick Grid does not work with updated version of jquery so load an older version -->
		<script src="<?php echo plugins_url( 'slickgrid/lib/jquery-1.7.min.js', __FILE__ );?>"></script>
		<script src="<?php echo plugins_url( 'slickgrid/lib/jquery-ui-1.8.16.custom.min.js', __FILE__ );?>"></script>

		<script src="<?php echo plugins_url( 'js/colorbox.js', __FILE__ );?>"></script>
		<script src="<?php echo plugins_url( 'slickgrid/lib/jquery.event.drag-2.2.js', __FILE__ );?>"></script>

		<script src="<?php echo plugins_url( 'slickgrid/slick.core.js', __FILE__ );?>"></script>
		<script src="<?php echo plugins_url( 'slickgrid/slick.grid.js', __FILE__ );?>"></script>
		<script src="<?php echo plugins_url( 'slickgrid/slick.editors.js?v=01', __FILE__ );?>"></script>

		</head>
		<body id="view-issues" class="<?php echo self::$access;?>-access">
		<div id="view_header">
			<button id="cancel_views" onclick="">< Back</button>
			<span class="total-issues"></span>
			<?php if(self::$access == 'full'){ ?>
			<select id="with-selected" name="with_selected">
				<option value="">- With Selected -</option>
				<?php if(!empty($_GET['location']) && ($_GET['location'] == 'archive' || $_GET['location'] == 'trash')) { ?>
					<option value="active">Move to Active</option>
				<?php } ?>
				<?php if(empty($_GET['location']) || (!empty($_GET['location']) && $_GET['location'] != 'archive')) { ?>
					<option value="archive">Move to Archive</option>
				<?php } ?>
				<?php if(!empty($_GET['location']) && $_GET['location'] == 'trash') { ?>
					<option value="delete">Delete</option>
				<?php }else{ ?>
					<option value="trash">Trash</option>
				<?php } ?>
				<!-- <option value="status:resolve">Mark as RESOLVED</option> -->
			</select>
			<?php } ?>
			<select id="location" name="location">
				<option <?php selected(!empty($_GET['location']) && $_GET['location'] == 'active' ? true : false);?> value="active">View Active</option>
				<option <?php selected(!empty($_GET['location']) && $_GET['location'] == 'archive' ? true : false);?> value="archive">View Archived</option>
				<?php if(self::$access == 'full'){ ?>
					<option <?php selected(!empty($_GET['location']) && $_GET['location'] == 'trash' ? true : false);?> value="trash">View Trashed</option>
				<?php } ?>
			</select>

			<a class="new-window" target="_blank" href="<?php echo self::$uri;?>view_issues=true"><i class="fa fa-external-link"></i></a>

			<a class="user-icon"><?php echo self::$user['name'];?> <i class="fa fa-user"></i></a>
			<input placeholder="Filter..." id="search" type="text">
		</div>
		<div id="grid-container"></div>
		<script>

		if(typeof parent.gravWindowMain != 'undefined')
		{
			var current_page = parent.gravWindowMain.location.pathname+parent.gravWindowMain.location.search;
			parent.openViewIssues();
		}

		var grid;

		var _original_grid_data = [];

		jQuery(document).ready(function() {

			$('#location').on('change', function(e){
				window.open('<?php echo self::$uri;?>view_issues=true&location='+$(this).val(), '_self');
			});

			if(typeof parent.gravWindowMain != 'undefined')
			{
				$('button#cancel_views').on('click', function(){
					if(current_page != parent.gravWindowMain.location.pathname+parent.gravWindowMain.location.search){
						parent.gravWindowMain.location = current_page;
					}
			        parent.closeIssue();
			        window.open('<?php echo self::$uri;?>gissues_controls=1', '_self');
			    });

			    $('.new-window').on('click', function(e)
			    {
			    	e.preventDefault();
			    	$('button#cancel_views').click();
			    	parent.popupQaViewsWindow = window.open('<?php echo self::$uri;?>view_issues=true', 'popupQaViewsWindow', 'width=900, height=600');
			    });
		    }
		    else
		    {
		    	$('button#cancel_views').hide();
		    	$('.new-window').hide();
		    }



		    $('#search').on('keyup', function()
		    {

		    	if(!$(this).val())
		    	{
		    		grid.setData(_original_grid_data);
		    		grid.render();
		    		add_grid_listeners();
		    	}
		    	else
		    	{
		    		var gdata = [];
			    	var cols = grid.getColumns();
			    	var d, c;

			    	var newData = [];
			    	var unique = []

			    	for(d in _original_grid_data)
			    	{
			    		for(c in cols)
				    	{
				    		if(_original_grid_data[d][cols[c].id].length > 0)
				    		{
					    		if(_original_grid_data[d][cols[c].id].toLowerCase().indexOf($(this).val().toLowerCase()) > 0)
					    		{
					    			if(!unique[d])
					    			{
					    				newData.push(_original_grid_data[d]);
					    				unique[d] = 1;
					    			}
					    		}
					    	}
				    	}
			    	}

					grid.setData(newData);
					grid.render();
					add_grid_listeners();
				}

		    });

		    $('.user-icon').on('click', function(e){
		    	parent.userLogout();
		    });

		    $('#with-selected').on('change', function(e)
		    {
		    	if($(this).val())
		    	{
		    		if(confirm('Are you sure?'))
		    		{
			    		var issue_action = $(this).val();
				    	var issue_ids = [];

				    	$('.checkbox-label input').each(function(){
				    		if($(this).is(':checked'))
				    		{
				    			issue_ids.push($(this).val());
				    		}
				    	});

				    	if(issue_ids.length)
				    	{
				    		$('body').addClass('loading');

				    		$.post('<?php echo self::$uri;?>', {
				                multi_select: true,
				                ids: issue_ids.join(','),
				                action: issue_action,
				            },
				            function(response)
				            {
				                if(response && response.indexOf('GRAVITATE_ISSUE_AJAX_SUCCESSFULLY') > 0)
				                {
				                	if(issue_action == 'archive' || issue_action == 'trash' || issue_action == 'active' || issue_action == 'delete')
				                	{
				                		var data = grid.getData();

				                		$('.checkbox-label input:checked').each(function(index)
				                		{
				                			data.splice(($(this).closest('.slick-row').index()-index), 1);
											_original_grid_data.splice(($(this).closest('.slick-row').index()-index), 1);
										});

										grid.setData(data);
										grid.render();
										add_grid_listeners();
				                	}
				                }
				                else
				                {
				                    // Error
				                    alert('There was an error. Please try again or contact your Account Manager.');
				                }
				                $('body').removeClass('loading');
				            });
				    	}
				    }

				    $('#with-selected').val('');
			    }
		    });

		    function update_grid_width()
		    {
				var cols = grid.getColumns();

				var w = $(window).width();

				if(w > 767)
				{
					grid.setOptions({rowHeight: 40});
					grid.invalidate();

					cols[<?php echo (self::$access == 'full' ? 2 : 1); ?>].width = 150;
		    		cols[<?php echo (self::$access == 'full' ? 3 : 2); ?>].width = 120;
		    		cols[<?php echo (self::$access == 'full' ? 4 : 3); ?>].width = 120;
		    		cols[<?php echo (self::$access == 'full' ? 5 : 4); ?>].width = 110;
			    	cols[<?php echo (self::$access == 'full' ? 6 : 5); ?>].width = ( w - <?php echo (self::$access == 'full' ? 728 : 690); ?>);
			    }
			    else
			    {
			    	grid.setOptions({rowHeight: 97});
					grid.invalidate();

			    	var new_w = (w / 7);
		    		cols[<?php echo (self::$access == 'full' ? 2 : 1); ?>].width = new_w;
		    		cols[<?php echo (self::$access == 'full' ? 3 : 2); ?>].width = new_w;
		    		cols[<?php echo (self::$access == 'full' ? 4 : 3); ?>].width = new_w;
		    		cols[<?php echo (self::$access == 'full' ? 5 : 4); ?>].width = new_w;
			    	cols[<?php echo (self::$access == 'full' ? 6 : 5); ?>].width = 0;
			    }

			    grid.setColumns(cols);
			    grid.resizeCanvas();
				add_grid_listeners();
			}

			$(window).resize(function()
		    {
		    	update_grid_width();
		    });

		    update_grid_width();

		});

		function add_grid_listeners()
	    {
	    	$('.slick-cell.r<?php echo (self::$access == 'full' ? 1 : 0); ?>').css('line-height', '35px');
			$('.slick-cell.r<?php echo (self::$access == 'full' ? 1 : 0); ?>').css('text-align', 'center');

	    	$('select').each(function(){
	    		$(this).css('color', $(this).find(":selected").attr('data-color'));
	    	});

	    	if('<?php echo self::$access;?>' != 'full')
			{
				$('select.department').attr('disabled', 'disabled');
				$('select.department').parent().css('position', 'relative').append('<span class="cover" onclick="alert(\'You do not have permission to edit this.\')"></span>');
			}

		    $('.update_data').on('change', function()
		    {
		    	var type = $(this).attr('type');
		    	var html;

		    	var data = grid.getData();
                var active = grid.getActiveCell();
                var cols = grid.getColumns();

		    	if(type == 'checkbox')
		    	{
		    		html = $(this).parent().parent().html();

		    		if($(this).is(':checked'))
		    		{
		    			html = html.split('value="'+$(this).val()+'"').join('value="'+$(this).val()+'" checked="checked"');
		    		}
		    		else
		    		{
		    			html = html.split('checked="checked"').join('');
		    		}

		    		//alert(html);

		    		data[active.row][cols[active.cell].id] = html;
                    grid.setData(data);
                    grid.render();

                    add_grid_listeners();
		    	}
		    	else
		    	{
		    		$(this).css('color', $(this).find(":selected").attr('data-color'));
		    		$('body').addClass('loading');
		    		html = $(this).parent().parent().html().split('selected="selected"').join('');
		    		html = html.split('value="'+$(this).val()+'"').join('value="'+$(this).val()+'" selected="selected"');

		    		$.post('<?php echo self::$uri;?>', {
		                update_data: true,
		                issue_id: data[active.row].id.split('<p>').join('').split('</p>').join(''),
		                issue_key: $(this).attr('id'),
		                issue_value: $(this).val(),
		            },
		            function(response)
		            {
		                if(response && response.indexOf('GRAVITATE_ISSUE_AJAX_SUCCESSFULLY') > 0)
		                {
		                    data[active.row][cols[active.cell].id] = html.split(" selected='selected'").join('');
		                    grid.setData(data);
		                    grid.render();

		                    add_grid_listeners();

		                    $('body').removeClass('loading');
		                }
		                else
		                {
		                	$('body').removeClass('loading');

		                    // Error
		                    alert('There was an error Saving the Issue. Please try again or contact your Account Manager.');
		                }
		            });
		    	}

		    });

			$('a.comments').on('click', function(){
				$.colorbox({iframe: true, title: 'Comments', href: '<?php echo self::$uri;?>view_comments=1&issue_id='+$(this).attr('data-issue-id'), height: '80%', width: '50%', maxWidth: '400px', maxHeight: '300px'});
			});
		}

		function delete_issue(issue_id)
		{
			if('<?php echo self::$access;?>' != 'full')
			{
				alert('You do not have permission to delete issues.');
			}
			else
			{
				if(confirm('You are about to Delete this Issue.\\n\\nClick OK to continue.'))
				{
					$.post( '<?php echo self::$uri;?>', {
		                delete_issue: issue_id,
		            },
		            function(response)
		            {
		                //alert(response);
		                if(response && response.indexOf('GRAVITATE_ISSUE_AJAX_SUCCESSFULLY') > 0)
		                {
		                    //
		                    var data = grid.getData();
							data.splice(grid.getActiveCell().row, 1);
							_original_grid_data.splice(grid.getActiveCell().row, 1);
							grid.setData(data);
							grid.render();
							add_grid_listeners();
		                }
		                else
		                {
		                    // Error
		                    alert('There was an error Saving the Issue. Please try again or contact your Account Manager.');
		                }
		            });
				}
			}
		}

		function HTMLFormatter(row, cell, value, columnDef, dataContext) {
		        return value;
		}

		function sorterNumeric(a, b) {
		    var x = (isNaN(a[sortcol]) || a[sortcol] === "" || a[sortcol] === null) ? -99e+10 : parseFloat(a[sortcol]);
		    var y = (isNaN(b[sortcol]) || b[sortcol] === "" || b[sortcol] === null) ? -99e+10 : parseFloat(b[sortcol]);
		    return sortdir * (x === y ? 0 : (x > y ? 1 : -1));
		}

		function sorterStringCompare(a, b) {
		    var x = a[sortcol], y = b[sortcol];
		    return sortdir * (x === y ? 0 : (x > y ? 1 : -1));
		}

		  var data = [], raw_data,
		      columns = [
		          <?php if(self::$access == 'full'){ ?>{ id: "checkbox", name: "&nbsp;", field: "checkbox", width: 40, sortable: false},<?php } ?>
		          { id: "id", name: "#", field: "id", width: 60, sortable: true, sorter: sorterNumeric },
		          { id: "status", name: "Status", field: "status", width: 150, sortable: true, sorter: sorterStringCompare },
		          { id: "department", name: "Department", field: "department", width: 120, sortable: true, sorter: sorterStringCompare },
		          { id: "priority", name: "Priority", field: "priority", width: 120, sortable: true, sorter: sorterStringCompare },
		          { id: "created_by", name: "Created By", field: "created_by", width: 110, sortable: true, sorter: sorterStringCompare },
		          { id: "description", name: "Description", field: "description", width: ($(window).width()-<?php echo (self::$access == 'full' ? 728 : 690); ?>), sortable: true, sorter: sorterStringCompare, editor: Slick.Editors.LongText },
		          { id: "info", name: "Info", field: "info", width: 130, sortable: true, sorter: sorterStringCompare }
		      ],
		      options = {
		        enableCellNavigation: true,
		        enableColumnReorder: true,
		        multiColumnSort: true,
		        syncColumnCellResize: true,
		        rowHeight: 40,
		        defaultFormatter: HTMLFormatter,
		        editable: true,
		      };

		    <?php

		    $issues = get_posts(array('post_type' => 'gravitate_issue', 'post_status' => 'draft', 'posts_per_page' => -1));

		    $total_issues = 0;

		    if($issues)
		    {
		        $num = 0;
		        foreach($issues as $issue)
		        {
		        	$data = get_post_meta( $issue->ID, 'gravitate_issue_data', 1);

		        	if(((empty($_GET['location']) || $_GET['location'] == 'active') && (empty($data['location']) || $data['location'] == 'active')) || (!empty($_GET['location']) && !empty($data['location']) && $data['location'] == $_GET['location']))
		        	{
		        		$total_issues++;

			            $description = str_replace('"','', $data['description']);
			            $description = strip_tags(str_replace(array("\n","\r","'"), "", $description));

			            $args = array();
						$args['post_parent'] = $issue->ID;
						$args['post_type'] = 'gravitate_issue_com';
						$args['post_status'] = 'draft';

						$comments = get_posts($args);

			            ?>

			            var inner_start = '<p>';

			            raw_data = {
			                <?php if(self::$access == 'full'){ ?>checkbox: '<label class="checkbox-label"><input class="update_data" type="checkbox" name="id[\'<?php echo $issue->ID;?>\']" value="<?php echo $issue->ID;?>"></label>',<?php } ?>
			                id: "<?php echo $issue->ID;?>",
			                status: inner_start+"<select id=\"status\" class=\"status update_data\"><?php if(!empty(self::$settings['status'])){foreach(self::$settings['status'] as $k => $v){?><option data-order=\"<?php echo $k;?>\" data-color=\"<?php echo $v['color'];?>\" <?php selected($data['status'], sanitize_title($v['value']));?> value=\"<?php echo sanitize_title($v['value']);?>\"><?php echo $v['value'];?></option><?php }} ?></select></p>",
			                department: inner_start+"<select id=\"department\" class=\"department update_data\"><option value=\"\"></option><?php if(!empty(self::$settings['departments'])){foreach(self::$settings['departments'] as $k => $v){?><option data-order=\"<?php echo $k;?>\" data-color=\"<?php echo $v['color'];?>\" <?php selected($data['department'], sanitize_title($v['value']));?> value=\"<?php echo sanitize_title($v['value']);?>\"><?php echo $v['value'];?></option><?php }} ?></select></p>",
			                priority: inner_start+"<select id=\"priority\" class=\"priority update_data\"><?php if(!empty(self::$settings['priorities'])){foreach(self::$settings['priorities'] as $k => $v){?><option data-order=\"<?php echo $k;?>\" data-color=\"<?php echo $v['color'];?>\" <?php selected($data['priority'], sanitize_title($v['value']));?> value=\"<?php echo sanitize_title($v['value']);?>\"><?php echo $v['value'];?></option><?php }} ?></select></p>",
			                created_by: inner_start+"<?php echo ucwords($data['created_by']);?></p>",
			                description: inner_start+'<?php echo $description;?></p>',
			                info: inner_start+'<a class="btn" target="GravSupportWindowMain" title="<?php echo $data['url'];?>" href="<?php echo site_url().$data['url'];?>"><i class="fa fa-file-o"></i></a><a class="btn" target="GravSupportWindowMain" href="<?php echo str_replace(array('http:', 'https:'), '', $data['screenshot']);?>"><i class="fa fa-photo"></i></a><a class="btn external_link<?php if(empty($data['link'])){ ?> inactive<?php } ?>" <?php if(!empty($data['link'])){ ?>target="_blank" <?php } ?>title="<?php echo $data['link'];?>"<?php if(!empty($data['link'])){ ?> href="<?php echo $data['link'];?>"<?php } ?>><i class="fa fa-link"></i></a><a class="btn comments" data-issue-id="<?php echo $issue->ID;?>" href="#"><i class="fa fa-comment<?php echo (empty($comments) ? "-o" : "");?>"></i></a><a class="btn" onclick=\'alert(\"URL: <?php echo $data['url'];?>\\n\\nBrowser: <?php echo $data['browser'];?>\\n\\nOS: <?php echo $data['os'];?>\\n\\n\\nBrowser Width: <?php echo $data['screen_width'];?>\\n\\nDevice Width: <?php echo $data['device_width'];?>\\n\\nIP: <?php echo $data['ip'];?>\\n\\n\\nDate Time: <?php echo date('M jS - g:ia', strtotime($issue->post_date));?>\");\'><i class="fa fa-info-circle"></i></a></p>'
			            };

			            data[<?php echo $num;?>] = raw_data;

			            _original_grid_data[<?php echo $num;?>] = raw_data;

			            <?php
			            $num++;
			        }
		        }
		    }
		    ?>

		    $('.total-issues').html(<?php echo $total_issues;?>);

		  	grid = new Slick.Grid("#grid-container", data, columns, options);

			grid.onCellChange.subscribe(function (e,args) {

                var cols = grid.getColumns();

                if(cols[args.cell].id == 'description')
                {
                	$('body').addClass('loading');

	                $.post( '<?php echo self::$uri;?>', {
		                update_data: true,
		                issue_id: args.item.id.split('<p>').join('').split('</p>').join(''),
		                issue_key: 'description',
		                issue_value: args.item.description.split('<p>').join('').split('</p>').join(''),
		            },
		            function(response)
		            {
		                if(response && response.indexOf('GRAVITATE_ISSUE_AJAX_SUCCESSFULLY') > 0)
		                {
		                    $('body').removeClass('loading');
		                }
		                else
		                {
		                	$('body').removeClass('loading');

		                    // Error
		                    alert('There was an error Saving the Issue. Please try again or contact your Account Manager.');
		                }
		                add_grid_listeners();
		            });
				}
             });

			grid.onColumnsReordered.subscribe(function(e, args) {
				add_grid_listeners();
			});

			grid.onViewportChanged.subscribe(function() {
				add_grid_listeners();
				setTimeout(function(){
					add_grid_listeners();
				}, 200);
			});

			grid.onSort.subscribe(function (e, args) {
			  var cols = args.sortCols;

			  args.grid.getData().sort(function (dataRow1, dataRow2) {
			  for (var i = 0, l = cols.length; i < l; i++) {
			      sortdir = cols[i].sortAsc ? 1 : -1;
			      sortcol = cols[i].sortCol.field;

			      var result = cols[i].sortCol.sorter(dataRow1, dataRow2); // sorter property from column definition comes in play here
			      if (result != 0) {
			        return result;
			      }
			    }
			    return 0;
			  });
			  args.grid.invalidateAllRows();
			  args.grid.render();
			  add_grid_listeners();
			});

			add_grid_listeners();

		</script>
		</body>
		</html>
		<?php
	}

	public static function user_profile()
	{
		?>
		<!doctype html>
		<html lang="en-US">
		<head>
		<meta charset="utf-8">
		<title>Issues</title>
		<link rel='stylesheet' href='<?php echo plugins_url( 'css/gravitate-issue-tracker.css', __FILE__ );?>' type='text/css' media='all' />
		<script type='text/javascript'>
			parent.showProfile();
		</script>
		</head>
		<body>
			<div id="user-profile">
				<h2> Create your Profile</h2>
				<form method="post">
					<input type="hidden" name="save_user_profile" value="1">
					<div class="left">
						<label>Your Email *</label>
						<input type="text" name="grav_issues_user_email" required>
					</div>
				    <div class="left">
				        <label>Display Name *</label>
				        <input type="text" name="grav_issues_user_name" required>
				    </div>
				    <div class="right"><br>
				        <input type="submit" name="submit" value="Next">
				    </div>
				</form>
			</div>
		</body>
		</html>

		<?php
	}

	static function controls()
	{
		?>
		<!doctype html>
		<html lang="en-US">
		<head>
		<meta charset="utf-8">
		<title>Issues</title>
		<link href="<?php echo plugins_url( 'css/font-awesome.css', __FILE__ );?>" rel="stylesheet">
		<link rel='stylesheet' href='<?php echo plugins_url( 'css/gravitate-issue-tracker.css', __FILE__ );?>' type='text/css' media='all' />
		<script type='text/javascript' src='<?php echo includes_url();?>/js/jquery/jquery.js'></script>
		<script type='text/javascript'>

		var $ = jQuery;

		parent.closeIssue();

		navigator.sayswho= (function(){
		    var ua= navigator.userAgent, tem,
		    M= ua.match(/(opera|chrome|safari|firefox|msie|trident(?=\/))\/?\s*(\d+)/i) || [];
		    if(/trident/i.test(M[1])){
		        tem=  /\brv[ :]+(\d+)/g.exec(ua) || [];
		        return 'IE '+(tem[1] || '');
		    }
		    if(M[1]=== 'Chrome'){
		        tem= ua.match(/\bOPR\/(\d+)/)
		        if(tem!= null) return 'Opera '+tem[1];
		    }
		    M= M[2]? [M[1], M[2]]: [navigator.appName, navigator.appVersion, '-?'];
		    if((tem= ua.match(/version\/(\d+)/i))!= null) M.splice(1, 1, tem[1]);
		    return M.join(' ');
		})();

		(function (window) {
		    {
		        var unknown = '-';

		        // screen
		        var screenSize = '';
		        if (screen.width) {
		            width = (screen.width) ? screen.width : '';
		            height = (screen.height) ? screen.height : '';
		            screenSize += '' + width + " x " + height;
		        }

		        //browser
		        var nVer = navigator.appVersion;
		        var nAgt = navigator.userAgent;
		        var browser = navigator.appName;
		        var version = '' + parseFloat(navigator.appVersion);
		        var majorVersion = parseInt(navigator.appVersion, 10);
		        var nameOffset, verOffset, ix;

		        // Opera
		        if ((verOffset = nAgt.indexOf('Opera')) != -1) {
		            browser = 'Opera';
		            version = nAgt.substring(verOffset + 6);
		            if ((verOffset = nAgt.indexOf('Version')) != -1) {
		                version = nAgt.substring(verOffset + 8);
		            }
		        }
		        // MSIE
		        else if ((verOffset = nAgt.indexOf('MSIE')) != -1) {
		            browser = 'Microsoft Internet Explorer';
		            version = nAgt.substring(verOffset + 5);
		        }
		        // Chrome
		        else if ((verOffset = nAgt.indexOf('Chrome')) != -1) {
		            browser = 'Chrome';
		            version = nAgt.substring(verOffset + 7);
		        }
		        // Safari
		        else if ((verOffset = nAgt.indexOf('Safari')) != -1) {
		            browser = 'Safari';
		            version = nAgt.substring(verOffset + 7);
		            if ((verOffset = nAgt.indexOf('Version')) != -1) {
		                version = nAgt.substring(verOffset + 8);
		            }
		        }
		        // Firefox
		        else if ((verOffset = nAgt.indexOf('Firefox')) != -1) {
		            browser = 'Firefox';
		            version = nAgt.substring(verOffset + 8);
		        }
		        // MSIE 11+
		        else if (nAgt.indexOf('Trident/') != -1) {
		            browser = 'Microsoft Internet Explorer';
		            version = nAgt.substring(nAgt.indexOf('rv:') + 3);
		        }
		        // Other browsers
		        else if ((nameOffset = nAgt.lastIndexOf(' ') + 1) < (verOffset = nAgt.lastIndexOf('/'))) {
		            browser = nAgt.substring(nameOffset, verOffset);
		            version = nAgt.substring(verOffset + 1);
		            if (browser.toLowerCase() == browser.toUpperCase()) {
		                browser = navigator.appName;
		            }
		        }
		        // trim the version string
		        if ((ix = version.indexOf(';')) != -1) version = version.substring(0, ix);
		        if ((ix = version.indexOf(' ')) != -1) version = version.substring(0, ix);
		        if ((ix = version.indexOf(')')) != -1) version = version.substring(0, ix);

		        majorVersion = parseInt('' + version, 10);
		        if (isNaN(majorVersion)) {
		            version = '' + parseFloat(navigator.appVersion);
		            majorVersion = parseInt(navigator.appVersion, 10);
		        }

		        // mobile version
		        var mobile = /Mobile|mini|Fennec|Android|iP(ad|od|hone)/.test(nVer);

		        // cookie
		        var cookieEnabled = (navigator.cookieEnabled) ? true : false;

		        if (typeof navigator.cookieEnabled == 'undefined' && !cookieEnabled) {
		            document.cookie = 'testcookie';
		            cookieEnabled = (document.cookie.indexOf('testcookie') != -1) ? true : false;
		        }

		        // system
		        var os = unknown;
		        var clientStrings = [
		            {s:'Windows 3.11', r:/Win16/},
		            {s:'Windows 95', r:/(Windows 95|Win95|Windows_95)/},
		            {s:'Windows ME', r:/(Win 9x 4.90|Windows ME)/},
		            {s:'Windows 98', r:/(Windows 98|Win98)/},
		            {s:'Windows CE', r:/Windows CE/},
		            {s:'Windows 2000', r:/(Windows NT 5.0|Windows 2000)/},
		            {s:'Windows XP', r:/(Windows NT 5.1|Windows XP)/},
		            {s:'Windows Server 2003', r:/Windows NT 5.2/},
		            {s:'Windows Vista', r:/Windows NT 6.0/},
		            {s:'Windows 7', r:/(Windows 7|Windows NT 6.1)/},
		            {s:'Windows 8.1', r:/(Windows 8.1|Windows NT 6.3)/},
		            {s:'Windows 8', r:/(Windows 8|Windows NT 6.2)/},
		            {s:'Windows NT 4.0', r:/(Windows NT 4.0|WinNT4.0|WinNT|Windows NT)/},
		            {s:'Windows ME', r:/Windows ME/},
		            {s:'Android', r:/Android/},
		            {s:'Open BSD', r:/OpenBSD/},
		            {s:'Sun OS', r:/SunOS/},
		            {s:'Linux', r:/(Linux|X11)/},
		            {s:'iOS', r:/(iPhone|iPad|iPod)/},
		            {s:'Mac OS X', r:/Mac OS X/},
		            {s:'Mac OS', r:/(MacPPC|MacIntel|Mac_PowerPC|Macintosh)/},
		            {s:'QNX', r:/QNX/},
		            {s:'UNIX', r:/UNIX/},
		            {s:'BeOS', r:/BeOS/},
		            {s:'OS/2', r:/OS\/2/},
		            {s:'Search Bot', r:/(nuhk|Googlebot|Yammybot|Openbot|Slurp|MSNBot|Ask Jeeves\/Teoma|ia_archiver)/}
		        ];
		        for (var id in clientStrings) {
		            var cs = clientStrings[id];
		            if (cs.r.test(nAgt)) {
		                os = cs.s;
		                break;
		            }
		        }

		        var osVersion = unknown;

		        if (/Windows/.test(os)) {
		            osVersion = /Windows (.*)/.exec(os)[1];
		            os = 'Windows';
		        }

		        switch (os) {
		            case 'Mac OS X':
		                osVersion = /Mac OS X (10[\.\_\d]+)/.exec(nAgt)[1];
		                break;

		            case 'Android':
		                osVersion = /Android ([\.\_\d]+)/.exec(nAgt)[1];
		                break;

		            case 'iOS':
		                osVersion = /OS (\d+)_(\d+)_?(\d+)?/.exec(nVer);
		                osVersion = osVersion[1] + '.' + osVersion[2] + '.' + (osVersion[3] | 0);
		                break;
		        }

		        // flash (you'll need to include swfobject)
		        var flashVersion = 'no check';
		        if (typeof swfobject != 'undefined') {
		            var fv = swfobject.getFlashPlayerVersion();
		            if (fv.major > 0) {
		                flashVersion = fv.major + '.' + fv.minor + ' r' + fv.release;
		            }
		            else  {
		                flashVersion = unknown;
		            }
		        }
		    }

		    window.jscd = {
		        screen: screenSize,
		        browser: browser,
		        browserVersion: version,
		        mobile: mobile,
		        os: os,
		        osVersion: osVersion,
		        cookies: cookieEnabled,
		        flashVersion: flashVersion
		    };
		}(this));

		jQuery(document).ready(function($) {

		    setTimeout(function(){
		    	jQuery('#input').val(parent.gravWindowMain.location.href);
				parent.document.onkeydown = KeyPress;
				document.onkeydown = KeyPress;

			}, 1000);

			$('button#capture').on('click', function(){
				captureScreenshot();
			});

			$('.user-icon').on('click', function(e){
		    	parent.userLogout();
		    });

			$('button#capture').hide();

			$('button#capture-status').on('click', function(){
				makeScreenshot();
			});

			$('button#issue').on('click', function(){
				toggle_capture();

			});

		    $('button#view_issues').on('click', function(){
		        parent.closeIssue();
				$('#controls').hide();
				closeScreenshot();
		        window.open('<?php echo self::$uri;?>view_issues=true', '_self');
		    });

			$('button#sendCapture').on('click', function(){

		        if(!$('#description').val())
		        {
		            alert('You must provide a Description');
		        }
		        else if(!$('#priority').val())
		        {
		            alert('You must provide a Priority');
		        }
		        else
		        {
		    		if(!storedLines.length)
		    		{
		    			//alert("You need to create at least one arrow pointing to the location of the issue.\n\nYou can create an arrow by clicking and dragging within the red box.");
		    			if(confirm("There are no arrows pointing to an issue.\n\nClick OK if you want to submit the issue without creating any arrows")){
		                    saveScreenshotData();
		    			}
		    		}
		    		else
		    		{
		                saveScreenshotData();
		    		}
		        }
			});

			$('button#cancelCapture').on('click', function(){
				toggle_capture();
			});

			$('#priority').on('change', function()
			{
				if($(this).val())
				{
					$(this).css('color', '#000');
				}
				else
				{
					$(this).css('color', '#777');
				}
			});

			$('.change-url-link').on('click', function(e)
			{
				closeIssueCapture();
				parent.gravWindowMain.location.href = '<?php echo self::$uri_root;?>';
			});

			$('#change-url-form').on('submit', function(e)
			{
				e.preventDefault();
				closeIssueCapture();
				parent.gravWindowMain.location.href = $('.change-url').val();
				return false;
			});


			jQuery(window).resize(function() {

		         jQuery('#screenSize').html(jQuery(window).width());

		    }).resize(); // Trigger resize handlers.

		    jQuery('#screenSize').html(jQuery(window).width());


		    // Check if URL needs to be updated
		    updateURL();


		});//ready

		function updateURL()
		{
			if(typeof parent.gravWindowMain != 'undefined')
			{
				jQuery('.change-url').val(parent.gravWindowMain.location.href);
				closeIssueCapture();
			}
		}

		function toggle_capture()
		{
			if(!$('#issue:hidden').length)
			{
				update_current_issues();
				parent.openIssue();
				$('#issue').hide();
				$('#controls').fadeIn();
				$('#cancelCapture').show();
				makeScreenshot();
				$('#controls textarea').focus();
				$('#description').val('');
				$('#department').val('');
				$('#priority').val('');
				$('#link').val('');
			}
			else
			{
				closeIssueCapture();
			}
		}

		function closeIssueCapture()
		{
			parent.closeIssue();
			$('#controls').hide();
			$('#cancelCapture').hide();
			$('#issue').show();
			closeScreenshot();
		}

		function KeyPress(e)
		{
			var evtobj = window.event? event : e
			if(evtobj.keyCode == 90 && (evtobj.ctrlKey || evtobj.metaKey))
			{
				storedLines.pop();
				ctx.clearRect(0,0,maxx,maxy);
				redrawStoredLines(ctx);
			}
			if(evtobj.keyCode == 67 && (evtobj.ctrlKey || evtobj.metaKey) && evtobj.shiftKey)
			{
				toggle_capture();
			}
		}
		function closeScreenshot()
		{
		    if(parent.gravWindowMain.document.getElementById('qadrawing'))
		    {
		        elem=parent.gravWindowMain.document.getElementById('qadrawing');
		        elem.parentNode.removeChild(elem);
		        storedLines = [];
		        $(parent.gravWindowMain.window).off('mousedown').off('mousemove').off('mouseup');
		        $('button#capture-status').html('Start Capture');
		        $('button#capture').hide();
		    }
		}
		function makeScreenshot()
		{
			if(parent.gravWindowMain.document.getElementById('qadrawing'))
		    {
		        closeScreenshot();
		    }
			else // Add Screen Shot
			{
				canvas = parent.gravWindowMain.document.createElement('canvas');
		        canvas.id = 'qadrawing';
		        canvas.style.position = 'absolute';
		        canvas.style.top = $(parent.gravWindowMain).scrollTop()+'px';
		        canvas.style.left = '0';
		        canvas.style.bottom = '0';
		        canvas.style.right = '0';
		        canvas.style.boxShadow = '0 0 0 6px red inset';
		        canvas.style.zIndex = '1000000000';
		        canvas.width = $(parent.gravWindowMain).width();
		        canvas.height = ($(parent.gravWindowMain).height()-6);
		        canvas.style.width = '100%';

				parent.gravWindowMain.document.getElementsByTagName('body')[0].appendChild(canvas);

				$obj = $(parent.gravWindowMain.window.document.getElementById('qadrawing'));
				ctx = canvas.getContext('2d');
				$(parent.gravWindowMain.window).mousedown(mDown).mousemove(mMove).mouseup(mDone);
				$('button#capture-status').html('Cancel Capture');
				$('button#capture').show();

				$obj.on('click', function(e){
					e.stopPropagation();
				});
			}
		}

		function saveScreenshotData()
		{
		    var div = document.createElement("DIV");
		    div.setAttribute('data-html2canvas-ignore', 'true');
		    div.id = 'captureLoadingDiv';
		    div.style.position = 'fixed';
		    div.style.top = '0';
		    div.style.bottom = '0';
		    div.style.left = '0';
		    div.style.right = '0';
		    div.style.background = "#333333 url('<?php echo plugins_url( 'loading.gif', __FILE__ );?>') no-repeat center center";
		    div.style.backgroundSize = '10%';
		    div.style.opacity = '0.7';
		    div.style.zIndex = '1000000000';
		    parent.gravWindowMain.document.body.appendChild(div);

		    parent.gravWindowMain.html2canvas(parent.gravWindowMain.document.body, {
		    	logging: false,
		    	proxy: '<?php echo plugins_url( 'html2canvasproxy.php', __FILE__ );?>',
		        onrendered: function(_canvas) {

		            var img_data = _canvas.toDataURL("image/png", 0.1);

		            if(img_data)
		            {
		                $.post( '<?php echo self::$uri;?>', {
		                    save_issue: true,
		                    status: 'pending',
		                    description: $('#description').val(),
		                    browser: navigator.sayswho,
		                    device_width: jscd.screen,
		                    screen_width: jQuery(window).width(),
		                    os: jscd.os +' '+ jscd.osVersion,
		                    ip: '<?php echo self::real_ip();?>',
		                    created_by: $('#created_by').val(),
		                    department: $('#department').val(),
		                    priority: $('#priority').val(),
		                    screenshot: '',
		                    screenshot_data: img_data,
		                    url: parent.gravWindowMain.location.pathname+parent.gravWindowMain.location.search,
		                    link: $('#link').val(),
		                },
		                function(response)
		                {
		                    if(response && response.indexOf('GRAVITATE_ISSUE_AJAX_SUCCESSFULLY') > 0)
		                    {
		                        parent.closeIssue();
		                        $('#controls').hide();
		                        $('#issue-container').fadeIn();
		                        $('#cancelCapture').hide();
								$('#issue').show();
		                        closeScreenshot();
		                        parent.gravWindowMain.document.body.removeChild(parent.gravWindowMain.document.getElementById('captureLoadingDiv'));

		                        if(typeof parent.popupQaViewsWindow != 'undefined')
		                        {
		                        	parent.popupQaViewsWindow.window.location.reload(true);
		                        }
		                    }
		                    else
		                    {
		                        // Error
		                        alert('There was an error Saving the Issue. Please try again or contact your Account Manager.');
		                    }
		                });
		            }
		        },
		        top: $(parent.gravWindowMain.window.document.getElementById('qadrawing')).offset().top,
		        height: ($(parent.gravWindowMain).height()+8)
		    });
		};


		function captureScreenshot()
		{
			parent.gravWindowMain.html2canvas(parent.gravWindowMain.document.body, {
		        onrendered: function(_canvas) {

		            var img = _canvas.toDataURL("image/png", 0);
		            jQuery('#screenshots').append('<img src="'+img+'">');
		            makeScreenshot();

		        },
		        top: $(parent.gravWindowMain.window.document.getElementById('qadrawing')).offset().top,
				height: ($(parent.gravWindowMain).height()+8)
		    });
		};


		var canvas, ctx, storedLines = [];

		// Functions from blog tutorial
		function drawFilledPolygon(canvas,shape)
		{
			canvas.beginPath();
			canvas.moveTo(shape[0][0],shape[0][1]);

			for(p in shape)
				if (p > 0) canvas.lineTo(shape[p][0],shape[p][1]);
			canvas.lineTo(shape[0][0],shape[0][1]);
			canvas.fillStyle = "#ff0000";
			canvas.fill();
		};

		function translateShape(shape,x,y)
		{
			var rv = [];
			for(p in shape)
				rv.push([ shape[p][0] + x, shape[p][1] + y ]);
			return rv;
		};

		function rotateShape(shape,ang)
		{
			var rv = [];
			for(p in shape)
				rv.push(rotatePoint(ang,shape[p][0],shape[p][1]));
			return rv;
		};

		function rotatePoint(ang,x,y)
		{
			return [
				(x * Math.cos(ang)) - (y * Math.sin(ang)),
				(x * Math.sin(ang)) + (y * Math.cos(ang))
			];
		};

		function drawLineArrow(canvas,x1,y1,x2,y2)
		{
			canvas.beginPath();
			canvas.moveTo(x1,y1);
			canvas.lineTo(x2,y2);
			canvas.lineWidth = 6;
			canvas.strokeStyle = "#ff0000";
			canvas.stroke();
			canvas.fillStyle = "#ff0000";
			var ang = Math.atan2(y2-y1,x2-x1);
			drawFilledPolygon(canvas,translateShape(rotateShape(arrow_shape,ang),x2,y2));
		};

		function redrawLine(canvas,x1,y1,x2,y2)
		{
			canvas.clearRect(0,0,maxx,maxy);
			drawLineArrow(canvas,x1,y1,x2,y2);
			redrawStoredLines(canvas);
		};

		function redrawStoredLines(canvas)
		{
			if (storedLines.length == 0) {
		        return;
		    }

		    // redraw each stored line
		    for (var i = 0; i < storedLines.length; i++) {
		    	drawLineArrow(canvas,storedLines[i].x1,storedLines[i].y1,storedLines[i].x2,storedLines[i].y2);
		    }
		};

		// Event handlers
		function mDown(e)
		{
			$(parent.gravWindowMain.document.body).css('cursor','none');
			read_position();
			var p = get_offset(e);
			if ((p[0] < 0) || (p[1] < 0)) return;
			if ((p[0] > maxx) || (p[1] > maxy)) return;
			drawing = true;
			ox = p[0];
			oy = p[1];
			return nothing(e);
		};

		function mMove(e)
		{
			if (!!drawing)
			{
				var p = get_offset(e);
				// Constrain the line to the canvas...
				if (p[0] < 0) p[0] = 0;
				if (p[1] < 0) p[1] = 0;
				if (p[0] > maxx) p[0] = maxx;
				if (p[1] > maxy) p[1] = maxy;
				redrawLine(ctx,ox,oy,p[0],p[1]);
			}
			return nothing(e);
		};

		function mDone(e)
		{
			$(parent.gravWindowMain.document.body).css('cursor','auto');
			if (drawing) {
				var p = get_offset(e);
				$(parent.gravWindowMain.document.body).css('cursor','auto');
				debug_msg(['Draw Arrow',ox,oy,p[0],p[1]].toString());

				storedLines.push({
		            x1: ox,
		            y1: oy,
		            x2: p[0],
		            y2: p[1]
		        });

				drawing = false;
				return mMove(e);
			}
		};

		function nothing(e)
		{
			e.stopPropagation();
			e.preventDefault();
			return false;
		};

		function read_position()
		{
			var o = $obj.position();
			yoff = o.top;
			xoff = o.left;
		};
		function get_offset(e)
		{
			return [ e.pageX - xoff, e.pageY - yoff ];
		};

		function debug_msg(msg)
		{
			//console.log(msg);
		};

		var arrow_shape = [
			[ -17, -12 ],
			[ -8, 0 ],
			[ -17, 12 ],
			[ 4, 0 ]
		];

		var debug_ctr = 0;
		var debug_clr = 12;
		var $obj;
		var maxx = $(window).width(), maxy = 2000;
		var xoff,yoff;
		var ox,oy;
		var drawing;


		function update_current_issues()
		{
			 $.post( '<?php echo self::$uri;?>', {
                get_current_page_issues: true,
                url: parent.gravWindowMain.location.pathname+parent.gravWindowMain.location.search,
            },
            function(response)
            {
                if(response)
                {
                	$('#current-issues').html('');
                	response = response.split(']');
                	response = response[0]+']';
                	var items = JSON.parse(response);
                	for(var i in items)
                	{
                		$('#current-issues').append('<a title="# '+items[i].id+'&#13;Status: '+items[i].status+'&#13;Created By: '+items[i].created_by+'&#13;'+items[i].description+'"><span>#'+items[i].id+'</span><span style="color:'+items[i].color+';">'+items[i].status+'</span>'+items[i].description+'</a>');
                	}
                }
            });
		}

		</script>
		</head>
		<body>
		<div id="issue-container">
			<button id="cancelCapture">Cancel</button><button id="issue">Submit Issue</button> &nbsp; &nbsp; <button id="view_issues">View Issues</button>
			<a class="change-url-link"><i class="fa fa-globe"></i></a>
			<form id="change-url-form">
				<input class="change-url" type="text" name="change_url" placeholder="http://">
			</form>
			<a class="user-icon"><?php echo self::$user['name'];?> <i class="fa fa-user"></i></a>
		</div>
		<div id="controls"<?php if(self::$access == 'full'){ ?> class="full-access"<?php } ?>>
			<div class="left" style="width: 63%;">
				<br>
				<textarea id="description" required placeholder="Description *"></textarea>
				<br>
		        <select id="priority" name="priority" required>
		        	<option value=""> - Priority * - </option>
		        	<?php

				    if(!empty(self::$settings['priorities']))
		            {
		            	foreach(self::$settings['priorities'] as $k => $v)
		            	{
		          			?>
		          			<option value="<?php echo sanitize_title($v['value']);?>"><?php echo $v['value'];?></option>
		          			<?php
		          		}
		          	}

				    ?>
		        </select>
		        <?php if(self::$access == 'full'){ ?>
		        <select id="department" name="department">
		        	<option value=""> - Department - </option>
		        	<?php

				    if(!empty(self::$settings['departments']))
		            {
		            	foreach(self::$settings['departments'] as $k => $v)
		            	{
		          			?>
		          			<option value="<?php echo sanitize_title($v['value']);?>"><?php echo $v['value'];?></option>
		          			<?php
		          		}
		          	}

				    ?>
		        </select>
		        <?php } ?>
		        <input type="text" id="link" name="link" placeholder="(optional link)">
		        <button id="sendCapture">Submit Issue</button>
			</div>
			<div class="right" style="width: 34%;">
				<br><label>Current Issues on this page</label><br>
				<div id="current-issues">

				</div>
			</div>
		</div>
		</body>
		</html>
		<?php
	}

	static function tracker()
	{
		?><!doctype html>
		<html lang="en-US">
		<head>
		<meta charset="utf-8">
		<title>QA Tracker</title>
		<link rel="shortcut icon" href="<?php echo plugins_url( 'favicon.ico', __FILE__ );?>" type="image/x-icon" />
		<script type='text/javascript' src='<?php echo includes_url();?>/js/jquery/jquery.js'></script>
		<script type='text/javascript'>

		var $ = jQuery;

		function frameLoaded()
		{
			gravWindowMain.document.onkeydown = gravWindowControls.KeyPress;

			if(typeof gravWindowControls != 'undefined')
			{
				gravWindowControls.updateURL();
			}

			if(gravWindowMain.windowMain)
			{
				gravWindowMain = gravWindowMain.windowMain;
			}
			else
			{
				gravWindowMain = GravSupportWindowMain;
			}
		}

		function userLogout()
		{
			if(confirm('Click OK to Logout'))
	    	{
	    		// Remove Cookie
	    		document.cookie = "grav_issues_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC";
	    		window.open('/', '_top');
	    	}
		}

		function openIssue()
		{
			var pace = 3;
			var stop = 112;
			var current = 28;
		    $('frameset').attr('rows', 120 + ',*');
		}

		function showProfile()
		{
		    $('frameset').attr('rows', 72 + ',*');
		}

		function openViewIssues()
		{
		    $('frameset').attr('rows', 270 + ',*');
		}

		function closeIssue()
		{
			var pace = 3;
			var stop = 112;
			var current = 28;
		    $('frameset').attr('rows', 34 + ',*');
		}

		var gravWindowControls;
		var gravWindowMain;

		jQuery(document).ready(function() {
			gravWindowControls = GravSupportWindowControls;
			gravWindowMain = GravSupportWindowMain;
		});

		</script>
		</head>

		<frameset rows="28,*" border="4">
		  <frame name="GravSupportWindowControls" src="?<?php echo (!empty($_GET['gravqatracker']) ? 'gravqatracker='.$_GET['gravqatracker'].'&' : '');?>gissues_controls=1" scrolling="no" frameborder="6" bordercolor="#333333" />
		  <frame name="GravSupportWindowMain" src="<?php echo ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://').$_SERVER['HTTP_HOST'];?>" frameborder="0" onload="frameLoaded();" />
		</frameset>
		</html>
		<?php
	}

	public static function plugin_settings_link($links) {
	  $settings_link = '<a href="options-general.php?page=gravitate_qa_tracker">Settings</a>';
	  array_unshift($links, $settings_link);
	  return $links;
	}
}
