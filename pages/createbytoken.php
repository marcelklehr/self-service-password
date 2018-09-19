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

# This page is called to reset a password when a valid token is found in URL

#==============================================================================
# POST parameters
#==============================================================================
# Initiate vars
$result = "";
$login = "";
$token = "";
$tokenid = "";
$newpassword = "";
$ldap = "";
$userdn = "";
if (!isset($pwd_forbidden_chars)) { $pwd_forbidden_chars=""; }
$mail = "";

if (isset($_REQUEST["token"]) and $_REQUEST["token"]) { $token = strval($_REQUEST["token"]); }
 else { $result = "tokenrequired"; }

#==============================================================================
# Get token
#==============================================================================
if ( $result === "" ) {

    # Open session with the token
    if ( $crypt_tokens ) {
        $tokenid = decrypt($token, $keyphrase);
    } else {
        $tokenid = $token;
    }

    ini_set("session.use_cookies",0);
    ini_set("session.use_only_cookies",1);

    # Manage lifetime with sessions properties
    if (isset($token_lifetime)) {
        ini_set("session.gc_maxlifetime", $token_lifetime);
        ini_set("session.gc_probability",1);
        ini_set("session.gc_divisor",1);
    }
    
    session_id($tokenid);
    session_name("token");
    session_start();
    $login = $_SESSION['login'];
    $userdn = $_SESSION['userdn'];
    $mail = $_SESSION['mail'];
    $cn = $_SESSION['cn'];
    $newpassword = $_SESSION['password'];

    if ( !$login ) {
        $result = "tokennotvalid";
	error_log("Unable to open session $tokenid");
    } else {
        if (isset($token_lifetime)) {
            # Manage lifetime with session content
            $tokentime = $_SESSION['time'];
            if ( time() - $tokentime > $token_lifetime ) {
                $result = "tokennotvalid";
                error_log("Token lifetime expired");
	    }
        }
    }

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
# Create user
#==============================================================================
if ( $result === "" ) {
	$result = create_user($ldap, $userdn, $login, $mail, $cn, $newpassword, $ad_mode, $ad_options, $samba_mode, $samba_options, $shadow_options, $hash, $hash_options);
}

# Delete token if all is ok
if ( $result === "usercreated" ) {
    $_SESSION = array();
    session_destroy();
}

#==============================================================================
# HTML
#==============================================================================
if ( in_array($result, $obscure_failure_messages) ) { $result = "badcredentials"; }
?>

<div class="result alert alert-<?php echo get_criticity($result) ?>">
<p><i class="fa fa-fw <?php echo get_fa_class($result) ?>" aria-hidden="true"></i> <?php echo $messages[$result]; ?></p>
</div>

<?php if ( $result !== "usercreated" ) { ?>

<?php
if ( $show_help) {
    echo "<div class=\"help alert alert-warning\"><p>";
    echo "<i class=\"fa fa-fw fa-info-circle\"></i> ";
    echo $messages["createbytokenhelp"];
    echo "</p></div>\n";
}
?>

<?php } else {

    # Notify that user has been created
    if ($mail) {
        $data = array( "login" => $login, "mail" => $mail, "cn" => $cn);
        if ( !send_mail($mailer, $mail, $mail_from, $mail_from_name, $messages["createaftertokensubject"], $messages["createaftertokenmessage"].$mail_signature, $data) ) {
            error_log("Error while sending registration email to $mail (user $login)");
        }
    }
    if (isset($register_notify_mail_addresses)) {
    	send_register_notify_mail($mailer, $register_notify_mail_addresses, $messages["createaftertokenadminsubject"], $messages["createaftertokenadminmessage"].$mail_signature, $data);
    }
    
    if (isset($messages['usercreatedextramessage'])) {
    	echo "<div class=\"result alert alert-" . get_criticity($result) . "\">";
    	echo "<p><i class=\"fa fa-fw " . get_fa_class($result) . "\" aria-hidden=\"true\"></i> " . $messages['usercreatedextramessage'] . "</p>";
    	echo "</div>\n";
    }

}
?>

