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

$plugin['version'] = '0.3.1';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com';
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

if (class_exists('\Textpattern\Tag\Registry')) {
    Txp::get('\Textpattern\Tag\Registry')->register('smd_fuzzy_find');
}

function smd_fuzzy_find($atts, $thing = '')
{
    global $pretext, $smd_fuzzLang, $prefs;

    extract(lAtts(array(
        'form'            => 'search_results',
        'section'         => '',
        'category'        => '',
        'sublevel'        => '0',
        'search_term'     => '?q',
        'match_with'      => '',
        'tolerance'       => '2',
        'min_word_length' => '4',
        'delim'           => ',',
        'limit'           => '',
        'case_sensitive'  => '0',
        'refine'          => '',
        'show'            => '',
        'status'          => '',
        'debug'           => '0',
        'no_match_label'  => '#',
        'suggest_label'   => '#',
        'too_short_label' => '#',
        'labeltag'        => 'p',
    ), $atts));

    // Process defaults; most can't be set in lAtts() because of the custom delimiter
    $match_with = empty($match_with) ? "article:" . implode(";", do_list($prefs['searchable_article_fields'])) : $match_with;
    $limit = ($limit) ? $limit : "words:5" .$delim. "articles:10";
    $refine = ($refine) ? $refine : "metaphone" .$delim. "soundex";
    $show = ($show) ? $show : "words" .$delim. "articles";
    $status = ($status) ? $status : "live" .$delim. "sticky";

    $refineAllow = array("metaphone", "soundex");
    $showAllow = array("articles", "words");
    $colNames = array(
        'Keywords'  => "article:keywords",
        'Body'      => "article:body",
        'Excerpt'   => "article:excerpt",
        'Category1' => "article:category1",
        'Category2' => "article:category2",
        'Section'   => "article:section",
        'ID'        => "article:id",
        'AuthorID'  => "article:authorid",
        'Title'     => "article:title",
        'message'   => "comments:message",
        'email'     => "comments:email",
        'name'      => "comments:name",
        'web'       => "comments:web",
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
    for ($idx = 0; $idx < count($lookin); $idx += 2) {
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
    $no_match_label = ($no_match_label == "#") ? $smd_fuzzLang->gTxt('no_match', array("{search_term}" => txpspecialchars($search_term))) : $no_match_label;
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
            $clause[] = '(Section IN (' .implode(",", $secinc). '))';
        }

        // Category
        list($catinc, $catexc) = smd_doList($category, false, "article:".$sublevel, true, $delim);

        if ($catinc) {
            $imp = implode(",", $catinc);
            $clause[] = '(Category1 IN (' .$imp. ') OR Category2 IN (' .$imp. '))';
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

        while ($row = nextRow($rs)) {
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

            // Remove between-word Unicode punctuation and replace with space
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
                while (list($idx, $dist) = each($matches)) {
                    $term = smd_getWord($werds, $search_term, $idx);

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
A PHP class for approximate string searching of large text masses, adapted (*cough* borrowed) from https://elonen.iki.fi/code/misc-notes/appr-search-php/. Instantiate one of these and pass it the string pattern/word you are looking for and a number indicating how close that match has to be/minimum length of strings to consider (i.e. the amount of error tolerable). 0=close match/short words; 10=pretty much every long (10 char minimum) string in the world. Practical values are usually 1 or 2, sometimes 3.

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
    function search_short($patt, $k, $text, $start_index = 0, $max_len = -1, $text_strlen = -1)
    {
        if ($text_strlen < 0) {
            $text_strlen = strlen($text);
        }

        if ($max_len < 0) {
            $max_len = $text_strlen;
        }

        $start_index = (int)max(0, $start_index);
        $n = min($max_len, $text_strlen - $start_index);
        $m = strlen($patt);
        $end_index = $start_index + $n;

        // If $text is shorter than $patt, use the built-in
        // levenshtein() instead:
        if ($n < $m) {
            $lev = levenshtein(substr($text, $start_index, $n), $patt);

            if ($lev <= $k) {
                return Array($start_index + $n - 1 => $lev);
            } else {
                return Array();
            }
        }

        $s = Array();

        for ($i = 0; $i < $m; $i++) {
            $c = $patt[$i];

            if (isset($s[$c])) {
                $s[$c] = min($i, $s[$c]);
            } else {
                $s[$c] = $i;
            }
        }

        if ($end_index < $start_index) {
            return Array();
        }

        $matches = Array();
        $da = $db = range(0, $m - $k + 1);

        $mk = $m - $k;

        for ($t = $start_index; $t < $end_index; $t++) {
            $c = $text[$t];
            $in_patt = isset($s[$c]);

            if ($t & 1) {
                $d = &$da;
                $e = &$db;
            } else {
                $d = &$db;
                $e = &$da;
            }

            for ($i = 1; $i <= $mk; $i++) {
                $g = min($k + 1, $e[$i] + 1, $e[$i + 1] + 1);

                // TODO: optimize this with a look-up-table?
                if ($in_patt)
                    for ($j = $e[$i - 1]; ($j < $g && $j <= $mk); $j++) {
                        if ($patt[$i + $j - 1] == $c) {
                            $g = $j;
                        }
                    }

                $d[$i] = $g;
            }

            if ($d[$mk] <= $k) {
                $err = $d[$mk];
                $i = min($t-$err + $k + 1, $start_index + $n - 1);

                if (!isset($matches[$i]) || $err < $matches[$i]) {
                    $matches[$i] = $err;
                }
            }
        }

        unset($da, $db);

        return $matches;
    }

    function test_short_search()
    {
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

    function __construct ($pattern, $max_error)
    {
        $this->patt = $pattern;
        $this->patt_len = strlen($this->patt);
        $this->max_err = $max_error;

        // Calculate pattern partition size
        $intpartlen = floor($this->patt_len / ($this->max_err + 2));

        if ($intpartlen < 1) {
            $this->too_short_err = true;
            return;
        } else {
            $this->too_short_err = false;
        }

        // Partition the pattern for pruning
        $this->parts = Array();

        for ($i = 0; $i < $this->patt_len; $i += $intpartlen) {
            if ($i + $intpartlen * 2 > $this->patt_len) {
                $this->parts[] = substr($this->patt, $i);
                break;
            } else {
                $this->parts[] = substr($this->patt, $i, $intpartlen);
            }
        }

        $this->n_parts = count($this->parts);

        // The intpartlen test above should have covered this:
        assert($this->n_parts >= $this->max_err + 1);

        // Find maximum part length
        foreach ($this->parts as $p) {
            $this->max_part_len = max( $this->max_part_len, strlen($p));
        }

        // Make a new part array with duplicate strings removed
        $this->unique_parts = array_unique($this->parts);

        // Transform the pattern into a low resolution pruning string
        // by replacing parts with single characters
        $this->transf_patt = "";
        reset($this->parts);

        while (list(,$p) = each($this->parts)) {
           $this->transf_patt .= chr(array_search($p, $this->unique_parts) + ord("A"));
        }

        // Self diagnostics
        $this->test_short_search();
    }

    function search($text)
    {
        // Find all occurrences of unique parts in the
        // full text. The result is an array:
        //   Array( <index> => <part#>, .. )
        $part_map = Array();
        reset($this->unique_parts);

        while (list($pi, $part_str) = each($this->unique_parts)) {
            $pos = strpos($text, $part_str);

            while ($pos !== false) {
                $part_map[$pos] = $pi;
                $pos = strpos($text, $part_str, $pos+1);
            }
        }

        ksort($part_map); // Sort by string index

        // The following code does several things simultaneously:
        //  1) Divide the indices into groups using gaps
        //    larger than $this->max_err as boundaries.
        //  2) Translate the groups into strings so that
        //    part# 0 = 'A', part# 1 = 'B' etc. to make
        //    a low resolution approximate search possible later
        //  3) Save the string indices in the full string
        //    that correspond to characters in the translated string.
        //  4) Discard groups (=part sequences) that are too
        //    short to contain the approximate pattern.
        // The format of resulting array:
        //   Array(
        //    Array( "<translate-string>",
        //           Array( <translated-idx> => <full-index>, ... ) ),
        //    ... )
        $transf = Array();
        $transf_text = "";
        $transf_pos = Array();
        $last_end = 0;
        $group_len = 0;
        reset($part_map);

        while (list($i,$p) = each($part_map)) {
            if ($i - $last_end > $this->max_part_len+$this->max_err) {
                if ($group_len >= ($this->n_parts-$this->max_err)) {
                    $transf[] = Array( $transf_text, $transf_pos );
                }

                $transf_text = "";
                $transf_pos = Array();
                $group_len = 0;
            }

            $transf_text .= chr($p + ord("A"));
            $transf_pos[] = $i;
            $group_len++;
            $last_end = $i + strlen($this->parts[$p]);
        }

        if (strlen($transf_text) >= ($this->n_parts-$this->max_err)) {
            $transf[] = Array( $transf_text, $transf_pos );
        }

        unset($transf_text, $transf_pos);

        if (current($transf) === false) {
            return Array();
        }

        // Filter the remaining groups ("approximate anagrams"
        // of the pattern) and leave only the ones that have enough
        // parts in correct order. You can think of this last step of the
        // algorithm as a *low resolution* approximate string search.
        // The result is an array of candidate text spans to be scanned:
        //   Array( Array(<full-start-idx>, <full-end-idx>), ... )
        $part_positions = Array();

        while (list(,list($str, $pos_map)) = each($transf)) {
//          print "|$transf_patt| - |$str|\n";
            $lores_matches = $this->search_short($this->transf_patt, $this->max_err, $str);

            while (list($tr_end, ) = each($lores_matches)) {
                $tr_start = max(0, $tr_end - $this->n_parts);

                if ($tr_end >= $tr_start) {
                    $median_pos = $pos_map[(int)(($tr_start + $tr_end) / 2)];
                    $start = $median_pos - ($this->patt_len / 2 + 1) - $this->max_err - $this->max_part_len;
                    $end = $median_pos + ($this->patt_len / 2 + 1) + $this->max_err + $this->max_part_len;

//                  print "#" . strtr(substr( $text, $start, $end-$start ), "\n\r", "$$") . "#\n";
//                  print_r( $this->search_short( &$this->patt, $this->max_err, &$text, $start, $end-$start ));

                    $part_positions[] = Array($start, $end);
                }
            }
            unset( $lores_matches );
        }
        unset( $transf );

        if (current($part_positions) === false) {
            return Array();
        }

        // Scan the final candidates and put the matches in a new array:
        $matches = Array();
        $text_len = strlen($text);

        while (list(, list($start, $end)) = each($part_positions)) {
            $m = $this->search_short($this->patt, $this->max_err, $text, $start, $end - $start, $text_len);

            while (list($i, $cost) = each($m)) {
                $matches[$i] = $cost;
            }
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
function smd_getWord($haystack, $searchterm, $offset = 0)
{
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
# --- BEGIN PLUGIN HELP ---
h1. smd_fuzzy_find

Ever wanted TXP's search facility to be a little more, well, inaccurate? Help people with fat fingers to find what they were actually looking for with this tool that can find mis-spelled or like-sounding words from search results.

Results are ordered by approximate relevancy and it's automatically context-sensitive because the pool of words it compares against are from your site. On a zoo website, someone typing in "lino" won't get articles about flooring but more likely articles about lions, which is what they really wanted. We hope.

h2. Features

* Search for similarly spelled, or similar sounding words (may be switched on/off)
* Limit the search to article @status@, @section@ or @category@ to speed up proceedings
* Tweak sensitivity to give better results (more specific, less likely to find a match) or general results (less specific, may match stuff you don't expect)
* Can offer links to exact search terms if you wish (a bit like Google's "Did you mean ...")
* Display matching articles using a form/container. Default is the built-in @search_results@ form
* Unless overridden using the @match_with@ attribute, the Title and Body will be searched. Alternatively, if you have set the search locations using "wet_haystack":https://forum.textpattern.com/viewtopic.php?id=29036 then those places will be searched instead

h2. Author

"Stef Dawson":https://stefdawson.com/commentForm, with notable mention to "Jarno Elonen":https://elonen.iki.fi/code/misc-notes/appr-search-php/index.html for the Fuzzy Find algorithm which -- to this day -- remains mere voodoo to me.

h2. Installation / Uninstallation

p(required). Requires Textpattern 4.0.7 and "smd_lib v0.33":https://stefdawson.com/downloads/smd_lib_v0.33.txt must be installed and activated.

Download the plugin from either "textpattern.org":https://textpattern.org/plugins/932/smd_fuzzy_find, or the "software page":https://stefdawson.com/sw, paste the code into the TXP Admin -> Plugins pane, install and enable the plugin. Visit the "forum thread":https://forum.textpattern.com/viewtopic.php?id=25367 for more info or to report on the success or otherwise of the plugin.

To uninstall, simply delete from the Admin -> Plugins page.

h2. Usage

The plugin is *not* a replacement for the built-in TXP search; it should be used to augment it, like this:

bc. <txp:if_search>
  <dl class="results">
    <txp:chh_if_data>
      <txp:article limit="8" searchform="excerpts" />
    <txp:else />
      <txp:smd_fuzzy_find form="excerpts" />
    </txp:chh_if_data>
  </dl>
</txp:if_search>

Exact matches will be processed as normal but mismatches will be handled by smd_fuzzy_find. If you try to use smd_fuzzy_find on its own, you will likely receive a warning about a missing @<txp:article />@ tag.

h3. Attributes

|_. Attribute name |_. Default |_. Values |_. Description |
| search_term | @?q@ | @?q@ or any text | You may use a fixed string here but it's rather pointless |
| match_with | @article:body;title@ or wet_haystack criteria | @keywords@ / @body@ / @excerpt@ / @category1@ / @category2@ / @section@ / @id@ / @authorid@ / @title@ / @custom_1@ / etc | Which article fields you would like to match. Define the object you want to look in (currently only @article@ is supported) followed by a colon, then a list of semi-colon separated places to look |
| show | @words, articles@ |  @words, articles@ / @words@ / @articles@ | Whether to list the closest matching articles, the closest matching search terms, or both |
| section | _unset_ (i.e. search the whole site) | any valid section containing articles | Limit the search to one or more sections; give a comma-separated list. You can use @?s@ for the current section or @!s@ for anything except the current section, or you can read the value from another part of the article, a custom field / txp:variable / url variable |
| category | _unset_ (i.e. search all categories) | any valid article category | Limit the search to one or more categories; give a comma-separated list. You can use @?c@ for the current global category or @!c@ for all cats except the current one. Like @section@ you can read from other places too |
| sublevel (formerly _subcats_)| @0@ | integer / @all@ | Number of subcategory levels to traverse. @0@ = top-level only; @1@ = top level + one sub-level; and so on |
| status | @live, sticky@ | @live@ / @sticky@ / @hidden@ / @pending@ / @draft@ or any combination | Restricts the search to particular types of document status |
| tolerance | @2@ | 0 - 5 | How fuzzy the search is and how long the minimum search term is allowed to be. 0 means a very close match, that allows short search words. 5 means it's quite relaxed and is likelt return nothing like what you searched for; search words must then be longer, roughly >7 characters. Practical values are 0-3 |
| refine  | @soundex, metaphone@ | @soundex@, @metaphone@ or @soundex, metaphone@ | Switch on soundex and/or metaphone support for potentially better matching (though it's usually only of use in English) |
| case_sensitive | @0@ | @0@ (off) / @1@ (case-sensitive) | Does what it says on the tin |
| min_word_length | @4@ | integer | The minimum word length allowed in the search results |
| limit | @words:5, articles:10@ | integer / @words:@ + a number / @articles:@ + a number | The maximum number of words and/or articles to display in the results. Use a single integer to limit both to the same value. Specify @articles:5@ to remove any limit on the number of alternative search words offered, and limit the number of returned articles to a maximum of 5. Similarly, @words:4@ will remove the article limit, but only show a maximum of 4 alternative words. Both of these are subject to their respective @show@ options being set |
| form | @search_results@ | any valid form name | The TXP form with which to process each matching article. You may also use the plugin as a container. Note that @<txp:search_result_excerpt />@ is honoured as closely as possible, i.e. if the closest words are found in the keywords, body or excerpt fields. If they are found in any other location (e.g. custom_3) the article title will be returned but the search_result_excerpt will likely be empty. This is a limitation of that tag, not the plugin directly |
| delim | @,@ | any characters | Change the delimiter for all options that take a comma-separated list |
| no_match_label | MLP: @no_match@ | any text or MLP string | The phrase to display when no matches (maybe not even fuzzy ones) are found. Use @no_match_label=""@ to turn it off |
| suggest_label | MLP: @suggest@ | any text or MLP string | The phrase to display immediately prior to showing close-matching articles/words. Use @suggest_label=""@ to switch it off |
| too_short_label | MLP: @too_short@ | any text or MLP string  | Searches of under about 3 characters (sometimes more depending on your content) are too short for any reasonable fuzziness to be applied. This message is displayed in that circumstance. Use @too_short_label=""@ to switch it off |
| labeltag | @p@ | any valid tag name, without its brackets | The (X)HTML tag in which to wrap any labels |

h2. Tips and tricks

* If you can, limit the search criteria using @status@, @section@ and @category@ to improve performance
* Tweak the @refine@ options to see if you get better or worse results for your content / language
* Most of the default values are optimal for good results, but for scientific or specialist sites you may wish to increase the @tolerance@ and @min_word_length@ to avoid false positives
* For offering an advanced search facility, write an HTML form that allows people to customise the search criteria (e.g. check boxes for @metaphone@ / @soundex / case_sensitive@ ; text boxes for @tolerance@ / @min_word_length@ / @limit@ ; select lists of categories to search ; etc). Then use a series of smd_if statements inside your @<txp:if_search />@ to check for the existence of each URL variable, check they have acceptable values and then plug them into the smd_fuzzy_find tag using replacements such as @tolerance="{smd_if_tolerance}"@

h2. Known issues

* Slow with large article sets
* Searching for a word with an apostrophe in it may cause odd character encoding or incomplete results
* Searching for multiple (space-separated) words can lead to odd results
* Sometimes it makes you laugh and picks something that seems totally unrelated

h2. Credits

This plugin wouldn't have existed without the original Fuzzy Find algorithm by Jarno Elonen, as noted above. All kudos goes in that direction. Also, extended thanks to the beta testers, especially Els Lepelaars for feedback and unending patience during development.

# --- END PLUGIN HELP ---
-->
<?php
}
?>