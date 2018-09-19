<?php
#==============================================================================
# LTB Self Service Password
#
# Copyright (C) 2009 Clement OUDOT
# Copyright (C) 2009 LTB-project.org
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# GPL License: http://www.gnu.org/licenses/gpl.txt
#
#==============================================================================

# This page is called to change password

#==============================================================================
# POST parameters
#==============================================================================
# Initiate vars
$result = "";
$login = "";
$confirmpassword = "";
$newpassword = "";
$oldpassword = "";
$ldap = "";
$userdn = "";
$cn = "";
if (!isset($pwd_forbidden_chars)) { $pwd_forbidden_chars=""; }
$mail = "";

if (isset($_POST["confirmpassword"]) and $_POST["confirmpassword"]) { $confirmpassword = strval($_POST["confirmpassword"]); }
 else { $result = "confirmpasswordrequired"; }
if (isset($_POST["newpassword"]) and $_POST["newpassword"]) { $newpassword = strval($_POST["newpassword"]); }
 else { $result = "newpasswordrequired"; }
# Get user email for notification
if (!isset($register_sslauth_mailoverride) or !$register_sslauth_mailoverride) {
    if (isset($_REQUEST["mail"]) and $_REQUEST["mail"]) { $mail = $_REQUEST["mail"]; }
     else if (isset($register_require_mail) and $register_require_mail) { $result = "mailrequired"; } 
}
if (!$register_autocreate_login and (!isset($register_sslauth_loginoverride) or !$register_sslauth_loginoverride)) {
    if (isset($_REQUEST["login"]) and $_REQUEST["login"]) { $login = strval($_REQUEST["login"]); }
     else { $result = "loginrequired"; }
}
if (!isset($register_sslauth_loginoverride) or !$register_sslauth_cnoverride) {
	if (isset($_REQUEST["cn"]) and $_REQUEST["cn"]) { $cn = strval($_REQUEST["cn"]); }
	else { $result = "cnrequired"; }
}
if ($register_sslauth) {
    if (isset($register_sslauth_loginoverride) and $register_sslauth_loginoverride) { $login = strval($register_sslauth_loginoverride); }
    if (isset($register_sslauth_cnoverride) and $register_sslauth_cnoverride) { $cn = strval($register_sslauth_cnoverride); }
    if (isset($register_sslauth_mailoverride) and $register_sslauth_mailoverride) { $mail = strval($register_sslauth_mailoverride); }
}
if ((!$cn or isset($register_sslauth_cnoverride)) and (!$login or isset($register_sslauth_loginoverride) or $register_autocreate_login) and (!mail or isset($register_sslauth_mailoverride)) and ! $confirmpassword and ! $newpassword)
 { $result = "emptyregisterform"; }

if ($register_autocreate_login and isset($cn)) {
	$login = str_replace(' ', '.', strtolower($cn));
}

# Check the entered username for characters that our installation doesn't support
if ( $result === "" ) {
    $result = check_username_validity($login,$login_forbidden_chars);
}

# Match new and confirm password
if ( $newpassword != $confirmpassword ) { $result="nomatch"; }

#==============================================================================
# Check reCAPTCHA
#==============================================================================
if ( $result === "" && $use_recaptcha ) {
    $result = check_recaptcha($recaptcha_privatekey, $recaptcha_request_method, $_POST['g-recaptcha-response'], $login);
}

#==============================================================================
# Check if user exists
#==============================================================================
if ( $result === "" ) {

    # Connect to LDAP
    $ldap = ldap_connect($ldap_url);
    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    if ( $ldap_starttls && !ldap_start_tls($ldap) ) {
        $result = "ldaperror";
        error_log("LDAP - Unable to use StartTLS");
    } else {

	    # Bind
	    if ( isset($ldap_binddn) && isset($ldap_bindpw) ) {
	        $bind = ldap_bind($ldap, $ldap_binddn, $ldap_bindpw);
	    } else {
	        $bind = ldap_bind($ldap);
	    }
	
	    if ( !$bind ) {
	        $result = "ldaperror";
	        $errno = ldap_errno($ldap);
	        if ( $errno ) {
	            error_log("LDAP - Bind error $errno  (".ldap_error($ldap).")");
	        }
	    } else {
	
	        # Search for user
	        $ldap_filter = str_replace("{login}", $login, $ldap_filter);
	        $search = ldap_search($ldap, $ldap_base, $ldap_filter);
	
	        $errno = ldap_errno($ldap);
	        if ( $errno ) {
	            $result = "ldaperror";
	            error_log("LDAP - Search error $errno  (".ldap_error($ldap).")");
	        } else {
	
	            # Get user DN
	            $entry = ldap_first_entry($ldap, $search);
	            $userdn = ldap_get_dn($ldap, $entry);
	
	       		# Get user DN
				$entry = ldap_first_entry($ldap, $search);
				if ($entry) {
					$userdn = ldap_get_dn($ldap, $entry);
	
					if( $userdn ) {
						$result = "userexists";
						error_log("LDAP - User $login already exists");
					}
				}
	        }
	    }
	    
    }

}

#==============================================================================
# Check password strength
#==============================================================================
if ( $result === "" ) {
    $result = check_password_strength( $newpassword, '', $pwd_policy_config, $login );
}


$userdn = 'uid='.$login.','.$register_ldap_group;
if ($register_sslauth && isset($register_sslauth_mailoverride) && isset($register_sslauth_ldap_group)) {
	$userdn = 'uid='.$login.','.$register_sslauth_ldap_group;
}

if (!isset($register_sslauth_mailoverride) && isset($mail)) {

	#==============================================================================
	# Build and store token
	#==============================================================================
	if ( $result === "" ) {
	
		# Use PHP session to register token
		# We do not generate cookie
		ini_set("session.use_cookies",0);
		ini_set("session.use_only_cookies",1);
	
		session_name("createtoken");
		session_start();
		$_SESSION['userdn'] = $userdn;
		$_SESSION['login'] = $login;
		$_SESSION['mail'] = $mail;
		$_SESSION['cn'] = $cn;
		$_SESSION['password'] = $newpassword;
		$_SESSION['time']  = time();
	
		if ( $crypt_tokens ) {
			$token = encrypt(session_id(), $keyphrase);
		} else {
			$token = session_id();
		}
	
	}
	
	#==============================================================================
	# Send token by mail
	#==============================================================================
	if ( $result === "" ) {
	
		if ( empty($reset_url) ) {
	
			# Build reset by token URL
			$method = "http";
			if ( !empty($_SERVER['HTTPS']) ) { $method .= "s"; }
			$server_name = $_SERVER['SERVER_NAME'];
			$server_port = $_SERVER['SERVER_PORT'];
			$script_name = $_SERVER['SCRIPT_NAME'];
	
			# Force server port if non standard port
			if (   ( $method === "http"  and $server_port != "80"  )
			or ( $method === "https" and $server_port != "443" )
			) {
				$server_name .= ":".$server_port;
			}
	
			$create_url = $method."://".$server_name.$script_name;
		}
	
		$create_url .= "?action=createbytoken&token=".urlencode($token);
		error_log("Send reset URL $create_url");
	
		$data = array( "login" => $login, "mail" => $mail, "url" => $create_url ) ;
	
		# Send message
		if ( send_mail($mailer, $mail, $mail_from, $mail_from_name, $messages["createtokensubject"], $messages["createtokenmessage"].$mail_signature, $data) ) {
			$result = "createtokensent";
		} else {
			$result = "createtokennotsent";
			error_log("Error while sending token to $mail (user $login)");
		}
	}
}

#==============================================================================
# Create user
#==============================================================================
if ( $result === "" ) {
    $result = create_user($ldap, $userdn, $login, $mail, $cn, $newpassword, $ad_mode, $ad_options, $samba_mode, $samba_options, $shadow_options, $hash, $hash_options);
}

#==============================================================================
# HTML
#==============================================================================
if ( in_array($result, $obscure_failure_messages) ) { $result = "badcredentials"; }


if ($result !== "") { 
?>
<div class="result alert alert-<?php echo get_criticity($result); ?>">
<p><i class="fa fa-fw <?php echo get_fa_class($result); ?>" aria-hidden="true"></i> <?php echo $messages[$result]; ?></p>
</div>

<?php 
}

if ( $result !== "usercreated" ) {

if ( $show_help ) {
    echo "<div class=\"help alert alert-warning\"><p>";
    echo "<i class=\"fa fa-fw fa-info-circle\"></i> ";
    echo $messages["createhelp"];
    echo "</p>";
    if (isset($messages['createhelpextramessage'])) {
        echo "<p>" . $messages['createhelpextramessage'] . "</p>";
    }
    echo "</div>\n";
}
?>

<?php
if ($pwd_show_policy_pos === 'above') {
    show_policy($messages, $pwd_policy_config, $result);
}
?>

<div class="alert alert-info">
<form action="#" method="post" class="form-horizontal">
<?php if (!$register_autocreate_login) { ?>
    <div class="form-group">
        <label for="login" class="col-sm-4 control-label"><?php echo $messages["login"]; ?></label>
        <div class="col-sm-8">
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-fw fa-user"></i></span>
                <input type="text" name="login" id="login" <?php if (isset($register_sslauth_loginoverride)) {?> readonly <?php } ?> value="<?php echo htmlentities($login); ?>" class="form-control" placeholder="<?php echo $messages["login"]; ?>" />
            </div>
        </div>
    </div>
<?php } ?>
     <div class="form-group">
        <label for="cn" class="col-sm-4 control-label"><?php echo $messages["cn"]; ?></label>
        <div class="col-sm-8">
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-fw fa-user"></i></span>
                <input type="text" name="cn" id="cn" <?php if (isset($register_sslauth_cnoverride)) {?> readonly <?php } ?> value="<?php echo htmlentities($cn); ?>" class="form-control" placeholder="<?php echo $messages["cn"]; ?>" />
            </div>
        </div>
    </div>
    <div class="form-group">
        <label for="mail" class="col-sm-4 control-label"><?php echo $messages["mail"]; ?></label>
        <div class="col-sm-8">
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-fw fa-envelope"></i></span>
                <input type="text" name="mail" id="mail" class="form-control" placeholder="<?php echo $messages["mail"]; ?>"
 <?php if (isset($register_sslauth_mailoverride)) {?> readonly <?php } ?> value="<?php echo htmlentities($mail); ?>"/>
            </div>
        </div>
    </div>
    <div class="form-group">
        <label for="newpassword" class="col-sm-4 control-label"><?php echo $messages["newpassword"]; ?></label>
        <div class="col-sm-8">
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-fw fa-lock"></i></span>
                <input type="password" name="newpassword" id="newpassword" class="form-control" placeholder="<?php echo $messages["newpassword"]; ?>" />
            </div>
        </div>
    </div>
    <div class="form-group">
        <label for="confirmpassword" class="col-sm-4 control-label"><?php echo $messages["confirmpassword"]; ?></label>
        <div class="col-sm-8">
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-fw fa-lock"></i></span>
                <input type="password" name="confirmpassword" id="confirmpassword" class="form-control" placeholder="<?php echo $messages["confirmpassword"]; ?>" />
            </div>
        </div>
    </div>
<?php if ($use_recaptcha) { ?>
    <div class="form-group">
        <div class="col-sm-offset-4 col-sm-8">
            <div class="g-recaptcha" data-sitekey="<?php echo $recaptcha_publickey; ?>" data-theme="<?php echo $recaptcha_theme; ?>" data-type="<?php echo $recaptcha_type; ?>" data-size="<?php echo $recaptcha_size; ?>"></div>
            <script type="text/javascript" src="https://www.google.com/recaptcha/api.js?hl=<?php echo $lang; ?>"></script>
        </div>
    </div>
<?php } ?>
    <div class="form-group">
        <div class="col-sm-offset-4 col-sm-8">
            <button type="submit" class="btn btn-success">
                <i class="fa fa-fw fa-check-square-o"></i> <?php echo $messages['submit']; ?>
            </button>
        </div>
    </div>
</form>
</div>

<?php
if ($pwd_show_policy_pos === 'below') {
    show_policy($messages, $pwd_policy_config, $result);
} ?>

<?php } else {

    # Notify user creation
	$data = array( "login" => $login, "mail" => $mail, "password" => $newpassword);
    if ($mail) {
        if ( !send_mail($mailer, $mail, $mail_from, $mail_from_name, $messages["createsubject"], $messages["createmessage"].$mail_signature, $data) ) {
            error_log("Error while sending created email to $mail (user $login)");
        }
    }
    if (isset($register_notify_mail_addresses)) {
    	send_register_notify_mail($mailer, $register_notify_mail_addresses, $messages["createadminsubject"], $messages["createadminmessage"].$mail_signature, $data);
    }

    if (isset($messages['usercreatedextramessage'])) {
        echo "<div class=\"result alert alert-" . get_criticity($result) . "\">";
        echo "<p><i class=\"fa fa-fw " . get_fa_class($result) . "\" aria-hidden=\"true\"></i> " . $messages['usercreatedextramessage'] . "</p>";
        echo "</div>\n";
    }

} ?>