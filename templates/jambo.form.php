<?php

/**
 * Defalut form template for the Jambo contact form plugin for Habari
 *
 * @package jambo
 */

?>

<div id="jambo">
	<form method="post" action="<?php echo $jambo->form_action; ?>">
	
	<?php if ( $jambo->success ) { ?>
		<p class="success"><?php echo $jambo->success_msg; ?></p>
	<?php } ?>
	
	<?php if ( $jambo->error ) { ?>
		<div class="warning">
			<?php echo $jambo->error_msg; ?>
			<ul>
			<?php foreach ( $jambo->errors as $jambo_error ) { ?>
				<li><?php echo $jambo_error; ?></li>
			<?php } ?>
			</ul>
		</div>
	<?php } ?>
	
	<?php if ( $jambo->show_form ) { ?>
	<p>
		<label>
			Your Name: (required)<br />
			<?php echo $jambo->name; ?>
		<label>
	</p>
	
	<p>
		<label>
			Your Email: (required)<br />
			<?php echo $jambo->email; ?>
		<label>
	</p>
	
	<p>
		<label>
			Subject: (optional)<br />
			<?php echo $jambo->subject; ?>
		<label>
	</p>
	
	<p>
		<label>
			Your Remarks: (required)<br />
			<?php echo $jambo->message; ?>
		<label>
	</p>
	
	<p>
		<?php echo $jambo->osa; ?>
		<input type="submit" value="Send It!" name="submit" class="button" />
	</p>
	
	<?php } ?>
	
	</form>
</div>