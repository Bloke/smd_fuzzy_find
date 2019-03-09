<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_fuzzy_find';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.22';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'http://stefdawson.com';
$plugin['description'] = 'Offers alternative spellings and/or close-matching articles from search terms.';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '0';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

/** Uncomment me, if you need a textpack
$plugin['textpack'] = <<< EOT
#@admin
#@language en-gb
abc_sample_string => Sample String
abc_one_more => One more
#@language de-de
abc_sample_string => Beispieltext
abc_one_more => Noch einer
EOT;
**/
// End of textpack

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
require_plugin('smd_lib');

// MLP support
global $smd_fuzzLang;
$smd_fuzz_str = array(
	'too_short' => 'The text you are searching for is probably too short. Try a longer word. ',
	'no_match' => 'Sorry, no results matched "{search_term}" exactly. ',
	'suggest' => 'Here are the closest matching {thingies} that may help you find what you are looking for: ',
	'suggest_join' => 'and',
	'articles' => 'articles',
	'words' => 'words',
);
$smd_fuzzLang = new smd_MLP('smd_fuzzy_find', 'smd_fuzz', $smd_fuzz_str);

function smd_fuzzy_find($atts, $thing='') {
	global $pretext, $smd_fuzzLang, $prefs;

	extract(lAtts(array(
		'form' => 'search_results',
		'section' => '',
		'category' => '',
		'sublevel' => '0',
		'search_term' => '?q',
		'match_with' => '',
		'tolerance' => '2',
		'min_word_length' => '4',
		'delim' => ',',
		'limit' => '',
		'case_sensitive' => '0',
		'refine' => '',
		'show' => '',
		'status' => '',
		'debug' => '0',
		'no_match_label' => '#',
		'suggest_label' => '#',
		'too_short_label' => '#',
		'labeltag' => 'p',
	), $atts));

	// Process defaults; most can't be set in lAtts() because of the custom delimiter
	$match_with = empty($match_with) ? "article:" . implode(";", do_list($prefs['searchable_article_fields'])) : $match_with;
	$limit = ($limit) ? $limit : "words:5" .$delim. "articles:10";
	$refine = ($refine) ? $refine : "metaphone" .$delim. "soundex";
	$show = ($show) ? $show : "words" .$delim. "articles";
	$status = ($status) ? $status : "live" .$delim. "sticky";

	$refineAllow = array("metaphone", "soundex");
	$showAllow = array("articles", "words");
	$colNames = array('Keywords' => "article:keywords",
							'Body' => "article:body",
							'Excerpt' => "article:excerpt",
							'Category1' => "article:category1",
							'Category2' => "article:category2",
							'Section' => "article:section",
							'ID' => "article:id",
							'AuthorID' => "article:authorid",
							'Title' => "article:title",
							'message' => "comments:message",
							'email' => "comments:email",
							'name' => "comments:name",
							'web' => "comments:web",
							);

	$places = array('textpattern' => "article", 'txp_discuss' => "comments");
	$clause = array();
	$refineList = array();
	$dbTables = array();
	$dbFields = array();

	// Expand the args in case they're ? or ! shortcuts, and do some validity checking.
	$search_term = smd_doList($search_term, false, "", false, $delim);
	$search_term = $search_term[0][0];
	if ($debug > 1) {
		echo "++ SEARCH TERM ++";
		dmp($search_term);
	}
	$refine = do_list($refine, $delim);
	for ($idx = 0; $idx < count($refine); $idx++) {
		if (in_array($refine[$idx], $refineAllow)) {
			$refineList[$idx] = $refine[$idx];
		}
	}
	$meta_search = (in_array("metaphone", $refineList)) ? metaphone($search_term) : "";
	$sound_search = (in_array("soundex", $refineList)) ? soundex($search_term) : "";
	$tolerance = intval($tolerance);

	// match_with needs to be built into a series of arrays of database tables and columns
	$lookin = smd_split($match_with, false, ":,\s");
	// Loop over pairs of elements
	for ($idx = 0; $idx < count($lookin); $idx+=2) {
		if (($tmp = array_search($lookin[$idx], $places)) !== false) {
			$dbTables[] = $tmp;
			$dbFieldList = smd_split($lookin[$idx+1], false, ";");
			$dbField = array();
			foreach ($dbFieldList as $lookField) {
				$key = array_search($lookin[$idx].":".strtolower($lookField), $colNames);
				if ($key) {
					$dbField[] = $key;
				} else if (strpos($lookField, "custom_") === 0) {
					$dbField[] = $lookField;
				}
			}
			if (count($dbField) > 0) {
				$dbFields[] = $dbField;
			}
		}
	}

	if (count($dbTables) == 0 || count($dbFields) == 0) {
		$dbTables[] = "textpattern";
		$dbFields[] = "*";
	}
	if ($debug) {
		echo "++ FIELDS ++";
		dmp($dbFields);
	}

	$showList = do_list($show, $delim);
	for ($idx = count($showList); $idx > 0; $idx--) {
		if (!in_array($showList[$idx-1], $showAllow)) {
			unset($showList[$idx]);
		}
	}
	$limitBy = array();
	$limit = do_list($limit, $delim);
	foreach ($limit as $limOption) {
		if (is_numeric($limOption)) {

			$limitBy["articles"] = $limOption;
			$limitBy["words"] = $limOption;
		} else {
			$limsplit = smd_split($limOption, false, ":");
			if ((count($limsplit) == 2) && (in_array($limsplit[0], $showAllow)) && (is_numeric($limsplit[1]))) {
				$limitBy[$limsplit[0]] = $limsplit[1];
			}
		}
	}
	$thingiesL10n = array();
	foreach ($showList as $item) {
		$thingiesL10n[] = $smd_fuzzLang->gTxt($item);
	}
	$thingies = implode(" ".$smd_fuzzLang->gTxt('suggest_join')." ", $thingiesL10n);
	$no_match_label = ($no_match_label == "#") ? $smd_fuzzLang->gTxt('no_match', array("{search_term}" => $search_term)) : $no_match_label;
	$suggest_label = ($suggest_label == "#") ? $smd_fuzzLang->gTxt('suggest', array("{thingies}" => $thingies)) : $suggest_label;
	$too_short_label = ($too_short_label == "#") ? $smd_fuzzLang->gTxt('too_short') : $too_short_label;

	// Roll any status, section and category filters into the initial query
	$clause[] = '1=1';

	if (in_array("textpattern", $dbTables)) {
		// Status
		list($statinc, $statexc) = smd_doList($status, false, '', false, $delim);
		for ($idx = 0; $idx < count($statinc); $idx++) {
			$tmpa[] = doQuote(getStatusNum($statinc[$idx]));
		}
		if ($tmpa) {
			$clause[] = 'Status IN (' .implode(",", $tmpa). ')';
		}

		// Section
		list($secinc, $secexc) = smd_doList($section, false, '', true, $delim);
		if ($secinc) {
			$clause[] = '( Section IN (' .implode(",", $secinc). ') )';
		}

		// Category
		list($catinc, $catexc) = smd_doList($category, false, "article:".$sublevel, true, $delim);
		if ($catinc) {
			$imp = implode(",", $catinc);
			$clause[] = '( Category1 IN (' .$imp. ') OR Category2 IN (' .$imp. ') )';
		}

		// Combine the query portions
		$clause = implode(" AND ", $clause);

		// Add on any exclusions
		$tmpa = array();
		for ($idx = 0; $idx < count($statexc); $idx++) {
			$tmpa[] = doQuote(getStatusNum($statexc[$idx]));
		}
		$clause .= ($tmpa) ? ' AND Status NOT IN ('.implode(",", $tmpa).')' : '';

		$imp = implode(",", $secexc);
		$clause .= ($secexc) ? ' AND Section NOT IN ('.$imp.')' : '';
	
		$imp = implode(",", $catexc);
		$clause .= ($catexc) ? ' AND Category1 NOT IN ('.$imp.') AND Category2 NOT IN ('.$imp.')' : '';
	}

	$clause = is_array($clause) ? join(" ", $clause) : $clause;
	//TODO: comments
/*
	if (in_array("txp_discuss",$dbTables)) {
		$clause .= " AND textpattern.ID = txp_discuss.parentid";
	}
*/

	if ($debug > 0) {
		echo "++ WHERE CLAUSE ++";
		dmp($clause);
	}

	$out = "";
	// Perform the searches
	$finder = new smd_FuzzyFind($search_term, $tolerance);
	if ($finder->too_short_err) {
		$out .= ($labeltag == "") ? "" : "<" .$labeltag.">";
		$out .= $no_match_label;
		$out .= $too_short_label;
		$out .= ($labeltag == "") ? "" : "</" .$labeltag.">";
	} else {
		$cols = "*" . ((in_array("textpattern", $dbTables)) ? ", unix_timestamp(textpattern.Posted) AS uPosted, unix_timestamp(textpattern.LastMod) AS uLastMod, unix_timestamp(textpattern.Expires) AS uExpires" : "");
		$rs = safe_rows_start($cols, implode($dbTables, ", "), $clause, $debug);
		if (in_array("textpattern",$dbTables)) {
			$opform = ($thing) ? $thing : fetch_form($form);
		}
		$pageurl = smd_removeQSVar($pretext['request_uri'],'q');
		$allFields = "";
		$artList = array();
		$wordList = array();
		$termList = array();
		while($row = nextRow($rs)) {
			$allFields = "";
			// Join all the required places to look into a looong text block
			if ($dbFields[0] == "*") {
				foreach ($row as $colname) {
					$allFields .= $colname." ";
				}
			} else {
				foreach ($dbFields as $fieldRow) {
					foreach ($fieldRow as $theField) {
						$allFields .= $row[$theField]." ";
					}
				}
			}

			// Remove between-word unicode punctuation and replace with space
			$allFields = strip_tags($allFields);
			$allFields = trim(preg_replace('#[\p{P}]+#u', ' ', $allFields));

			// Split the remainder by (single or multiple) spaces
			$werds = preg_split('/\s+/', $allFields, -1, PREG_SPLIT_NO_EMPTY);
			// ...and reconstitute the unique words as a huge space-delimited string
			$werds = implode(" ",array_unique($werds));
			// Take into account case sensitivity
			$werds = ($case_sensitive) ? $werds : strtolower($werds);

			// Find close word matches
			$matches = $finder->search($werds);
			if ($debug > 1) {
				if ($debug > 3 || $matches) {
					echo "++ UNIQUE WORDS ++";
					dmp($werds);
				}
				if ($matches) {
					echo "++ CLOSEST MATCHES ++";
					dmp($matches);
				}
			}

			if (count($matches) > 0) {
				$shortestDist = 100; // A stupidly high number to start with
				$shortestMetaDist = -1;
				$closestWord = "";
				$closestMetaWord = "";
				$max_term_len = 0;
				// Build a unique array of closest matching words
				while(list($idx,$dist) = each($matches)) {
					$term = smd_getWord($werds,$search_term,$idx);

					// Only words meeting the minimum requirement need apply
					$max_term_len = (strlen($term) > $max_term_len) ? strlen($term) : $max_term_len;
					if (strlen($term) < $min_word_length) {
						continue;
					}
					$term = ($case_sensitive) ? $term : strtolower($term);
					if ($debug > 2) {
						echo $term.br;
					}
					if ($dist < $shortestDist) {
						$shortestDist = $dist;
						$closestWord = $term;
					}
					if ($meta_search != "") {
						$meta_term = metaphone($term);
						if ($debug > 2) {
							echo $meta_term . " : " . $meta_search ." ".br;
						}
						$levDist = levenshtein($meta_search, $meta_term);
						if ($levDist <= $shortestMetaDist || $shortestMetaDist < 0) {
							$shortestMetaDist = $levDist;
							$closestMetaWord  = $term;
						}
					}
				}
				// Pick the one that sounds closest to the original
				if (trim($closestWord) != "") {
					$idx = md5($closestWord);
					$bestFit = $closestWord;
					$bestDist = $shortestDist;
					if ($sound_search != "") {
						$sound1 = levenshtein(soundex($closestWord), $sound_search);
						$sound2 = levenshtein(soundex($closestMetaWord), $sound_search);
						if ($sound1 >= $sound2) {
							$idx = md5($closestMetaWord);
							$bestFit = $closestMetaWord;
							$bestDist = $shortestMetaDist;
						}
					}
					$wordList[$idx] = $bestFit;
					$wordDist[$idx] = $bestDist;

					if ($debug > 2) {
						echo "++ BEST FIT ++";
						dmp($bestFit);
					}
				}

				// Build an array of unique matching articles
				if ($max_term_len >= $min_word_length) {
					if (in_array("textpattern", $dbTables)) {
						populateArticleData($row);
					}
					// Temporarily assign the closest match to the query string so that
					// the search_result_excerpt can hilight the found words
					$pretext['q'] = $term;
					$artList[] = parse($opform);
					$pretext['q'] = $search_term;
				}
			}
		}
		// Sort the word list in order of relevance
		if (count($wordList) > 0) {
			array_multisort($wordDist,$wordList);
		}

		// Output stuff to the page
		$out .= ($labeltag == "") ? "" : "<" .$labeltag.">";
		$out .= $no_match_label;
		if (count($wordList) > 0) {
			$out .= (count($showList) > 0) ? $suggest_label : "";

			if (in_array("words", $showList)) {
				$ctr = 0;
				foreach ($wordList as $item) {
					if (array_key_exists("words", $limitBy) && $ctr >= $limitBy["words"]) {
						break;
					}
					$out .= '<a class="smd_fuzzy_suggest" href="'.smd_addQSVar($pageurl,'q',$item).'">'.$item.'</a>'.n;
					$ctr++;
				}
			}
			$out .= ($labeltag == "") ? "" : "</" .$labeltag.">";
			if (in_array("articles", $showList)) {
				$ctr = 0;
				foreach ($artList as $art) {
					if (array_key_exists("articles", $limitBy) && $ctr >= $limitBy["articles"]) {
						break;
					}
					$out .= $art;
					$ctr++;
				}
			}
		}
	}
	return $out;
}


/* smd_FuzzyFind
A PHP class for approximate string searching of large text masses, adapted (*cough* borrowed) from http://elonen.iki.fi/code/misc-notes/appr-search-php/. Instantiate one of these and pass it the string pattern/word you are looking for and a number indicating how close that match has to be/minimum length of strings to consider (i.e. the amount of error tolerable). 0=close match/short words; 10=pretty much every long (10 char minimum) string in the world. Practical values are usually 1 or 2, sometimes 3.

Usage example:
  $finder = new smd_FuzzyFind($patt, $max_err);
  if ($finder->too_short_err)
    $error = "Unable to search: use longer pattern or reduce error tolerance.";

  while($text = get_next_page_of_text()) {
    $matches = $finder->search($text);
    while(list($idx,$rng) = each($matches))
      print "Match found ending at position $idx with a closeness of $val\n";
  }

The code uses initial filtering to sort out possible match candidates and then applies a slower character-by-character search (search_short()) against them.
*/

if(!class_exists('smd_FuzzyFind')) {
class smd_FuzzyFind {
	// The last 3 parameters are for optimization only, to avoid the
	// surprisingly slow strlen() and substr() calls:
	//  - $start_index = from which character of $text to start the search
	//  - $max_len = maximum character to search (starting from $start_index)
	//  - $text_strlen =
	// The return value is an array of matches:
	//   Array( [<match-end-index>] => <error>, ... )
	// Note: <error> is generally NOT an exact edit distance but rather a
	// lower bound. This is unfortunate but the routine would be slower if
	// the exact error was calculate along with the matches.
	// The function is based on the non-deterministic automaton simulation
	// algorithm (without bit parallelism optimizations).
	function search_short($patt, $k, $text, $start_index=0, $max_len=-1, $text_strlen=-1) {
		if ( $text_strlen < 0 )
			$text_strlen = strlen( $text );

		if ( $max_len < 0 )
			$max_len = $text_strlen;

		$start_index = max( 0, $start_index );
		$n = min( $max_len, $text_strlen-$start_index );
		$m = strlen( $patt );
		$end_index = $start_index + $n;

		// If $text is shorter than $patt, use the built-in
		// levenshtein() instead:
		if ($n < $m)
		{
			$lev = levenshtein(substr($text, $start_index, $n), $patt);
			if ( $lev <= $k )
				return Array( $start_index+$n-1 => $lev );
			else
				return Array();
		}

		$s = Array();
		for ($i=0; $i<$m; $i++)
		{
			$c = $patt{$i};
			if ( isset($s[$c]))
				$s[$c] = min($i, $s[$c]);
			else
				$s[$c] = $i;
		}

		if ( $end_index < $start_index )
			return Array();

		$matches = Array();
		$da = $db = range(0, $m-$k+1);

		$mk = $m-$k;

		for ($t=$start_index; $t<$end_index; $t++)
		{
			$c = $text{$t};
			$in_patt = isset($s[$c]);

			if ($t&1) { $d=&$da; $e=&$db; }
			else { $d=&$db; $e=&$da; }

			for ($i=1; $i<=$mk; $i++)
			{
				$g = min( $k+1, $e[$i]+1, $e[$i+1]+1 );

				// TODO: optimize this with a look-up-table?
				if ( $in_patt )
					for ($j=$e[$i-1]; ($j<$g && $j<=$mk); $j++)
						if ( $patt{$i+$j-1} == $c )
							$g = $j;

				$d[$i] = $g;
			}

			if ( $d[$mk] <= $k )
			{
				$err = $d[$mk];
				$i = min( $t-$err+$k+1, $start_index+$n-1);
				if ( !isset($matches[$i]) || $err < $matches[$i])
					$matches[$i] = $err;
			}
		}

		unset( $da, $db );
		return $matches;
	}
	function test_short_search() {
		$test_text = "Olipa kerran jussi bj&xling ja kolme\n iloista ".
			"jussi bforling:ia mutta ei yhtaan jussi bjorling-nimista laulajaa.";
		$test_patt = "jussi bjorling";
		assert( $this->search_short($test_patt, 4, $test_text) == Array(27=>2, 60=>1, 94=>0));
		assert( $this->search_short($test_patt, 2, $test_text) == Array(27=>2, 60=>1, 94=>0));
		assert( $this->search_short($test_patt, 1, $test_text) == Array(60=>1, 94=>0));
		assert( $this->search_short($test_patt, 0, $test_text) == Array(94=>0));
		assert( $this->search_short("bjorling", 2, $test_text, 19, 7) == Array());
		assert( $this->search_short("bjorling", 2, $test_text, 19, 8) == Array(26=>2));
		assert( $this->search_short("bjorling", 2, $test_text, 20, 8) == Array());
	}

	var $patt, $patt_len, $max_err;
	var $parts, $n_parts, $unique_parts, $max_part_len;
	var $transf_patt;
	var $too_short_err;
	function smd_FuzzyFind( $pattern, $max_error ) {
		$this->patt = $pattern;
		$this->patt_len = strlen($this->patt);
		$this->max_err = $max_error;

		// Calculate pattern partition size
		$intpartlen = floor($this->patt_len/($this->max_err+2));
		if ($intpartlen < 1)
		{
			$this->too_short_err = True;
			return;
		}
		else $this->too_short_err = False;

		// Partition the pattern for pruning
		$this->parts = Array();
		for ($i=0; $i<$this->patt_len; $i+=$intpartlen)
		{
			if ( $i + $intpartlen*2 > $this->patt_len )
			{
				$this->parts[] = substr( $this->patt, $i );
				break;
			}
			else
				$this->parts[] = substr( $this->patt, $i, $intpartlen );
		}
		$this->n_parts = count($this->parts);

		// The intpartlen test above should have covered this:
		assert( $this->n_parts >= $this->max_err+1 );

		// Find maximum part length
		foreach( $this->parts as $p )
			$this->max_part_len = max( $this->max_part_len, strlen($p));

		// Make a new part array with duplicate strings removed
		$this->unique_parts = array_unique($this->parts);

		// Transform the pattern into a low resolution pruning string
		// by replacing parts with single characters
		$this->transf_patt = "";
		reset( $this->parts );
		while (list(,$p) = each($this->parts))
		   $this->transf_patt .= chr(array_search($p, $this->unique_parts)+ord("A"));

		// Self diagnostics
		$this->test_short_search();
	}
	function search( $text ) {
		// Find all occurences of unique parts in the
		// full text. The result is an array:
		//   Array( <index> => <part#>, .. )
		$part_map = Array();
		reset( $this->unique_parts );
		while (list($pi, $part_str) = each($this->unique_parts))
		{
			$pos = strpos($text, $part_str);
			while ( $pos !== False )
			{
				$part_map[$pos] = $pi;
				$pos = strpos($text, $part_str, $pos+1);
			}
		}
		ksort( $part_map ); // Sort by string index

		// The following code does several things simultaneously:
		//  1) Divide the indices into groups using gaps
		//	  larger than $this->max_err as boundaries.
		//  2) Translate the groups into strings so that
		//	  part# 0 = 'A', part# 1 = 'B' etc. to make
		//	  a low resolution approximate search possible later
		//  3) Save the string indices in the full string
		//	  that correspond to characters in the translated string.
		//  4) Discard groups (=part sequences) that are too
		//	  short to contain the approximate pattern.
		// The format of resulting array:
		//   Array(
		//	  Array( "<translate-string>",
		//			 Array( <translated-idx> => <full-index>, ... ) ),
		//	  ... )
		$transf = Array();
		$transf_text = "";
		$transf_pos = Array();
		$last_end = 0;
		$group_len = 0;
		reset( $part_map );
		while (list($i,$p) = each($part_map))
		{
			if ( $i-$last_end > $this->max_part_len+$this->max_err )
			{
				if ( $group_len >= ($this->n_parts-$this->max_err))
					$transf[] = Array( $transf_text, $transf_pos );

				$transf_text = "";
				$transf_pos = Array();
				$group_len = 0;
			}

			$transf_text .= chr($p + ord("A"));
			$transf_pos[] = $i;
			$group_len++;
			$last_end = $i + strlen($this->parts[$p]);
		}
		if ( strlen( $transf_text ) >= ($this->n_parts-$this->max_err))
			$transf[] = Array( $transf_text, $transf_pos );

		unset( $transf_text, $transf_pos );

		if ( current($transf) === False )
			return Array();

		// Filter the remaining groups ("approximate anagrams"
		// of the pattern) and leave only the ones that have enough
		// parts in correct order. You can think of this last step of the
		// algorithm as a *low resolution* approximate string search.
		// The result is an array of candidate text spans to be scanned:
		//   Array( Array(<full-start-idx>, <full-end-idx>), ... )
		$part_positions = Array();
		while (list(,list($str, $pos_map)) = each($transf))
		{
//			print "|$transf_patt| - |$str|\n";
			$lores_matches = $this->search_short( $this->transf_patt, $this->max_err, $str );
			while (list($tr_end, ) = each($lores_matches))
			{
				$tr_start = max(0, $tr_end - $this->n_parts);
				if ( $tr_end >= $tr_start )
				{
					$median_pos = $pos_map[ (int)(($tr_start+$tr_end)/2) ];
					$start = $median_pos - ($this->patt_len/2+1) - $this->max_err - $this->max_part_len;
					$end = $median_pos + ($this->patt_len/2+1) + $this->max_err + $this->max_part_len;

//					print "#" . strtr(substr( $text, $start, $end-$start ), "\n\r", "$$") . "#\n";
//					print_r( $this->search_short( &$this->patt, $this->max_err, &$text, $start, $end-$start ));

					$part_positions[] = Array($start, $end);
				}
			}
			unset( $lores_matches );
		}
		unset( $transf );

		if ( current($part_positions) === False )
			return Array();

		// Scan the final candidates and put the matches in a new array:
		$matches = Array();
		$text_len = strlen($text);
		while (list(, list($start, $end)) = each($part_positions))
		{
			$m = $this->search_short( $this->patt, $this->max_err, $text, $start, $end-$start, $text_len );
			while (list($i, $cost) = each($m))
				$matches[$i] = $cost;
		}
		unset($part_positions);

		return $matches;
	}
}
}

/* smd_getWord

Takes a string and an offset into that string and returns the nearest "word" before that offset position.
If the offset is not supplied it starts from the beginning of the string, thus returning the first word.

Takes 3 args:
# [*] The (usually looong) space-delimited string to look in
# [*] The word to look for
# The offset into the string at which to start looking
*/

if (!function_exists("smd_getWord")) {
function smd_getWord($haystack,$searchterm,$offset=0) {
	$numwords = str_word_count($searchterm);

	$len = strlen($haystack);

	// If we're mid-word, find its end
	$idx = $offset-1;
	while ($idx < $len && $haystack[$idx] != " ") {
		$idx++;
	}
	$offset = $idx;

	// Move the word we want to the start
	$haystack = trim(strrev(substr($haystack,0,$offset)));

	// Make sure to return the correct number of words
	$spacePos = false;
	for ($idx = 0; $idx < $numwords; $idx++) {
		$spacePos = (strpos($haystack, " ", $spacePos));
		if ($spacePos !== false) {
			$spacePos += 1;
		}
	}

	return trim(strrev((($spacePos === false) ? $haystack : substr($haystack, 0, $spacePos))));
}
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN CSS ---
<style type="text/css">
#smd_help { line-height:1.3 ;}
#smd_help code { font-weight:bold; font: 105%/130% "Courier New", courier, monospace; background-color: #FFFFCC;}
#smd_help code.block { font-weight:normal; border:1px dotted #999; background-color: #f0e68c; display:block; margin:10px 10px 20px; padding:10px; }
#smd_help a:link, #smd_help a:visited { color: blue; text-decoration: none; border-bottom: 1px solid blue; padding-bottom:1px;}
#smd_help a:hover, #smd_help a:active { color: blue; text-decoration: none; border-bottom: 2px solid blue; padding-bottom:1px;}
#smd_help h1 { color: #369; font: 20px Georgia, sans-serif; margin: 0; text-align: center; }
#smd_help h2 { border-bottom: 1px solid black; padding:10px 0 0; color: #369; font: 17px Georgia, sans-serif; }
#smd_help h3 { color: #693; font: bold 12px Arial, sans-serif; letter-spacing: 1px; margin: 10px 0 0;text-transform: uppercase;}
#smd_help h4 { font: bold 11px Arial, sans-serif; letter-spacing: 1px; margin: 10px 0 0 ;text-transform: uppercase; }
#smd_help .atnm { font-weight:bold; }
#smd_help .required {color:red;}
#smd_help table {width:100%; text-align:center; padding-bottom:1em;}
#smd_help td, #smd_help th {vertical-align:top; border:2px ridge #ccc; padding:.4em; }
</style>
# --- END PLUGIN CSS ---
-->
<!--
# --- BEGIN PLUGIN HELP ---
<div id="smd_help">

	<h1>smd_fuzzy_find</h1>

	<p>Ever wanted <span class="caps">TXP</span>&#8217;s search facility to be a little more, well, inaccurate? Help people with fat fingers to find what they were actually looking for with this tool that can find mis-spelled or like-sounding words from search results.</p>

	<p>Results are ordered by approximate relevancy and it&#8217;s automatically context-sensitive because the pool of words it compares against are from your site. On a zoo website, someone typing in &#8220;lino&#8221; won&#8217;t get articles about flooring but more likely articles about lions, which is what they really wanted. We hope.</p>

	<h2>Features</h2>

	<ul>
		<li>Search for similarly spelled, or similar sounding words (may be switched on/off)</li>
		<li>Limit the search to article <code>status</code>, <code>section</code> or <code>category</code> to speed up proceedings</li>
		<li>Tweak sensitivity to give better results (more specific, less likely to find a match) or general results (less specific, may match stuff you don&#8217;t expect)</li>
		<li>Can offer links to exact search terms if you wish (a bit like Google&#8217;s &#8220;Did you mean &#8230;&#8221;)</li>
		<li>Display matching articles using a form/container. Default is the built-in <code>search_results</code> form</li>
		<li>Unless overridden using the <code>match_with</code> attribute, the Title and Body will be searched. Alternatively, if you have set the search locations using <a href="http://forum.textpattern.com/viewtopic.php?id=29036">wet_haystack</a> then those places will be searched instead</li>
	</ul>

	<h2>Author</h2>

	<p><a href="http://stefdawson.com/commentForm">Stef Dawson</a>, with notable mention to <a href="http://elonen.iki.fi/code/misc-notes/appr-search-php/index.html">Jarno Elonen</a> for the Fuzzy Find algorithm which &#8212; to this day &#8212; remains mere voodoo to me.</p>

	<h2>Installation / Uninstallation</h2>

	<p class="required">Requires Textpattern 4.0.7 and <a href="http://stefdawson.com/downloads/smd_lib_v0.33.txt">smd_lib v0.33</a> must be installed and activated.</p>

	<p>Download the plugin from either <a href="http://textpattern.org/plugins/932/smd_fuzzy_find">textpattern.org</a>, or the <a href="http://stefdawson.com/sw">software page</a>, paste the code into the <span class="caps">TXP</span> Admin -&gt; Plugins pane, install and enable the plugin. Visit the <a href="http://forum.textpattern.com/viewtopic.php?id=25367">forum thread</a> for more info or to report on the success or otherwise of the plugin.</p>

	<p>To uninstall, simply delete from the Admin -&gt; Plugins page.</p>

	<h2>Usage</h2>

	<p>The plugin is <strong>not</strong> a replacement for the built-in <span class="caps">TXP</span> search; it should be used to augment it, like this:</p>

<pre class="block"><code class="block">&lt;txp:if_search&gt;
  &lt;dl class=&quot;results&quot;&gt;
    &lt;txp:chh_if_data&gt;
      &lt;txp:article limit=&quot;8&quot; searchform=&quot;excerpts&quot; /&gt;
    &lt;txp:else /&gt;
      &lt;txp:smd_fuzzy_find form=&quot;excerpts&quot; /&gt;
    &lt;/txp:chh_if_data&gt;
  &lt;/dl&gt;
&lt;/txp:if_search&gt;
</code></pre>

	<p>Exact matches will be processed as normal but mismatches will be handled by smd_fuzzy_find. If you try to use smd_fuzzy_find on its own, you will likely receive a warning about a missing <code>&lt;txp:article /&gt;</code> tag.</p>

	<h3 class="tag">Attributes</h3>

	<table>
		<tr>
			<th>Attribute name </th>
			<th>Default </th>
			<th>Values </th>
			<th>Description </th>
		</tr>
		<tr>
			<td> search_term </td>
			<td> <code>?q</code> </td>
			<td> <code>?q</code> or any text </td>
			<td> You may use a fixed string here but it&#8217;s rather pointless </td>
		</tr>
		<tr>
			<td> match_with </td>
			<td> <code>article:body;title</code> or wet_haystack criteria </td>
			<td> <code>keywords</code> / <code>body</code> / <code>excerpt</code> / <code>category1</code> / <code>category2</code> / <code>section</code> / <code>id</code> / <code>authorid</code> / <code>title</code> / <code>custom_1</code> / etc </td>
			<td> Which article fields you would like to match. Define the object you want to look in (currently only <code>article</code> is supported) followed by a colon, then a list of semi-colon separated places to look </td>
		</tr>
		<tr>
			<td> show </td>
			<td> <code>words, articles</code> </td>
			<td>  <code>words, articles</code> / <code>words</code> / <code>articles</code> </td>
			<td> Whether to list the closest matching articles, the closest matching search terms, or both </td>
		</tr>
		<tr>
			<td> section </td>
			<td> <em>unset</em> (i.e. search the whole site) </td>
			<td> any valid section containing articles </td>
			<td> Limit the search to one or more sections; give a comma-separated list. You can use <code>?s</code> for the current section or <code>!s</code> for anything except the current section, or you can read the value from another part of the article, a custom field / txp:variable / url variable </td>
		</tr>
		<tr>
			<td> category </td>
			<td> <em>unset</em> (i.e. search all categories) </td>
			<td> any valid article category </td>
			<td> Limit the search to one or more categories; give a comma-separated list. You can use <code>?c</code> for the current global category or <code>!c</code> for all cats except the current one. Like <code>section</code> you can read from other places too </td>
		</tr>
		<tr>
			<td> sublevel (formerly <em>subcats</em>)</td>
			<td> <code>0</code> </td>
			<td> integer / <code>all</code> </td>
			<td> Number of subcategory levels to traverse. <code>0</code> = top-level only; <code>1</code> = top level + one sub-level; and so on </td>
		</tr>
		<tr>
			<td> status </td>
			<td> <code>live, sticky</code> </td>
			<td> <code>live</code> / <code>sticky</code> / <code>hidden</code> / <code>pending</code> / <code>draft</code> or any combination </td>
			<td> Restricts the search to particular types of document status </td>
		</tr>
		<tr>
			<td> tolerance </td>
			<td> <code>2</code> </td>
			<td> 0 &#8211; 5 </td>
			<td> How fuzzy the search is and how long the minimum search term is allowed to be. 0 means a very close match, that allows short search words. 5 means it&#8217;s quite relaxed and is likelt return nothing like what you searched for; search words must then be longer, roughly &gt;7 characters. Practical values are 0-3 </td>
		</tr>
		<tr>
			<td> refine  </td>
			<td> <code>soundex, metaphone</code> </td>
			<td> <code>soundex</code>, <code>metaphone</code> or <code>soundex, metaphone</code> </td>
			<td> Switch on soundex and/or metaphone support for potentially better matching (though it&#8217;s usually only of use in English) </td>
		</tr>
		<tr>
			<td> case_sensitive </td>
			<td> <code>0</code> </td>
			<td> <code>0</code> (off) / <code>1</code> (case-sensitive) </td>
			<td> Does what it says on the tin </td>
		</tr>
		<tr>
			<td> min_word_length </td>
			<td> <code>4</code> </td>
			<td> integer </td>
			<td> The minimum word length allowed in the search results </td>
		</tr>
		<tr>
			<td> limit </td>
			<td> <code>words:5, articles:10</code> </td>
			<td> integer / <code>words:</code> + a number / <code>articles:</code> + a number </td>
			<td> The maximum number of words and/or articles to display in the results. Use a single integer to limit both to the same value. Specify <code>articles:5</code> to remove any limit on the number of alternative search words offered, and limit the number of returned articles to a maximum of 5. Similarly, <code>words:4</code> will remove the article limit, but only show a maximum of 4 alternative words. Both of these are subject to their respective <code>show</code> options being set </td>
		</tr>
		<tr>
			<td> form </td>
			<td> <code>search_results</code> </td>
			<td> any valid form name </td>
			<td> The <span class="caps"><span class="caps">TXP</span></span> form with which to process each matching article. You may also use the plugin as a container. Note that <code>&lt;txp:search_result_excerpt /&gt;</code> is honoured as closely as possible, i.e. if the closest words are found in the keywords, body or excerpt fields. If they are found in any other location (e.g. custom_3) the article title will be returned but the search_result_excerpt will likely be empty. This is a limitation of that tag, not the plugin directly </td>
		</tr>
		<tr>
			<td> delim </td>
			<td> <code>,</code> </td>
			<td> any characters </td>
			<td> Change the delimiter for all options that take a comma-separated list </td>
		</tr>
		<tr>
			<td> no_match_label </td>
			<td> <span class="caps"><span class="caps">MLP</span></span>: <code>no_match</code> </td>
			<td> any text or <span class="caps"><span class="caps">MLP</span></span> string </td>
			<td> The phrase to display when no matches (maybe not even fuzzy ones) are found. Use <code>no_match_label=&quot;&quot;</code> to turn it off </td>
		</tr>
		<tr>
			<td> suggest_label </td>
			<td> <span class="caps"><span class="caps">MLP</span></span>: <code>suggest</code> </td>
			<td> any text or <span class="caps"><span class="caps">MLP</span></span> string </td>
			<td> The phrase to display immediately prior to showing close-matching articles/words. Use <code>suggest_label=&quot;&quot;</code> to switch it off </td>
		</tr>
		<tr>
			<td> too_short_label </td>
			<td> <span class="caps"><span class="caps">MLP</span></span>: <code>too_short</code> </td>
			<td> any text or <span class="caps"><span class="caps">MLP</span></span> string  </td>
			<td> Searches of under about 3 characters (sometimes more depending on your content) are too short for any reasonable fuzziness to be applied. This message is displayed in that circumstance. Use <code>too_short_label=&quot;&quot;</code> to switch it off </td>
		</tr>
		<tr>
			<td> labeltag </td>
			<td> <code>p</code> </td>
			<td> any valid tag name, without its brackets </td>
			<td> The (X)HTML tag in which to wrap any labels </td>
		</tr>
	</table>

	<h2>Tips and tricks</h2>

	<ul>
		<li>If you can, limit the search criteria using <code>status</code>, <code>section</code> and <code>category</code> to improve performance</li>
		<li>Tweak the <code>refine</code> options to see if you get better or worse results for your content / language</li>
		<li>Most of the default values are optimal for good results, but for scientific or specialist sites you may wish to increase the <code>tolerance</code> and <code>min_word_length</code> to avoid false positives</li>
		<li>For offering an advanced search facility, write an <span class="caps">HTML</span> form that allows people to customise the search criteria (e.g. check boxes for <code>metaphone</code> / <code>soundex / case_sensitive</code> ; text boxes for <code>tolerance</code> / <code>min_word_length</code> / <code>limit</code> ; select lists of categories to search ; etc). Then use a series of smd_if statements inside your <code>&lt;txp:if_search /&gt;</code> to check for the existence of each <span class="caps">URL</span> variable, check they have acceptable values and then plug them into the smd_fuzzy_find tag using replacements such as <code>tolerance=&quot;{smd_if_tolerance}&quot;</code></li>
	</ul>

	<h2>Known issues</h2>

	<ul>
		<li>Slow with large article sets</li>
		<li>Searching for a word with an apostrophe in it may cause odd character encoding or incomplete results</li>
		<li>Searching for multiple (space-separated) words can lead to odd results</li>
		<li>Sometimes it makes you laugh and picks something that seems totally unrelated</li>
	</ul>

	<h2>Changelog</h2>

	<ul>
		<li>26 Mar 2007 | v0.01 | Initial public beta</li>
		<li>31 Mar 2007 | v0.02 | Fixed case sensitivity ; sped up and improved wordlist generation ; added <code>refine</code> to switch off soundex/metaphone if required</li>
		<li>06 Aug 2007 | v0.03 | Fixed: multi-word searching (<code>smd_getWord</code> bug) and <code>search_result_excerpt</code> ; added <code>category</code> and <code>status</code> support</li>
		<li>30 Dec 2007 | v0.1 | Official first release. Added <code>labeltag</code> and custom field support ; fixed bad counting in <code>limit</code> attribute (thanks Els)</li>
		<li>23 Jan 2008 | v0.11 | Fixed: <span class="caps">MLP</span>ing some strings broke the plugin (thanks Els)</li>
		<li>01 Apr 2008 | v0.12 | Moved some smd_lib functions to the plugin ; requires smd_lib_v0.32</li>
		<li>02 Dec 2008 | v0.2 | Requires smd_lib v0.33 ; tentative unicode support ; will read search locations set by wet_haystack ; enhanced <code>subcats</code> and renamed it <code>sublevel</code> ; added <code>delim</code> ; enhanced debug output ; plugin can now be used as a container ; tightened code, enhanced <code>?</code> and <code>!</code> support ; fixed smd_getWord (again!) and field list bug</li>
		<li>19 Dec 2008 | v0.21 | Fixed <span class="caps">MLP</span> string bug</li>
		<li>17 Oct 2013 | v0.22 | Added class <code>smd_fuzzy_suggest</code> to suggested word anchors</li>
	</ul>

	<h2>Credits</h2>

	<p>This plugin wouldn&#8217;t have existed without the original Fuzzy Find algorithm by Jarno Elonen, as noted above. All kudos goes in that direction. Also, extended thanks to the beta testers, especially Els Lepelaars for feedback and unending patience during development. And of course Team TextPattern for the best <span class="caps">CMS</span> on the planet.</p>

</div>
# --- END PLUGIN HELP ---
-->
<?php
}
?>