<?php

# PHP IBAN - http://code.google.com/p/php-iban - LGPLv3

# Global flag by request
$__disable_iiban_gmp_extension=false;

# Verify an IBAN number.
#  If $machine_format_only, do not tolerate unclean (eg. spaces, dashes, leading 'IBAN ' or 'IIBAN ', lower case) input.
#  (Otherwise, input can be printed 'IIBAN xx xx xx...' or 'IBAN xx xx xx...' or machine 'xxxxx' format.)
#  Returns true or false.
function verify_iban($iban,$machine_format_only=false) {

 # First convert to machine format.
 if(!$machine_format_only) { $iban = iban_to_machine_format($iban); }

 # Get country of IBAN
 $country = iban_get_country_part($iban);

 # Test length of IBAN
 if(strlen($iban)!=iban_country_get_iban_length($country)) { return false; }

 # Get checksum of IBAN
 $checksum = iban_get_checksum_part($iban);

 # Get country-specific IBAN format regex
 $regex = '/'.iban_country_get_iban_format_regex($country).'/';

 # Check regex
 if(preg_match($regex,$iban)) {
  # Regex passed, check checksum
  if(!iban_verify_checksum($iban)) { 
   return false;
  }
 }
 else {
  return false;
 }

 # Otherwise it 'could' exist
 return true;
}

# Convert an IBAN to machine format.  To do this, we
# remove IBAN from the start, if present, and remove
# non basic roman letter / digit characters
function iban_to_machine_format($iban) {
 # Uppercase and trim spaces from left
 $iban = ltrim(strtoupper($iban));
 # Remove IIBAN or IBAN from start of string, if present
 $iban = preg_replace('/^I?IBAN/','',$iban);
 # Remove all non basic roman letter / digit characters
 $iban = preg_replace('/[^a-zA-Z0-9]/','',$iban);
 return $iban;
}

# Convert an IBAN to human format. To do this, we
# simply insert spaces right now, as per the ECBS
# (European Committee for Banking Standards) 
# recommendations available at:
# http://www.europeanpaymentscouncil.eu/knowledge_bank_download.cfm?file=ECBS%20standard%20implementation%20guidelines%20SIG203V3.2.pdf 
function iban_to_human_format($iban) {
 # First verify validity, or return
 if(!verify_iban($iban)) { return false; }
 # Remove all spaces
 $iban = str_replace(' ','',$iban);
 # Add spaces every four characters
 return wordwrap($iban,4,' ',true);
}

# Get the country part from an IBAN
function iban_get_country_part($iban) {
 $iban = iban_to_machine_format($iban);
 return substr($iban,0,2);
}

# Get the checksum part from an IBAN
function iban_get_checksum_part($iban) {
 $iban = iban_to_machine_format($iban);
 return substr($iban,2,2);
}

# Get the BBAN part from an IBAN
function iban_get_bban_part($iban) {
 $iban = iban_to_machine_format($iban);
 return substr($iban,4);
}

# Check the checksum of an IBAN - code modified from Validate_Finance PEAR class
function iban_verify_checksum($iban) {
 # convert to machine format
 $iban = iban_to_machine_format($iban);
 # move first 4 chars (countrycode and checksum) to the end of the string
 $tempiban = substr($iban, 4).substr($iban, 0, 4);
 # subsitutute chars
 $tempiban = iban_checksum_string_replace($tempiban);
 # mod97-10
 $result = iban_mod97_10($tempiban);
 # checkvalue of 1 indicates correct IBAN checksum
 if ($result != 1) {
  return false;
 }
 return true;
}

# Find the correct checksum for an IBAN
#  $iban  The IBAN whose checksum should be calculated
function iban_find_checksum($iban) {
 $iban = iban_to_machine_format($iban);
 # move first 4 chars to right
 $left = substr($iban,0,2) . '00'; # but set right-most 2 (checksum) to '00'
 $right = substr($iban,4);
 # glue back together
 $tmp = $right . $left;
 # convert letters using conversion table
 $tmp = iban_checksum_string_replace($tmp);
 # get mod97-10 output
 $checksum = iban_mod97_10_checksum($tmp);
 # return 98 minus the mod97-10 output, left zero padded to two digits
 return str_pad((98-$checksum),2,'0',STR_PAD_LEFT);
}

# Set the correct checksum for an IBAN
#  $iban  IBAN whose checksum should be set
function iban_set_checksum($iban) {
 $iban = iban_to_machine_format($iban);
 return substr($iban,0,2) . iban_find_checksum($iban) . substr($iban,4);
}

# Character substitution required for IBAN MOD97-10 checksum validation/generation
#  $s  Input string (IBAN)
function iban_checksum_string_replace($s) {
 $iban_replace_chars = range('A','Z');
 foreach (range(10,35) as $tempvalue) { $iban_replace_values[]=strval($tempvalue); }
 return str_replace($iban_replace_chars,$iban_replace_values,$s);
}

# Same as below but actually returns resulting checksum
function iban_mod97_10_checksum($numeric_representation) {
 $checksum = intval(substr($numeric_representation, 0, 1));
 for ($position = 1; $position < strlen($numeric_representation); $position++) {
  $checksum *= 10;
  $checksum += intval(substr($numeric_representation,$position,1));
  $checksum %= 97;
 }
 return $checksum;
}

# Perform MOD97-10 checksum calculation ('Germanic-level effiency' version - thanks Chris!)
function iban_mod97_10($numeric_representation) {
 global $__disable_iiban_gmp_extension;
 # prefer php5 gmp extension if available
 if(!($__disable_iiban_gmp_extension) && function_exists('gmp_intval')) { return gmp_intval(gmp_mod(gmp_init($numeric_representation, 10),'97')) === 1; }

/*
 # old manual processing (~16x slower)
 $checksum = intval(substr($numeric_representation, 0, 1));
 for ($position = 1; $position < strlen($numeric_representation); $position++) {
  $checksum *= 10;
  $checksum += intval(substr($numeric_representation,$position,1));
  $checksum %= 97;
 }
 return $checksum;
 */

 # new manual processing (~3x slower)
 $length = strlen($numeric_representation);
 $rest = "";
 $position = 0;
 while ($position < $length) {
        $value = 9-strlen($rest);
        $n = $rest . substr($numeric_representation,$position,$value);
        $rest = $n % 97;
        $position = $position + $value;
 }
 return ($rest === 1);
}

# Get an array of all the parts from an IBAN
function iban_get_parts($iban) {
 return array(
         'country'		=>      iban_get_country_part($iban),
 	 'checksum'		=>	iban_get_checksum_part($iban),
	 'bban'			=>	iban_get_bban_part($iban),
 	 'bank'			=>	iban_get_bank_part($iban),
	 'country'		=>	iban_get_country_part($iban),
	 'branch'		=>	iban_get_branch_part($iban),
	 'account'		=>	iban_get_account_part($iban),
	 'nationalchecksum'	=>	iban_get_nationalchecksum_part($iban)
        );
}

# Get the Bank ID (institution code) from an IBAN
function iban_get_bank_part($iban) {
 $iban = iban_to_machine_format($iban);
 $country = iban_get_country_part($iban);
 $start = iban_country_get_bankid_start_offset($country);
 $stop = iban_country_get_bankid_stop_offset($country);
 if($start!=''&&$stop!='') {
  $bban = iban_get_bban_part($iban);
  return substr($bban,$start,($stop-$start+1));
 }
 return '';
}

# Get the Branch ID (sort code) from an IBAN
function iban_get_branch_part($iban) {
 $iban = iban_to_machine_format($iban);
 $country = iban_get_country_part($iban);
 $start = iban_country_get_branchid_start_offset($country);
 $stop = iban_country_get_branchid_stop_offset($country);
 if($start!=''&&$stop!='') {
  $bban = iban_get_bban_part($iban);
  return substr($bban,$start,($stop-$start+1));
 }
 return '';
}

# Get the (branch-local) account ID from an IBAN
function iban_get_account_part($iban) {
 $iban = iban_to_machine_format($iban);
 $country = iban_get_country_part($iban);
 $start = iban_country_get_branchid_stop_offset($country);
 if($start=='') {
  $start = iban_country_get_bankid_stop_offset($country);
 }
 if($start!='') {
  $bban = iban_get_bban_part($iban);
  return substr($bban,$start+1);
 }
 return '';
}

# Get the national checksum part from an IBAN
function iban_get_nationalchecksum_part($iban) {
 $iban = iban_to_machine_format($iban);
 $country = iban_get_country_part($iban);
 $start = iban_country_get_nationalchecksum_start_offset($country);
 if($start == '') { return ''; }
 $stop = iban_country_get_nationalchecksum_stop_offset($country);
 if($stop == '') { return ''; }
 $bban = iban_get_bban_part($iban);
 return substr($bban,$start,($stop-$start+1));
}

# Get the name of an IBAN country
function iban_country_get_country_name($iban_country) {
 return _iban_country_get_info($iban_country,'country_name');
}

# Get the domestic example for an IBAN country
function iban_country_get_domestic_example($iban_country) {
 return _iban_country_get_info($iban_country,'domestic_example');
}

# Get the BBAN example for an IBAN country
function iban_country_get_bban_example($iban_country) {
 return _iban_country_get_info($iban_country,'bban_example');
}

# Get the BBAN format (in SWIFT format) for an IBAN country
function iban_country_get_bban_format_swift($iban_country) {
 return _iban_country_get_info($iban_country,'bban_format_swift');
}

# Get the BBAN format (as a regular expression) for an IBAN country
function iban_country_get_bban_format_regex($iban_country) {
 return _iban_country_get_info($iban_country,'bban_format_regex');
}

# Get the BBAN length for an IBAN country
function iban_country_get_bban_length($iban_country) {
 return _iban_country_get_info($iban_country,'bban_length');
}

# Get the IBAN example for an IBAN country
function iban_country_get_iban_example($iban_country) {
 return _iban_country_get_info($iban_country,'iban_example');
}

# Get the IBAN format (in SWIFT format) for an IBAN country
function iban_country_get_iban_format_swift($iban_country) {
 return _iban_country_get_info($iban_country,'iban_format_swift');
}

# Get the IBAN format (as a regular expression) for an IBAN country
function iban_country_get_iban_format_regex($iban_country) {
 return _iban_country_get_info($iban_country,'iban_format_regex');
}

# Get the IBAN length for an IBAN country
function iban_country_get_iban_length($iban_country) {
 return _iban_country_get_info($iban_country,'iban_length');
}

# Get the BBAN Bank ID start offset for an IBAN country
function iban_country_get_bankid_start_offset($iban_country) {
 return _iban_country_get_info($iban_country,'bban_bankid_start_offset');
}

# Get the BBAN Bank ID stop offset for an IBAN country
function iban_country_get_bankid_stop_offset($iban_country) {
 return _iban_country_get_info($iban_country,'bban_bankid_stop_offset');
}

# Get the BBAN Branch ID start offset for an IBAN country
function iban_country_get_branchid_start_offset($iban_country) {
 return _iban_country_get_info($iban_country,'bban_branchid_start_offset');
}

# Get the BBAN Branch ID stop offset for an IBAN country
function iban_country_get_branchid_stop_offset($iban_country) {
 return _iban_country_get_info($iban_country,'bban_branchid_stop_offset');
}

# Get the BBAN (national) checksum start offset for an IBAN country
#  Returns '' when (often) not present)
function iban_country_get_nationalchecksum_start_offset($iban_country) {
 return _iban_country_get_info($iban_country,'bban_checksum_start_offset');
}

# Get the BBAN (national) checksum stop offset for an IBAN country
#  Returns '' when (often) not present)
function iban_country_get_nationalchecksum_stop_offset($iban_country) {
 return _iban_country_get_info($iban_country,'bban_checksum_stop_offset');
}

# Get the registry edition for an IBAN country
function iban_country_get_registry_edition($iban_country) {
 return _iban_country_get_info($iban_country,'registry_edition');
}

# Is the IBAN country one official issued by SWIFT?
function iban_country_get_country_swift_official($iban_country) {
 return _iban_country_get_info($iban_country,'country_swift_official');
}

# Is the IBAN country a SEPA member?
function iban_country_is_sepa($iban_country) {
 return _iban_country_get_info($iban_country,'country_sepa');
}

# Get the IANA code of an IBAN country
function iban_country_get_iana($iban_country) {
 return _iban_country_get_info($iban_country,'country_iana');
}

# Get the ISO3166-1 alpha-2 code of an IBAN country
function iban_country_get_iso3166($iban_country) {
 return _iban_country_get_info($iban_country,'country_iso3166');
}

# Get the list of all IBAN countries
function iban_countries() {
 global $_iban_registry;
 return array_keys($_iban_registry);
}

# Given an incorrect IBAN, return an array of zero or more checksum-valid
# suggestions for what the user might have meant, based upon common
# mistranscriptions.
function iban_mistranscription_suggestions($incorrect_iban) {
 
 # abort on ridiculous length input (but be liberal)
 $length = strlen($incorrect_iban);
 if($length<5 || $length>34) { return array('(supplied iban length insane)'); }

 # abort if mistranscriptions data is unable to load
 if(!_iban_load_mistranscriptions()) { return array('(failed to load mistranscriptions)'); }

 # init
 global $_iban_mistranscriptions;
 $suggestions = array();

 # we have a string of approximately IBAN-like length.
 # ... now let's make suggestions.
 $numbers = array('0','1','2','3','4','5','6','7','8','9');
 for($i=0;$i<$length;$i++) {
  # get the character at this position
  $character = substr($incorrect_iban,$i,1);
  # for each known transcription error resulting in this character
  foreach($_iban_mistranscriptions[$character] as $possible_origin) {
   # if we're:
   #  - in the first 2 characters (country) and the possible replacement
   #    is a letter
   #  - in the 3rd or 4th characters (checksum) and the possible
   #    replacement is a number
   #  - later in the string
   if(($i<2 && !in_array($possible_origin,$numbers)) ||
      ($i>=2 && $i<=3 && in_array($possible_origin,$numbers)) ||
      $i>3) {
    # construct a possible IBAN using this possible origin for the
    # mistranscribed character, replaced at this position only
    $possible_iban = substr($incorrect_iban,0,$i) . $possible_origin .  substr($incorrect_iban,$i+1);
    # if the checksum passes, return it as a possibility
    if(verify_iban($possible_iban)) {
     array_push($suggestions,$possible_iban);
    }
   }
  }
 }

 # now we check for the type of mistransposition case where all of
 # the characters of a certain type within a string were mistransposed.
 #  - first generate a character frequency table
 $char_freqs = array();
 for($i=0;$i<strlen($incorrect_iban);$i++) {
  if(!isset($char_freqs[substr($incorrect_iban,$i,1)])) {
   $char_freqs[substr($incorrect_iban,$i,1)] = 1;
  }
  else {
   $char_freqs[substr($incorrect_iban,$i,1)]++;
  }
 }
 #  - now, for each of the characters in the string...
 foreach($char_freqs as $char=>$freq) {
  # if the character occurs more than once
  if($freq>1) {
   # check the 'all occurrences of <char> were mistranscribed' case
   foreach($_iban_mistranscriptions[$char] as $possible_origin) {
    $possible_iban = str_replace($char,$possible_origin,$incorrect_iban);
    if(verify_iban($possible_iban)) {
     array_push($suggestions,$possible_iban);
    }
   }
  }
 }

 return $suggestions;
}


##### internal use functions - safe to ignore ######

# Load the IBAN registry from disk.
global $_iban_registry;
$_iban_registry = array();
_iban_load_registry();
function _iban_load_registry() {
 global $_iban_registry;
 # if the registry is not yet loaded, or has been corrupted, reload
 if(!is_array($_iban_registry) || count($_iban_registry)<1) {
  $data = file_get_contents(dirname(__FILE__) . '/registry.txt');
  $lines = explode("\n",$data);
  array_shift($lines); # drop leading description line
  # loop through lines
  foreach($lines as $line) {
   if($line!='') {
    # split to fields
    $old_display_errors_value = ini_get('display_errors');
    ini_set('display_errors',false);
    $old_error_reporting_value = ini_get('error_reporting');
    ini_set('error_reporting',false);
    list($country,$country_name,$domestic_example,$bban_example,$bban_format_swift,$bban_format_regex,$bban_length,$iban_example,$iban_format_swift,$iban_format_regex,$iban_length,$bban_bankid_start_offset,$bban_bankid_stop_offset,$bban_branchid_start_offset,$bban_branchid_stop_offset,$registry_edition,$country_sepa,$country_swift_official,$bban_checksum_start_offset,$bban_checksum_stop_offset,$country_iana,$country_iso3166) = explode('|',$line);
    ini_set('display_errors',$old_display_errors_value);
    ini_set('error_reporting',$old_error_reporting_value);
    # assign to registry
    $_iban_registry[$country] = array(
                                'country'			=>	$country,
 				'country_name'			=>	$country_name,
				'country_sepa'			=>	$country_sepa,
 				'domestic_example'		=>	$domestic_example,
				'bban_example'			=>	$bban_example,
				'bban_format_swift'		=>	$bban_format_swift,
				'bban_format_regex'		=>	$bban_format_regex,
				'bban_length'			=>	$bban_length,
				'iban_example'			=>	$iban_example,
				'iban_format_swift'		=>	$iban_format_swift,
				'iban_format_regex'		=>	$iban_format_regex,
				'iban_length'			=>	$iban_length,
				'bban_bankid_start_offset'	=>	$bban_bankid_start_offset,
				'bban_bankid_stop_offset'	=>	$bban_bankid_stop_offset,
				'bban_branchid_start_offset'	=>	$bban_branchid_start_offset,
				'bban_branchid_stop_offset'	=>	$bban_branchid_stop_offset,
				'registry_edition'		=>	$registry_edition,
                                'country_swift_official'        =>      $country_swift_official,
				'bban_checksum_start_offset'	=>	$bban_checksum_start_offset,
				'bban_checksum_stop_offset'	=>	$bban_checksum_stop_offset,
				'country_iana'			=>	$country_iana,
				'country_iso3166'		=>	$country_iso3166
                               );
   }
  }
 }
}

# Get information from the IBAN registry by example IBAN / code combination
function _iban_get_info($iban,$code) {
 $country = iban_get_country_part($iban);
 return _iban_country_get_info($country,$code);
}

# Get information from the IBAN registry by country / code combination
function _iban_country_get_info($country,$code) {
 global $_iban_registry;
 $country = strtoupper($country);
 $code = strtolower($code);
 if(array_key_exists($country,$_iban_registry)) {
  if(array_key_exists($code,$_iban_registry[$country])) {
   return $_iban_registry[$country][$code];
  }
 }
 return false;
}

# Load common mistranscriptions from disk.
function _iban_load_mistranscriptions() {
 global $_iban_mistranscriptions;
 # do not reload if already present
 if(is_array($_iban_mistranscriptions) && count($_iban_mistranscriptions) == 36) { return true; }
 $_iban_mistranscriptions = array();
 $file = dirname(__FILE__) . '/mistranscriptions.txt';
 if(!file_exists($file) || !is_readable($file)) { return false; }
 $data = file_get_contents($file);
 $lines = explode("\n",$data);
 foreach($lines as $line) {
  # match lines with ' c-<x> = <something>' where x is a word-like character
  if(preg_match('/^ *c-(\w) = (.*?)$/',$line,$matches)) {
   # normalize the character to upper case
   $character = strtoupper($matches[1]);
   # break the possible origins list at '/', strip quotes & spaces
   $chars = explode(' ',str_replace('"','',preg_replace('/ *?\/ *?/','',$matches[2])));
   # assign as possible mistranscriptions for that character
   $_iban_mistranscriptions[$character] = $chars;
  }
 }
 return true;
}

# Find the correct national checksum for an IBAN
#  (Returns the correct national checksum as a string, or '' if unimplemented for this IBAN's country)
#  (NOTE: only works for some countries)
function iban_find_nationalchecksum($iban) {
 return _iban_nationalchecksum_implementation($iban,'find');
}

# Verify the correct national checksum for an IBAN
#  (Returns true or false, or '' if unimplemented for this IBAN's country)
#  (NOTE: only works for some countries)
function iban_verify_nationalchecksum($iban) {
 return _iban_nationalchecksum_implementation($iban,'verify');
}

# Verify the correct national checksum for an IBAN
#  (Returns the (possibly) corrected IBAN, or '' if unimplemented for this IBAN's country)
#  (NOTE: only works for some countries)
function iban_set_nationalchecksum($iban) {
 $result = _iban_nationalchecksum_implementation($iban,'set');
 if($result != '' ) {
  $result = iban_set_checksum($result); # recalculate IBAN-level checksum
 }
 return $result;
}

# Internal function to overwrite the national checksum portion of an IBAN
function _iban_nationalchecksum_set($iban,$nationalchecksum) {
 $country = iban_get_country_part($iban);
 $start = iban_country_get_nationalchecksum_start_offset($country);
 if($start == '') { return ''; }
 $stop = iban_country_get_nationalchecksum_stop_offset($country);
 if($stop == '') { return ''; }
 # determine the BBAN
 $bban = iban_get_bban_part($iban);
 # alter the BBAN
 $fixed_bban = substr($bban,0,$start) . $nationalchecksum . substr($bban,$stop+1);
 # reconstruct the fixed IBAN
 $fixed_iban = $country . iban_get_checksum_part($iban) . $fixed_bban;
 return $fixed_iban;
}

# Internal proxy function to access national checksum implementations
#  $iban = IBAN to work with (length and country must be valid, IBAN checksum and national checksum may be incorrect)
#  $mode = 'find', 'set', or 'verify'
#    - In 'find' mode, the correct national checksum for $iban is returned.
#    - In 'set' mode, a (possibly) modified version of $iban with the national checksum corrected is returned.
#    - In 'verify' mode, the checksum within $iban is compared to correctly calculated value, and true or false is returned.
#  If a national checksum algorithm does not exist or remains unimplemented for this country, or the supplied $iban or $mode is invalid, '' is returned.
#  (NOTE: We cannot collapse 'verify' mode and implement here via simple string comparison between 'find' mode output and the nationalchecksum part,
#         because some countries have systems which do not map to this approach, for example the Netherlands has no checksum part yet an algorithm exists)
function _iban_nationalchecksum_implementation($iban,$mode) {
 if($mode != 'set' && $mode != 'find' && $mode != 'verify') { return ''; } #  blank value on return to distinguish from correct execution
 $iban = iban_to_machine_format($iban);
 $country = iban_get_country_part($iban);
 if(strlen($iban)!=iban_country_get_iban_length($country)) { return ''; }
 $function_name = '_iban_nationalchecksum_implementation_' . strtolower($country);
 if(function_exists($function_name)) {
  return $function_name($iban,$mode);
 }
 return '';
}

# Implement the national checksum for a Belgium (BE) IBAN
#  (Credit: @gaetan-be)
function _iban_nationalchecksum_implementation_be($iban,$mode) {
 if($mode != 'set' && $mode != 'find' && $mode != 'verify') { return ''; } # blank value on return to distinguish from correct execution
 $nationalchecksum = iban_get_nationalchecksum_part($iban);
 $account = iban_get_account_part($iban);
 $account_less_checksum = substr($account,strlen($account)-2);
 $expected_nationalchecksum = bcmod($account_less_checksum, 97);
 if($mode=='find') {
  return $expected_nationalchecksum;
 }
 elseif($mode=='set') {
  return _iban_nationalchecksum_set($iban,$expected_nationalchecksum);
 }
 elseif($mode=='verify') {
  return ($nationalchecksum == $expected_nationalchecksum);
 }
}

# MOD11 helper function for the Spanish (ES) IBAN national checksum implementation
#  (Credit: @dem3trio, code lifted from Spanish Wikipedia at https://es.wikipedia.org/wiki/C%C3%B3digo_cuenta_cliente)
function _iban_nationalchecksum_implementation_es_mod11_helper($numero) {
 if(strlen($numero)!=10) return "?";
 $cifras = Array(1,2,4,8,5,10,9,7,3,6);
 $chequeo=0;
 for($i=0; $i<10; $i++) {
  $chequeo += substr($numero,$i,1) * $cifras[$i];
 }
 $chequeo = 11 - ($chequeo % 11);
 if ($chequeo == 11) $chequeo = 0;
 if ($chequeo == 10) $chequeo = 1;
 return $chequeo;
}

# Implement the national checksum for a Spanish (ES) IBAN
#  (Credit: @dem3trio, adapted from code on Spanish Wikipedia at https://es.wikipedia.org/wiki/C%C3%B3digo_cuenta_cliente)
function _iban_nationalchecksum_implementation_es($iban,$mode) {
 if($mode != 'set' && $mode != 'find' && $mode != 'verify') { return ''; } # blank value on return to distinguish from correct execution
 # extract appropriate substrings
 $bankprefix = iban_get_bank_part($iban) . iban_get_branch_part($iban);
 $nationalchecksum = iban_get_nationalchecksum_part($iban);
 $account = iban_get_account_part($iban);
 $account_less_checksum = substr($account,2);
 # first we calculate the initial checksum digit, which is MOD11 of the bank prefix with '00' prepended
 $expected_nationalchecksum  = _iban_nationalchecksum_implementation_es_mod11_helper("00".$bankprefix);
 # then we append the second digit, which is MOD11 of the account
 $expected_nationalchecksum .= _iban_nationalchecksum_implementation_es_mod11_helper($account_less_checksum);
 if($mode=='find') {
  return $expected_nationalchecksum;
 }
 elseif($mode=='set') {
  return _iban_nationalchecksum_set($iban,$expected_nationalchecksum);
 }
 elseif($mode=='verify') {
  return ($nationalchecksum == $expected_nationalchecksum);
 }
}

# Helper function for the France (FR) BBAN national checksum implementation
#  (Credit: @gaetan-be)
function _iban_nationalchecksum_implementation_fr_letters2numbers_helper($bban) {
 $allNumbers = "";
 $conversion = array(
                     "A" => 1, "B" => 2, "C" => 3, "D" => 4, "E" => 5, "F" => 6, "G" => 7, "H" => 8, "I" => 9, 
                     "J" => 1, "K" => 2, "L" => 3, "M" => 4, "N" => 5, "O" => 6, "P" => 7, "Q" => 8, "R" => 9, 
                     "S" => 2, "T" => 3, "U" => 4, "V" => 5, "W" => 6, "X" => 7, "Y" => 8, "Z" => 9
                    );
 for ($i=0; $i < strlen($bban); $i++) {
  if(is_numeric($bban{$i})) {
   $allNumbers .= $bban{$i};
  }
  else {
   $letter = strtoupper($bban{$i});
   if(array_key_exists($letter, $conversion)) {
    $allNumbers .= $conversion[$letter];
   }
   else {
    return null;
   }
  }
 }
 return $allNumbers;
}

# Implement the national checksum for a France (FR) IBAN
#  (Credit: @gaetan-be, http://www.credit-card.be/BankAccount/ValidationRules.htm#FR_Validation and 
#           https://docs.oracle.com/cd/E18727_01/doc.121/e13483/T359831T498954.htm)
function _iban_nationalchecksum_implementation_fr($iban,$mode) {
 if($mode != 'set' && $mode != 'find' && $mode != 'verify') { return ''; } # blank value on return to distinguish from correct execution
 # first, extract the BBAN
 $bban = iban_get_bban_part($iban);
 # convert to numeric form
 $bban_numeric_form = _iban_nationalchecksum_implementation_fr_letters2numbers_helper($bban);
 # if the result was null, something is horribly wrong
 if(is_null($bban_numeric_form)) { return ''; }
 # extract other parts
 $bank = substr($bban_numeric_form,0,5);
 $branch = substr($bban_numeric_form,5,5);
 $account = substr($bban_numeric_form,10,11);
 # actual implementation: mod97( (89 x bank number "Code banque") + (15 x branch code "Code guichet") + (3 x account number "Numéro de compte") )
 $sum = bcadd( bcmul("89", $bank) , bcmul("15", $branch));
 $sum = bcadd( $sum, bcmul("3", $account));
 $expected_nationalchecksum = bcsub("97", bcmod($sum, "97"));
 if(strlen($expected_nationalchecksum) == 1) { $expected_nationalchecksum = '0' . $expected_nationalchecksum; }
 # return
 if($mode=='find') {
  return $expected_nationalchecksum;
 }
 elseif($mode=='set') {
  return _iban_nationalchecksum_set($iban,$expected_nationalchecksum);
 }
 elseif($mode=='verify') {
  return (iban_get_nationalchecksum_part($iban) == $expected_nationalchecksum);
 }
}

?>
