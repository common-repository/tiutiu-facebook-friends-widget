<?php
/*
Plugin Name: TiuTiu Facebook Friends Widget
Plugin URI: http://tiu.gl/tiutiu/
Description: Widget for showing X random Facebook friends.
Version: 0.1
Author: TiuTiu I/S | Pierre Minik Lynge
Author URI: http://tiu.gl/tiutiu/
*/
if(!class_exists('Facebook')) {
	require_once 'facebook/src/facebook.php';
}

function get_facebook_friends_cookie($app_id, $application_secret) {
  $args = array();
  parse_str(trim($_COOKIE['fbs_' . $app_id], '\\"'), $args);
  ksort($args);
  $payload = '';
  foreach ($args as $key => $value) {
    if ($key != 'sig') {
      $payload .= $key . '=' . $value;
    }
  }
  if (md5($payload . $application_secret) != $args['sig']) {
    return null;
  }
  return $args;
}

add_action("widgets_init", array('tiutiu_facebook_friends', 'register'));
register_activation_hook( __FILE__, array('tiutiu_facebook_friends', 'activate'));
register_deactivation_hook( __FILE__, array('tiutiu_facebook_friends', 'deactivate'));


add_action('init', 'tiutiu_facebook_friends_load_javascript_n_stylesheets');
function tiutiu_facebook_friends_load_javascript_n_stylesheets() {
	if(!is_admin()) {
		$tiutiu_facebook_friends_dir = trailingslashit( get_bloginfo('wpurl') ).PLUGINDIR.'/'. dirname( plugin_basename(__FILE__) );
		wp_enqueue_style('tiutiu_facebook_friends_css', $tiutiu_facebook_friends_dir.'/css/tiutiu_facebook_friends.css', false, false, 'screen');
	}		
}

add_action('admin_head', 'tiutiu_facebook_javascript');
function tiutiu_facebook_javascript() {
?>
<script type="text/javascript" >
function saveFacebookSessionInformation() {
	var data = {
		action: 'tiutiu_facebook_friends_ajax'
	};

	// since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
	jQuery.post(ajaxurl, data, function(response) {
		jQuery('#fbLoginButton').html('Facebook connection has been setup.');
	});
}
</script>
<?php
}


add_action('wp_ajax_tiutiu_facebook_friends_ajax', 'tiutiu_facebook_friends_savepermissions');
function tiutiu_facebook_friends_savepermissions() {
	$options = get_option('tiutiu_facebook_friends');
	if($options['facebook_appId']!='' AND $options['facebook_secret'] != '') {
		$cookie = get_facebook_friends_cookie($options['facebook_appId'], $options['facebook_secret']);
		$options['oauth_access_token'] = $cookie['access_token'];
		update_option('tiutiu_facebook_friends' , $options);
		
	}
	die();
}



class tiutiu_facebook_friends {
	function activate(){
	    $data = array( 'facebook_appId' => '' ,'facebook_secret' => '', 'maxviews' => 9, 'oauth_access_token' => '');
	    if ( ! get_option('tiutiu_facebook_friends')){
	      add_option('tiutiu_facebook_friends' , $data);
	    } else {
	      update_option('tiutiu_facebook_friends' , $data);
	    }
	}
	function deactivate(){
	    delete_option('tiutiu_facebook_friends');
	}
	function control(){
		$options = get_option('tiutiu_facebook_friends');
		if($_POST['tiutiu_facebook_friends_appId']!='') {
			$options['facebook_appId'] = $_POST['tiutiu_facebook_friends_appId'];
			$options['facebook_secret'] = $_POST['tiutiu_facebook_friends_secret'];
			$options['maxviews'] = $_POST['tiutiu_facebook_friends_maxviews'];
			update_option('tiutiu_facebook_friends' , $options);
			$options = get_option('tiutiu_facebook_friends');
			if($_POST['tiutiu_facebook_friends_flush_cache']=='true') {
				delete_transient('tiutiu_facebook_wall_results');
				delete_transient('tiutiu_facebook_wall_user');
			}
		}
		?>
		<p><label>Facebook App ID <input name="tiutiu_facebook_friends_appId"
		type="text" value="<?php echo $options['facebook_appId']; ?>" /></label></p>
		<p><label>Facebook App Secret <input name="tiutiu_facebook_friends_secret"
		type="text" value="<?php echo $options['facebook_secret']; ?>" /></label></p>
		<p><label>Number of friends to show <input name="tiutiu_facebook_friends_maxviews"
		type="text" value="<?php echo $options['maxviews']; ?>" /></label></p>
		<?php if($options['facebook_appId']!='' AND $options['facebook_secret'] != '' AND $options['oauth_access_token']=='') { 
				$facebook = new Facebook(array(
				  'appId'  => $options['facebook_appId'],
				  'secret' => $options['facebook_secret'],
				  'cookie' => true
				));
				if($_GET['session']!='') {
					$data = str_replace("\\", "", $_GET["session"]);
					$sessiondata = json_decode($data, true);
					$facebook->setSession($sessiondata, true);
					$session = $facebook->getSession();
				}
			?>
		    <div id="fb-root"></div>
			<script src="http://connect.facebook.net/en_US/all.js"></script>
			<script>
			  FB.init({appId: '<?php echo $options["facebook_appId"]; ?>', session: <?php echo json_encode($session); ?>, status: true, cookie: true, xfbml: true});
			</script>
			
			<?php
				if ($session) {
				  echo 'Facebook connection has been setup.';
				} else {
				  echo '<div id="fbLoginButton"><fb:login-button perms="publish_stream,offline_access" onlogin="saveFacebookSessionInformation()"></fb:login-button></div>';
				}
			}
			elseif($options['facebook_appId']!='' AND $options['facebook_secret'] != '' AND $options['oauth_access_token']!='') {
				echo 'Facebook connection has been setup.';
			} ?><p><label>	<input name="tiutiu_facebook_friends_flush_cache"
					type="checkbox" value="true" /> Flush cashe</label></p><?php
	}
	function widget($args){
		$options = get_option('tiutiu_facebook_friends');
		if($options['facebook_appId']!='' AND $options['facebook_secret'] !='' AND $options['oauth_access_token']!='') {
			$maxviews = $options['maxviews'];
			if(!is_numeric($maxviews)) {
				$maxviews = 9;
			}
			echo $args['before_widget'];
			if(!$friends = get_transient('tiutiu_facebook_friends_results')) {
				$friends = json_decode(file_get_contents('https://graph.facebook.com/me/friends?access_token='.$options['oauth_access_token']), true);
				set_transient('tiutiu_facebook_friends_results', $friends, 60*60*6);
			}
			if(!$user = get_transient('tiutiu_facebook_friends_user')) {
				$user = json_decode(file_get_contents('https://graph.facebook.com/me?access_token='.$options['oauth_access_token']), true);
				set_transient('tiutiu_facebook_friends_user', $user, 60*60*6);
			}
			echo $args['before_title'] . '<a href="'.$user['link'].'" title="'.$user['name'].' on Facebook"><img src="http://graph.facebook.com/'.$user['id'].'/picture?type=square" alt="'.$user['name'].'"/>'.$user['name'].' on Facebook</a>' . $args['after_title'];
			shuffle($friends['data']);
			$i = 0;
			$num = count($friends['data']);
			echo '<p>Displaying '.number_format($maxviews, 0, ',', '.').' of '.number_format($num, 0, ',', '.').' friends.</p>';
		    while($i<$num) {
				echo '<a href="http://www.facebook.com/profile.php?id='.$friends['data'][$i]['id'].'" title="'.$friends['data'][$i]['name'].'"><img src="http://graph.facebook.com/'.$friends['data'][$i]['id'].'/picture?type=square" alt="'.$friends['data'][$i]['name'].'"/></a>';
				$i++;
				if($i==$maxviews) $i = $num;
			}
			echo '<!-- ';
			print_r($friends);
			echo ' -->';
			echo $args['after_widget'];
		}
	}
	function register(){
	    register_sidebar_widget('TiuTiu Facebook Friends', array('tiutiu_facebook_friends', 'widget'));
	    register_widget_control('TiuTiu Facebook Friends', array('tiutiu_facebook_friends', 'control'));
	}
}
?>