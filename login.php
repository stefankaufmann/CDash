<?php
/*=========================================================================

  Program:   CDash - Cross-Platform Dashboard System
  Module:    $Id$
  Language:  PHP
  Date:      $Date$
  Version:   $Revision$

  Copyright (c) 2002 Kitware, Inc.  All rights reserved.
  See Copyright.txt or http://www.cmake.org/HTML/Copyright.html for details.

     This software is distributed WITHOUT ANY WARRANTY; without even 
     the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR 
     PURPOSE.  See the above copyright notices for more information.

=========================================================================*/
include_once("cdash/common.php");
include("cdash/config.php");
require_once("cdash/pdo.php");
include_once("cdash/version.php"); 

$loginerror = "";

/** Database authentication */
function databaseAuthenticate($email,$password,$SessionCachePolicy)
{
  global $loginerror;
  $loginerror = "";
  
  include "cdash/config.php";
    
  $db = pdo_connect("$CDASH_DB_HOST", "$CDASH_DB_LOGIN","$CDASH_DB_PASS");
  pdo_select_db("$CDASH_DB_NAME",$db);
  $sql="SELECT id,password FROM ".qid("user")." WHERE email='$email'";
  $result = pdo_query("$sql"); 
  
  if(pdo_num_rows($result)==0)
    {
    pdo_free_result($result);
    $loginerror = "This user doesn't exist.";
    return false;
    }

  $user_array = pdo_fetch_array($result); 
  $pass = $user_array["password"];
  
  // External authentication    
  if($password === NULL && isset($CDASH_EXTERNAL_AUTH) && $CDASH_EXTERNAL_AUTH)
    {
    // create the session array 
    $sessionArray = array ("login" => $login, "password" => 'this is not a valid password', "passwd" => $user_array['password'], "ID" => session_id(), "valid" => 1, "loginid" => $user_array["id"]);  
    $_SESSION['cdash'] = $sessionArray;                
    pdo_free_result($result);
    return true;                               // authentication succeeded 
    }
  else if(md5($password)==$pass)
    {
    session_name("CDash");
    session_cache_limiter($SessionCachePolicy);
    session_set_cookie_params($CDASH_COOKIE_EXPIRATION_TIME);
    @ini_set('session.gc_maxlifetime', $CDASH_COOKIE_EXPIRATION_TIME+600);
    session_start();
    
    // create the session array 
    if(isset($_SESSION['cdash']["password"]))
      {
      $password = $_SESSION['cdash']["password"];
      }
    $sessionArray = array ("login" => $email, "passwd" => $pass, "ID" => session_id(), "valid" => 1, "loginid" => $user_array["id"]);  
    $_SESSION['cdash'] = $sessionArray;
    return true;
    }
  
  $loginerror = "Wrong email or password.";
  return false;
}


/** LDAP authentication */
function ldapAuthenticate($email,$password,$SessionCachePolicy)
{
  global $loginerror;
  $loginerror = "";
  
  include "cdash/config.php";
  include_once "models/user.php";
  
  $ldap = ldap_connect($CDASH_LDAP_HOSTNAME);
  ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION,$CDASH_LDAP_PROTOCOL_VERSION);
  if(isset($ldap) && $ldap != '')
    {
    /* search for pid dn */
    $result = ldap_search($ldap,$CDASH_LDAP_BASEDN, 'mail='.$email, array('dn','cn'));
    if ($result != 0) 
      {
      $entries = ldap_get_entries($ldap, $result);
      @$principal = $entries[0]['dn'];
      if(isset($principal)) 
        {
        // bind as this user
        if(@ldap_bind($ldap, $principal, $password)) 
          {
          $sql="SELECT id,password FROM ".qid("user")." WHERE email='$email'";
           $result = pdo_query("$sql"); 

          // If the user doesn't exist we add it, but without email
          if(pdo_num_rows($result)==0)
            {            
            @$givenname = $entries[0]['cn'][0];
            if(!isset($givenname))
              {
              $loginerror = 'No givenname (cn) set in LDAP, cannot register user into MIDAS';
              return false;
              }
            $names = explode(" ",$givenname);
            
            $User = new User;
                  
            if(count($names)>1)
              {
              $User->FirstName = $names[0];
              $User->LastName = $names[1];
              for($i=2;$i<count($names);$i++)
                {
                $User->LastName .= " ".$names[$i];
                }
              }
            else
              {
              $User->LastName = $names[0];
              }
            
            // Add the user in the database
            $storedPassword = md5($password);
            $User->Email = $email;
            $User->Password = $storedPassword;
            $User->Save();
            $userid = $User->Id;              
            }
          else
            {   
            $user_array = pdo_fetch_array($result); 
            $storedPassword = $user_array["password"];
             $userid = $user_array["id"];
             // If the password has changed we update
            if($storedPassword != md5($password))
              {
              $User = new User;
              $User->Id = $userid;
              $User->SetPassword(md5($password));
              }
            }
          
          session_name("CDash");
          session_cache_limiter($SessionCachePolicy);
          session_set_cookie_params($CDASH_COOKIE_EXPIRATION_TIME);
          @ini_set('session.gc_maxlifetime', $CDASH_COOKIE_EXPIRATION_TIME+600);
          session_start();
    
          // create the session array 
          if(isset($_SESSION['cdash']["password"]))
            {
            $password = $_SESSION['cdash']["password"];
            }
          $sessionArray = array ("login" => $email,"passwd" => $storedPassword, "ID" => session_id(), "valid" => 1, "loginid" => $userid);  
          $_SESSION['cdash'] = $sessionArray;
          return true;
          }
        else
          {
          $loginerror = "Wrong email or password.";
          return false;
          }  
        } 
      else 
        {
        $loginerror = 'User not found in LDAP';
        }
      ldap_free_result($result);
      } 
    else 
      {
      $loginerror = 'Error occured searching the LDAP';
      }
    ldap_close($ldap);
    } 
  else 
    {
    $loginerror = 'Could not connect to LDAP at '.$CDASH_LDAP_HOSTNAME;
    }  
  return false;
}

/** authentication */
function authenticate($email,$password,$SessionCachePolicy)
{
  if(empty($email))
    {
    return 0;
    }
  include "cdash/config.php";
  
  if($CDASH_USE_LDAP)
    {
    // If the user is '1' we use it to login
    $db = pdo_connect("$CDASH_DB_HOST", "$CDASH_DB_LOGIN","$CDASH_DB_PASS");
    pdo_select_db("$CDASH_DB_NAME",$db);
    $query = pdo_query("SELECT id FROM ".qid("user")." WHERE email='$email'");
     if($query && pdo_num_rows($query)>0)
      {
      $user_array = pdo_fetch_array($query); 
       if($user_array["id"] == 1)
        {
        return databaseAuthenticate($email,$password,$SessionCachePolicy);
        }  
      }
    return ldapAuthenticate($email,$password,$SessionCachePolicy);
    }
  else
    {
    return databaseAuthenticate($email,$password,$SessionCachePolicy);
    }  
}


/** Authentication function */
function auth($SessionCachePolicy='private_no_expire')
{  
  include "cdash/config.php";
  $loginid= 1231564132;

  if(isset($CDASH_EXTERNAL_AUTH) && $CDASH_EXTERNAL_AUTH
     && isset($_SERVER['REMOTE_USER'])) 
    {
    $login = $_SERVER['REMOTE_USER'];
    return authenticate($login,NULL,$SessionCachePolicy);
    }
     
  if (@$_GET["logout"]) 
    {                             // user requested logout            
    session_name("CDash");
    session_cache_limiter('nocache');
    @session_start(); 
    unset($_SESSION['cdash']);  
    session_destroy(); 
    echo "<script language=\"javascript\">window.location='index.php'</script>";             
    return 0; 
    }
    
  if(isset($_POST["sent"])) // arrive from login form 
    {
    @$login = $_POST["login"];
    @$passwd = $_POST["passwd"];
    return authenticate($login,$passwd,$SessionCachePolicy);
    }
  else
    {                                         // arrive from session var 
    session_name("CDash");
    session_cache_limiter($SessionCachePolicy);
    session_set_cookie_params($CDASH_COOKIE_EXPIRATION_TIME);
    @ini_set('session.gc_maxlifetime', $CDASH_COOKIE_EXPIRATION_TIME+600);
    session_start();
   
    $email = @$_SESSION['cdash']["login"];
  
    if(!empty($email))
      {  
      $db = pdo_connect("$CDASH_DB_HOST", "$CDASH_DB_LOGIN","$CDASH_DB_PASS");
      pdo_select_db("$CDASH_DB_NAME",$db);
      $sql="SELECT id,password FROM ".qid("user")." WHERE email='$email'";
      $result = pdo_query("$sql"); 
      
      if(pdo_num_rows($result)==0)
        {
        pdo_free_result($result);
        $loginerror = "This user doesn't exist.";
        return false;
        }
    
      $user_array = pdo_fetch_array($result); 
      if($user_array["password"] == $_SESSION['cdash']["passwd"])
        {
        return true;
        }
      $loginerror = "Wrong email or password.";
      return false;
      }
    }
  }  
  
/** Login Form function */
function LoginForm($loginerror)
{  
  include("cdash/config.php");
  require_once("cdash/pdo.php");
  include_once("cdash/common.php"); 
  include("cdash/version.php");
    
  $xml = "<cdash>";
  $xml .= "<title>Login</title>";
  $xml .= "<cssfile>".$CDASH_CSS_FILE."</cssfile>";
  $xml .= "<version>".$CDASH_VERSION."</version>";
  if(isset($CDASH_NO_REGISTRATION) && $CDASH_NO_REGISTRATION==1)
    {
    $xml .= add_XML_value("noregister","1");
    } 
  if(@$_GET['note'] == "register")
    {
    $xml .= "<message>Registration Complete. Please login with your email and password.</message>";
    }
  
  if($loginerror !=  "")
    {
    $xml .= "<message>".$loginerror."</message>";
    }     
    
  $xml .= "</cdash>";
  generate_XSLT($xml,"login");
}

// -------------------------------------------------------------------------------------- 
// main 
// -------------------------------------------------------------------------------------- 
$mysession = array ("login"=>FALSE, "passwd"=>FALSE, "ID"=>FALSE, "valid"=>FALSE, "langage"=>FALSE);  
$uri = basename($_SERVER['PHP_SELF']);  
$stamp = md5(srand(5));  
$session_OK = 0;

if(!auth(@$SessionCachePolicy) && !@$noforcelogin):                 // authentication failed 
  LoginForm($loginerror); // display login form 
  $session_OK=0;
else:                        // authentication was successful 
  $tmp = session_id();       // session is already started 
  $session_OK = 1;
endif;

// If we should use the local/prelogin.php
if(file_exists("local/prelogin.php"))
  {
  include("local/prelogin.php");
  }
?>
