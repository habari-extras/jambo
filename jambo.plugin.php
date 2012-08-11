<?php

/**
 * Jambo a contact form plugin for Habari
 *
 * @package jambo
 *
 * @todo document the functions.
 * @todo use AJAX to submit form, fallback on default if no AJAX.
 * @todo allow "custom fields" to be added by user.
 * @todo redo the hook and make it easy to add other formui comment stuff.
 * @todo use Habari's spam filtering.
 */

class Jambo extends Plugin
{	
	private static function default_options()
	{
		$options = array(
			'send_to' => $_SERVER['SERVER_ADMIN'],
			'subject' => _t( '[CONTACT FORM] %s' ),
			'show_form_on_success' => 1,
			'success_msg' => _t( 'Thank you for your feedback. I\'ll get back to you as soon as possible.' ),
			'error_msg' => _t( 'The following errors occurred with the information you submitted. Please correct them and re-submit the form.' )
			);
		return Plugins::filter( 'jambo__defaultoptions', $options );
	}

	/**
	 * On activation, check and set default options
	 */
	public function action_plugin_activation( $file )
		{
			if ( Plugins::id_from_file( $file ) == Plugins::id_from_file( __FILE__ ) ) {
				foreach ( self::default_options() as $name => $value ) {
					Options::set( 'jambo__' . $name, $value );
				}
			}
		}
	
	/**
	 * Build the configuration settings
	 */
	public function configure()
	{
		$ui = new FormUI( 'jambo_config' );

		// Add a text control for the address you want the email sent to
		$send_to = $ui->append( 'text', 'send_to', 'option:jambo__send_to', _t( 'Where To Send Email: ' ) );
		$send_to->add_validator( 'validate_required' );

		// Add a text control for email subject
		$subject = $ui->append( 'text', 'subject', 'option:jambo__subject', _t( 'Subject: ' ) );
		$subject->add_validator( 'validate_required' );

		// Add an explanation for the subject field. Shouldn't FormUI have an easier way to do this?
		$ui->append( 'static', 'subject_explanation', '<p>' . _t( 'An %s in the subject will be replaced with a subject provided by the user. If omitted, no subject will be requested.' ) . '</p>' );
		
		// Add a text control for the prefix to the success message
		$success_msg = $ui->append( 'textarea', 'success_msg', 'option:jambo__success_msg', _t( 'Success Message: ' ) );
		
		$ui->append( 'submit', 'save', 'Save' );
		return $ui;
	}
	
	/**
	 * Find out if we should request a subject 
	 **/
	private static function ask_subject( $subject = null )
	{
		$ask = true;
		
		if( $subject == null )
		{
			$subject = Options::get( 'jambo__subject' );
		}
		
		if( strpos( $subject, '%s' ) === false )
		{
			$ask = false;
		}
		
		return Plugins::filter( 'jambo__ask_subject', $ask );
	}
		
	/**
	 * Implement the shortcode to show the form
	 */
	function filter_shortcode_contact($content, $code, $attrs, $context)
	{	
		return $this->get_jambo_form( $attrs, $context )->get();
	}
	
	/**
	 * Get the jambo form
	 */
	public function get_jambo_form( $attrs, $context = null ) {
		// borrow default values from the comment forms
		$commenter_name = '';
		$commenter_email = '';
		$commenter_url = '';
		$commenter_content = '';
		$user = User::identify();
		if ( isset( $_SESSION['comment'] ) ) {
			$details = Session::get_set( 'comment' );
			$commenter_name = $details['name'];
			$commenter_email = $details['email'];
			$commenter_url = $details['url'];
			$commenter_content = $details['content'];
		}
		elseif ( $user->loggedin ) {
			$commenter_name = $user->displayname;
			$commenter_email = $user->email;
			$commenter_url = Site::get_url( 'habari' );
		}

		// Process settings from shortcode and database
		$settings = array(
			'subject' => Options::get( 'jambo__subject' ),
			'send_to' => Options::get( 'jambo__send_to' ),
			'success_message' => Options::get( 'jambo__success_msg', 'Thank you contacting me. I\'ll get back to you as soon as possible.' )
		);
		$settings = array_merge( $settings, $attrs );
		
		// Now start the form.
		$form = new FormUI( 'jambo' );
// 		$form->set_option( 'form_action', URL::get( 'submit_feedback', array( 'id' => $this->id ) ) );

		// Create the Name field
		$form->append(
			'text',
			'jambo_name',
			'null:null',
			_t( 'Name' ),
			'formcontrol_text'
		)->add_validator( 'validate_required', _t( 'Your Name is required.' ) )
		->id = 'jambo_name';
		$form->jambo_name->tabindex = 1;
		$form->jambo_name->value = $commenter_name;

		// Create the Email field
		$form->append(
			'text',
			'jambo_email',
			'null:null',
			_t( 'Email' ),
			'formcontrol_text'
		)->add_validator( 'validate_email', _t( 'Your Email must be a valid address.' ) )
		->id = 'jambo_email';
		$form->jambo_email->tabindex = 2;
		$form->jambo_email->caption = _t( 'Email' );
		$form->jambo_email->value = $commenter_email;

		// Create the Subject field, if requested
		if( self::ask_subject( $settings['subject'] ) )
		{
			$form->append(
				'text',
				'jambo_subject',
				'null:null',
				_t( 'Subject' ),
				'formcontrol_text'
			)
			->id = 'jambo_subject';
			$form->jambo_subject->tabindex =32;
		}

		// Create the Message field
		$form->append(
			'text',
			'jambo_message',
			'null:null',
			_t( 'Message', 'jambo' ),
			'formcontrol_textarea'
		)->add_validator( 'validate_required', _t( 'Your message cannot be blank.', 'jambo' ) )
		->id = 'jambo_message';
		$form->jambo_message->tabindex = 4;

		// Create the Submit button
		$form->append( 'submit', 'jambo_submit', _t( 'Submit' ), 'formcontrol_submit' );
		$form->jambo_submit->tabindex = 5;

		// Set up form processing
		$form->on_success( array($this, 'process_jambo'), $settings );
		
		Plugins::act( 'jambo_build_form', $form, $this ); // Allow modification of form
		
		// Return the form object
		return $form;
	}
	
	/**
	 * Process the jambo form and send the email
	 */
	function process_jambo( $form, $settings )
	{		
		
		// get the values and the stored options.
		$email = array();
		$email['sent'] = false;
		$email['name'] = $form->jambo_name->value;
		$email['send_to'] =	$settings['send_to'];
		$email['email'] = $form->jambo_email->value;
		$email['message'] = $form->jambo_message->value;
		$email['success_message'] = $settings['success_message'];
/*		// interesting stuff, this OSA business. If it's not covered by FormUI, maybe it should be.
		$email['osa'] =            $this->handler_vars['osa'];
		$email['osa_time'] =       $this->handler_vars['osa_time'];
*/		
		// Develop the email subject
		$email['subject'] = $settings['subject'];
		if( self::ask_subject( $email['subject'] ) )
		{
			$email['subject'] = sprintf( $email['subject'], $form->jambo_subject->value );
		}
		
		// Utils::mail expects an array
		$email['headers'] = array( 'MIME-Version' => '1.0',
			'From' => "{$email['name']} <{$email['email']}>",
			'Content-Type' => 'text/plain; charset="utf-8"' );

		$email = Plugins::filter( 'jambo_email', $email, $form ); // Allow another plugin to modify the sent email

		$email['sent'] = Utils::mail( $email['send_to'], $email['subject'], $email['message'], $email['headers'] );

		return '<p class="jambo-confirmation">' . $email['success_message']  .'</p>';
	}
	
	/**
	 * Check the email using spam filter, piggybacking on Comment functionality
	 */
	// public function filter_jambo_email( $email, $form )
	// {
	// 	// figure out OSA stuff?
	// 	
	// 	// if ( ! $this->verify_OSA( $handlervars['osa'], $handlervars['osa_time'] ) ) {
	// 	// 	ob_end_clean();
	// 	// 	header('HTTP/1.1 403 Forbidden');
	// 	// 	die(_t('<h1>The selected action is forbidden.</h1><p>You are submitting the form too fast and look like a spam bot.</p>'));
	// 	// }
	// 	
	// 	if( $email['valid'] !== false ) {
	// 		$comment = new Comment( array(
	// 			'name' => $email['name'],
	// 			'email' => $email['email'],
	// 			'content' => $email['message'],
	// 			'ip' => sprintf("%u", ip2long( $_SERVER['REMOTE_ADDR'] ) ),
	// 			'post_id' => ( isset( $post ) ? $post->id : 0 ),
	// 		) );
	// 
	// 		$handlervars['ccode'] = $handlervars['jcode'];
	// 		$_SESSION['comments_allowed'][] = $handlervars['ccode'];
	// 		Plugins::act('comment_insert_before', $comment);
	// 
	// 		if( Comment::STATUS_SPAM == $comment->status ) {
	// 			ob_end_clean();
	// 			header('HTTP/1.1 403 Forbidden');
	// 			die(_t('<h1>The selected action is forbidden.</h1><p>Your attempted contact appears to be spam. If it wasn\'t, return to the previous page and try again.</p>'));
	// 		}
	// 	}
	// 
	// 	return $email;
	// }
	
	/**
	 * Get an OSA (is this necessary?)
	 */
	private function get_OSA( $time ) {
		$osa = 'osa_' . substr( md5( $time . Options::get( 'GUID' ) . self::VERSION ), 0, 10 );
		$osa = Plugins::filter('jambo_OSA', $osa, $time);
		return $osa;
	}
	
	/**
	 * Verify an OSA (see above)
	 */
	private function verify_OSA( $osa, $time ) {
		if ( $osa == $this->get_OSA( $time ) ) {
			if ( ( time() > ($time + 5) ) && ( time() < ($time + 5*60) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return OSA input (see above)
	 */
	private function OSA( $vars ) {
		if ( array_key_exists( 'osa', $vars ) && array_key_exists( 'osa_time', $vars ) ) {
			$osa = $vars['osa'];
			$time = $vars['osa_time'];
		}
		else {
			$time = time();
			$osa = $this->get_OSA( $time );
		}
		return "<input type=\"hidden\" name=\"osa\" value=\"$osa\" />\n<input type=\"hidden\" name=\"osa_time\" value=\"$time\" />\n";
	}

}

?>