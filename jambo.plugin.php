<?php

/**
 * Jambo a contact form plugin for Habari
 *
 * @package jambo
 *
 * @todo document the functions.
 * @todo use AJAX to submit form, fallback on default if no AJAX.
 * @todo allow "custom feilds" to be added by user.
 */

require_once 'jambohandler.php';

class Jambo extends Plugin
{
	const VERSION= '1.2';
	const OPTION_NAME= 'jambo';
	
	private $theme;
	
	private static function default_options()
	{
		return array(
			'send_to' => $_SERVER['SERVER_ADMIN'],
			'subject_prefix' => _t( '[CONTACT FORM]' ),
			'show_form_on_success' => 1,
			'success_msg' => _t( 'Thank you for your feedback. I\'ll get back to you as soon as possible.' ),
			'error_msg' => _t( 'The following errors occurred with the information you submitted. Please correct them and re-submit the form.' )
			);
	}
	
	public function action_plugin_activation( $file )
	{
		if ( $file == $this->get_file() ) {
			foreach ( self::default_options() as $name => $value ) {
				Options::set( self::OPTION_NAME . ':' . $name, $value );
			}
		}
	}
	
	// helper function to return option values
	public static function get( $name ) {
		return Options::get( self::OPTION_NAME . ':' . $name );
	}
	
	/**
	 * Return plugin metadata for this plugin
	 *
	 * @return array Plugin metadata
	 */
	public function info()
	{
		return array(
			'url' => 'http://drunkenmonkey.org/projects/jambo',
			'name' => 'Jambo',
			'license' => 'Apache License 2.0',
			'author' => 'Drunken Monkey Labs',
			'authorurl' => 'http://drunkenmonkey.org/projects/jambo',
			'version' => self::VERSION,
			'description' => 'Adds a contact form to any page or post.'
		);
	}
	
	public function set_priorities()
	{
		return array(
			'filter_post_content_out' => 11
			);
	}
	
	/* Set up options */
	public function filter_plugin_config( $actions, $plugin_id )
      {
		if ( $plugin_id == $this->plugin_id() ) {
			$actions[]= _t('Configure');
		}
		return $actions;
	}

	public function action_plugin_ui( $plugin_id, $action )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			switch ( $action ) {
				case _t('Configure') :
					$ui = new FormUI( self::OPTION_NAME );
					
					// Add a text control for the address you want the email sent to
					$send_to= $ui->add( 'text', 'send_to', 'Where To Send Email: ' );
					$send_to->add_validator( 'validate_required' );
					
					// Add a text control for the prefix to the subject field
					$subject_prefix= $ui->add( 'text', 'subject_prefix', 'Subject Prefix: ' );
					$subject_prefix->add_validator( 'validate_required' );
					
					$show_form_on_success= $ui->add( 'checkbox', 'show_form_on_success', 'Show Contact Form After Sending?: ' );
					
					// Add a text control for the prefix to the success message
					$success_msg= $ui->add( 'textarea', 'success_msg', 'Success Message: ' );
					$success_msg->add_validator( 'validate_required' );
					
					// Add a text control for the prefix to the subject field
					$error_msg= $ui->add( 'textarea', 'error_msg', 'Error Message: ' );
					$error_msg->add_validator( 'validate_required' );
					
					$ui->out();
					break;
			}
		}
	}
	
	public function filter_rewrite_rules( $rules )
	{
		$rules[] = new RewriteRule(array(
			'name' => 'jambo',
			'parse_regex' => '/^jambo\/send\/(?P<jcode>[0-9a-f]+)[\/]{0,1}$/i',
			'build_str' => 'jambo/send/{$jcode}',
			'handler' => 'JamboHandler',
			'action' => 'send',
			'priority' => 2,
			'rule_class' => RewriteRule::RULE_PLUGIN,
			'is_active' => 1,
			'description' => 'Rewrite for Jambo Contact Form Plugin submittion.'
		));
		return $rules;
	}

	public function filter_rewrite_args( $args, $rulename )
	{
		switch( $rulename ) {
			case 'jambo':
				$args['jcode']= $this->get_code();
				setcookie( 'habari_rc[' . $args['jcode'] . ']', md5( Options::get('GUID') . 'other salt' ), time()+60*60*2, '/' );
				break;
		}
		return $args;
	}
	
	// this allows theme authors/users to create a customized jambo.form template
	// for Jambo to output it's form. we should have a default smarty template too.
	public function filter_available_templates( $templates, $class ) {
		if ( !in_array( 'jambo.form', $templates ) ) {
			switch ( strtolower($class) ) {
				case 'rawphpengine':
					$templates= array_merge( $templates, array('jambo.form') );
					break;
			}
		}
		return $templates;
	}
	
	public function filter_include_template_file( $template_path, $template_name, $class )
	{
		if ( $template_name == 'jambo.form' ) {
			if ( ! file_exists( $template_path ) ) {
				switch ( strtolower($class) ) {
					case 'rawphpengine':
						$template_path= dirname( $this->get_file() ) . '/templates/jambo.form.php';
						break;
					case 'smartyengine':
						$template_path= dirname( $this->get_file() ) . '/templates/jambo.form.tpl';
						break;
				}
			}
		}
		return $template_path;
	}
	
	// here we store the current theme object for use later
	// saves us from creating a new theme object and using more resources.
	public function action_add_template_vars( &$theme, $handler_vars )
	{
		$this->theme= $theme;
	}
	
	public function filter_post_content_out( $content )
	{
		$content= str_ireplace( array('<!-- jambo -->', '<!-- contactform -->'), $this->get_form(), $content );
		return $content;
	}
	
	public function filter_jambo_email( $email, $handlervars )
	{
		if ( !$this->verify_code($handlervars['jcode']) ) {
			ob_end_clean();
			header('HTTP/1.1 403 Forbidden');
			die(_t('<h1>The selected action is forbidden.</h1><p>Please enable cookies in your browser.</p>'));
		}
		if ( ! $this->verify_OSA( $handlervars['osa'], $handlervars['osa_time'] ) ) {
			ob_end_clean();
			header('HTTP/1.1 403 Forbidden');
			die(_t('<h1>The selected action is forbidden.</h1><p>You are submitting the form too fast and look like a spam bot.</p>'));
		}
		
		if ( empty( $email['name'] ) ) {
			$email['valid']= false;
			$email['errors']['name']= _t( '<em>Your Name</em> is a <strong>required field</strong>.' );
		}
		if ( empty( $email['email'] ) ) {
			$email['valid']= false;
			$email['errors']['email']= _t( '<em>Your Email</em> is a <strong>required field</strong>.' );
		}
		// validate email addy as per RFC2822 and RFC2821 with a little exception (see: http://www.regular-expressions.info/email.html)
		elseif( !preg_match("@^[a-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+/=?^_`{|}~-]+)*\@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$@i", $email['email'] ) ) {
			$email['valid']= false;
			$email['errors']['email']= _t( '<em>Your Email</em> must be a <strong>valid email address.</strong>' );
		}
		if ( empty( $email['message'] ) ) {
			$email['valid']= false;
			$email['errors']['message']= _t( '<em>Your Remarks</em> is a <strong>required field</strong>.' );
		}
		
		return $email;
	}
	
	/**
	 * Get a 10-digit hex code that identifies the user submitting the feedback
	 * @param The IP address of the commenter
	 * @return A 10-digit hex code
	 **/	 	 	 	 
	private function get_code( $ip = '' )
	{
		if( $ip == '' ) {
			$ip= ip2long($_SERVER['REMOTE_ADDR']);
		}
		$code= substr(md5( Options::get('GUID') . 'more salt' . $ip ), 0, 10);
		$code= Plugins::filter('jambo_code', $code, $ip);
		return $code;
	}
	
	/**
	 * Verify a 10-digit hex code that identifies the user submitting the feedback
	 * @param The IP address of the commenter
	 * @return True if the code is valid, false if not
	 **/	 	 	 	 
	private function verify_code( $suspect_code, $ip = '' )
	{
		return ( $suspect_code == $this->get_code( $ip ) );
	}
	
	private function get_OSA( $time ) {
		$osa= 'osa_' . substr( md5( $time . Options::get( 'GUID' ) . self::VERSION ), 0, 10 );
		$osa= Plugins::filter('jambo_OSA', $osa, $time);
		return $osa;
	}
	
	private function verify_OSA( $osa, $time ) {
		if ( $osa == $this->get_OSA( $time ) ) {
			if ( ( time() > ($time + 5) ) && ( time() < ($time + 5*60) ) ) {
				return true;
			}
		}
		return false;
	}
	
	private function OSA( $vars ) {
		if ( array_key_exists( 'osa', $vars ) && array_key_exists( 'osa_time', $vars ) ) {
			$osa= $vars['osa'];
			$time= $vars['osa_time'];
		}
		else {
			$time= time();
			$osa= $this->get_OSA( $time );
		}
		return "<input type=\"hidden\" name=\"osa\" value=\"$osa\" />\n<input type=\"hidden\" name=\"osa_time\" value=\"$time\" />\n";
	}
	
	/**
	 * 
	 */
	public static function input( $type, $name, $label, $vars= array() )
	{
		$style= ( array_key_exists( 'errors', $vars ) && array_key_exists( $name, $vars['errors'] ) ) ? 'class="input-warning"' : '';
		$value= array_key_exists( $name, $vars ) ? $vars[$name] : '';
		
		switch ( $type ) {
			default:
			case 'text':
				return '<input type="text" size="40" maxlength="50" name="' . $name . '" value="' . $value . '" ' . $style . ' />';
				break;
			case 'textarea':
				return '<textarea ' . $style . ' rows="8" cols="30" name="' . $name . '">' . $value . '</textarea>';
				break;
		}
	}
	
	private function get_form()
	{
		if ( $this->theme instanceof Theme && $this->theme->template_exists( 'jambo.form' ) ) {
			$vars= array_merge( User::commenter(), Session::get_set( 'jambo_email' ) );
			
			$this->theme->jambo= new stdClass;
			$jambo= $this->theme->jambo;
			
			$jambo->form_action= URL::get('jambo');
			$jambo->success_msg= self::get( 'success_msg' );
			$jambo->error_msg= self::get('error_msg');
			$jambo->show_form= true;
			$jambo->success= false;
			$jambo->error= false;
			
			if ( array_key_exists( 'valid', $vars ) && $vars['valid'] ) {
				$jambo->success= true;
				$jambo->show_form= self::get( 'show_form_on_success' );
			}
			
			if ( array_key_exists( 'errors', $vars ) ) {
				$jambo->error= true;
				$jambo->errors= $vars['errors'];
			}
			
			$jambo->name= $this->input( 'text', 'name', 'Your Name: (Required)', $vars );
			$jambo->email= $this->input( 'text', 'email', 'Your Email: (Required)', $vars );
			$jambo->subject= $this->input( 'text', 'subject', 'Subject: ', $vars );
			$jambo->message= $this->input( 'textarea', 'message', 'Your Remarks: (Required)', $vars );
			$jambo->osa= $this->OSA( $vars );
			
			return $this->theme->fetch( 'jambo.form' );
		}
		return null;
	}
}

?>